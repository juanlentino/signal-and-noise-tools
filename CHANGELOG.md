# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.52] - 2026-10-20 — live scroll depth, a CSP-ready /stats and /verify


- **Root bootstrap files count as probes too.** The 5xx summary's probe test also matches direct requests for `/wp-settings.php`, `/wp-load.php`, `/wp-blog-header.php`, `/wp-config.php` and `/wp-config-sample.php`, which WordPress loads itself and never routes (seen 2026-10-19: seven 500s on `/wp-settings.php`). Entry points such as `/wp-login.php`, `/wp-cron.php` and `/wp-comments-post.php` are not probes.

- **The strict CSP can be enforced without breaking /verify or /stats.** /verify now prints the theme's own theme-init script byte for byte (one copy in `inc/theme-init-script.php`, its hash pinned to the theme's `sha256-+aDIk0AQYDEK+tK47t/wNITT4nprLRPhL+LH2Hb8THs=`), so the policy's existing hash covers it; same behavior and look. /stats no longer uses `wp_localize_script` (an executing `var snLiveNow = {...}` no hash can pin): the config rides in a `<script type="application/json" id="sn-live-now-config">` data block that CSP ignores, and `assets/live-now.js` reads it (window.snLiveNow stays as a fallback; admin pages carry neither, as before). No other public page prints an executing inline script from the plugin. The daily `sn_csp_hash_check` now also reads /resume/, /verify/ and /stats/, sends `Sec-GPC: 1`, and the `csp_hashes` note names the page and the first 60 characters of any unlisted script (kept only for unlisted ones). New `tests/public-stats-live-config.php` (8 checks, 5 red before); `tests/csp-hash-check.php` 44 to 52 and `tests/provenance-verify.php` 112 to 114 (red first).
- **Being read now says how far its readers have scrolled.** Under each page in the admin Right now block (classic and native), one quiet line in plain words, e.g. "1 past a quarter, 2 past halfway, 1 at the end", each reader said once at their deepest milestone, or "not scrolled yet" when the read came back with nobody past 25%; a failed read shows nothing (not measured is not 0). It comes from the beacon's 25/50/75/100 scroll events over the same 5-minute window, human rule and excluded paths as the list: one more grouped Analytics Engine query per realtime refresh (none when nobody is on a page), distinct readers per path and milestone, so no reader key leaves Analytics Engine. `live-now` pages gain `scroll { reached_25, reached_50, reached_75, reached_100, readers_with_scroll }` (local only; live-now has no remote twin, contract unchanged); the public /stats answer and the stored 5-minute slots do not carry it. New `tests/analytics-live-scroll.php` (25 checks, 21 red before) and `tests/js/live-now-scroll.js`.


