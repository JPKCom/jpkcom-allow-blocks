# Per-role block permissions with a settings screen

**Status:** approved 2026-07-30
**Plugin:** `jpkcom-allow-blocks`
**Target release:** 3.0.0

## Why

The plugin currently carries a hard-coded list of 17 permitted block types and applies it through `allowed_block_types_all`.

That list has never taken effect. The whole registration sits inside `if ( ! is_admin() )`, but `is_admin()` means "this request is for the admin area", not "the current user is an administrator" — and the block editor *is* the admin area. All three core call sites of `get_block_editor_settings()` live in `wp-admin/`: `edit-form-blocks.php:357`, `site-editor.php:165`, `widgets-form-blocks.php`. Measured on WordPress 7.0.2 with the plugin active, the editor receives:

```
"allowedBlockTypes": true
```

So every block is available to everyone. The intent was clearly "restrict non-administrators", which needs a capability check, not `is_admin()`.

Rather than repair a hard-coded list, this replaces it with a settings screen: enumerate the registered blocks and let an administrator enable or disable them per user role.

## Decisions

These were settled before design and constrain everything below.

| Question | Decision |
|---|---|
| Administrators | Hard-exempt. Not shown in the UI, cannot be restricted. |
| A block with no stored setting | Allowed. New blocks appear for everyone until switched off. |
| The old hard-coded list | Dropped entirely. Nothing is migrated; behaviour after the update matches behaviour before it (everything allowed). |
| `jpkcom_allowed_block_types()` | Removed, without a deprecated shim. It took two arguments and returned a fixed array regardless of them, so a shim could only return that same stale list — worse than a clear fatal for the unlikely caller that used it. Noted in the changelog as a breaking change. |
| Import | Merges. Per role: a role present in the file replaces that role's list; roles absent from the file are untouched. |
| Layout | Matrix — blocks as rows grouped by category, roles as columns. |

## Approach

WordPress offers an *allow* filter (`allowed_block_types_all`); the decision above makes the setting logically a *deny* list. The plugin stores the deny list and derives the allow list at runtime.

Storing what is **blocked** rather than what is allowed makes three requirements fall out without special cases:

- A block that appears is in no deny list, so it is allowed.
- A block whose plugin is deactivated keeps its entry — nothing is pruned, so nothing is lost, and reactivating restores the previous behaviour.
- A block that is gone for good is visible in the UI under "no longer registered" and can be removed deliberately.

Two alternatives were considered and rejected:

- **Client-side `unregisterBlockType`.** True deny semantics and immune to blocks PHP cannot see, but an unregistered block turns existing instances in saved content into "unsupported block" notices. Taking a block away from a role must not damage existing pages.
- **Hybrid of both.** Covers the residual case at the cost of two mechanisms, two failure modes and duplicated documentation, for a case that affects zero blocks on the target site.

### Known limitation

The allow list has to be enumerated, and PHP only knows blocks registered through `register_block_type` — 168 on the reference site (core 110, areoi 47, plus rank-math, safe-svg and JPKCom blocks). A block registered only in JavaScript is invisible to PHP and would be dropped from the derived list as a side effect. This is rare, since `block.json` registration is the standard. The filter `jpkcom_allow_blocks_extra_block_names` lets a site add such names.

The restriction governs the editor UI. It is not a security boundary: it controls what the inserter offers, not what can be posted.

## Data model

One option, `jpkcom_allow_blocks_settings`, stored with autoload off — it is only read in the admin area, and the front end should not carry it on every request.

```php
[
  'schema'  => 1,
  'updated' => '2026-07-30T15:04:11+00:00',
  'roles'   => [
      'editor'      => [ 'core/code', 'core/html' ],
      'contributor' => [ 'core/code', 'core/html', 'core/table' ],
  ],
  'labels'  => [
      'core/code'      => 'Code',
      'areoi/carousel' => 'Carousel',
  ],
]
```

- `roles` maps a role slug to the block names blocked for it. A role with no entry blocks nothing.
- `labels` remembers the last known human-readable title per block, so the UI can show something meaningful for a block whose plugin is currently inactive.
- `schema` exists for future migrations.

Validation on every write: role slugs through `sanitize_key()`, block names against the WordPress block-name grammar `^[a-z0-9-]+/[a-z0-9-]+$`, labels through `sanitize_text_field()`. Anything that fails is dropped rather than stored, and the caller is told how many entries were rejected.

## Runtime filter

`allowed_block_types_all`, priority 10, two arguments:

