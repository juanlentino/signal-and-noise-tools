# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The correction detector sees every correction notice, not only the bold one.** `editorial_conventions` now matches a paragraph whose text opens "Correction," whatever wraps it (`<em>`, `<b>`, `<i>`, nested, or bare), so note 1514's italic colon-form notice would have been reported. A new form finding names a notice that is not in the house lead-in `<strong>Correction, Month D, YYYY.</strong>`, with no fix (signed prose; the owner applies it), and the class-only fix is now offered only when the lead-in is already right. The corpus-integrity scan also counts the colon form ("Correction, October 1, 2026: …") as a dated notice instead of date drift, while still judging a later date the notice text cites.

## [2026.10.31] - 2026-10-17 — lamps on /verify, a roadmap ladder on /maturity, and an honest /now date


- **Lamps on /verify, and an LED ladder on /maturity.** Approved by the owner from renders.
  - Each /verify check's stamp now leads with a lamp whose shape, not only its color, says the state: solid for PASS, solid blood for FAIL, a hollow dashed ring for UNREACHABLE (so a network failure never reads as a failed check), half filled for NOTE, and a dotted ring while pending. Forced colors keep the solid lamps solid. CSS only, in `prov-verify.css`; `tests/prov-verify-lamps.php` pins it (red before: 7 failed).
  - Each /maturity index card now shows its family's roadmap mix from the live board: one segment per item, solid done, dashed planned, dotted considering, hollow later, then the counts as text ("5 done · 1 planned · 2 considering · 2 later"). `sn_maturity_index_ladder()` takes only counts from the board, never item text. A card whose label matches no family, such as Roadmap, draws nothing rather than zeros. The ladder's text uses the font token. `tests/maturity-index-ladder.php` pins it (red before).

- **/now's Updated date is the list's own again.** The /now page regenerates on every save of the list, including re-saves of identical content. `sn_now_build_body()` stamped the day of the rebuild, so the visible "Updated" date could move while the list stayed exactly the same. That breaks the rule that the date belongs to the list (#1228 had fixed only the stored stamp). The body now reads the stored content-change stamp through `sn_now_updated_label()`, and falls back to today only when no list is stored or the stamp cannot be read. Found by a design-research pass. `tests/now-page-migration.php` pins it (red before: 1 failed).


