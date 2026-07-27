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
| `JPKCOM_ALLOW_BLOCKS_VERSION` | `'2.0.4'` | Plugin version (sync with header/README/phpdoc.xml) |

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
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`. Checksum verification is **mandatory**: a missing or unfetchable `checksum_sha256` aborts the update instead of installing unverified code. The verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed (no second download). Failed manifest fetches are negatively cached for 1 h.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

**Supply-chain: GitHub Actions sind auf Commit-SHAs gepinnt.** Alle `uses:`-Zeilen in `.github/workflows/` referenzieren einen 40-stelligen Commit-SHA statt eines Tags (`@v4`), mit der Version als Kommentar dahinter. Grund: ein Tag ist ein beweglicher Zeiger und lässt sich umhängen, ein SHA nicht. Da dieser Workflow die Plugin-ZIP **und** die SHA256-Summe erzeugt, der der Auto-Updater vertraut, würde eine kompromittierte Action ein manipuliertes ZIP samt passender Prüfsumme ausliefern — die Prüfsumme sichert den Transportweg, das Pinning den Build. `.github/dependabot.yml` hält die Pins wöchentlich aktuell (ein gesammelter PR). Beim Aktualisieren immer SHA *und* Versionskommentar zusammen ändern.

**CI & Dependabot-Auto-Merge.** Zwei zusätzliche Workflows:

- `.github/workflows/ci.yml` — läuft auf jedem `pull_request`. Prüft: `php -l` über alle PHP-Dateien; ungültige benannte Argumente an internen PHP-Funktionen (fängt die Klasse `sprintf(format:, values:)` → `ArgumentCountError`, die `php -l` nicht sieht); YAML-Validität aller `.github`-Dateien; und dass jede Action auf einem 40-stelligen Commit-SHA gepinnt ist (beide YAML-Formen, `uses:` und `- uses:`).
- `.github/workflows/dependabot-auto-merge.yml` — merged Dependabot-PRs automatisch, aber nur `semver-patch` und `semver-minor`. Major-Updates bekommen stattdessen einen Kommentar und bleiben manuell. Greift nur bei PRs von `dependabot[bot]` aus diesem Repo, nie aus Forks.

> **Zwei Repo-Einstellungen sind Voraussetzung, sonst ist der Auto-Merge wirkungslos oder gefährlich:**
> 1. **„Allow auto-merge"** muss in den Repo-Settings aktiv sein.
> 2. Der Branch-Schutz muss den CI-Job als **Required status check** führen (`CI / Lint & Guards`). Fehlt das, merged `gh pr merge --auto` **sofort** — es gibt dann nichts, worauf es warten müsste, und die CI wäre reine Dekoration.

Zusammen mit `cooldown: default-days: 7` in der `dependabot.yml` heißt das: kein Action-Release wird in seiner ersten Woche übernommen, patch/minor laufen danach automatisch durch (sofern CI grün), major bleibt eine bewusste Entscheidung.


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
