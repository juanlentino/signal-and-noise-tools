# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Machine readers carry Cloudflare's verified-bot category.** Rights signals 1.27.0 records the `x-sn-verified-bot` header (set by a zone Transform Rule, so a client cannot forge it) and its aggregate read returns `verified_bot`; the row normaliser now passes that label through. Empty means not verified, or a row from before 1.27.0.
### Added
- **Analytics ingest check in Site Health.** If no human pageview has been recorded for 24 hours, the new check flags it and says whether other traffic kept arriving (the collector runs but records no people) or nothing did (the collector stopped writing). A dead collector no longer reads as a quiet day. A failed or unconfigured query reports "could not check", never zero.

### Fixed
- **The edge-workers check now reads the login guard's enforcement fields.** It flags the killswitch left off (`enforcement: "off"`), any `degradedList`, `degradedList6` or `degradedMeta` flag, the worker's own `stale6` verdict when the age math cannot see it, and a lost Durable Object binding (`config.rate_limit_global` or `rate_limit_escalates` false). Each is one plain sentence, and a worker that omits a field raises nothing.
- **The north star docblock names the right rotation clock.** The visitor hash rotates at midnight America/New_York (the worker's `SN_ROTATE_TZ`), not UTC. The counts were already right: the north star buckets rolling 7-day weeks from now, and each hash is one visitor-day whatever the query window's zone, so only the comment changed.
- **The remote contract comment counts its twins.** It said 8 remote twin abilities; there are 15.

## [19.3.3] - 2026-09-27 — U+2028 notes can anchor

### Fixed
- **A note containing U+2028 or U+2029 can anchor.** PHP escapes those two characters even under `JSON_UNESCAPED_UNICODE`, while the provenance worker's `JSON.stringify` emits them raw, so the canonical bytes differed and the worker refused the note on every reconcile. Text pasted from Word or Docs carries them. `sn_prov_canonical_json()` now adds `JSON_UNESCAPED_LINE_TERMINATORS`; a test pins the bytes against Node's `JSON.stringify`. No ledger record could hold one, so no stored hash moves. Found in the 2026-09-27 worker research.

### Changed
- **Correction to 19.3.2: `lib/.htaccess` is inert on this host.** Cloudways hands PHP to Nginx and PHP-FPM, which ignore `.htaccess` (as `tests/.htaccess` already noted), so `lib/pdf/vendor/*.php` still answered 200 after the release. The block is enforced at the edge instead: the Cloudflare custom rule "readme+licence" now also blocks `*.php` under `/wp-content/plugins/signal-and-noise-tools/lib/` (verified 403). The `.htaccess` stays for Apache hosts.

