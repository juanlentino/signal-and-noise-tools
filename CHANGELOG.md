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
- **The Search view reads in two bands.** 16.2.0 wedged the Bing panel and its full-width one-row table between Google's pages and Google's cross-exam, and the view ran two and a half screens. Google's story now runs unbroken (window, queries beside topics, pages, cross-exam, coverage, seen-but-never-clicked, drift, its empty fold), then Bing as the last band in one panel with its queries inside it. Every metrics table (Google queries, pages, Bing queries) sits behind the house ten-row clamp with "View all N"; nothing is dropped, the scroll is. Bing's empty states are visible panels, because the band paints after the view's fold has flushed and a note collected there would leak into the next view. The topic-interest test fixture takes the renderer's real shape (it warned six times a run). Pinned.

## [16.2.2] - 2026-09-18 — the tile reads the verdict

### Fixed
- **The Zenodo tile reads the keyring's verdict.** It read On for any stored token, while the keyring had refused the sandbox row ("HTTP 403") since the first Verify all; two surfaces disagreed and the one that had asked Zenodo lost. `sn_zenodo_tile_state` (pure) says Off, Unverified, Refused (with the keyring's detail) or On, in both painters. Pinned.
- **The Zenodo probe creates a draft and deletes it.** "Can list depositions" proved a token reached the API and nothing about whether the account could write; the deposit flow's first verb is create. A 403 on create reads as refused with the likeliest cause named (a token minted on the other environment; sandbox and production are separate accounts); a draft that cannot be deleted is named in the verdict. Pinned.

