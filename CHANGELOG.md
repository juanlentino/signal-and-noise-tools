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
- **SN Machine Readers counted too many reads as "Not measured".** The widget took a read with no recorded network to be unmeasured, but the edge began verifying clients one worker version before it began recording networks. Reads are now classed by their day, the same rule the rights evidence uses: before verification began they are not measured, from then on they are verified or named and not verified.

## [21.5.1] - 2026-10-04 — the Cited by list sits outside the signed content

### Fixed
- **The "Cited by" list no longer sits inside a note's signed content.** It was appended through `the_content`, which puts it inside the element the public ledger's checker reads against the signed text. No note has a public citation yet, so nothing failed; the first one would have turned that note's public check red, and anyone can cause a citation by linking to a note and sending a webmention. On a block theme the list is now placed after the post-content element, as its sibling. Run through the ledger's own extractor: inside the element the checked text changes, after it the text is identical.

