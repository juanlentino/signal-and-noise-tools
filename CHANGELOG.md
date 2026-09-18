# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.1.2] - 2026-09-18 — the title is the query, nothing more

### Fixed
- **A written search title is the whole title tag, on pages too.** Bing Webmaster Tools flagged 18 pages with titles past 70 characters (2026-09-18). The pillars' overrides carried the " — Juan Lentino" suffix that notes lost in 15.9.1, 15 characters of the 89 on `/provenance/`; now an `_sn_seo_title` override drops the suffix on any type, while a page without one keeps "Page — Site", as does a title the theme's route filter supplies. Pinned.
- **Health check 28 has a ceiling.** A search title over 65 characters (Bing flags past 70, Google shows about 60) is a finding that says the count, and the check now reads pages that carry an override as well as notes; a page without one is never a finding. Pinned at 65 and 66.
- **`/notes/tags/` carries a canonical and a description.** The glossary is a theme route WordPress sees as a 404 the theme clears, so the SEO view dispatcher missed it and printed neither. The description names the live tag count. Pinned.

