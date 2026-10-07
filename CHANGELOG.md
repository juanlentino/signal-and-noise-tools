# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.9.1] - 2026-10-07 — the analytics 2.0 check sets aside sampled visitor-days no human figure counts

### Changed
- **The analytics 2.0 check sets aside sampled visitor-days no human figure counts** (owner rule 2026-10-07). On Oct 6 and 7, Analytics Engine sampled pageviews differently in the two datasets, so no busy day could read `match` and the 7-green clock could not start. Every visitor-day sampled differently on those days was a stored bot, failed the human rule, or was over the page-view cap. A sampled event now matches when, with those visitor-days taken out of both sides, what is left is identical visitor by visitor (rows and weights) and in its totals (rows, weighted count, visitors). It needs a complete over-cap list; a failed or cut-short list sets nothing aside, and a visitor-day human on either side is never set aside. The day names the events (`human_sample`) and the visitor-days (`set_aside`). On such a day the bot and suspect figures for those events are estimates that may differ between the datasets; every human figure is unchanged. A row on one side and none on the other is still a mismatch, so Oct 6's `vi` (2 vs 0) stays one.

