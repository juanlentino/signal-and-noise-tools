# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.81] - 2026-10-31 — unmatched paths class feeds by visits; Secrets API watch waits for a 7.2 RC

### Fixed
- **Unmatched paths: a feed is no longer read as a scanner.** The 2026.10.80 report (scan of 2026-10-31) listed seven paths under "Scanners and bots" that people reach: `/notes/feed`, `/feed/atom`, `/feed`, `/comments/feed`, `/tag/metadata`, `/notes/tag/ai-music` and `/compound`. Every analytics event counts a visit, but only a page view counts a view, so an event-only row (a feed fetch, among others) carried visits (17 human for `/notes/feed`) and no views, and the class fell through to "bot". They never got the live check, the list's own sentence ("paths only scanners or bots reached") was false for them, and `/notes/tag/ai-music` hid a 404 with a suggested fix (`/music`). A path with no page views at all is now classed by its visits; page views still decide wherever there are any, and a path with neither stays with the scanners. Among people rows with no human view, visits now rank before recency, so a feed is not ranked behind a suspect-only row (found by the independent review); every other row keeps its order. Its Views cell reads "17 visits, no page views" on both twins instead of blank, and the coverage line says how such a path is classed. Pinned in `tests/unmatched-paths.php` and `tests/os-leaf-tools-reports-unmatched.php`.
- **Unmatched paths: no "0 human views" headline.** When every visitor path left has no human page view (only feeds or suspect-only paths), the headline read "N paths visitors reached are not a note or page (0 human views in all)". It now counts visits instead ("no human page views, 17 visits in all"); the report payload carries `people_visits`. A scan stored before this keeps the old words until the next scan. Pinned in `tests/os-leaf-tools-reports-unmatched.php`.
- **The Secrets API watch no longer ripens before core ships it (#1625).** It ripened on `function_exists( 'wp_set_secret' )` alone, and on 2026-10-31 that function existed on a site running WordPress 7.1.4, from something other than core: the morning brief called the keyring fold due against a proposal, not core's API. The recorded call (2026-10-25) is to build against the 7.2 release candidate, so the watch now also needs WordPress 7.2-RC1 or later; until then its note says the function exists but waits for the RC. A 7.2 beta or a 7.2 trunk build does not count; anything from 7.2-RC1 on does. The misplaced docblock above it (it described the 7.2 watch) now sits on its own function. Pinned in `tests/watches.php`.

