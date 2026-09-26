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
- **The spam rules read the form's own logic.** A person only sees the fields the form shows for what they picked; Forms validates against those but stores everything posted, so a script that fills every input leaves answers in fields that were hidden. `hidden_answers` (strong) fires when answers sit in two or more hidden branches (a branch is one rule set, so Outlet, Angle and Deadline under "Press" are one); one stray branch passes, since a person can switch their choice after typing. Rules mirror Forms' own `is`/`is_not`/`empty`/`not_empty`; any other operator counts the field visible, never guessed hidden. Tested on the contact form's real shape: Darby Vang's entry (Other, with answers under Speaking, Music, Role and Message) is caught on four branches; a switched choice and real inquiries pass; a one-branch threshold fails the switched-choice case.

### Changed
- **The spam rules read the pitch, not only the name.** 18.8.2's Spam-folder check caught 4 of the 8 real spam entries and missed four cold sales pitches with plausible names. One signal added from those four: `pitch` (strong: SEO backlinks, "price as low as", "85% discount", a bought company database, replica watches, cash on delivery). A name copied into other answers was considered and left out: a freelancer's Company is often their own name, and a required field gets filled with anything. All four are now tests, beside real inquiries that must pass, including one that mentions SEO and one whose Company is the sender's name; disabling `pitch` fails all four.

## [18.8.2] - 2026-09-26 — the spam scan measures its rules against the Spam folder

### Changed
- **The forms spam scan measures the rules against the Spam folder.** `forms-spam-scan` gains `spam_folder`: of the entries already marked spam, how many the content rules would have caught on their own, with each reason, and which they miss. Report-only. The inbox scan alone could not tell a clean inbox from rules that catch nothing.

