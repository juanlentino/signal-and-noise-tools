# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.1.1] - 2026-10-05 — /stats calendar beside the rhythm

### Fixed
- **/stats: the calendar sits beside the reading-rhythm sentence on a wide page**, under a full-width chart, instead of leaving the right half empty. Reading order is unchanged (sentence, chart, calendar); under 900px it stacks as before.

