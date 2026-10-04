# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **SN Reading fits its card again.** Once session depth started arriving, "Two pages" and "Three or more" added two rows and pushed "Open Analytics" below the card's fold. They share one row now ("Two pages · three or more: 10% · 2%"). "Open Machine Readers" gets the same gap before its arrow as the other widget links.

## [21.8.1] - 2026-10-04 — SN Health and SN Anchors read at a glance

### Fixed
- **SN Health and SN Anchors read at a glance again.** 21.8.0's accessibility pass made hover-only text visible, and both cards filled up. Health's headline now counts apart what it used to fold together ("16 passed · 1 to look at · 1 could not run", or "All 18 checks passed"), and a check that could not run shows a short reason (parenthetical provider errors dropped, at most 140 characters cut at a word, so a reason in its second sentence survives) and "Full reason on the Health tab." instead of the provider's raw error. Anchors says "✓ All anchored: 50 notes, 6 pages" in one line instead of three, and drops the Archive sentence that repeated its two rows, keeping it when the run is halted (it carries the reason) or Archive is not configured (it names the two wp-config keys).
- **An error notice interrupts.** `os-notice` defaults to `role="status"` (polite, confirmed in OpenStation's source); the kit now marks the danger tone `role="alert"`. The per-row naming of `os-repeater` buttons is OpenStation's to fix (WordPress/openstation#946).

