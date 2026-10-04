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
- **An Internet Archive capture is confirmed by the capture, not only by the job.** All fifty older notes were captured and the plugin still read "0 captured, 50 waiting": the Archive's job status kept answering `pending` hours after the pages were in the Wayback Machine. When the job gives no answer, the hourly pass now asks the Archive for the newest capture of the note's own URL and counts it when it was made after the request (a capture from before it is someone else's crawl). A note is marked as having no outcome only when both reads answered; an answer that cannot be read keeps it waiting. A pass stops drawing notes after thirty seconds, so a slow Archive cannot hold the cron request.

## [21.5.1] - 2026-10-04 — the Cited by list sits outside the signed content

### Fixed
- **The "Cited by" list no longer sits inside a note's signed content.** It was appended through `the_content`, which puts it inside the element the public ledger's checker reads against the signed text. No note has a public citation yet, so nothing failed; the first one would have turned that note's public check red, and anyone can cause a citation by linking to a note and sending a webmention. On a block theme the list is now placed after the post-content element, as its sibling. Run through the ledger's own extractor: inside the element the checked text changes, after it the text is identical.

