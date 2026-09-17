# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.0.0] - 2026-09-17 — a tag with three notes is a page

### Added
- **Tag archives with three or more notes ride the sitemap; thinner ones are `noindex`.** Every tag carries an owner-written description that the archive paints as its intro and meta description, so a tag with three notes is a real page about "music metadata" or "AI disclosure". Both taxonomies were dropped from the sitemap until now, and Google's URL Inspection read every tag archive as "URL is unknown to Google" although each note links four of them. `post_tag` stays (category still goes, one category is a duplicate of `/notes/`); the taxonomy provider excludes the tags under the line (`sn_sitemap_tag_is_hub`, three notes, pure and pinned); the robots emitter adds `noindex` to an archive under it. On the 2026-09-17 vocabulary: 21 hubs in, 4 thin out.

