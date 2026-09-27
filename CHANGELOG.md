# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.3.3] - 2026-09-27 — U+2028 notes can anchor

### Fixed
- **A note containing U+2028 or U+2029 can anchor.** PHP escapes those two characters even under `JSON_UNESCAPED_UNICODE`, while the provenance worker's `JSON.stringify` emits them raw, so the canonical bytes differed and the worker refused the note on every reconcile. Text pasted from Word or Docs carries them. `sn_prov_canonical_json()` now adds `JSON_UNESCAPED_LINE_TERMINATORS`; a test pins the bytes against Node's `JSON.stringify`. No ledger record could hold one, so no stored hash moves. Found in the 2026-09-27 worker research.

### Changed
- **Correction to 19.3.2: `lib/.htaccess` is inert on this host.** Cloudways hands PHP to Nginx and PHP-FPM, which ignore `.htaccess` (as `tests/.htaccess` already noted), so `lib/pdf/vendor/*.php` still answered 200 after the release. The block is enforced at the edge instead: the Cloudflare custom rule "readme+licence" now also blocks `*.php` under `/wp-content/plugins/signal-and-noise-tools/lib/` (verified 403). The `.htaccess` stays for Apache hosts.

