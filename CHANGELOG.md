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
- **The SN Queue keeps a weekday rule: from Jan 4, 2027, notes go out on Mondays and Thursdays.** Queue calendar phase 2, item 4, the rule the owner picked on 2026-11-09 (all 18 notes scheduled from Jan 4 already keep it).
  - A scheduled note on a day the rule leaves out is an "Off day" line among the cadence warnings, on the card and in the Cadence box on both Connections › Scheduled twins: "Off day: a note on Tue, Jan 12, 2027 (from Jan 4, 2027, notes go out on Mon and Thu)".
  - Suggested slots land only on an allowed day. When the usual spacing lands on a day the rule leaves out, the slot takes the nearest allowed day, held 2 to 7 days after the last note, so after a Monday it suggests the Thursday, not the next Monday a week on. On the live queue, three drafts would be suggested Mon Mar 8, Thu Mar 11 and Mon Mar 15, 2027.
  - The rules are a setting (`sn_content_queue_weekday_rules`), edited in a new **Weekday rules** box on both Scheduled twins: each rule in words, then Starts, Ends and a checkbox per weekday. The owner's rule is seeded once on the first admin load after install. Where two rules cover a day, the later start wins.
  - An independent review found six issues, all fixed before merge. A pull could land 8 days on, opening a gap. A reason named the wrong cause after a walk past a planned day. The native form's weekday boxes had no group name. Its end field was labeled differently from the classic one. Two orderings had no test (an off day before a gap, and the tie between two allowed days).
  - Without a rule, the warnings and slots are exactly what they were. The content-queue ability declares the new `offday` kind.
  - Item 5 (pending links) is parked: its one real case, "The catalog with no engineer" linking to "The key stays in the drawer", is already linked.
  - Docs: `docs/DESKTOP-CARDS.md` (Weekday rules). Tests: `tests/queue-weekday-rules.php`, `tests/queue-weekday-twins.php` (both new), `tests/abilities-content-queue.php`, `tests/queue-slot-twins.php`.

## [2026.11.18] - 2026-11-09 — Edge readings count visitor requests only

### Fixed
- **The login doors, probed paths and edge locations count visitor requests only.** These three readings counted every request Cloudflare logged for the site. When a Worker forwards a visitor's request, Cloudflare logs it twice (the visitor's, then the Worker's own request to the server), and it logs its own Early Hints cache lookups as requests too. Read live for 2026-11-08:
  - The doors read 28 for 17 real requests. The card's five 504s were all Early Hints lookups, each next to the visitor's real 404 or 403 in the same minute.
  - Edge locations read 39,697 requests and 916 MB for 18,511 visitor requests and 331 MB.
  - The colo, doors and probes queries now filter on `requestSource:"eyeball"` (the filter the sampling probe already uses), and each new document was run live, with the current one as the control. They take effect at the next nightly rollup. Days already stored keep their old rows until they leave the range. The Bandwidth and Edge requests headline comes from the exact daily totals, which Cloudflare cannot split by source, so it does not move.
  - Tests: `tests/edge-analytics.php`.
- **The 5xx total counts what visitors asked for.** A Worker's 5xx is usually its own request for a visitor's, so the same failure was counted twice. On 2026-11-07, 7 of the 14 Worker errors had a visitor error in the same minute, path and city.
  - The total and each day's total are now visitors plus unrecorded rows. Unrecorded means stored before the source was recorded, and it stays in, since unclassified is not the same as not a visitor.
  - Worker and other rows remain under who asked, who answered and the times.
  - Which URLs failed and the path-by-status list now count visitors only too.
  - The total applies at once to the stored days, since every day in the window carries its source. The path lists take effect from the next rollup. Until a stored day leaves the window, its paths keep its Worker rows, and the dashboard count is held to the total.
  - The install check no longer needs a Worker's row to be maintenance.
  - Remote contract 31 carries the wording. The Worker's own bump ships after this release is installed.
  - Tests: `tests/edge-analytics-sees-5xx.php`, `tests/edge-5xx-read-back.php`, `tests/desktop-status-extra.php`, `tests/remote-contract-shapes.php`.

