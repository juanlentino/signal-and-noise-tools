# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Removed
- **The `.git` preservation module.** `inc/wp-update-git-preservation.php` moved the plugin's `.git` to a fixed path, `wp-content/upgrade/sn-signal-and-noise-tools-git-backup`, for the length of a WP UI install, then moved it back. It existed for the old deploy that ran `git checkout` inside the live plugin directory. That deploy has shipped a tag archive since v9.54.2, the footprint janitor deletes `.git`, and the module returned early on a missing `.git`, so on production it did nothing. Kept, it would have parked a `.git` at a predictable name in the web-served directory WordPress 7.1.4 just hardened against exactly that (it now gives its own working directory an unguessable name). No behavior changes on the live site.

## [2026.10.77] - 2026-10-29 — sn-posts reads the search surfaces

### Added
- **`sn-posts` reads the search surfaces.** Rows carry `seo_title`, `meta_description` and `og_card_title`, the three search fields `sn-apply`'s `surfaces` type writes. Until now the only way to see one was a dry-run write, read back from the diff's `before`. They are opt-in through `fields` like any row key, sit in the full row when `fields` is omitted, and work under every scope with no size cap, because they are short postmeta and not bodies. An unset field is `""`, never omitted and never null, so "not written yet" cannot read as "not supported". `include_surface_checks: true` adds `surface_checks` per row: `meta_description` and `seo_title` each as `{length, status}`, with status `ok`, `short`, `long` or `empty`. The grading reuses existing limits rather than restating them: sn-validate's description range and the search-title health check's ceiling. `seo_title` has no floor, so it is never `short`. The checks are informational and block nothing. Pinned in `tests/abilities-sn-posts.php`, including a source scan that fails on a restated threshold.

### Changed
- **One map from surfaces field to meta key, for the reads.** `inc/surface-fields.php` holds it. `sn-apply`'s dry-run diff, its revision-mode staging and `sn-posts` all read it, where the diff and the staging each used to spell the four keys out. The publish-mode write (`update-post-surfaces`), `create_draft` and the front-end readers still spell them, so a test pins the map to the publish write's four keys. Moving the map changed nothing in the diff or the staging.

