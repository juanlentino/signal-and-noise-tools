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
- **Versioning is the WordPress shape.** `X.Y.0` is a release, `X.Y.Z` a fix, and `X` rolls by itself when `Y` would reach 10 — how core, WooCommerce, Jetpack and Yoast number. `X` is not a breaking-change flag; a breaking release says BREAKING in its headline. Replaces SemVer, whose minor had reached 110 in seventeen days because it counted merged fixes. `tools/cut-release.sh` takes `release|fix` (`minor`/`patch` still work as aliases; `major` refuses and explains). One cut per arc, not per merge. Owner decision 2026-09-11; the survey of what the kernel, Apple, Ubuntu and CalVer do is in `docs/proposals/2026-09-11-versioning-scheme.md`; the rule is `docs/VERSIONING.md`. The next cut is 14.0.0 — the roll, not a break.

### Added
- **Search served by the kernel.** `/notes/?s=` now ranks notes with the kernel's BM25 over a search index the corpus build writes (`snt_ml_search_index`: term frequencies + idf, `autoload = no`, stamped with the build's `built_at` so a half-written pair is refused). Any word the reader typed can match — WordPress's search required every word — and the best answer comes first; pages and notes only `LIKE` found follow in date order exactly as before. The plugin shapes the theme's existing query through `posts_clauses` when the theme flags it (`sn_notes_search`): the `WHERE` is widened to the ranked ids (re-scoped to publish + no-password, so widening never out-scopes), `ORDER BY` gets `FIELD()` in rank order ahead of the date. Each ranked row shows its evidence through `sn_notes_search_snippet`: the sentence holding the rarest query token, escaped, the words in `<mark>`. Plugin off → today's search, byte for byte. Board: the row moves Planned → done, and the family's founding row ("A deterministic layer…") graduates onto /maturity/machine-learning/ as a principle — the done column had hit the wall canary (the #844 pattern). Spec `docs/proposals/2026-09-11-notes-search-kernel-design.md`; `tests/notes-search-ranking.php` (44 pins, the acceptance fixture red without the flag), `tests/ml-kernel.php` (+4), `tests/ml-artifacts.php` (+9). **Needs theme ≥ 13.0.0 for the flag and the snippet; older themes see no change.**
- **`tools/cut-release.sh` refuses a previous cut that grew after its tag.** A branch opened before the last cut adds its bullet under what was then `## [Unreleased]`; by the time it squash-merges, that heading is a released version, and the next cut would archive the bullet as if it had shipped. Bitten twice on 2026-09-11 (#1167, #1171), fixed by hand both times. The guard diffs the previous section's bullets against the same section at its own tag and names the strays; it runs before the empty-Unreleased refusal, so the message says why Unreleased is empty. An unfetched tag is a loud NOTE, never a pass. Verified on the live trap (it named both #1171 bullets), on the not-fetched path, and on a clean tree.

## [13.110.2] - 2026-09-11 — a dated correction notice is the convention, not date drift

### Fixed
- **`corpus_integrity` no longer reads the correction convention as drift.** A paragraph that is exactly a dated editorial notice — `Correction, September 3, 2026.`, `Updated 2026-09-04`, the shapes the site actually writes after a claim-changing edit — carries a date later than `post_date` by design, and the scan reported both live instances as `date_coherence` findings. `snt_corpus_integrity_is_dated_notice()` recognises the narrow shape (notice word, optional punctuation, a date as the whole remainder — prose that merely contains the word is still judged); notices are counted under a new `counts.corrections` so the practice is visible, never a candidate (`tests/corpus-integrity-scan.php` +9, including four refusals).

### Changed
- README: the write door's eight slugs by name; the Abilities REST route named as a write surface with its guard; a **Public surface** table listing every credential-free route and edge endpoint with who serves it and why it is public (`tests/rest-routes.php` pins the plugin half). `phpstan.neon`'s header no longer says CI runs soft. `.gitattributes` drops an export-ignore for a `.pre-commit-config.yaml` that never existed.

