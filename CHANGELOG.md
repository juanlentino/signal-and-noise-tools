# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Security: stop printing the core version.** After CVE-2026-87902
  (GHSA-7hp8-65ch-5whp; the site runs 7.1.2, patched, not exposed) the home page
  and `/wp-login.php` still announced `ver=7.1.2` through the emoji script.
  Emoji is removed entirely, the core version in core assets' `ver` becomes a
  salted token (so year-long caches still refetch on a core update), and
  `the_generator` is empty for every type. Plugin and theme `ver` stay.
  `inc/core-fingerprint.php`, pinned by `tests/core-fingerprint.php`.
- **Deploy status reports core.** `get-deploy-status` gains `core` {current,
  latest, state ok/behind/security/unknown, auto_updates, reason}, read from the
  cached `update_core` transient only, and a local-only `runtime` {php,
  register_argc_argv}. The remote twin carries `core` but never `runtime`;
  remote contract 13 (additive). The desktop widget and the Operations leaf
  paint a Core row. Pinned by `tests/core-fingerprint.php`,
  `tests/abilities-remote-set.php`, `tests/remote-contract-shapes.php`,
  `tests/dash-widgets.php`, `tests/desktop-status-resilience.cjs`.
- **docs/SECURITY.md**: the core fingerprint, the readme/licence edge block,
  a draft Cloudflare rule for encoded traversal in `pagename`, and the
  2026-09-29 incident note.

## [19.6.3] - 2026-09-28 — the check reads the refresh

### Fixed
- The Analytics server token check in Connections › Credentials now reads the worker's refresh result. The worker-version reader dropped the refresh block from the worker's report, so the check said "Unknown" on every real reading while the refresh itself was fine. A test now parses a live copy of the worker's report.

