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
- **The bot-signals validation accumulates instead of rolling over 14 days (owner, option A, 2026-10-24).** The watch needed 20 visitor-days from each known-human cohort inside a rolling 14 days, and the site sees about 4 Private Relay readers and 1 intent visitor in that window, so it could never ripen. The readout now reads up to 90 days (Analytics Engine's retention; rows before the signals began on 2026-09-29 carry no presence bit and drop out), and the watch gates on relay readers alone; intent visitors, about one a fortnight, are reported at their count beside stored bots, over-cap visitor-days and hosting suspects. Expected to ripen around early December. Nothing subtracts. The north star's observe-only likely-automated share reads the same longer window, labeled with the days the signals actually cover (about 26 today), not 90. The per-day store keeps its 14-day read. A live check on 2026-10-24: a scripted headless run scored 6 (webdriver, no input, headless) and a disguised one (webdriver hidden, real Chrome user agent) scored 3, the threshold.

### Fixed
- **The daily CSP check reads /provhub.** The /provhub family (/provhub/, /present/, /verify/, /resources/) are standalone HTML files on the server, not WordPress pages, and the check's page list never read them, so when the strict policy was enforced it blocked every inline script there without a word: the public verifier sat on "Fetching sample files", the QR overlay and copy button were dead, and the beacon never loaded. The check now reads those four, and a page counts only under the run's own mode: once any page enforces the strict policy, a page that enforces the loose one (an exempt path such as /contact/ or, once excluded at Cloudflare, /provhub) is not pinned even while it still carries the strict Report-Only copy, so an exemption does not raise a false alarm. Site Health's "pins every inline script on N pages" counts only those pages.

## [2026.10.61] - 2026-10-23 — the owner's analytics name every row

### Changed
- **Owner analytics name every row; public /stats keeps its floor (2026-10-23).** Privacy floors belong to the audience, not the data. On a small site the 3-visit floor hid most human views as `(withheld)`, including the pages the owner needed to see, while the live view already named single visits. `analytics-rows` (local and the remote twin), the sn-metrics sections (sources, geography, devices, journeys) and `analytics-query` now list every row with its real name, views and engagement. Contract kept: `withheld` stays in each payload, always `{rows:0, views:0, visits:0}`, and `floor` is `null` (the value the query already returned for a day series). `(unmatched)` and `(invalid)` are unchanged. The public /stats page is untouched (`SN_PUBLIC_STATS_MIN_GROUP` stays 3, pinned by a test), and the quiet-week movers keep their noise threshold under their own constant, `SNT_MOVERS_FLOOR` (3). Tool and ability descriptions say the new behavior. The remote worker's own description of `sn_remote_analytics_query` still says values under 3 visitor-days are withheld; it needs a separate update.

