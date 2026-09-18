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
- **The Zenodo tile reads the keyring's verdict.** It read On for any stored token, while the keyring had refused the sandbox row ("HTTP 403") since the first Verify all; two surfaces disagreed and the one that had asked Zenodo lost. `sn_zenodo_tile_state` (pure) says Off, Unverified, Refused (with the keyring's detail) or On, in both painters. Pinned.
- **The Zenodo probe creates a draft and deletes it.** "Can list depositions" proved a token reached the API and nothing about whether the account could write; the deposit flow's first verb is create. A 403 on create reads as refused with the likeliest cause named (a token minted on the other environment; sandbox and production are separate accounts); a draft that cannot be deleted is named in the verdict. Pinned.

## [16.2.1] - 2026-09-18 — a draft belongs to its environment

### Fixed
- **The Zenodo draft slot is per environment.** Flipping from production to sandbox read the production draft ids on sandbox, where "The persistent identifier does not exist" is a 404, and the 16.1.3 rule forgot them: five resumable production drafts became orphans. Production keeps the original meta key (every id stored before this fix is a production id); sandbox has its own, and neither environment reads or forgets the other's. Pinned.
- **The shell toast decodes entities.** The flash strings carry `&rsquo;`, `&mdash;` and `&hellip;` for the HTML notice, and the toast printed them as text ("Zenodo&rsquo;s answer"). Pinned.

