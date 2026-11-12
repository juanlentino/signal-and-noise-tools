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
- **Today's view count says when it is an estimate.** On 2026-11-11 "Today so far" read 31 while the Sessions tab listed 20 page views. Nothing was counted twice: Analytics Engine sampled two visitor-days when it wrote them, keeping 7 page-view rows that stand for 17, and 2 that stand for 3. The figures add up those weights, so 31 is the right estimate; the Sessions tab lists the rows that were kept. The realtime warmer's today read now also asks for `count()` beside `sum(_sample_interval)`, in the same request (`inc/analytics-realtime.php`), and `inc/analytics-today-estimate.php` (new) says whether today was sampled. When it was, admin surfaces say so:
  - **The SN Traffic desktop card** reads "Today so far (estimate)" (payload key `today_estimate`, additive).
  - **The Analytics header strip's Today so far** reads "views, estimate".
  - **Analytics › Overview, Views today** reads "human pageviews · site-local day · sampled, an estimate".
  - **The Sessions tab**, when its window includes today, names the kept rows and the views they stand for.
  - **`live-now`** answers `today_stored` beside `today` (admin answer only).
  - /stats is unchanged.
- **`analytics-dual-write` reports sampling again.** Since worker 2.0.0 stopped the legacy write, every event took the "rows on one side only" branch and `sampled` read false on every day, sampled or not. Each day now reads sampling off each dataset directly, and the new `sampled_rows` lists, per event, the stored rows and what they stand for (2026-11-12 UTC: `pv: pageviews 20 stored for 31`). The day's `state` is unchanged.
- Tests: `tests/analytics-realtime.php` and `tests/analytics-v2-compare.php` (both fail against the previous code). `tests/analytics-live-readers.php` and `tests/analytics-live-scroll.php` now match their source lines whatever the alignment.

## [2026.11.28] - 2026-11-11 — A strict human figure beside presumed human

### Added
- **Strictly human, beside presumed human (owner decision 2026-11-11).** A second human figure on every surface that shows the presumed-human totals: the same totals less the visitor-days strict rule v1 removes, with the count removed and the reason. `human` is not changed and nothing is subtracted from it; both numbers are always shown. Strict rule v1 removes a presumed-human visitor-day (under the page-view cap) only when its bot-signal score reaches 3 and it fired webdriver, a user-agent mismatch, or headless with a time-zone mismatch; `no_input` alone, `no_input` with `tz_mismatch` (VPN or traveling readers, phone readers who do not touch) and `no_input` with `headless` (an intent reader fired it) never do. Measured live over the last 30 days: 78 flagged by score, 61 removed by the rule. The rule is named once, `SNT_STRICT_HUMAN_RULE` in `inc/analytics-strict-human.php`.
  - **Stored nightly, never queried on render.** The per-day bot-signal store (`sn_bot_signals_by_day()`) now keeps each UTC day's removed visitor-days and their page views. The first nightly run after install also backfills every older day of the 90-day Analytics Engine window (`SNT_BOT_SIGNAL_READOUT_DAYS`), in 14-day chunks behind a cursor (`sn_bot_signals_by_day_backfill`) inside what is left of a 35-second tick (measured from the start of the nightly job; a chunk starts only with 8 seconds in hand); a chunk that fails or reaches the row cap is retried the next night from the cursor, as the nightly read keeps its stored days on the same faults. A day no read covers (older than the AE window) keeps its old shape and counts as unmeasured, never as 0 removed.
  - **`get-analytics-summary`, the local door only:** a `strict` block (`visitor_days`, `views`, `removed`, `rule: strict_v1`, `reason`, `basis: inferred`, `window` with `from`, `through`, `partial`), null for suspect and bot and when no UTC day of the range is measured. The remote twin and its contract are unchanged.
  - **Analytics › Quality (classic and native):** "Strictly human: N visitor-days (M removed: automation signals)" under the class legend, beside the first-party sentence.
  - **Widgets:** the SN Traffic desktop card (a line under its views headline), the S&N Audience dashboard box (a Strictly human cell beside Visitor-days) and the dashboard box's Views signal (a line under its comparison), each from the same helper. The existing numbers are unchanged. Not /stats.
  - Tests: `tests/analytics-strict-human.php` (which fixtures are removed, the store, the range figure, null when unmeasured, the line), with pins added in `tests/abilities-analytics.php`, `tests/abilities-remote-analytics.php`, `tests/analytics-bot-signals.php` (the backfill), `tests/analytics-bottrend-render.php`, `tests/openstation-analytics-quality-painter.php`, `tests/desktop-mode-widget-views-delta.php`, `tests/dash-signals.php`, `tests/dash-widget.php`, `tests/dashboard-home-week.php` and `tests/dash-widgets.php`; each fails against the code before this change.

