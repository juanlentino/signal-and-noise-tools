# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.3] - 2026-09-10 — the validator grades the description that ships

### Fixed
- `sn_validate` now grades the meta description a page actually **ships**, not whichever string happens to be stored on it. The front page, `/notes` and `/provenance` take their description from `seo_copy.*` settings and never emit `_sn_meta_description` at all, but the validator read the post meta on every post — so on `/provenance` it reported a 175-character length while the page served 83, naming a string that appears nowhere in the HTML and that no edit to either value alone could satisfy. The three route-served pages now resolve through the settings key; every other post still reads post meta. An empty route setting skips the surface rather than quietly falling back to the meta row, because "which store" and "is it filled" are different questions.
- The route table that decides this lived inside `sn_seo_description_for_post()`, where no other caller could ask it. It is now `sn_seo_description_setting_key()` and is read by both the description resolver and the validator, so a fourth route-served page is added in one place instead of two that drift apart.

