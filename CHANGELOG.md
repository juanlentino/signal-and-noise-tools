# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Fixed: the indexing publish check counts links from the site header and footer.** The link graph reads post bodies only, so pages the header nav and the footer meta-nav link from every page (/about, /resume, /services, /now, /colophon and the Spanish twins /sobre-mi, /cv, /servicios) were flagged for having almost no inbound links. The check now renders the active theme's header and footer template parts through core (no HTTP), reads their links with core's HTML API, and caches that set per theme, version and parts content, cleared on a theme switch, an update, a Customizer save, or a template part or navigation save; if rendering fails in cron it reads the parts' block markup instead. A page the chrome links passes the inbound rule and its failure rows say `inbound_source: chrome`; a Spanish page passes when its English page is chrome-linked, since the Spanish header links the twin. Request and stuck rows gain a `chrome` flag beside the unchanged body count, and `publish_check.chrome_paths` names the set used. Notes are still judged on body links only. Remote contract 23 (`inc/search-console-indexing-chrome.php`, `tests/search-console-indexing-chrome.php`).

## [2026.10.55] - 2026-10-22 — the indexing guard: a weekly request list, a stuck flag and a publish check

### Added
- **The indexing guard: which URLs to request in Search Console, and one rule for what is indexable.** Integrity › Reports (classic and native) gains an Indexing report with three lists read from stored data: the URLs to request this week (published notes and pages not indexed 7 or more days after publish, "Crawled - currently not indexed" first, then the core pages and their Spanish twins, then the rest oldest first, at most 10), the ones stuck past 30 days (at most 25), and a publish check that warns when a note or core page 3 or more days old has fewer than two links from indexed pages, is out of the sitemap, or has a canonical elsewhere. Each row carries Google's state, the days since publish, the links from indexed pages and a URL Inspection link; nothing requests indexing or uses the Indexing API. The same lists are `signal-noise/indexing-requests` on the local read door and, by owner approval, `signal-noise/remote-indexing-requests` on the remote door (contract 22; the broad search-coverage map stays local). Underneath, one predicate (`inc/indexable.php`) now decides indexability for the sitemap, the weekly URL Inspection targets, the day-3 and day-10 inspections, the index_gap watch and IndexNow, so /verify (a Page the provenance verifier serves as noindex) leaves the sitemap and stops spending inspection quota, as does /call; /verify's canonical gains its trailing slash. IndexNow skips pages the sitemap leaves out and also pings a published post's old URL when its slug changes. The link graph counts pages as sources and targets, matched by full path, so inbound counts cover the core pages and a page with no graph node reads unknown rather than zero. The sitemap's lastmod and the JSON-LD dateModified read one clock (the provenance commit, else the last modification), and pages now carry dateModified. An internal redirect target saved without its trailing slash lands in one hop, and the About seed links /about/uses/ (`inc/search-console-indexing.php`, `tests/indexable.php`, `tests/search-console-indexing.php`).

