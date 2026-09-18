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
- **Every bucket upload goes as `application/octet-stream`.** The first production pass created its drafts and then failed every upload with Zenodo's "Invalid 'Content-Type' header. Expected one of: application/octet-stream": the bucket takes that type and no other, and the file's own type rides its extension. The test had pinned `text/markdown` as correct. Pinned to octet-stream.
- **A failed resume read keeps the draft.** When the read of a stored draft came back with anything but success the code forgot the draft id and the next pass minted a fresh one; ten empty drafts on the first production day. Now only a 404 forgets it, and the step is named `resume`, not `create`. Pinned at 500 and 404.

## [16.1.2] - 2026-09-18 — the title is the query, nothing more

### Fixed
- **A written search title is the whole title tag, on pages too.** Bing Webmaster Tools flagged 18 pages with titles past 70 characters (2026-09-18). The pillars' overrides carried the " — Juan Lentino" suffix that notes lost in 15.9.1, 15 characters of the 89 on `/provenance/`; now an `_sn_seo_title` override drops the suffix on any type, while a page without one keeps "Page — Site", as does a title the theme's route filter supplies. Pinned.
- **Health check 28 has a ceiling.** A search title over 65 characters (Bing flags past 70, Google shows about 60) is a finding that says the count, and the check now reads pages that carry an override as well as notes; a page without one is never a finding. Pinned at 65 and 66.
- **`/notes/tags/` carries a canonical and a description.** The glossary is a theme route WordPress sees as a 404 the theme clears, so the SEO view dispatcher missed it and printed neither. The description names the live tag count. Pinned.

