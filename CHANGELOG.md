# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.5.2] - 2026-10-04 — an Archive capture is confirmed by the capture itself

### Fixed
- **SN Machine Readers counted too many reads as "Not measured".** The widget took a read with no recorded network to be unmeasured, but the edge began verifying clients one worker version before it began recording networks. Reads are now classed by their day, the same rule the rights evidence uses: before verification began they are not measured, from then on they are verified or named and not verified.
- **An Internet Archive capture is confirmed by the capture, not only by the job.** All fifty older notes were captured and the plugin still read "0 captured, 50 waiting": the Archive's job status kept answering `pending` hours after the pages were in the Wayback Machine. When the job gives no answer, the hourly pass now asks the Archive for the newest capture of the note's own URL (in both spellings, with and without the scheme, since the Archive answers "none" for one form of a URL whose other form it holds) and counts it when it was made after the request (a capture from before it is someone else's crawl). A note is marked as having no outcome only when both reads answered; an answer that cannot be read keeps it waiting. A pass stops drawing notes after thirty seconds, so a slow Archive cannot hold the cron request.

