# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.110.1] - 2026-09-11 — the read door is readonly by pin, and the README's first screen is headlines

### Fixed
- **`corpus_integrity` no longer reads the correction convention as drift.** A paragraph that is exactly a dated editorial notice — `Correction, September 3, 2026.`, `Updated 2026-09-04`, the shapes the site actually writes after a claim-changing edit — carries a date later than `post_date` by design, and the scan reported both live instances as `date_coherence` findings. `snt_corpus_integrity_is_dated_notice()` recognises the narrow shape (notice word, optional punctuation, a date as the whole remainder — prose that merely contains the word is still judged); notices are counted under a new `counts.corrections` so the practice is visible, never a candidate (`tests/corpus-integrity-scan.php` +9, including four refusals).

### Changed
- README: the write door's eight slugs by name; the Abilities REST route named as a write surface with its guard; a **Public surface** table listing every credential-free route and edge endpoint with who serves it and why it is public (`tests/rest-routes.php` pins the plugin half). `phpstan.neon`'s header no longer says CI runs soft. `.gitattributes` drops an export-ignore for a `.pre-commit-config.yaml` that never existed.

### Fixed
- **`reader-anomalies` is annotated `readonly => true`.** Its description said read-only; its annotation did not, and two consumers key on the annotation — the MCP projection (`readOnlyHint`) and, since v13.110.0, the rw run-route guard, which was treating it as a write. Found by the new read-door pin below.
- **The rw run-route guard now checks the rw allowlist before the annotation.** `describe-tags` is returns-only (`readonly => true`, honestly) and sits on the rw door because it BILLS an AI call — annotation-only keying let an application password reach it without the door's credential. Membership outranks the annotation (`sn_mcp_rw_guard_run_route_applies`; `tests/mcp-rw-guard-run-route.php` +1).

### Added
- `tests/mcp-capabilities.php`: every read-door slug is registered `readonly => true`; no rw-door slug is, except the named AI-billed returns-only exceptions (`describe-tags`); every doored slug is found by the scan; a true/false control. Derived from source, so a new ability is judged the moment it registers.
- `tests/rest-routes.php`: the REST route population is 19, every registration carries a `permission_callback`, and exactly three are public — the webmention receiver, the credential fetch, the bridge — each with its reason on the record. A fourth `__return_true` has to be argued onto the list.
- **`Rights signals` health check gains `parity`**: the served `/wp-json` `Content-Signal` must equal `SN_TDM_CONTENT_SIGNAL` byte-for-byte. The header is authored in two repos (origin constant, edge Worker) and v10.70.1 found them silently diverged; the semantic checks pass either spelling, so this is the one that would have caught it. An undefined origin constant is a failure, not a skip. The suite's fixture carried the pre-v10.70.1 spaced string and was corrected (`tests/health-check-rights-signals.php` +6).
- (worker) `signal-and-noise-analytics-worker` #27 pins that no response ever sets a cookie.


### Changed
- README: *What it does* is now thirteen one-line headlines; the paragraphs moved verbatim under a new **In depth** section, and OpenStation's became a table. First screen 6,400 → 1,400 chars.
