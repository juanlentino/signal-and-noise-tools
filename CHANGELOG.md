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
- **Two guard suites walked zero files from inside a linked worktree.** `tests/admin-class-orphans.php` and `tests/direct-access-guard-window.php` skip other sessions' worktrees by refusing any path containing `/.claude/` (#1055) — tested on the absolute path, so a run from `.claude/worktrees/<name>/` excluded its own root and scanned nothing. The orphan suite then reported all 91 baseline classes "now styled" and eight drift regressions; the guard-window suite held 0 files to the window. Both now test the path relative to the scan root, which keeps nested worktrees skipped and makes the root's own location irrelevant. The vacuity assertions caught it, as designed.

## [14.0.0] - 2026-09-11 — search served by the kernel

### Changed
- **Versioning is the WordPress shape.** `X.Y.0` is a release, `X.Y.Z` a fix, and `X` rolls by itself when `Y` would reach 10 — how core, WooCommerce, Jetpack and Yoast number. `X` is not a breaking-change flag; a breaking release says BREAKING in its headline. Replaces SemVer, whose minor had reached 110 in seventeen days because it counted merged fixes. `tools/cut-release.sh` takes `release|fix` (`minor`/`patch` still work as aliases; `major` refuses and explains). One cut per arc, not per merge. Owner decision 2026-09-11; the survey of what the kernel, Apple, Ubuntu and CalVer do is in `docs/proposals/2026-09-11-versioning-scheme.md`; the rule is `docs/VERSIONING.md`. The next cut is 14.0.0 — the roll, not a break.

### Added
- **Search served by the kernel.** `/notes/?s=` now ranks notes with the kernel's BM25 over a search index the corpus build writes (`snt_ml_search_index`: term frequencies + idf, `autoload = no`, stamped with the build's `built_at` so a half-written pair is refused). Any word the reader typed can match — WordPress's search required every word — and the best answer comes first; pages and notes only `LIKE` found follow in date order exactly as before. The plugin shapes the theme's existing query through `posts_clauses` when the theme flags it (`sn_notes_search`): the `WHERE` is widened to the ranked ids (re-scoped to publish + no-password, so widening never out-scopes), `ORDER BY` gets `FIELD()` in rank order ahead of the date. Each ranked row shows its evidence through `sn_notes_search_snippet`: the sentence holding the rarest query token, escaped, the words in `<mark>`. Plugin off → today's search, byte for byte. Board: the row moves Planned → done, and the family's founding row ("A deterministic layer…") graduates onto /maturity/machine-learning/ as a principle — the done column had hit the wall canary (the #844 pattern). Spec `docs/proposals/2026-09-11-notes-search-kernel-design.md`; `tests/notes-search-ranking.php` (46 pins, the acceptance fixture red without the flag), `tests/ml-kernel.php` (+4), `tests/ml-artifacts.php` (+9). **Needs theme ≥ 13.0.0 for the flag and the snippet; older themes see no change.**
- **`tools/cut-release.sh` refuses a previous cut that grew after its tag.** A branch opened before the last cut adds its bullet under what was then `## [Unreleased]`; by the time it squash-merges, that heading is a released version, and the next cut would archive the bullet as if it had shipped. Bitten twice on 2026-09-11 (#1167, #1171), fixed by hand both times. The guard diffs the previous section's bullets against the same section at its own tag and names the strays; it runs before the empty-Unreleased refusal, so the message says why Unreleased is empty. An unfetched tag is a loud NOTE, never a pass. Verified on the live trap (it named both #1171 bullets), on the not-fetched path, and on a clean tree.

