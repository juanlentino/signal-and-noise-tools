# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.96.2] - 2026-09-04 — the surfaces nothing was covering

### Fixed
- A post save now purges the paginated archive pages and the sitemap. The set
  was five hardcoded URLs; `/notes/page/2..4/`, `/wp-sitemap.xml` and
  `/wp-sitemap-posts-post-1.xml` are all edge-cached and none was purged, so an
  edit left the archive beyond page 1 and the sitemap stale until TTL. The page
  count is derived from the corpus, not written down, and capped. (#1008)
- `cache-freshness` now reports `probe_scope`. The probe fetches the permalink
  and nothing else, so a `fresh` verdict was a statement about one URL while
  reading as one about the edge. (#1008)
- The OpenStation PWA's launch URL now redirects to the custom login instead of
  serving the decoy 404. Its manifest — public, unauthenticated — names
  `/wp-admin/admin.php?page=openstation` as `start_url`, so the 404 hid nothing
  and broke the installed app every time the session lapsed. Every other
  unauthenticated `/wp-admin` path still 404s, and a PWA launch is not counted
  as reconnaissance. (#1004)
- Admin form controls no longer fall below 16px on a phone. Five rules were
  specific enough to beat core's `max-width: 782px` bump, so iOS zoomed into a
  focused field and never zoomed back out. Desktop sizes are unchanged; the
  bump is restated at core's own breakpoint. (#1000)

### Added
- A tombstone service worker at `/tools/sw.js`. The removed `/tools/` PWA left a
  registration behind in every browser that visited it, and a registration
  outlives its server. The route serves a worker whose only job is to
  unregister itself, clear the caches its predecessor left, and reload the
  pages it controls. It does **not** address the separate 503s seen on
  `/wp-admin/`. (#1002)

