# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **/now's Updated date is the list's own again.** The /now page regenerates on every save of the list, including re-saves of identical content. `sn_now_build_body()` stamped the day of the rebuild, so the visible "Updated" date could move while the list stayed exactly the same. That breaks the rule that the date belongs to the list (#1228 had fixed only the stored stamp). The body now reads the stored content-change stamp through `sn_now_updated_label()`, and falls back to today only when no list is stored or the stamp cannot be read. Found by a design-research pass. `tests/now-page-migration.php` pins it (red before: 1 failed).

## [2026.10.30] - 2026-10-17 — the provenance record as an edit list, with a fingerprint per version


- **The provenance record reads as an edit list, and every version shows its fingerprint.** Each row of a note's version chain is now an edit-decision-list line: event number, version, the time it was recorded (UTC, from the chain's own `committed_at`, as a `<time>`), its block and the short hash, in fixed monospace columns on wide screens. Under each row, `sn_prov_fingerprint_svg()` draws the full SHA-256 of that version's signed text as 64 bars, one per hex digit, each as tall as the digit plus one. Change one character and every bar moves, so a note's versions visibly disagree. The strip is decorative (`aria-hidden`, the hash text stays the value), one `currentColor` path with no inline style, so it follows the theme, prints and survives forced colors. A row without a full 64-hex hash draws no strip, and an unreadable time shows nothing rather than a wrong one. The panel sits outside the signed region, so no signed text changes. Approved by the owner from renders. `tests/provenance-render.php` pins both helpers and the row (red before the change).


