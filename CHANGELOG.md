# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.2.3] - 2026-09-18 — the search view reads in two bands

### Fixed
- **The Search view reads in two bands.** 16.2.0 wedged the Bing panel and its full-width one-row table between Google's pages and Google's cross-exam, and the view ran two and a half screens. Google's story now runs unbroken (window, queries beside topics, pages, cross-exam, coverage, seen-but-never-clicked, drift, its empty fold), then Bing as the last band in one panel with its queries inside it. Every metrics table (Google queries, pages, Bing queries) sits behind the house ten-row clamp with "View all N"; nothing is dropped, the scroll is. Bing's empty states are visible panels, because the band paints after the view's fold has flushed and a note collected there would leak into the next view. The topic-interest test fixture takes the renderer's real shape (it warned six times a run). Pinned.

