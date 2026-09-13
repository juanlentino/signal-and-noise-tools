# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.5.1] - 2026-09-13 — the updater reports the header's Tested up to (7.1)

### Fixed
- **The Updates page said "Compatibility with WordPress 7.1: Not tested" — on a 7.1 site, for a plugin whose header says `Tested up to: 7.1`.** Core paints the value the *updater* reports, and `inc/wp-update-integration.php` hard-coded `tested = '7.0'` in both places (the update transient and the View details modal) while the header had moved on. `sn_gh_plugin_compat()` now reads `Requires at least` / `Tested up to` / `Requires PHP` from the plugin file header once, and both surfaces use it — one source, so the two cannot drift again. `tests/manifest-floor.php` refuses any compatibility literal in the updater and pins the reported `tested` to the header's 7.1; both mutations (a literal restored, the helper's own fallback) go red.

