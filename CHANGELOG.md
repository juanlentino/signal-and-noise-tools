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
- **Cite this note: one citation builder, and the Google Scholar head meta.** `sn_cite_this_for( $post_id )` (new `inc/cite-this.php`) returns a note's APA line and BibTeX entry as read now: pinned to the chain head's version (`sn_zenodo_head_commit()`) and that version's date (`sn_schema_date_modified()`, the provenance commit, UTC), ending with the production DOI (`sn_zenodo_doi_for()`) when there is one and the permalink when there is not. A note with only its founding snapshot claims no version. Its inputs are the Article's own, and the BibTeX escape and key are ports of the WebMCP bridge's `snBibEscape` and `snCiteKey`, pinned in `tests/cite-this.php` against that module's output. The theme's new cite line (signal-noise/note-cite) prints it; without this plugin the line renders nothing. On a single note, `wp_head` now carries `citation_title`, `citation_author`, `citation_publication_date` (the publish date, `YYYY/MM/DD`) and, with a DOI, `citation_doi`.

## [2026.10.82] - 2026-10-31 — Indexing report marks a page requested and checks with Google live

### Added
- **Indexing: mark a page requested, and check with Google live (Integrity › Reports › Indexing, both twins).** Google's Indexing API covers job postings and livestreams only, so asking Google to index a note stays one click in Search Console; the row's link now says so ("Request in Search Console"). Everything else moved onto the site:
  - **Mark requested** on each row records the day of the mark (the site cannot see the day of the click in Search Console, so the status says "(marked)"). Only a path the inspection map holds, not indexed, can be marked.
  - **Check now with Google** inspects the marked rows, then the request list, live (`snt_gsc_inspect_one( $id, 0, true )`, forced past the weekly six-day freshness guard). A press starts no new page after 20 seconds and says what is left; the next press continues where it stopped, never re-inspecting a page it already answered. A forced pass that fails keeps the last good answer. A complete check that got an answer cannot be repeated for 10 minutes. It spends quota only on the owner's click: every screen, the brief and the watches still read the stored map. A forced check leaves the map's `synced_at` alone, so the headline's map date stays the weekly run's.
  - **Each row reads Google's answer against the mark:** not requested yet; requested (marked), waiting on Google; requested, no crawl since in 14 days, request again; requested, crawled since and still left out, request again. Every row shows Google's last crawl date, stored all along and never shown. Pages indexed after a request are listed under "Indexed after your request" for 30 days.
  - **The reminder stops repeating:** a marked row with no crawl since waits quietly for 14 days. The `index_gap` watch counts it ("N waiting on Google after your request") instead of listing it, and the morning brief leaves it out; a crawl after the mark that still did not index brings it back.
  - The marks and the status ride the health report only. `sn_indexing_report()`, which the `indexing-requests` ability and its remote twin return, is unchanged, so the remote contract does not move. A press refreshes the stored report directly, never through `sn_health_store_scan()`, so it adds no scan-history row. New: `inc/search-console-requested.php`, `inc/search-console-check-now.php`, `inc/health-render-indexing-status.php`, `inc/admin-post-actions/gsc-indexing.php`. Pinned in `tests/gsc-requested.php`, `tests/search-console-coverage.php`, `tests/search-console-index-watch.php`, `tests/os-leaf-tools-reports.php` and `tests/morning-brief.php`.

