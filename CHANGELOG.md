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
- **A post save purges its Markdown twin too.** The WordPress AI plugin's Markdown Feeds experiment serves every post and page as Markdown at `?output_format=markdown`, and the edge caches that address under its query string (`cf-cache-status: HIT`), so an edit left agents reading the old text until the cache revalidated. That copy answers before the theme tags the response, so the tag purge the live site uses never reaches it: every save path (the note and wide tag purges, and the URL list for an older theme) now sends the permalink's `?output_format=markdown` by URL, from the pre-change post when leaving publish.

## [2026.10.62] - 2026-10-24 — the CSP check reads /provhub; the bot-signals readout accumulates

### Changed
- **The bot-signals validation accumulates instead of rolling over 14 days (owner, option A, 2026-10-24).** The watch needed 20 visitor-days from each known-human cohort inside a rolling 14 days, and the site sees about 4 Private Relay readers and 1 intent visitor in that window, so it could never ripen. The readout now reads up to 90 days (Analytics Engine's retention; rows before the signals began on 2026-09-29 carry no presence bit and drop out), and the watch gates on relay readers alone; intent visitors, about one a fortnight, are reported at their count beside stored bots, over-cap visitor-days and hosting suspects. Expected to ripen around early December. Nothing subtracts. The north star's observe-only likely-automated share reads the same longer window, labeled with the days the signals actually cover (about 26 today), not 90. The per-day store keeps its 14-day read. A live check on 2026-10-24: a scripted headless run scored 6 (webdriver, no input, headless) and a disguised one (webdriver hidden, real Chrome user agent) scored 3, the threshold.

### Fixed
- **The daily CSP check reads /provhub.** The /provhub family (/provhub/, /present/, /verify/, /resources/) are standalone HTML files on the server, not WordPress pages, and the check's page list never read them, so when the strict policy was enforced it blocked every inline script there without a word: the public verifier sat on "Fetching sample files", the QR overlay and copy button were dead, and the beacon never loaded. The check now reads those four, and a page counts only under the run's own mode: once any page enforces the strict policy, a page that enforces the loose one (an exempt path such as /contact/ or, once excluded at Cloudflare, /provhub) is not pinned even while it still carries the strict Report-Only copy, so an exemption does not raise a false alarm. Site Health's "pins every inline script on N pages" counts only those pages.

