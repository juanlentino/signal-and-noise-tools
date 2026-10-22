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
- **The indexing guard: which URLs to request in Search Console, and one rule for what is indexable.** Integrity › Reports (classic and native) gains an Indexing report with three lists read from stored data: the URLs to request this week (published notes and pages not indexed 7 or more days after publish, "Crawled - currently not indexed" first, then the core pages and their Spanish twins, then the rest oldest first, at most 10), the ones stuck past 30 days (at most 25), and a publish check that warns when a note or core page 3 or more days old has fewer than two links from indexed pages, is out of the sitemap, or has a canonical elsewhere. Each row carries Google's state, the days since publish, the links from indexed pages and a URL Inspection link; nothing requests indexing or uses the Indexing API. The same lists are `signal-noise/indexing-requests` on the local read door and, by owner approval, `signal-noise/remote-indexing-requests` on the remote door (contract 22; the broad search-coverage map stays local). Underneath, one predicate (`inc/indexable.php`) now decides indexability for the sitemap, the weekly URL Inspection targets, the day-3 and day-10 inspections, the index_gap watch and IndexNow, so /verify (a Page the provenance verifier serves as noindex) leaves the sitemap and stops spending inspection quota, as does /call; /verify's canonical gains its trailing slash. IndexNow skips pages the sitemap leaves out and also pings a published post's old URL when its slug changes. The link graph counts pages as sources and targets, matched by full path, so inbound counts cover the core pages and a page with no graph node reads unknown rather than zero. The sitemap's lastmod and the JSON-LD dateModified read one clock (the provenance commit, else the last modification), and pages now carry dateModified. An internal redirect target saved without its trailing slash lands in one hop, and the About seed links /about/uses/ (`inc/search-console-indexing.php`, `tests/indexable.php`, `tests/search-console-indexing.php`).

## [2026.10.54] - 2026-10-22 — DOI downloads on /provenance, and the index watch skips noindex pages

### Added
- **/provenance shows how often its documents are downloaded.** A quiet line at the end of the page reads, for example, "59 documents with DOIs · 126 downloads all time · 31 in the last 30 days (Zenodo)", with Zenodo linked to the search for the author ORCID. The existing daily Zenodo sync now also stores each record's lifetime unique downloads (latest day only) and a site-only total every day, counting only records whose DOI belongs to a published site document, so duplicate deposits and records that are not the site's are left out; the owner's all-ORCID "DOI downloads" reading is unchanged. The page renders from stored snapshots only, never calling Zenodo, and hides the line when the latest snapshot is missing, older than two days or from before the site split; the 30-day figure shows only when a snapshot exactly 30 days back exists and the difference is not a drop. Connections › Zenodo, on both the classic and the native leaf, has an on/off switch (on by default), an optional lead label and a floor under which the 30-day figure is left out; a change purges /provenance at the edge. The fetch now asks Zenodo in one stable order and counts a repeated record once (`inc/provenance-doi-line.php`, `inc/north-star-return.php`, `tests/provenance-doi-line.php`).

### Fixed
- **The `index_gap` watch no longer lists pages the plugin marks noindex.** It skipped a page only when Google's own coverage state said "Excluded by noindex", so a page Google never fetched to see the tag (/call, flagged noindex and out of the sitemap) was counted as stuck. The candidate test now also asks `sn_post_settings_get_noindex()`, the same per-page flag the robots meta and the sitemap read (`inc/search-console-index-watch.php`, `tests/search-console-index-watch.php`).

### Documentation
- `README.md`, `AI.md`: Connector for TypeSafe Jev links point at its renamed repo, `connector-for-typesafe-jev` (GitHub redirects the old URL).

