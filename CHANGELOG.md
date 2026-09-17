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
- **The native Home's Caches tile painted its verdict unreadable.** 15.9.0 made the filler run (the host now sees a root that earns its identity late), and what it wrote arrived in the light admin's colours: the value span borrowed the classic `.sn-glance-card__value` class so the filler could find it, and that class carries `color: var(--sn-text)`, near-black on the dark leaf; the classic `.sn-pill--ok` chip read as a pastel block. The value now carries a `data-snt-freshness-value` attribute (no style), the filler finds it by that attribute first, and on the native leaf the badge is the kit's `<os-badge tone>`, the element every other tile uses. The classic page is unchanged. Pinned.

## [16.0.0] - 2026-09-17 — a tag with three notes is a page

### Added
- **Tag archives with three or more notes ride the sitemap; thinner ones are `noindex`.** Every tag carries an owner-written description that the archive paints as its intro and meta description, so a tag with three notes is a real page about "music metadata" or "AI disclosure". Both taxonomies were dropped from the sitemap until now, and Google's URL Inspection read every tag archive as "URL is unknown to Google" although each note links four of them. `post_tag` stays (category still goes, one category is a duplicate of `/notes/`); the taxonomy provider excludes the tags under the line (`sn_sitemap_tag_is_hub`, three notes, pure and pinned); the robots emitter adds `noindex` to an archive under it. On the 2026-09-17 vocabulary: 21 hubs in, 4 thin out.

