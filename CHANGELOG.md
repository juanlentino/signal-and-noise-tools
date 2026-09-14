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
- **The Anchors widget could not show a freshly minted version.** A commit is `unanchored` from the moment it is persisted until the settle-window dispatch reaches the Worker and the Worker answers `pending`; the theme presents that as *Recording*. `snt_prov_anchor_overview()` counted only `confirmed` and `pending`, so a note that had just minted v2 read "40 of 41 notes anchored, no anchors pending" on the desktop while its page said RECORDING · V2. The overview now carries a third list, `recording` (`{ post_id, title, version }`), and the widget paints it: "1 recording · 40 of 41 anchored" with the note and version. `anchor-status` (read door, local only) gains the field. Guard: a minted-not-dispatched v2 is a recording row; confirmed + pending + recording = total, so no note falls between the lists; mutation (recording dropped) red.

## [14.6.1] - 2026-09-14 — stale crawl reads the body change; posts-signals on the read door

### Fixed
- **Stale crawl compared the crawl to `post_modified`, which a tag edit bumps.** The first live reading of the rebuilt Posts view flagged 17 stale crawls; the edit dates clustered on 2026-09-09 and 2026-09-12, the two bulk saves (tag vocabulary, surfaces) that never changed a word. `sn_analytics_posts_row()` now carries `body_changed_ts`: the newest signed commit's `committed_at` (`sn_prov_last_commit_gmt_from_chain()`), falling back to `post_modified` only for an unsigned post; `sn_analytics_posts_flags()` reads that. The cell's title says "Body changed", the queue's implied fix says "last body change". Guards: a signed note whose body has not changed since the crawl is not stale however many bulk saves bumped it; a new commit after the crawl (even pending) is; no `committed_at` falls back; mutation (flag back on `post_modified`) red.

### Added
- **`signal-noise/posts-signals`: the Posts view as data.** The same `sn_analytics_posts_signals()` rows the tab paints, with timestamps as ISO strings and the three flags verbatim, so an agent reads `not_indexed` / `stale_crawl` / `orphaned` instead of re-deriving them from `search-coverage` + `inbound-pass` (three copies of a threshold is three sources of truth). Every field stays `{ value, why }`; machine reads ride only the site-wide strip; the `note` field states both reading rules. Read door 33 → 34; `sn-status` gains the `posts_signals` section (22 → 23). Read-only over stored syncs; never inspects, syncs or probes. `tests/abilities-posts-signals.php` (14): registration and annotations, WP_Error when the module is absent (defined at runtime so the pin can fail), the payload is the tab.

