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
- **Health check 28, notes without a query-shaped title.** The 2026-09-17 pressure test against Search Console found 40 of 43 notes with a title tag that is the aphorism alone, 21 never shown to anyone, and half of all impressions on an accidental match. The H1 stays the voice; the `_sn_seo_title` override carries the plain words a reader would search, in the shape the ranking notes already use ("The estate cannot sign: key succession and music provenance"). A defect that reaches zero one line at a time, so the gap cannot silently reopen with the next scheduled note. Pure judge (`sn_health_search_title_is_shaped`, `sn_health_search_titles_judge`), the query as the thin part, the three registries (scan, surface, family) and the loader pinned.

### Fixed
- **The Dashboard leaf's Caches tile sat on "Checking…" under a meta line that said "verified fresh".** Measured live in the owner's shell: OpenStation's runtime inserts a bare `<div>` and sets `class="snt-app …"` and `data-os-app` afterwards by attribute morph, so `assets/os-host.js`, which only scanned added nodes, never hosted a root (13 analytics roots, zero `snt:paint` events) and the freshness hydrator never re-armed after a repaint. The host's document observer now also takes `class` and `data-os-app` attribute records and re-scans their target; `host()` is idempotent. Re-hosting the root by hand filled the tile with "3/3 fresh" in under a second, which is the fix's proof.

## [15.8.2] - 2026-09-17 — every card says where it points

### Fixed
- **Cron health said "1 expected but not scheduled" on every read since 14.7.0, and the Cron widget never showed it.** `sn_gsc_inspect_one` is an on-demand single event (day 3 and day 10 after a publish) and was never in `snt_cron_hook_is_on_demand()`, so it read as an expected recurring job with no schedule. Listed now; a derived test scans the owned-hooks registry for every hook its comment calls a single event and requires each to be on-demand, so the next one cannot repeat this.
- **The Cron widget judges, not only counts.** Its localized summary now carries the cron-health verdict and the soonest SN job; the card paints "Next: `<job>` · in 4 min" (a past due time reads "due", never a negative) and the health summary in amber when the verdict is not ok, with the dot tracking the verdict as well as orphans. The counts alone could not say a recurring job was missing, which is how the false alarm above stayed invisible behind a green dot.
- **Every "Open … →" lands on the leaf where its reading lives, not on the Dashboard tab.** Health → Monitoring › Health; Anchors → Tools › Provenance ("Open Provenance →"); Cache gains "Open Cloudflare →" (Connections › Cloudflare); Queue gains "Open Scheduled →" (Connections › Scheduled, which folds native future posts with the fragment queue). Uptime and Deploy keep the Dashboard, which is their leaf; the uptime card's link now says so instead of "Open Uptime".
- **The Cache card says what its verdict covers**: "Verdict covers the post's own URL" (the probe fetches the permalink; archive pages, the sitemap and the feed are purged, not probed). A fact, not a tally: the v13.87.3 ruling against standing counts on that card stands, pinned.
- **Site Views' top mover printed "▼ -16"**; the arrow carries the sign now.
- **SN Queue's section headings were uppercase and letter-spaced**, the one card that was; sentence case at 11px/.55 like every other card. The suite's uppercase ban had a hand-kept list of eight of eleven widget files, so the queue passed it; the list is now derived from the directory with a floor.