1. `current_user_can( 'manage_options' )` → return the incoming value unchanged. This is the administrator exemption, expressed as a capability rather than a role slug so multisite super admins are covered. Overridable through `jpkcom_allow_blocks_is_exempt`.
2. A block counts as blocked only when **every** role of the current user blocks it — the intersection across the user's roles. This matches WordPress capability semantics: more roles never mean fewer rights.
3. Empty intersection → return unchanged. An active but unconfigured plugin is completely inert.
4. Incoming value is an array → subtract the blocked names. Restrictions set by other plugins are respected instead of overwritten, which is what the current code gets wrong.
5. Incoming value is `true` → build from the registry plus `jpkcom_allow_blocks_extra_block_names`, minus the blocked names.
6. Incoming value is `false` → stays `false`. Somebody disabled the editor entirely; this plugin does not re-enable it.

## Settings screen

`Appearance → Block permissions`, a submenu of `themes.php`, capability `manage_options`.

Rows are blocks grouped by block category; columns are roles. The block list is the registry **unioned with** every name present in the settings, so blocks from a deactivated plugin still appear — under a "no longer registered" group with a warning marker and the option to drop them there deliberately.

Roles without `edit_posts` (`subscriber` on the reference site) are hidden by default, with a toggle to show them, since a custom role may handle that differently.

Search, category filter and column selection run client-side in a small vanilla JavaScript file. No build step and no dependency.

**Saving computes the difference over the rendered rows only.** The form submits, for every row it rendered, both the block name and its checkbox state. The new deny list per role is:

```
(old deny list − rendered block names) ∪ (rendered block names whose box is unchecked)
```

Without this, saving while a search filter is active would wipe every setting not currently on screen.

## Export and import

**Export** is an `admin_post` handler that sends a JSON download containing the full option plus plugin version, site URL and timestamp for provenance. Filename: `jpkcom-allow-blocks-<site>-<date>.json`.

**Import** runs in three steps: upload → preview → confirm. The preview states concretely what will happen — how many roles are affected, how many blocks change, how many settings refer to blocks unknown on this site. Only after confirmation is anything written.

Applying merges per role: for each role in the file, its deny list replaces the stored one; roles absent from the file are left alone. The unit is the role, not the individual block. `labels` are merged with the file taking precedence for keys it contains.

A role in the file that does not exist on this site **is stored anyway**, for the same reason a block from a deactivated plugin is kept: the role may be created later, or provided by a plugin that is currently off. The preview names such roles so the import is not silently doing more than it appears to.

## Error handling

- A missing or corrupt option is treated as empty: nothing blocked, plugin inert, never a fatal error.
- Deactivating the plugin leaves the option untouched, which is the point of the whole deny-list design. **Uninstalling leaves it as well** — no `uninstall.php`. Losing a carefully built permission matrix because somebody removed and reinstalled the plugin would be worse than an orphaned option, and the option is a single row that can be deleted by hand.
- Invalid JSON or a wrong `schema` on import changes nothing and reports it as an admin notice.
- Uploads are checked with `is_uploaded_file()` and capped at 1 MB.
- Saving with nothing blocked stores an empty structure, which the filter treats as inert.
- Every write path is nonce-checked and capability-checked.

## File layout

Five files, one responsibility each:

```
jpkcom-allow-blocks.php        ← header, constants, updater bootstrap, module loading
includes/settings-store.php    ← read, write and validate the option (single source of truth)
includes/block-filter.php      ← allowed_block_types_all
includes/admin-page.php        ← menu, matrix rendering, save handler
includes/import-export.php     ← export download, import parse/preview/apply
assets/admin.js, assets/admin.css
tests/test-blocks.php
```

`settings-store.php` is the only module that touches the option. The filter and the admin page both go through it, so validation cannot be bypassed by adding a caller later.

## Tests

`tests/test-blocks.php`, standalone with stubbed WordPress functions, in the style used across the fleet:

- intersection across multiple roles, including a user whose second role blocks nothing
- administrator exemption, and the `jpkcom_allow_blocks_is_exempt` override
- inert when no block is blocked
- all four incoming filter values: `true`, an array, `false`, and an empty array
- block names unknown to the registry survive a save round trip
- invalid block names and role slugs are rejected on save and on import
- import merge semantics: role in file replaces, role absent stays
- export/import round trip reproduces the same settings
- saving with a filtered subset of rows preserves the rows that were not rendered

## Localisation

New interface strings are translatable, with `de_DE` and `de_DE_formal` catalogues shipped, matching the rest of the fleet. The POT is generated from the plugin directory only, excluding `tests`.

## Out of scope

- Per-user overrides. Roles are the unit.
- Restricting block *patterns*, *styles* or *variations*.
- Restricting administrators.
- A network-wide settings screen on multisite. Settings are per site, as the plugin has no `Network:` header.
