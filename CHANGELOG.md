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
- **Content → Now Page and Uses Page pair their cards.** Each card is one independent section or gear group — nothing about card 2 depends on card 1 — so they are siblings, and a stack is the right shape for a sequence and the wrong one for a set. Measured live 2026-09-10 on Now Page: four cards stacked to **1,386px at 728px wide; paired, the leaf is 831px and each card measures 826px** — shorter *and* wider, because `.snt-cols` also trips the width-cap escape and releases the leaf from 820px. This is the same rule that turned AI → MCP Clients from a 2,852px essay into a 2,136px grid.
- **A form that pairs its cards earns the width; a form of fields does not.** OpenStation caps `os-form` at 760px, and that is the right measure for labelled inputs — the space beside `site/identity-and-seo` or `security/login` is the price of legibility, not a defect, and this pass deliberately leaves those alone. The override is scoped to `os-form:has( .snt-cols )`, so only a form that has actually paired sibling cards opts in. Without it, pairing inside the 760px cap merely split it and cards fell to 355px: shorter but less readable, a bad trade.


## [13.109.11] - 2026-09-10 — four doors as four cards

### Changed
- **AI → MCP Clients explains four doors as four cards, not a vertical essay.** The tile row at the top of the leaf already said there were four doors and put them side by side; the body then explained the same four one under the other, each in a full-width section whose prose used under half of it. The four door explainers now pair into two rows, mirroring the tiles above, and the footer caveat sits beside the deep links instead of taking a band of its own. Measured live 2026-09-10: **2,852px → 2,136px**, cards at 865px — a better measure for the prose than 1,700. "Connect a client" keeps the full width, because it carries a JSON config block and a CLI command and wrapping either is worse than the space costs.

### Fixed
- Paired sections sat 24px below their neighbour. `.snt-leaf os-section + os-section` is vertical-stack rhythm, and it is still true of the **second cell of a `.snt-cols` row** — which took that margin on top of the grid `gap`. Measured live: paired door cards at top 797 / 821 instead of 797 / 797. The reset is scoped to `.snt-cols` and carries enough specificity to outrank the stack rule `(0,4,2)` vs `(0,3,2)` rather than win on source order, which is the same trap the 820px width cap fell into.

