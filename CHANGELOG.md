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
- **The edge 5xx rollup names which pages return which status (#1006).** It stored failing paths and responders as two separate dims, so there was no way to say which URL got a 520; on 2026-09-23..25 the 520s ran 75 to 110 a day, all on uncached PHP. A third dim, `err_path_status`, records `"<edge> <cache> <path>"` (for example `520 dynamic /wp-json/wp/v2/posts`) from the errors query the rollup already runs, capped at the column's 160 by cutting the path end, never the status. `edge-errors-summary` returns it as `paths_by_status` (top 10), and its remote twin with it, so the remote contract moves 9 to 10. It starts empty: days before this release have none, which means not recorded, not no errors.

## [18.8.3] - 2026-09-26 — the spam rules read the pitch and the form's own logic

### Fixed
- **Form rules that differ from a dropdown choice only in case can be found and fixed.** Forms compares a rule's `is`/`is_not` value exactly, and a dropdown stores the choice's value (`Research`), so the contact form's five routed notifications, written `research`, `press`, `speaking`, `music`, `role`, never fired. `forms-spam-scan` now reports `rule_case` per form; `forms-spam-apply` with `fix_rule_case` rewrites each to the choice's exact spelling (notifications, confirmations, field logic), then re-reads the form to verify. Only a case-only difference is touched: a typo or a case-insensitive operator is left alone. `tests/forms-rule-case.php` runs the form's real rules, with a negative control on the comparison.

### Changed
- **The spam rules read the form's own logic.** A person only sees the fields the form shows for what they picked; Forms validates against those but stores everything posted, so a script that fills every input leaves answers in fields that were hidden. `hidden_answers` (strong) fires when answers sit in two or more hidden branches (a branch is one rule set, so Outlet, Angle and Deadline under "Press" are one); one stray branch passes, since a person can switch their choice after typing. Rules mirror Forms' own `is`/`is_not`/`empty`/`not_empty`; any other operator counts the field visible, never guessed hidden. Tested on the contact form's real shape: Darby Vang's entry (Other, with answers under Speaking, Music, Role and Message) is caught on four branches; a switched choice and real inquiries pass; a one-branch threshold fails the switched-choice case.
- **The spam rules read the pitch, not only the name.** 18.8.2's Spam-folder check caught 4 of the 8 real spam entries and missed four cold sales pitches with plausible names. One signal added from those four: `pitch` (strong: SEO backlinks, "price as low as", "85% discount", a bought company database, replica watches, cash on delivery). A name copied into other answers was considered and left out: a freelancer's Company is often their own name, and a required field gets filled with anything. All four are now tests, beside real inquiries that must pass, including one that mentions SEO and one whose Company is the sender's name; disabling `pitch` fails all four.

