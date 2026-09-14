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
- **A tenth Attention reader: Search.** Six notes sat "Discovered, currently not indexed" for two months with no row anywhere; the coverage map was on a tab, and nine readers read everything but it. `attention_search()` reads `sn_analytics_posts_signals()`, the same rows the Posts view paints (flags derived once), and emits a row when a flag has stood past a horizon: *Discovered, not indexed* after `ATTENTION_SEARCH_DISCOVERED_DAYS` (7), *Crawled, not indexed* on sight (a quality verdict, with the fix stated as deepen, not request again), *stale crawl* after `ATTENTION_SEARCH_STALE_DAYS` (14) since the body change. The door is Search Console's URL Inspection page for that exact URL on the configured property, since no API can request indexing (the Indexing API covers job postings). Index rows are stamped with the inspection run, stale rows with the body change, so Acknowledge holds until the state moves. Guards: three rows out of seven fixtures, each horizon, the Crawled/Discovered split, the door URL, the stamps, no-run-yet and unreadable; mutations red: each horizon removed, Crawled read as Discovered.
- **New notes are inspected on their own clock.** `snt_gsc_coverage_on_publish()` schedules two single events per newly published post, `sn_gsc_inspect_one` at day 3 and day 10; `snt_gsc_inspect_one()` spends one URL Inspection call and merges the entry into the same map the weekly run writes (never marking a run complete), skipping when the entry is already fresh, the post is no longer published, or Search Console is not configured. Two calls per note against a daily quota of 2,000. Registered in the cron dashboard's hook census. Guards: two events per publish and none per update, unpublish, page or unconfigured site; one API call; merge keeps the old entries; fresh entry spends nothing; no map yet starts one marked incomplete; mutations red: freshness skip removed, single marks complete, update re-schedules.

## [14.6.2] - 2026-09-14 — the Anchors widget shows a recording version

### Fixed
- **The Anchors widget could not show a freshly minted version.** A commit is `unanchored` from the moment it is persisted until the settle-window dispatch reaches the Worker and the Worker answers `pending`; the theme presents that as *Recording*. `snt_prov_anchor_overview()` counted only `confirmed` and `pending`, so a note that had just minted v2 read "40 of 41 notes anchored, no anchors pending" on the desktop while its page said RECORDING · V2. The overview now carries a third list, `recording` (`{ post_id, title, version }`), and the widget paints it: "1 recording · 40 of 41 anchored" with the note and version. `anchor-status` (read door, local only) gains the field. Guard: a minted-not-dispatched v2 is a recording row; confirmed + pending + recording = total, so no note falls between the lists; mutation (recording dropped) red.

## [14.6.1] - 2026-09-14 — stale crawl reads the body change; posts-signals on the read door

### Fixed
- **Stale crawl compared the crawl to `post_modified`, which a tag edit bumps.** The first live reading of the rebuilt Posts view flagged 17 stale crawls; the edit dates clustered on 2026-09-09 and 2026-09-12, the two bulk saves (tag vocabulary, surfaces) that never changed a word. `sn_analytics_posts_row()` now carries `body_changed_ts`: the newest signed commit's `committed_at` (`sn_prov_last_commit_gmt_from_chain()`), falling back to `post_modified` only for an unsigned post; `sn_analytics_posts_flags()` reads that. The cell's title says "Body changed", the queue's implied fix says "last body change". Guards: a signed note whose body has not changed since the crawl is not stale however many bulk saves bumped it; a new commit after the crawl (even pending) is; no `committed_at` falls back; mutation (flag back on `post_modified`) red.

### Added
- **`signal-noise/posts-signals`: the Posts view as data.** The same `sn_analytics_posts_signals()` rows the tab paints, with timestamps as ISO strings and the three flags verbatim, so an agent reads `not_indexed` / `stale_crawl` / `orphaned` instead of re-deriving them from `search-coverage` + `inbound-pass` (three copies of a threshold is three sources of truth). Every field stays `{ value, why }`; machine reads ride only the site-wide strip; the `note` field states both reading rules. Read door 33 → 34; `sn-status` gains the `posts_signals` section (22 → 23). Read-only over stored syncs; never inspects, syncs or probes. `tests/abilities-posts-signals.php` (14): registration and annotations, WP_Error when the module is absent (defined at runtime so the pin can fail), the payload is the tab.

