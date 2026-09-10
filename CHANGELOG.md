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
- **Integrity → Links is back to a stack.** v13.109.12 paired its three link groups on the reading that they are siblings — which they are, but the reason it failed is mechanical rather than semantic: **each group already lays out its own cards horizontally**, so a horizontal wrapper divides an already-divided width. Measured live on the installed build: groups fell to 265px each, cards to ~230px, and every URL truncated — `dash.cloudfl…`, `platform.cloudways…`. Stacked, each group takes the full 820px, its two cards sit at ~390px, and the URLs read in full. The pass rule — siblings take a grid — assumes *single-column* siblings; a container already using the width is not a candidate. This is pinned, with the measurements, so it is not "fixed" again.


## [13.109.12] - 2026-09-10 — siblings pair, across eight leaves

### Changed
- **Content → Now Page and Uses Page pair their cards.** Each card is one independent section or gear group — nothing about card 2 depends on card 1 — so they are siblings, and a stack is the right shape for a sequence and the wrong one for a set. Measured live 2026-09-10 on Now Page: four cards stacked to **1,386px at 728px wide; paired, the leaf is 831px and each card measures 826px** — shorter *and* wider, because `.snt-cols` also trips the width-cap escape and releases the leaf from 820px. This is the same rule that turned AI → MCP Clients from a 2,852px essay into a 2,136px grid.
- **Six more leaves pair their sibling sections.** Same rule, applied where the sections are genuinely parallel rather than sequential: `security/login` (the slug that sets the door + the emergency unlock that gets you back through it), `security/audit-log` (two readouts, then two controls — the counter table keeps the full width), `monitoring/rss` (Settings + Maintenance both manage the feed; Activity and the requests table keep the width), `tools/reports` (each report is an independent measurement), `tools/trust` (what the checks watch + what the public sees), and `tools/links` — three link groups of near-identical height (181/181/200px measured live) that use `.snt-systems` rather than `.snt-cols`, because three siblings want one row of three, not a ragged 2 + 1.
- **A form that pairs its cards earns the width; a form of fields does not.** OpenStation caps `os-form` at 760px, and that is the right measure for labelled inputs — the space beside `site/identity-and-seo` or `security/login` is the price of legibility, not a defect, and this pass deliberately leaves those alone. The override is scoped to `os-form:has( .snt-cols )`, so only a form that has actually paired sibling cards opts in. Without it, pairing inside the 760px cap merely split it and cards fell to 355px: shorter but less readable, a bad trade.

