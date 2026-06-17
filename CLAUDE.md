# JPKCom Allow Block Types – Developer Reference

## Plugin Overview

Restricts the block editor for non-administrators to a curated allow-list of block types (a fixed set of `areoi/*` and `core/*` blocks). Admin requests are untouched; the filter only runs on the front-end/editor side via `! is_admin()`.

- **Text Domain:** `jpkcom-allow-blocks` (no header declared, defaults to slug; only used by the shared updater)
- **Min PHP:** 8.3 | **Min WP:** 6.9
- **Network:** not network-only (no `Network:` header)

---

## Architecture

```
Main file (jpkcom-allow-blocks.php)
├── declare(strict_types=1)
├── Plugin header
├── JPKCOM_ALLOW_BLOCKS_VERSION constant
├── init @ priority 5: boot JPKComGitPluginUpdater
└── if ( ! is_admin() )
    └── add_filter allowed_block_types_all → jpkcom_allowed_block_types() : string[]
```

---

## Behaviour

| Hook | Type | Effect |
|------|------|--------|
| `allowed_block_types_all` | filter (prio 10, 2 args) | Returns a fixed allow-list of block types for non-admin contexts |

The callback ignores its incoming value and always returns the curated array, so only the listed blocks are insertable for non-admins.

---

## Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_ALLOW_BLOCKS_VERSION` | `'2.0.3'` | Plugin version (sync with header/README/phpdoc.xml) |

---

## File Structure

```
jpkcom-allow-blocks/
├── jpkcom-allow-blocks.php       ← Main: header, constant, filter, updater bootstrap
├── includes/
│   └── class-plugin-updater.php  ← GitHub auto-updater (namespace: JPKComAllowBlocksGitUpdate)
├── .github/workflows/release.yml ← Build ZIP, manifest, PHPDoc, deploy to gh-pages (on tag push)
├── phpdoc.xml                    ← phpDocumentor config
├── README.md                     ← Public readme (source for the WP plugin modal)
├── CLAUDE.md                     ← This file
├── LICENSE                       ← GPL-2.0-or-later
└── .gitignore
```

---

## Plugin Updater

- **Namespace:** `JPKComAllowBlocksGitUpdate\JPKComGitPluginUpdater`
- **Manifest URL:** `https://jpkcom.github.io/jpkcom-allow-blocks/plugin_jpkcom-allow-blocks.json`
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file
- Front-end-only gate via `! is_admin()`
- Updater: SHA256 verification + URL validation (audited separately)

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_ALLOW_BLOCKS_VERSION`, `README.md`, `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
