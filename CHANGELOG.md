# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed

- **Emoji is back, and the core version stays hidden.** 19.7.0 turned emoji
  off to stop `wp-emoji-release.min.js?ver=7.1.2` leaking the WordPress
  version. Emoji now works exactly as stock WordPress again (front end, admin,
  feeds, email, the classic editor). Its script URL already passes through the
  same filter that swaps core's version for a private token, so it shows the
  token instead. The test runs WordPress's own emoji printer and checks that
  `7.1.2` appears nowhere. CI now fetches `formatting.php` for that check.

## [19.7.0] - 2026-09-29 — the core version stops leaking


- **Security: stop printing the core version.** After CVE-2026-87902
  (GHSA-7hp8-65ch-5whp; the site runs 7.1.2, patched, not exposed) the home page
  and `/wp-login.php` still announced `ver=7.1.2` through the emoji script.
  Emoji is removed entirely, the core version in core assets' `ver` becomes a
  salted token (so year-long caches still refetch on a core update), and
  `the_generator` is empty for every type. Plugin and theme `ver` stay.
  `inc/core-fingerprint.php`, pinned by `tests/core-fingerprint.php`.
- **Deploy status reports core.** `get-deploy-status` gains `core` {current,
  latest, state ok/behind/unknown, offer point/major/empty, auto_updates,
  reason}, read from the
  cached `update_core` transient only, and a local-only `runtime` {php,
  register_argc_argv}. The remote twin carries `core` but never `runtime`;
  remote contract 13 (additive). The desktop widget and the Operations leaf
  paint a Core row ("behind (point)" / "behind (major)"). Pinned by `tests/core-fingerprint.php`,
  `tests/abilities-remote-set.php`, `tests/remote-contract-shapes.php`,
  `tests/dash-widgets.php`, `tests/desktop-status-resilience.cjs`.
- **docs/SECURITY.md**: the core fingerprint, the readme/licence edge block,
  a draft Cloudflare rule for encoded traversal in `pagename`, and the
  2026-09-29 incident note.

### Added
- **Is this crawler real: `agent_networks` on the machine-readers crosstab.** Rights signals 1.28.0 records the network each machine read came from (`network`, Cloudflare's `asOrganization`). The row normaliser passes it through with only letters, digits and plain punctuation, capped at 128 characters, because whoever owns an IP block chooses that name. The `get-machine-readers-crosstab` ability now also returns, per claimed agent, its hits, the hits Cloudflare verified, the hits from before the network was recorded, and its top five networks. ClaudeBot hits from a hosting provider that are not verified read as an impostor. Local ability only: the crosstab has no remote twin, so the remote contract version does not change.

