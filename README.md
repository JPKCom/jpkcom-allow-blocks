# JPKCom Allow Block Types

**Plugin Name:** JPKCom Allow Block Types  
**Plugin URI:** https://github.com/JPKCom/jpkcom-allow-blocks  
**Description:** Only allow certain types of blocks in Gutenberg for non admins.  
**Version:** 3.0.0  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com  
**Contributors:** JPKCom  
**Tags:** Admin, Block, Bootstrap, Editor, Gutenberg  
**Requires at least:** 6.9  
**Tested up to:** 7.1  
**Requires PHP:** 8.3  
**Stable tag:** 3.0.0  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Only allow certain types of blocks in Gutenberg for non admins.


## Description

Restrict, per WordPress role, which block types the block editor offers.
Administrators always see every block and are never affected.

### The settings screen

Under **Appearance → Block permissions** is a matrix: every block type known
to the site as a row, every non-administrator role as a column. Unticking a
box blocks that block for that role; a freshly installed site starts with
nothing blocked, so every block stays available until you switch it off.

Blocks are grouped by their block category. A block that belonged to a
plugin which is currently deactivated is still listed, under an
"Unregistered" group with a warning marker, so a permission set up earlier
is never silently lost — it can be removed deliberately once you know the
block is really gone for good. Roles that cannot use the block editor at
all (no `edit_posts` capability, e.g. Subscriber on a default install) are
hidden by default; a toggle above the table brings them back for sites with
custom roles.

A search box, a category filter and per-role column toggles narrow the
table down without a page reload — useful once the list runs into the
hundreds of rows on a site with many block plugins.

The restriction only affects the block editor's inserter. It is not a
security or content-validation boundary.

### Export and import

**Export** downloads the current settings as a JSON file, so a permission
matrix built on one site can be moved to another.

**Import** never writes anything on upload alone: choosing a file first
shows a preview — how many roles would change, how many block entries that
touches, and any role or block name in the file that this site does not
currently know about — and only writes the settings once that preview is
confirmed. Importing merges per role: for every role present in the file,
that role's whole list of blocked blocks replaces what was stored;
any role the file does not mention is left exactly as it was.

For more details on WordPress' built-in block types visit: https://developer.wordpress.org/block-editor/reference-guides/core-blocks/


### Documentation

**API Documentation:** Complete PHPDoc-generated API documentation is available at:
[https://jpkcom.github.io/jpkcom-allow-blocks/docs/](https://jpkcom.github.io/jpkcom-allow-blocks/docs/)


## Installation

1. In your admin panel, go to 'Plugins' > and click the 'Add New' button.
2. Click Upload Plugin and 'Choose File', then select the Plugin's .zip file. Click 'Install Now'.
3. Click 'Activate' to use the plugin right away.


## Changelog

### 3.0.0
* **Breaking:** `jpkcom_allowed_block_types()` has been removed with no deprecated shim. It always returned the same hard-coded array regardless of its arguments, so a shim could only return that same stale list — code calling it directly will now fatal instead of silently getting outdated data
* Changed: the fixed 17-block allow-list is gone. Block permissions are now configured per role from a new settings screen under **Appearance → Block permissions**, with every currently registered block available to every role by default
* Fixed: the previous restriction never actually took effect. It was registered inside `if ( ! is_admin() )`, but the block editor *is* part of wp-admin, so `allowed_block_types_all` was never filtered there. Every block was available to everyone regardless of the plugin's hard-coded list
* Added: export block permissions to a JSON file and re-import them elsewhere, with a preview step before anything is written
* Added: `jpkcom_allow_blocks_is_exempt` filter to change which users bypass the restriction (defaults to `manage_options`), and `jpkcom_allow_blocks_extra_block_names` to add block names that are only registered in JavaScript and therefore invisible to the PHP-side registry
* Added: German translations (`de_DE`, `de_DE_formal`)

### 2.0.8
* Fixed: the update manifest no longer reports `network: true` for this plugin. The generator defaulted a missing `Network:` header to true, while WordPress' own default for a missing header is "not network-only". Metadata only — WordPress derives network-only from the plugin header via `is_network_only_plugin()`, not from the update manifest
* CI: the lint and guard workflow now also runs on pushes to `main`. It only covered pull requests, so a direct push with bypass rights skipped every check
* Changed: comments, workflow step names and CI output across the repository are now English throughout, and the developer notes in `CLAUDE.md` were translated and trimmed. No effect on the shipped plugin

### 2.0.7
* Changed: `Tested up to` raised to WordPress 7.1
* Changed: the bundled updater's runtime floor now matches the plugin's own minimum. It bailed out below WordPress 6.8 while the plugin header has required 6.9 for several releases, so the check could never fire on a supported installation
* CI: the release manifest's fallback values for `requires` and `tested` now say 6.9 and 7.1. They only apply when the README metadata cannot be read, but a stale fallback would have published a minimum the plugin no longer supports

### 2.0.6
* Added: plugin banners (`assets/banner-1544x500.avif`, `assets/banner-772x250.avif`) — a plain `#3c4955` surface with no lettering. The update manifest already advertised these two URLs, but nothing was published under them, so the plugin card in wp-admin had a broken banner

### 2.0.5
* CI: the release step no longer copies the staging directory into itself, so the ZIP has no empty `jpkcom-allow-blocks/jpkcom-allow-blocks/` folder
* CI: bumped the pinned GitHub Actions (checkout v7.0.1, setup-python v7.0.0, action-gh-release v3.0.2, fetch-metadata v3.1.0), still pinned to full commit SHAs
* CI: the release ZIP now excludes the development-only `tests/` and `tools/` directories
* CI: security and regression tests now run on every pull request, where a plugin has them

### 2.0.4
* Security: update packages are now verified *before* installation — the verified file is handed to WordPress instead of being downloaded a second time, so the bytes that were checked are the bytes that get installed
* Security: a missing or unfetchable SHA-256 checksum now aborts the update instead of installing unverified code (previously it silently skipped verification)
* Security: pinned every GitHub Action to a full commit SHA and added Dependabot with a 7-day cooldown, so a moved tag can no longer change the release build
* Security: tightened which download the updater claims, so sibling plugins cannot match each other's package
* Fixed: `sprintf()` calls in the updater bound named arguments to a variadic parameter, which raises `ArgumentCountError` on PHP 8.3
* Fixed: the "View Details" modal could fail with a `TypeError` when the manifest omitted `requires_plugins`
* Performance: a failed manifest fetch is now cached for an hour instead of being retried on every admin request
* Added: CI workflow on every pull request (PHP lint, named-argument check, YAML validation, action-pinning guard)

### 2.0.3
* Added secure self-hosted plugin updates via GitHub with SHA256 checksum verification
* Added an automated release workflow (builds the ZIP, generates the manifest and deploys to gh-pages on tag push)
* Raised the minimum WordPress version to 6.9 and "Tested up to" to WordPress 7.0
* Switched license metadata to the SPDX identifier `GPL-2.0-or-later` with the HTTPS license URI
* Added PHPDoc-generated API documentation, built and deployed to gh-pages on release
* Hardening: enabled `declare(strict_types=1)` and documented the block-types callback

### 2.0.2
* Tested up to WP v6.8

### 2.0.1
* Fix Stable tag

### 2.0.0
* Added README.md
* Plugin meta data update

### 1.0.0
* Initial Release
