# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.9] - 2026-09-10 — the fold reaches redirects, and the log stops asking

### Changed
- **The redirect map is one line per rule, matching Broken links.** Splitting the 404 log out did not fix this leaf: measured live 2026-09-10 after installing v13.109.8, Site → Redirects was still **9,101px — nine screens** of scrolling, 39 rules at 233px each, an edit form open on every one with **988px of empty space beside a 760px field**. The cap did not help because 39 is under it; an open form per row is what costs the height. Each rule is now a fold headed by the whole rule — `/source → /target` — hinted with its status code and age, and the edit form opens on the one being changed.
- **Both leaves are the same width.** Redirects was on the by-name allowlist that releases a leaf from the 820px cap, a leftover from when it carried a table and a 404 rail. It carries neither now, and a one-line fold at 1,748px has ~1,400px of dead middle. Both leaves read at the same measure as `site/front-end`, which was audited good.

### Fixed
- **Scanner noise stopped presenting itself as broken links needing a decision.** Observed on the live log: of 25 actionable rows, eleven were probes — `/es/music`, `/es/about`, `/en/privacy-policy`, `/nl/privacy-policy` and five more locale variants of real pages; `/_next/static` and `/_vercel/routes` fingerprinting the stack; `/app/(group)/layout`, a Next.js route group replayed verbatim. Each demanded its own redirect decision. Three rules now reject them: a two-letter language prefix (this site is single-locale and publishes nothing under one), the `_next`/`_nuxt`/`_vercel`/`__` build namespaces, and any path containing a parenthesis. Because `sn_404_log_actionable()` re-runs the filter on **read**, this clears the existing log without mutating the stored option. The controls matter more than the rules: `/roadmap`, `/tag/music-provenance`, `/notes/tag/ai-detection`, `/notes/desing-tokens`, `/essays/on-provenance` and a bare `/es` are all pinned as still-actionable, because over-rejection swallows a real broken link and that is worse than the noise it removes.

