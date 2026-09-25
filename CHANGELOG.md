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
- **The resume hero's download row is the Download PDF button alone.** The filename link beside it read RESUME (the saved PDF link label), repeating the page's own title right above. The page builder now writes core/file's own no-filename shape: one button, no dangling `aria-describedby`. The PDF link label field is gone from both resume forms, the document schema and the seed; a label saved before is ignored. The live page picks this up on the next resume save or PDF generation, since the page is rebuilt from the form.

## [18.6.1] - 2026-09-25 — the edge 5xx rollup stops counting Worker cache lookups and names forwarded visitors

### Fixed
- **The edge 5xx rollup stops counting a Worker's cache lookups as errors, and stops calling forwarded visitors "a Worker".** A Worker's `caches.default.match()` on a zone URL is logged as a 504 when it misses, though no request leaves the edge; on 2026-09-23 that was 31 of the 93 "worker" 5xx, all the rights-signals worker's crawler-verdict lookup. The errors query now excludes `edgeWorkerCacheAPI` alongside `earlyHintsCache`. Another 47 were `edgeWorkerFetch` 520s: sn-rights-signals sits on `juanlentino.com/*` and forwards every page, so those are visitors' requests the origin dropped, not a Worker's own traffic. The source label now reads "through a Worker (usually a visitor's request it forwarded)", and a Cache API row stored before this reads "a Worker's cache lookup, no request made". The `worker` count in `asked_by` keeps its name and meaning (the request came through a Worker); rows stored before the cut age out of the 7-day window within a week.

