# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.19] - 2026-11-09 — The SN Queue keeps a weekday rule

### Added
- **The SN Queue keeps a weekday rule: from Jan 4, 2027, notes go out on Mondays and Thursdays.** Queue calendar phase 2, item 4, the rule the owner picked on 2026-11-09 (all 18 notes scheduled from Jan 4 already keep it).
  - A scheduled note on a day the rule leaves out is an "Off day" line among the cadence warnings, on the card and in the Cadence box on both Connections › Scheduled twins: "Off day: a note on Tue, Jan 12, 2027 (from Jan 4, 2027, notes go out on Mon and Thu)".
  - Suggested slots land only on an allowed day. When the usual spacing lands on a day the rule leaves out, the slot takes the nearest allowed day, held 2 to 7 days after the last note, so after a Monday it suggests the Thursday, not the next Monday a week on. On the live queue, three drafts would be suggested Mon Mar 8, Thu Mar 11 and Mon Mar 15, 2027.
  - The rules are a setting (`sn_content_queue_weekday_rules`), edited in a new **Weekday rules** box on both Scheduled twins: each rule in words, then Starts, Ends and a checkbox per weekday. The owner's rule is seeded once on the first admin load after install. Where two rules cover a day, the later start wins.
  - An independent review found six issues, all fixed before merge. A pull could land 8 days on, opening a gap. A reason named the wrong cause after a walk past a planned day. The native form's weekday boxes had no group name. Its end field was labeled differently from the classic one. Two orderings had no test (an off day before a gap, and the tie between two allowed days).
  - Without a rule, the warnings and slots are exactly what they were. The content-queue ability declares the new `offday` kind.
  - Item 5 (pending links) is parked: its one real case, "The catalog with no engineer" linking to "The key stays in the drawer", is already linked.
  - Docs: `docs/DESKTOP-CARDS.md` (Weekday rules). Tests: `tests/queue-weekday-rules.php`, `tests/queue-weekday-twins.php` (both new), `tests/abilities-content-queue.php`, `tests/queue-slot-twins.php`.

