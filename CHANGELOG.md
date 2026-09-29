# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Beacon bot signals, observe-only.** The analytics worker (1.22.0) now stores five signals the theme beacon sends, as bits in `double8` with a presence bit (32), because Analytics Engine reads a missing double as 0. A nightly reading (with the daily rollup) groups the last 14 days by visitor-day and reports, per cohort, how often each signal fires: relay readers and intent visitors (a download, contact or verify event that day), which should read near zero, and stored bots that ran the script, over-cap visitor-days and hosting suspects, which should not. It also counts "likely automated" human visitor-days (a weighted score over the fired signals) beside human. Nothing is subtracted from any count; the one counted-human rule is unchanged and only lends its network and page-view-cap seams. The numbers are on the Analytics Quality tab and in `sn-status{bot_signals}` (local only, no remote twin). A new watch, `bot_signals_validation`, ripens when 14 days carry signal rows and every cohort has 20 visitor-days. The SQL ran live against Analytics Engine on 2026-09-29 and returned no rows cleanly, as expected before the worker ships. `tests/analytics-bot-signals.php` (33) feeds a captured Analytics Engine row shape through the real readout.
- docs/SECURITY.md records that the CVE-2026-87902 `pagename` traversal rule is live in Cloudflare (merged into the readme+licence block rule, url_decode form) and what was verified.

## [19.7.1] - 2026-09-29 — emoji stays for readers

### Fixed
- **Emoji is back on the public site, and the core version stays hidden.**
  19.7.0 turned emoji off everywhere to stop `wp-emoji-release.min.js?ver=7.1.2`
  leaking the WordPress version. Emoji now works as stock WordPress on the front
  end, in embeds, feeds and email. It stays off in wp-admin only (the admin
  script and styles and the classic editor plugin), as core's block editor
  already does. The emoji script URL passes through the same filter that swaps
  core's version for a private token, so it shows the token instead. The test
  runs WordPress's own emoji printer and checks that `7.1.2` appears nowhere.
  CI now fetches `formatting.php` for that check.

