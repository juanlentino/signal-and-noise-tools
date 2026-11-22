# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.
- **Preview PDF previews the résumé PDF.** With résumé settings in the draft it streams the one résumé's layout (two pages, What I've Built, verified credits), the file Publish would build, instead of the music layout nothing publishes (`sn_resume_pdf_draft_parts()`, layout shared with Generate through `sn_resume_ops_pdf_layout()`). Test: `tests/resume-ops-pdf-build.php`.

## [Unreleased]

### Added
- **A read lists which network numbers the relay rule's readers arrive from, before that rule is changed.** The analytics worker presumes a page view human when its network name matches `akamai|fastly` and the browser is Safari on iOS or macOS (iCloud Private Relay); it matches by name, never by AS number. `signal-noise/relay-networks` (sn-metrics' `relay_networks`, owner-only, local door only, no remote twin) reads the page views of the trailing `days` (1 to 90, default 90; 30 through sn-metrics unless `range` says otherwise) on networks whose name matches and returns `rows` of `{asn, network, class, browser, os, views, visitor_days}` (class as the worker stored it, views sample-corrected), most views first, at most 200 with `truncated`, and a `summary` read before that cap: `relay_human` (stored human, Safari, iOS or macOS: views, the distinct ASNs with their views, and `asn_unrecorded_views`) beside `safari_apple` and `not_safari_apple`, each the views by stored class. The AS number is double12 of a second-generation row (the worker's README and its doubles order; the plugin's own column notes never name double12), so rows before 2026-10-05 read ASN 0, which is counted apart and never listed as a network. Nothing is changed or subtracted, nothing is stored, no screen changes, and no visitor code, IP address or user agent is returned (`inc/analytics-relay-networks.php`, `inc/abilities-relay-networks.php`, both new). Test: `tests/analytics-relay-networks.php` (new; red before the change, and with the page-view filter, the cap, the sort, the OS half of the split, the window clamp, the input bound, the permission callback or the closed row schema removed; the real payload passes core's output validation).

## [2026.11.55] - 2026-11-22 — The vitals read names the elements behind not-good CLS readings

### Added
- **The Core Web Vitals read names which page elements account for the not-good CLS readings.** `signal-noise/get-analytics-vitals` (sn-metrics' `analytics_vitals`) gains `cls_sources`: for CLS readings over the good threshold (0.1) that carried a shift source, rows `{path, source, readings, needs_improvement, poor, worst}` grouped by path and source, most readings first, at most 50 (readings are sample-weighted, the two bands are counts, worst is a score). `cls_sources_unattributed` counts the not-good readings that carried no source, so an empty list is not read as "nothing shifted". The source is the selector analytics worker 2.4.0 stores in blob17 of a `vc` row (page markup only: tag, id, class names), read only where `blob1 = 'vc'`; the list stays empty until that worker and a theme release carrying the field are live. A failed sources read nulls both keys, says why in `cls_sources_failure` and `sn_analytics_vitals_failure()`, and leaves the rest of the payload standing (kept 5 minutes, not 15). Local door only; no screen changes (`inc/analytics-vitals-sources.php`, new). Tests: `tests/analytics-vitals-sources.php` (new; red before the change, and with the `vc` filter, the cap, the score scaling or the failed-leg check removed) and `tests/analytics-vitals-sources-on-core.php` (new: the real payloads, the failed leg included, pass core's output validation).

### Security
- **The cron orphan cleanup workflow refuses a `hooks` value that is not hook names.** ssh joins its arguments and the remote shell parses them again, so a value with a space lost every name after it and one with `;` or `$()` would have run on the server (only someone who can dispatch the workflow could send one). The runner now accepts letters, digits, underscores and commas only, the remote side checks each name again and passes it to PHP through the environment, and a hook whose handler check could not run is kept, not deleted (`.github/workflows/cron-orphan-cleanup.yml`).

