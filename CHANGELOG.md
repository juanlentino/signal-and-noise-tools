# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.0.3] - 2026-09-12 — check for updates without a nonce

### Fixed
- **"Check for updates" no longer expires in the phone PWA.** An iOS standalone PWA keeps the last-rendered admin page in memory for days without reloading, so the `wp_nonce_url()` "Check now" link in it died after 24h (or a session-token rotation) with "The link you followed has expired." The three links now point at core's own nonce-free `update-core.php?force-check=1` (gated by `update_core`), and the plugin's cache clear runs there on `load-update-core.php`. The old admin-post door stays for bookmarks. `tests/force-check-no-nonce.php`.

