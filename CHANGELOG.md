# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **AI → MCP Clients explains four doors as four cards, not a vertical essay.** The tile row at the top of the leaf already said there were four doors and put them side by side; the body then explained the same four one under the other, each in a full-width section whose prose used under half of it. The four door explainers now pair into two rows, mirroring the tiles above, and the footer caveat sits beside the deep links instead of taking a band of its own. Measured live 2026-09-10: **2,852px → 2,136px**, cards at 865px — a better measure for the prose than 1,700. "Connect a client" keeps the full width, because it carries a JSON config block and a CLI command and wrapping either is worse than the space costs.

### Fixed
- Paired sections sat 24px below their neighbour. `.snt-leaf os-section + os-section` is vertical-stack rhythm, and it is still true of the **second cell of a `.snt-cols` row** — which took that margin on top of the grid `gap`. Measured live: paired door cards at top 797 / 821 instead of 797 / 797. The reset is scoped to `.snt-cols` and carries enough specificity to outrank the stack rule `(0,4,2)` vs `(0,3,2)` rather than win on source order, which is the same trap the 820px width cap fell into.


## [13.109.10] - 2026-09-10 — auto-fit, so four tiles fill the row

### Fixed
- Three leaves painted their status tiles into half a row. `.snt-systems` used `repeat( auto-fill, … )`, and **`auto-fill` keeps the empty tracks it creates** — so a row with fewer items than tracks leaves the remainder blank instead of letting the items grow. Measured live 2026-09-10 on AI → MCP Clients: a **1,702px row held eight 202px tracks for four tiles**, so the tiles sat at 202px and the right half of the section was empty. `auto-fit` collapses the empty tracks; the same four tiles measure **417px and span the full row**. It was the lone `auto-fill` in the codebase against three `auto-fit`, and it is shared by AI → MCP Clients, Connections → Cloudways and the Dashboard's own Systems row.

