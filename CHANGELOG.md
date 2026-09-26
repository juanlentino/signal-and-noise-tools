# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [18.8.2] - 2026-09-26 — the spam scan measures its rules against the Spam folder

### Changed
- **The forms spam scan measures the rules against the Spam folder.** `forms-spam-scan` gains `spam_folder`: of the entries already marked spam, how many the content rules would have caught on their own, with each reason, and which they miss. Report-only. The inbox scan alone could not tell a clean inbox from rules that catch nothing.

