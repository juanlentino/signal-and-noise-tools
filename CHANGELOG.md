# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.54] - 2026-10-22 — DOI downloads on /provenance, and the index watch skips noindex pages

### Added
- **/provenance shows how often its documents are downloaded.** A quiet line at the end of the page reads, for example, "59 documents with DOIs · 126 downloads all time · 31 in the last 30 days (Zenodo)", with Zenodo linked to the search for the author ORCID. The existing daily Zenodo sync now also stores each record's lifetime unique downloads (latest day only) and a site-only total every day, counting only records whose DOI belongs to a published site document, so duplicate deposits and records that are not the site's are left out; the owner's all-ORCID "DOI downloads" reading is unchanged. The page renders from stored snapshots only, never calling Zenodo, and hides the line when the latest snapshot is missing, older than two days or from before the site split; the 30-day figure shows only when a snapshot exactly 30 days back exists and the difference is not a drop. Connections › Zenodo, on both the classic and the native leaf, has an on/off switch (on by default), an optional lead label and a floor under which the 30-day figure is left out; a change purges /provenance at the edge. The fetch now asks Zenodo in one stable order and counts a repeated record once (`inc/provenance-doi-line.php`, `inc/north-star-return.php`, `tests/provenance-doi-line.php`).

### Fixed
- **The `index_gap` watch no longer lists pages the plugin marks noindex.** It skipped a page only when Google's own coverage state said "Excluded by noindex", so a page Google never fetched to see the tag (/call, flagged noindex and out of the sitemap) was counted as stuck. The candidate test now also asks `sn_post_settings_get_noindex()`, the same per-page flag the robots meta and the sitemap read (`inc/search-console-index-watch.php`, `tests/search-console-index-watch.php`).

### Documentation
- `README.md`, `AI.md`: Connector for TypeSafe Jev links point at its renamed repo, `connector-for-typesafe-jev` (GitHub redirects the old URL).

