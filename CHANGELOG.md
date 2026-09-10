# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.17] - 2026-09-10 — the view resolves through storage

### Fixed
- **The Signal & Noise view switch actually survives a reload now.** v13.109.15 claimed this and did not deliver: it seeded `state.view` from storage in `mounted()`, and the seed ran *before* the app hydrated `state` from the PHP schema, whose `'view' => 'icons'` then overwrote it. Measured on the shipped build — stored `list`, `state.view` `icons`, forty tiles painted. The action was never at fault (dispatching the same one later works); the timing was, and the framework offers no post-hydration hook to move the seed into. The view is now resolved **through storage at paint time**, by the render function that runs after every hydration — so there is no moment at which a stale `state.view` can be painted, nothing is dispatched during render, and no "already seeded" flag is needed. The toggle reads the same resolver, so the control always matches what is on screen.

