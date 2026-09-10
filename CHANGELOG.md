# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.2] - 2026-09-10 — the card dek stops repeating the title

### Fixed
- A social card whose post has no excerpt no longer repeats the page title in its description. The dek falls back to the first 36 words of content, and on a Page whose template renders only `post-content` the title lives inside that content — so the card printed "ON PROVENANCE" in the title and then "On Provenance Two papers, three long-form essays…" underneath it, wasting the words that then fell into the ellipsis. A leading heading is now dropped before the words are counted. Only the leading one: a heading further down is a section title inside the prose.

