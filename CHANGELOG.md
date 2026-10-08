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
- **The admin Right now panel, redesigned around the live figures.** Under Active visitors and Views today (both kept): the last hour as 5-minute bars, then Being read now and Arrived from side by side, and the time of the last read, all refreshed in place every 30 s. Classic and native share one block (`inc/analytics-live-admin.php`). Arrived from is admin only: where readers whose pageview landed in the last 5 minutes came from, in the dashboard's source names; a click inside the site is not a source. The Traffic desktop widget gains the bars and a Top now line; its Today so far stays.
- **Is right now unusual? A live-surge signal.** The refresh keeps a log of completed 5-minute slots (eight days, from the hour it already reads; nothing new collected) and judges the last one against the same time of day on earlier days, by median and MAD with a one-reader noise floor and a three-reader minimum. Under four days of history it says it is still learning. The admin panel says it in words; a surge raises a neutral Attention row (Live traffic). Filed as an analytics signal, deliberately not an ML pipeline: the ML family's public promise is that reader data never enters it. The Analytics Maturity page gains the matching principle.
- **Agents can read the site right now.** `signal-noise/live-now` (read door 50 to 51) and `sn-status{live}`: live counts per class, today, the hour, pages, sources and the surge verdict, from the realtime cache only. Local only for now: like the last new payload, it gets a remote twin once its shape has settled.

### Fixed
- **The red "now" bar no longer lags.** An hour read from a cache a few seconds old could end on the previous slot and still paint it red; the bar is red only while it still is the current slot.

### Added
- **The last hour under Reading now, on /stats.** Twelve 5-minute bars of distinct human readers (the same rule as Reading now), the current slot in red, scaled to the hour's own peak, with the peak said in words underneath for anyone not reading the chart. One more grouped query per realtime refresh (`inc/analytics-live-hour.php`), grouped by a SELECT alias since Analytics Engine refuses a function in GROUP BY. A slot with no readers is a real 0; an hour not read yet leaves the chart empty. Redrawn every 60 s, never animated. Cookieless as before; nothing new collected.

## [23.1.0] - 2026-10-08 — being read now on /stats

### Added
- **Being read now, on /stats.** Under the Live figures, the pages people are on right now (the same 5-minute window as Reading now), each with its reader count, top five, in the most-read list's style. One more grouped query per realtime refresh (`inc/analytics-live-pages.php`); a path is listed only when it resolves to a published, unprotected note or page that is not hidden from search (or Home), so a draft, a private or noindex page, another post type or a junk path never appears; two spellings of one page count as one entry. A query string folds into its page. Not read yet stays a dash; nobody on a page says so. Aggregate counts, cookieless as before; nothing new collected.

