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
- **The forms spam scan measures the rules against the Spam folder.** `forms-spam-scan` gains `spam_folder`: of the entries already marked spam, how many the content rules would have caught on their own, with each reason, and which they miss. Report-only. The inbox scan alone could not tell a clean inbox from rules that catch nothing.

## [18.8.1] - 2026-09-26 — the spam scan reads the entries it scans

### Fixed
- **The forms spam scan reads the entries it scans.** AllTerrain Forms stores an entry's values as a JSON string; the 18.8.0 sweep cast that string to an array, so the rules saw one opaque value and flagged none of 338 inbox entries. It now decodes them (`snt_fs_entry_values()`, the same `json_decode` Forms uses). The live filter on new submissions was unaffected: Forms hands it the decoded array. The test had built entries as arrays, looser than the store; it now stores them as Forms does, and fails on the 18.8.0 read.

