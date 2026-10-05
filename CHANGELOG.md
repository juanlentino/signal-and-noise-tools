# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.4.1] - 2026-10-05 — the dual-write check accepts the same sample

### Changed
- **The dual-write check accepts the same sample on both sides.** Analytics Engine samples ordinary visitor-days at write time (22.3.0's diagnostic: about one in six human visitor-days, typically 2 stored rows standing for 4; every figure has always carried these estimates), so a day with any sampled event could never match and analytics 2.0 could never start. The two pageview datasets were measured holding the same sample. An event type now counts as exact when both pageview datasets hold the same sample: the same totals AND, read visitor by visitor, the same sampled visitor-days with the same stored rows and weights. It is listed under `identical_sample`; a different sample stays inconclusive and an exact difference is still a mismatch. Owner-approved.

