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
- **The spam rules read the pitch, not only the name.** 18.8.2's Spam-folder check caught 4 of the 8 real spam entries and missed four cold sales pitches with plausible names. Two signals added from those four: `pitch` (strong: SEO backlinks, "price as low as", "85% discount", a bought company database, replica watches, cash on delivery) and `name_echo` (weak: the sender's name copied into two or more unrelated answers, a script filling every field). All four are now tests, beside real inquiries that must pass, including one that mentions SEO; disabling `pitch` fails all four.

## [18.8.2] - 2026-09-26 — the spam scan measures its rules against the Spam folder

### Changed
- **The forms spam scan measures the rules against the Spam folder.** `forms-spam-scan` gains `spam_folder`: of the entries already marked spam, how many the content rules would have caught on their own, with each reason, and which they miss. Report-only. The inbox scan alone could not tell a clean inbox from rules that catch nothing.

