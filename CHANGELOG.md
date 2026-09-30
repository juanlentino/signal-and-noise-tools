# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The Deploy card's Core row says "update check not cached" instead of a red "?".** The row reads only WordPress's cached core update check, and that cache goes missing: on 2026-09-29 `wp_version_check` ran and succeeded at 22:50 UTC and the entry was gone hours later (what empties it is not yet proven). A fresh payload with a version now paints a muted dash and the phrase; a stale payload, or one without a version, keeps the red "?".
- **Beacon bot signals, observe-only.** The analytics worker (1.22.0) now stores five signals the theme beacon sends, as bits in `double8` with a presence bit (32), because Analytics Engine reads a missing double as 0. A nightly reading (with the daily rollup) groups the last 14 days by visitor-day and reports, per cohort, how often each signal fires: relay readers and intent visitors (a download, contact or verify event that day), which should read near zero, and stored bots that ran the script, over-cap visitor-days and hosting suspects, which should not. It also counts "likely automated" human visitor-days (a weighted score over the fired signals) beside human. Nothing is subtracted from any count; the one counted-human rule is unchanged and only lends its network and page-view-cap seams. The numbers are on the Analytics Quality tab, in `sn-status{bot_signals}`, and on the remote door (see the contract 14 bullet). A new watch, `bot_signals_validation`, ripens when 14 days carry signal rows and the relay and intent cohorts each have 20 visitor-days; the other cohorts are reported at whatever count they reach (over-cap ran 2 visitor-days in 92). The panel names the expected false positive: a phone reader who finishes a short page without scrolling or touching sends a time event with no input, so the relay readers' no-input rate is a human baseline, not a bot rate. The SQL ran live against Analytics Engine on 2026-09-29 and returned no rows cleanly, as expected before the worker ships. `tests/analytics-bot-signals.php` (36) feeds a captured Analytics Engine row shape through the real readout.
- **Remote contract 13 to 14: two new remote twins (owner ruling 2026-09-29).** `signal-noise/remote-bot-signals` carries the bot-signals readout byte for byte (cohort sizes and fire rates, aggregate counts, nothing subtracted); the admin ability now declares its keys and types in `snt_bot_signals_output_schema()`, which both registrations read, so the contract hash pins the payload. `signal-noise/remote-machine-readers-networks` carries the crosstab's `agent_networks` alone ("is this crawler real": agent, hits, verified hits, top networks) through a wrapper; the crosstab's cells stay local, and the parity test pins the strip. The deploy `runtime` row and the recompute status stay local, pinned. New hash `8af49ec6...` taken from the failing shapes test. The door lists them once sn-remote-mcp-worker 1.12.4 (contract 14) is deployed; until then the deploy probe reads `contract_match: false`, by design.
- **North star: an observe-only quality layer.** `signal-noise/north-star` gains `layers.quality.likely_automated_share`: the percent of human visitor-days carrying beacon signals (all pages, the bot-signals 14-day window) that score as likely automated. Null until the first nightly reading. It never touches the star: `tests/north-star-quality.php` scores every visitor-day automated and pins value, previous, series and trend unchanged.
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

