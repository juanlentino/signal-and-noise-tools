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
- **SN Queue, an OpenStation widget: "is the queue fed, and what goes out next".** The next scheduled note as the headline with its time, the depth line (`26 scheduled · runs to Dec 27`), three more upcoming, and the last three published, each row opening the editor as a native window. Core's Activity box frames the same rows as activity, built for busy multi-author blogs; this site lives on a scheduled queue of notes, one every few days, so the depth is the reading and no surface on the desktop showed it. Registered second, the editorial pair with Site Views. `assets/desktop-mode-widget-queue.js`, refreshed every 60s and on window focus. The Classic Admin home keeps its four boxes; the Posts screen filtered to Scheduled is its answer.
- **`signal-noise/content-queue`, the readonly ability behind it.** Three cheap reads (next four scheduled asc, the furthest one for the run-to date, last three published; the total rides the cached `wp_count_posts`). Every label is computed server-side in the SITE timezone, never the browser's: "Today 21:38", "Tomorrow 09:38", "Sat 11:38" within the week, "Sep 26", "Jan 3, 2027" past the year, "Overdue" for a scheduled post whose cron has not fired. Gate: `edit_posts`, whoever sees the Posts screen sees the queue. Not doored: `sn-posts` already carries the same rows for an agent (`tests/mcp-capabilities.php` records the verdict).
- **Defensive by construction, the way OpenStation and core do it.** The shaper is pure and tolerates junk (non-array rows dropped, an empty title paints as "(untitled)", a missing link paints plain text, a negative total floors at zero, a future publish stamp never goes negative); the widget guards every read off the payload, paints a message instead of a blank card on a failed fetch, and gates every async callback on teardown so a late response never repaints a removed container.

### Tests
- `tests/abilities-content-queue.php` (43): the site-timezone label across today/tomorrow/week/year and the Madrid day boundary, the age, the pure shaper's caps and junk tolerance, the three reads and their order, the empty site. Verified red by mutation (the Tomorrow branch, the furthest-row append, the timezone). `desktop-mode-integration.php` re-pinned for eleven widgets, the order, the height table (SN Queue budgeted 300, re-measure once live) and the compat dependency.

## [15.7.1] - 2026-09-17 — the sweep button asks with an empty hand

### Fixed
- **A POST with no input now carries `{}`, so `Sweep now` on the SN Anchors widget runs again.** The shared runner (`assets/snt-ability-run.js`) dropped an empty input on POST and sent no body at all; the abilities controller validates a missing input as `null`, and any write ability typed plain `object` refused it ("input is not of type object"). One line in the runner closes the class for every caller. `anchor-sweep`, `block-migrations-scan` and `corpus-integrity-scan` also take the `[object,null]` union, the house rule the read abilities already follow, so a bodyless curl or MCP call is accepted too.
- Tests: `ability-run-client.php` D.9/D.10 pin the runner's POST body (red against the old runner); Group E's comment in `abilities-categories.php` no longer claims POST always carried a body.

