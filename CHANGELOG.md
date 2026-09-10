# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.13] - 2026-09-10 — Links goes back to a stack

### Fixed
- **Integrity → Links is back to a stack.** v13.109.12 paired its three link groups on the reading that they are siblings — which they are, but the reason it failed is mechanical rather than semantic: **each group already lays out its own cards horizontally**, so a horizontal wrapper divides an already-divided width. Measured live on the installed build: groups fell to 265px each, cards to ~230px, and every URL truncated — `dash.cloudfl…`, `platform.cloudways…`. Stacked, each group takes the full 820px, its two cards sit at ~390px, and the URLs read in full. The pass rule — siblings take a grid — assumes *single-column* siblings; a container already using the width is not a candidate. This is pinned, with the measurements, so it is not "fixed" again.

