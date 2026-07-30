# JPKCom Allow Block Types – Developer Reference

## Plugin Overview

Lets an administrator restrict, per WordPress role, which block types the block
editor offers. A settings screen under Appearance renders every known block as
a row and every non-administrator role as a column; unticking a box blocks
that block for that role. Administrators are always exempt and are never
shown as a column.

- **Text Domain:** `jpkcom-allow-blocks` (declared in the plugin header,
  `Domain Path: /languages`; loaded via `load_plugin_textdomain()` on
  `plugins_loaded`)
- **Min PHP:** 8.3 | **Min WP:** 6.9
- **Network:** not network-only (no `Network:` header) — settings are per
  site

---

## Architecture

```
Main file (jpkcom-allow-blocks.php)
├── declare(strict_types=1)
├── Plugin header (incl. Text Domain / Domain Path)
├── JPKCOM_ALLOW_BLOCKS_VERSION, JPKCOM_ALLOW_BLOCKS_PATH constants
├── init @ priority 5: boot JPKComGitPluginUpdater
├── plugins_loaded: load_plugin_textdomain()
└── plugins_loaded @ priority 5: require the plugin modules
    ├── includes/settings-store.php    (always)
    ├── includes/block-filter.php      (always)
    ├── includes/admin-page.php        (is_admin() only)
    └── includes/import-export.php     (is_admin() only)
```

Modules load on `plugins_loaded` rather than at file scope so the settings
store exists before anything reads it, and well before
`allowed_block_types_all` is applied when the editor assembles its settings
in `wp-admin`. `settings-store.php` and `block-filter.php` load on every
request, including the front end, because `allowed_block_types_all` also
fires for REST requests made from the editor; `admin-page.php` and
`import-export.php` only register `admin_post_*` handlers and a submenu, so
they are gated on `is_admin()`.

---

## Why the 2.x filter never did anything

The plugin used to carry a hard-coded list of 17 block types and register the
filter inside `if ( ! is_admin() )`. That check means "this request is *for*
the admin area", not "the current user is an administrator" — and the block
editor **is** the admin area. Every core call site of
`get_block_editor_settings()` (`edit-form-blocks.php`, `site-editor.php`,
`widgets-form-blocks.php`) lives under `wp-admin/`, so the filter callback
was simply never invoked where it mattered. Measured on WordPress 7.0 with
the 2.x plugin active, the editor received `"allowedBlockTypes": true` for
every user. 3.0.0 replaces the whole mechanism; `jpkcom_allowed_block_types()`
is **removed with no deprecated shim** — it took two arguments and always
returned the same fixed array regardless of them, so a shim could only ever
return that stale list, which is worse than a clear fatal for the unlikely
caller that used it. This is a breaking change, called out in the
changelog.

---

## Data model: a deny list, not an allow list

