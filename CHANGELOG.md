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
- **Feed click-throughs show up as visits.** Each item link in the RSS and Atom feeds now carries `utm_source=rss&utm_medium=feed`, so a reader who clicks through from a feed reader lands as a campaign visit the edge worker already records (Measurement › Campaigns lists it as "rss / feed"). Only the link is tagged: the GUID is untouched, so feed readers keep their read state; the comments feed is left alone; a link is never tagged twice. The north star gains an input, "Clicked through from a feed" (7 days), counting every `utm_medium=feed` visit, so the theme's JSON Feed (tagged `utm_source=jsonfeed`, juanlentino/signal-and-noise#446) adds to it. It starts at zero: readers see the tagged links as their apps refresh. Reading inside a feed reader stays invisible; that is the reader's app.

### Documentation
- The 2026-09-25 to 26 session doc: the north star (18.7.0), the spam arc and the silent notifications (18.8.0 to 18.8.3), #1006 with contract 10 (18.8.4, worker 1.11.0), and the upstream OpenStation work (#888, #913/#914, #915).

## [18.8.4] - 2026-09-26 — the edge 5xx rollup names which pages return which status

### Added
- **The edge 5xx rollup names which pages return which status (#1006).** It stored failing paths and responders as two separate dims, so there was no way to say which URL got a 520; on 2026-09-23..25 the 520s ran 75 to 110 a day, all on uncached PHP. A third dim, `err_path_status`, records `"<edge> <cache> <path>"` (for example `520 dynamic /wp-json/wp/v2/posts`) from the errors query the rollup already runs, capped at the column's 160 by cutting the path end, never the status. `edge-errors-summary` returns it as `paths_by_status` (top 10), and its remote twin with it, so the remote contract moves 9 to 10. It starts empty: days before this release have none, which means not recorded, not no errors.

