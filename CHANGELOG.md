# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **S&N Home leads with what it is for.** The north star is the hero: engaged readers at display size, its four weeks as an `os-histogram`, and beside it the three signals that matter now (resume PDF downloads, research links followed, notes shared), ruled in the pulse tiles' own language. The other twelve signals fold behind "All signals". Home stops repeating itself: the Publishing and Trust & Operations tile groups, the recent-deploys list and the second maintenance bar are gone (the Systems wall, the Deploy Status and Quick Actions widgets and the Integrity tab carry them); the Systems wall folds behind one line ("All 11 systems current", or how many need a look), whole, so freshness-dot.js still fills its Caches card; "All clear" paints nothing, since the greeting already says the site is healthy; queries that brought no clicks are hidden; Continue working shows three rows (View all still counts every item). About half the height.

## [18.9.0] - 2026-09-26 — readers, what they do, and where they came from

### Added
- **The north star counts what readers do, not only what they read.** Four named goals join its intent layer, each once per visitor-day: resume PDF downloads (a `download` fired on /resume, no markup change to the resume page's block), feed subscribe clicks (`subscribe`, already tracked), notes shared (`share_copy` / `share_native`, fired by the theme's share row, juanlentino/signal-and-noise#446) and signatures checked (`verify`, the provenance chip on each note now carries `data-sn-goal="verify"`). Tests pin each goal and that subscribes and shares do not inflate the deliberate-action count; widening the /resume match fails its pin.
- **Research links followed.** The north star's intent layer gains the research track's real conversions: visitor-days that clicked from the site out to SSRN, doi.org, Zenodo, ORCID or the AES journal (a subdomain matches its parent; `snt_nsm_research_hosts` filters the list). The tracker already stored each outbound click's destination as a property row; a small query of its own reads it, leaving the session query other features share untouched. Tests pin the host match (a lookalike such as notssrn.com does not count; loosening the subdomain rule fails that pin) and the per-week distinct count.
- **Feed click-throughs show up as visits.** Each item link in the RSS and Atom feeds now carries `utm_source=rss&utm_medium=feed`, so a reader who clicks through from a feed reader lands as a campaign visit the edge worker already records (Measurement › Campaigns lists it as "rss / feed"). Only the link is tagged: the GUID is untouched, so feed readers keep their read state; the comments feed is left alone; a link is never tagged twice. The north star gains an input, "Clicked through from a feed" (7 days), counting every `utm_medium=feed` visit, so the theme's JSON Feed (tagged `utm_source=jsonfeed`, juanlentino/signal-and-noise#446) adds to it. It starts at zero: readers see the tagged links as their apps refresh. Reading inside a feed reader stays invisible; that is the reader's app.

### Documentation
- The 2026-09-25 to 26 session doc: the north star (18.7.0), the spam arc and the silent notifications (18.8.0 to 18.8.3), #1006 with contract 10 (18.8.4, worker 1.11.0), and the upstream OpenStation work (#888, #913/#914, #915).