One option, `jpkcom_allow_blocks_settings`, autoload **off** — it is only
read in the admin area (and by the editor's REST calls), so the front end
should not carry it on every request.

```php
[
  'schema'  => 1,
  'updated' => '2026-07-30T15:04:11+00:00',
  'roles'   => [
      'editor'      => [ 'core/code', 'core/html' ],
      'contributor' => [ 'core/code', 'core/html', 'core/table' ],
  ],
  'labels'  => [
      'core/code' => 'Code',
  ],
]
```

- `roles` maps a role slug to the block names **blocked** for it. A role with
  no entry blocks nothing.
- `labels` remembers the last known human-readable title per block, so the
  matrix can show something meaningful for a block whose plugin is currently
  inactive.
- `schema` exists for future migrations.

`allowed_block_types_all` is an *allow* filter, but WordPress' own default —
"everything" — already matches this plugin's own default of "nothing
blocked". Storing what is blocked, and deriving the allow list at runtime,
makes three things fall out without a single special case:

- A block nobody has touched appears in no role's deny list, so it is
  allowed. New blocks are available to everyone the moment they are
  registered.
- A block whose plugin is temporarily deactivated **keeps its entry** —
  nothing in this plugin ever prunes a stored block name. Reactivating the
  other plugin silently restores the previous restriction.
- A block that is gone for good stays visible in the settings matrix under
  an "unregistered" pseudo-category with a warning marker, so it can be
  dropped deliberately instead of by accident.

There is deliberately **no `uninstall.php`**. Deactivating the plugin leaves
the option untouched — that is the whole point of a deny list that is never
pruned — and uninstalling leaves it as well. Losing a carefully built
permission matrix because someone removed and reinstalled the plugin would
be worse than one orphaned option row, and that row can be deleted by hand
if it ever needs to be.

`includes/settings-store.php` is the **only** module that touches the
option (`jpkcom_allow_blocks_get_settings()`,
`jpkcom_allow_blocks_save_settings()`), so validation
(`jpkcom_allow_blocks_sanitize_settings()`) cannot be bypassed by adding a
new caller later. Every read and every write — the runtime filter, the
settings screen, import — goes through it. Anything that fails validation
(a non-`sanitize_key()` role slug, a block name that does not match the
WordPress block-name grammar `^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$`) is
dropped rather than stored; the sanitiser never throws, so a corrupt option
can only ever mean "nothing is blocked", never a fatal error.

---

## Runtime filter (`includes/block-filter.php`)

`allowed_block_types_all`, priority 10, two arguments.
`jpkcom_allow_blocks_filter_allowed( mixed $allowed, mixed $context )`:

1. Incoming value is `false` → return `false` unchanged. Somebody disabled
   the editor entirely; this plugin does not re-enable it.
2. `jpkcom_allow_blocks_is_exempt()` → return the incoming value unchanged.
   Exemption is expressed as `current_user_can( 'manage_options' )` rather
   than a role slug so multisite super admins are covered too, and it is
   overridable through the `jpkcom_allow_blocks_is_exempt` filter for sites
   that want a different boundary.
3. Compute the blocked set via `jpkcom_allow_blocks_blocked_for_roles()` —
   see the intersection rule below. If it is empty, return the incoming
   value unchanged: **an active but unconfigured plugin has zero effect**.
4. Incoming value is an array → `array_diff()` the blocked names out of it.
   The incoming array is only ever **reduced, never replaced or extended**,
   so a restriction another plugin already applied through the same filter
   is respected rather than overwritten — the bug the 2.x hard-coded list
   had, since it returned its own fixed array regardless of what it was
   given.
5. Incoming value is `true` → build the full list from the registry (plus
   `jpkcom_allow_blocks_extra_block_names`, see below), minus the blocked
   names.

### The intersection rule

A block counts as blocked for a user only when **every one of their roles**
blocks it (`jpkcom_allow_blocks_blocked_for_roles()`). This mirrors
WordPress capability semantics, where holding an additional role never
takes rights away — a user who is both `editor` and `contributor` can do
anything either role alone permits. A role that has no entry in `roles`
blocks nothing, which empties the intersection outright regardless of what
the user's other roles block.

### Blocks that are invisible to PHP

The allow list has to be enumerated, and PHP only knows about blocks
registered through `register_block_type()`/`block.json`. A block registered
purely in JavaScript (`registerBlockType()` with no server-side
counterpart) is invisible to `WP_Block_Type_Registry` and would silently
drop out of the derived allow list. This is rare — `block.json` registration
is the norm — but sites that hit it can add the missing names through the
`jpkcom_allow_blocks_extra_block_names` filter, which receives the array of
names the allow list is built from (registry + every name already mentioned
in settings) before it is deduplicated and validated.

The restriction governs the block **inserter**; it is not a security
boundary. It controls what a role can add through the editor UI, not what
content can be saved or posted through other means.

---

## Settings screen (`includes/admin-page.php`)

`Appearance → Block permissions` (`themes.php` submenu), capability
`manage_options`. Not a Settings API page — it renders and validates a
custom form by hand, submitted to `admin-post.php`.

- **Rows** are the union of the registered blocks and every block name
  mentioned anywhere in the stored settings (`roles` values and `labels`
  keys), so a block belonging to a currently-deactivated plugin still shows
  up — grouped under category `jpkcom-unregistered` with a warning badge —
  instead of silently vanishing from the one screen that could otherwise
  unblock it. Rows are sorted by category, then title.
- **Columns** are every role except `administrator`
  (`jpkcom_allow_blocks_editable_roles()`). Roles without the `edit_posts`
  capability (`subscriber` on a default install) are hidden unless the
  caller explicitly asks to include them — the render call always passes
  `true`, so they are shown; the parameter exists for callers with a
  narrower need.
- **Search, category filter and per-role column toggles** run client-side
  in `assets/admin.js` — plain browser JS, no build step, no dependency.
  Every listener is guarded on the presence of `.jpkcom-ab-table`, so the
  script is inert if it is ever enqueued somewhere else. This is why the
  save logic below cannot simply diff "every rendered row": rows can be
  hidden by the filters without being removed from the DOM.

### Save computes the difference over rendered rows only

The form submits one hidden `rendered[]` input per row it drew, plus a
`allowed[role][block-name]` checkbox for every ticked box. The handler
(`jpkcom_allow_blocks_apply_form()`) computes, per role:

```
new deny list = (old deny list − rendered block names) ∪ (rendered block names whose box is unticked)
```

Block names the form did not render **keep whatever they already had**.
Since the search box and category filter only hide table rows in the
browser — they never remove the corresponding `rendered[]` input — a
filtered view still submits every row, so this rule is really about API
callers or a truncated form, not the shipped UI. It still matters: any
future rendering that legitimately omits rows (pagination, a role-scoped
view) must not wipe the settings for the rows it did not draw, and this is
the mechanism that guarantees that.

---

## Export and import (`includes/import-export.php`)

**Export** (`admin_post_jpkcom_allow_blocks_export`) streams a JSON download
of `jpkcom_allow_blocks_export_payload()` — the full validated settings plus
`plugin_version`, `site_url` and `exported` for provenance — as
`jpkcom-allow-blocks-<date>.json`.

**Import** is upload → preview → confirm; nothing is written until the
preview is confirmed.

1. `admin_post_jpkcom_allow_blocks_import_preview` checks
   `manage_options` and its own nonce, verifies the upload with
   `is_uploaded_file()`, caps it at `JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES`
   (1 MB), then hands the raw JSON to `jpkcom_allow_blocks_parse_import()`.
   That function rejects, in order, non-array JSON, a missing/wrong
   `schema`, and a missing/non-array `roles` — every rejection path leaves
   `settings` empty and returns a translated `error`; nothing is read from
   or written to the option on any of these paths. On success it renders a
   small standalone HTML page (not the WP admin chrome) showing
   `jpkcom_allow_blocks_import_preview()`'s counts and a confirm form
   carrying the already-sanitised payload in a hidden field.
2. `admin_post_jpkcom_allow_blocks_import_apply` re-parses that hidden
   field — a tampered field is re-validated, not trusted — merges it over
   the current settings and saves.

### The merge unit is the role, not the block

`jpkcom_allow_blocks_merge_import()`: for every role slug present in the
incoming file, that role's **entire** deny list replaces what is currently
stored; a role absent from the file is left completely untouched. There is
no per-block union or diff across files. A role in the file that does not
exist on this site (`wp_roles()` has no such slug) is **stored anyway**,
for the identical reason a block from a deactivated plugin is kept — the
role may be created later, or belong to a plugin that is currently off.
`labels` are merged with the incoming file winning on keys it contains.

`jpkcom_allow_blocks_import_preview()` takes the live block registry as an
explicit `$known_blocks` parameter rather than reading
`WP_Block_Type_Registry` itself, so it stays a pure, easily-tested function;
the caller (the preview handler) always passes
`array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() )`.
`unknown_blocks` names any block mentioned in the incoming file's `roles`
that is not in that registry snapshot — including a block already present
in the *current* settings, since a plugin that is currently switched off
still leaves its name in storage, and this is precisely the case the
warning exists to surface.

---

## Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_ALLOW_BLOCKS_VERSION` | matches the header `Version:` | Plugin version (sync with header/README/phpdoc.xml) |
| `JPKCOM_ALLOW_BLOCKS_PATH` | `plugin_dir_path( __FILE__ )` | Base path used to require the modules and enqueue assets |
| `JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES` | `1048576` (1 MB) | Upper bound checked before an import file is read |

---

## File Structure

```
jpkcom-allow-blocks/
├── jpkcom-allow-blocks.php          ← Main: header, constants, textdomain, updater bootstrap, module loading
├── includes/
│   ├── settings-store.php           ← Read/write/validate the option (single source of truth)
│   ├── block-filter.php             ← allowed_block_types_all, exemption, intersection
│   ├── admin-page.php                ← Menu, matrix rendering, save handler
│   ├── import-export.php             ← Export download, import parse/preview/apply
│   └── class-plugin-updater.php     ← GitHub auto-updater (namespace: JPKComAllowBlocksGitUpdate)
├── assets/
│   ├── admin.css, admin.js          ← Settings screen styling and client-side filters
│   └── banner-*.avif                ← wp-admin plugin card banners
├── languages/                        ← .pot, .po/.mo/.l10n.php for de_DE and de_DE_formal
├── tests/                            ← Standalone test-*.php, no WordPress required
├── .github/workflows/release.yml    ← Build ZIP, manifest, PHPDoc, deploy to gh-pages (on tag push)
├── phpdoc.xml                        ← phpDocumentor config
├── README.md                         ← Public readme (source for the WP plugin modal)
├── CLAUDE.md                         ← This file
├── LICENSE                           ← GPL-2.0-or-later
└── .gitignore
```

---

## Localisation

Every user-facing string in `includes/*.php` (and the pre-existing strings
in `class-plugin-updater.php`) goes through `__()`/`esc_html__()`/
`esc_attr__()` under the `jpkcom-allow-blocks` text domain. `assets/admin.js`
has no user-facing text of its own (it only toggles CSS classes), so there
is nothing to translate on the JS side and no `wp_set_script_translations()`
call.

`de_DE` (informal) and `de_DE_formal` (`Sie` forms) catalogues ship in
`languages/`. To regenerate after adding or changing a string:

```bash
# Sync the plugin into a DDEV instance that has WP-CLI (excludes .git, .github, CLAUDE.md, docs):
rsync -a --exclude='.git' --exclude='.github' --exclude='CLAUDE.md' --exclude='docs' \
  jpkcom-allow-blocks/ <ddev-docroot>/wp-content/plugins/jpkcom-allow-blocks/

# From inside the DDEV project:
ddev wp i18n make-pot wp-content/plugins/jpkcom-allow-blocks \
  wp-content/plugins/jpkcom-allow-blocks/languages/jpkcom-allow-blocks.pot \
  --slug=jpkcom-allow-blocks --exclude=tests,docs --allow-root

# Update the two .po files by hand (new/changed msgids only), then:
ddev wp i18n make-mo wp-content/plugins/jpkcom-allow-blocks/languages --allow-root
ddev wp i18n make-php wp-content/plugins/jpkcom-allow-blocks/languages --allow-root
```

**Never merge `.po` files with `msgcat --use-first`** — it takes the first
file's header wholesale and silently drops `Plural-Forms`, which breaks
plural handling for every string in the merged file even though nothing
about the merge looked wrong. To carry existing translations forward into a
freshly generated `.pot`, use `msgmerge --compendium` instead, and always
verify afterwards that `Plural-Forms` and `Language` are still present:

```bash
grep -E '^"(Plural-Forms|Language):' languages/jpkcom-allow-blocks-de_DE.po
```

---

## Plugin Updater

- **Namespace:** `JPKComAllowBlocksGitUpdate\JPKComGitPluginUpdater`
- **Manifest URL:** `https://jpkcom.github.io/jpkcom-allow-blocks/plugin_jpkcom-allow-blocks.json`
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`. Checksum verification is **mandatory**: a missing or unfetchable `checksum_sha256` aborts the update instead of installing unverified code. The verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed (no second download). Failed manifest fetches are negatively cached for 1 h.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

**Actions are pinned to commit SHAs.** Every `uses:` line in `.github/workflows/` references a 40-character commit SHA instead of a tag (`@v4`), with the version as a trailing comment. A tag is a movable pointer and can be repointed; a SHA cannot. Since the release workflow builds the plugin ZIP **and** the SHA256 checksum the auto-updater trusts, a compromised action would ship a tampered ZIP together with a matching checksum — the checksum secures the transport, the pinning secures the build. `.github/dependabot.yml` keeps the pins current weekly in one combined PR; when updating, always change the SHA *and* the version comment together.

**CI** (`.github/workflows/ci.yml`) runs on every pull request *and* on every push to `main` — a required status check only covers pull requests, so a direct push with bypass rights would otherwise skip the checks entirely. It runs `php -l` over all PHP files; flags invalid named arguments to internal PHP functions (catches `sprintf(format:, values:)` → `ArgumentCountError`, which `php -l` does not see); validates the YAML of every `.github` file; asserts every action is pinned to a 40-character commit SHA; and executes `tests/test-*.php` where present.

**Dependabot auto-merge** (`.github/workflows/dependabot-auto-merge.yml`) merges only `semver-patch` and `semver-minor`, and only PRs from `dependabot[bot]` in this repo — never from forks. Major updates get a comment and stay manual. Two repo settings are prerequisites, otherwise this is useless or outright dangerous: "Allow auto-merge" must be enabled, and branch protection must list `CI / Lint & Guards` as a **required status check** — without it `gh pr merge --auto` merges *immediately*, since there is nothing left to wait for. Together with `cooldown: default-days: 7` no action release is adopted during its first week.

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP (excludes `tests`, `docs`, `CLAUDE.md`, `phpdoc.xml`, among others — `languages/` and `includes/` ship) → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

---

## Tests

`tests/test-*.php`, one file per module, each standalone — no WordPress
bootstrap. Every file stubs the WordPress functions its module calls
(`sanitize_key()`, `current_user_can()`, `wp_roles()`, etc.), requires the
module directly, and asserts against its functions:

- `tests/test-settings-store.php` — sanitiser edge cases (invalid role
  slugs, invalid block names, type coercion), the read/write round trip,
  and `jpkcom_allow_blocks_blocked_for_roles()`'s intersection rule across
  multiple roles, including a user whose second role blocks nothing.
- `tests/test-block-filter.php` — all four incoming `allowed_block_types_all`
  values (`true`, an array, `false`, an empty array), the administrator
  exemption and the `jpkcom_allow_blocks_is_exempt` override, and that an
  unconfigured plugin returns its input completely unchanged.
- `tests/test-admin-page.php` — `jpkcom_allow_blocks_block_rows()`'s
  registry/settings union and sort order, `jpkcom_allow_blocks_editable_roles()`,
  and `jpkcom_allow_blocks_apply_form()`'s save-difference rule: saving a
  form that only rendered a subset of rows must not touch the rows it did
  not render.
- `tests/test-import-export.php` — the export/import round trip, the
  per-role merge unit (role in file replaces, role absent stays untouched,
  unknown role slugs are kept), and `jpkcom_allow_blocks_import_preview()`'s
  counts against the live block registry.

Run all four locally:

```bash
for t in tests/test-*.php; do php "$t" || echo "FAILED: $t"; done
```

Not covered by this harness: the `admin_post_*` handlers themselves (they
need `check_admin_referer()`, `$_FILES`, `wp_die()`, real nonces) and the
settings screen's client-side JS. Both need a real WordPress instance to
exercise end to end.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file, direct-access guard
  (`defined( 'ABSPATH' )` / `defined( 'WPINC' )`) at the top of every file
- Every write path (`admin_post_jpkcom_allow_blocks_save`,
  `_export`, `_import_preview`, `_import_apply`) checks `manage_options`
  and its own nonce before touching anything
- `jpkcom_allow_blocks_sanitize_settings()` is the only path into the
  option: role slugs through `sanitize_key()`, block names against the
  WordPress block-name grammar, labels through `sanitize_text_field()` and
  length-capped
- Import uploads are checked with `is_uploaded_file()` and capped at
  `JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES` (1 MB) before being read
- The restriction is an editor-UI convenience, not a security boundary —
  see "Blocks that are invisible to PHP" above
- Updater: SHA256 verification + URL validation (audited separately)

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_ALLOW_BLOCKS_VERSION`, `README.md`, `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. If any user-facing string changed, regenerate the `.pot` and update both
   `.po` files (see Localisation above) before tagging
4. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
