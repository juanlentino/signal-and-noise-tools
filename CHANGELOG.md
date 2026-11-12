# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Exact page views, from the analytics worker's own counter.** Analytics Engine samples a busy visitor-day when it writes it, so its figures are weighted estimates (on 2026-11-11 one visitor-day kept 7 page-view rows standing for 17). Analytics worker 2.2.0 counts every public page view in D1 and sends today and yesterday with each 15-minute refresh poke. The poke validates that block and holds it in memory. After the response, `inc/analytics-exact.php` and `inc/analytics-exact-reconcile.php` (both new) classify each visitor-day from Analytics Engine's own rows, with the same human rule and over-cap list every figure uses, in one read per day. They then store only per-day totals per class (`sn_analytics_exact_v1`). No visitor hash is stored or returned.
  - **A day is called exact only when nothing contradicts it.** It must be on or after the counter's first full day, with no read cut short and the over-cap list read whole. Every counted visitor-day must have exactly one class in Analytics Engine, and none may have fewer counted page views than Analytics Engine kept. Otherwise the reading says why, and the estimate stands.
  - **Where it shows, while the reading is under 45 minutes old:**
    - SN Traffic's Today so far
    - the Analytics header strip, human class
    - Overview Views today ("exact count")
    - `live-now` `today` with `today_exact: true`
    - /stats Views today (owner 2026-11-11), with the same label and the same public answer shape
  - Tests: `tests/analytics-exact.php` (new; the guards fail when the reconcile is forced exact) and `tests/analytics-refresh-rest.php`.

## [2026.11.29] - 2026-11-12 — Today's views say when they are a sampled estimate

### Fixed
- **Today's view count says when it is an estimate.** On 2026-11-11 "Today so far" read 31 while the Sessions tab listed 20 page views. Nothing was counted twice: Analytics Engine sampled two visitor-days when it wrote them, keeping 7 page-view rows that stand for 17, and 2 that stand for 3. The figures add up those weights, so 31 is the right estimate; the Sessions tab lists the rows that were kept. The realtime warmer's today read now also asks for `count()` beside `sum(_sample_interval)`, in the same request (`inc/analytics-realtime.php`), and `inc/analytics-today-estimate.php` (new) says whether today was sampled. When it was, admin surfaces say so:
  - **The SN Traffic desktop card** reads "Today so far (estimate)" (payload key `today_estimate`, additive).
  - **The Analytics header strip's Today so far** reads "views, estimate" (human class only: the reading is of human rows).
  - **Analytics › Overview, Views today** reads "human pageviews · site-local day · sampled, an estimate".
  - **The Sessions tab**, when its window includes today, names the kept rows and the views they stand for.
  - **`live-now`** answers `today_stored` beside `today` (admin answer only).
  - /stats is unchanged.
- **`analytics-dual-write` reports sampling again.** Since worker 2.0.0 stopped the legacy write, every event took the "rows on one side only" branch and `sampled` read false on every day, sampled or not. Each day now reads sampling off each dataset directly, and the new `sampled_rows` lists, per event, the stored rows and what they stand for (2026-11-12 UTC: `pv: pageviews 20 stored for 31`). The day's `state` is unchanged.
- Tests: `tests/analytics-realtime.php` and `tests/analytics-v2-compare.php` (both fail against the previous code). `tests/analytics-live-readers.php` and `tests/analytics-live-scroll.php` now match their source lines whatever the alignment.

