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
- **The tag rule is the description; the count is a nudge.** /notes/tags promises "each one says what it covers, so you can tell before you click", which makes a tag on a note right when the note covers what its description says, the thing Jev's pass measures. 16.9.2's ceiling of four listed five notes on Content › Tags and in tag hygiene, and every one of them carried five fitting tags (zero misfits in the pass); 35 of 44 notes carry two or more tags from one group of the page's four, so no structural count hides in the taxonomy either. The `over_ceiling` rows are gone from tag hygiene, `sn-scan{tag_hygiene}` and both Tags surfaces (`sn_tag_notes_over_ceiling()` with them). The pre-publish gate keeps its prompt at five tags or more, reworded to the rule ("5 tags. Each one promises the note covers its description; drop any it only brushes."); `SN_TAG_CEILING` now lives in `inc/pre-publish-gate.php`, its only reader.

## [16.9.2] - 2026-09-19 — a tag names what a note touches

### Fixed
- **Tag fit reads the tags a note carries, asks the house question, and proposes nothing.** The second live pass showed both walls came from one mismatch: Jev was asked whether a note ARGUES what a tag names, and the site tags by what a note TOUCHES. Under the strict question a broad facet fit 44 of 69 notes (so Jev wanted Authorship everywhere, "The unlabeled majority" drew seven adds) and a touched-not-argued tag read 0.07 (so "Market harm names no track" lost three of four). The add side is gone: no Noul per candidate (60 questions a note, most of the 422k tokens), no umbrella lines, no Add boxes, no `assign[]` in the apply handler, and `signal-noise/suggest-tags` is unregistered (its only source went with them); what a note carries is the owner's call. The attached-tag question now asks whether the note touches what the tag names so a reader browsing the tag would find it relevant (0 the subject is absent, 1 touches it, 2 is about it), and a misfit is under 0.5 of 2 at confidence 0.7 or better: a short list Jev is sure about, or nothing. The stored 16.9.1 pass is still readable (its proposals are ignored); Read tags now replaces it for under a cent. `jev-tags-now` loses `missing`, `jev-tags` loses `umbrellas` and carries every attached score beside the misfits. Tests: jev-tags 21, admin-post-actions 215, os-leaf-content-tags 44, tag-consolidation-admin 31, abilities-integration 183.

### Added
- **A tag ceiling of four, in three places.** Read off the corpus 2026-09-19: 32 of 44 published notes carry 3 or 4 tags (mean 3.43, five at 5), and 25 tags over 44 notes means an archive holds 3 to 14 notes; past four the peripheral notes fill the archives and every archive starts to read as the whole corpus. One constant, `SN_TAG_CEILING = 4` (`inc/health-check-tag-hygiene.php`). The pre-publish gate warns at five or more, the line handed to it from PHP as `window.sntPrePublishGateConfig.tagCeiling` (no number in the JS). Tag hygiene, already an advisory, carries an `over_ceiling` finding per note over it, and `sn-scan{tag_hygiene}` a candidate keyed `post:<id>` with `apply_hint` null (trimmed in the editor). Content › Tags lists the notes over the line with their counts, each a link to its editor, on both surfaces; no form, since which facets to keep is an editorial call. Tests: health-check-tag-hygiene 16, sn-scan-tag-hygiene 18, pre-publish-gate 34, os-leaf-content-tags 49, tag-consolidation-admin 33.

