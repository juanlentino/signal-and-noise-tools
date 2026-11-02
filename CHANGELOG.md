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
- **A CI flake in `tests/mcp-telemetry-read.php`** (62/63 on the 2026.11.8 cut, green on rerun). Four checks rebuilt a timestamp, to the second, with `$ago()` after the fixture had built it, so a second ticking between the two failed them. They now compare the fixture's own value. A forced 1-second pause reproduces CI's 62/63 on the old test and passes the new one.
- **A résumé PDF with a page the check could not read is no longer passed as clean.** The built-in reader counts the file's pages and reads each one's content stream; a page it could not read flags "only N of M pages could be read", a stream that would not inflate flags "N streams could not be inflated", and an Info dictionary it could not find flags "the metadata could not be read" (it was skipped, so a phone there went unchecked). Before, page one alone was over the 1,000-character floor and the rest went unseen. The flags moved to `inc/resume-pdf/check.php`. `tests/resume-pdf-text-check.php` (+3).
- **An indirect `/Length 99 0 R` is no longer read as a length of 9.** The pattern backtracked to the first digit; it now takes the whole number, so the reader falls back to the `endstream` marker as intended. `tests/resume-pdf-text-check.php` (+1).
- **Three numbers on three lines are no longer a phone number.** The phone pattern let a line break separate its groups, so a stats row ("250", "300", "2024") flagged; the groups must now share a line, and a no-break space counts as a space. `tests/resume-pdf-text-check.php` (+2).
- **An operations PDF withdrawn for a phone number is purged at the edge.** Withdrawal deleted the stored file and its facts but skipped the Cloudflare purge, so the edge could keep serving the last copy. `tests/resume-ops-pdf-build.php` (new, 17), which runs `sn_resume_ops_pdf_build()` and `sn_resume_ops_pdf_generate()` through Dompdf and the check: before it, disabling the phone withdrawal or the noindex check failed no suite.
- **The operations PDF's bytes are read back after they are stored.** The write's result was never checked, so a file that failed to store (a large value can) was reported as generated while the old one was served. Generate now compares the stored bytes with the rendered ones (not `update_option()`'s return, which is false for an unchanged value too) and records a failure; `sn_resume_ops_pdf_live()` also requires a stored PDF, not only its facts.
- **Removing the operations résumé's settings removes its PDF.** Generate left the old file and its facts in place, still linked. It now deletes both.
- **An operations résumé with no experience and no publications is not built.** The last good file stays, and the PDF box says why.
- **The PDF box says what happened to the operations PDF.** After a failed Generate it said "the last Generate failed" and also "set up; Generate builds it"; it now says whether the earlier file is still the one served. A file with a flag other than a phone says it stays published, since only a phone number withdraws it (the `sn_resume_ops_pdf_live()` docblock said any failed check did). A draft that holds the setup reads "set up in the draft" rather than "not set up yet", and the generated line reads "linked from the Operations lane on /resume". The box lines moved to `inc/resume-pdf/operations-box.php`; `sn_resume_ops_config()` names the published operations layout in one place.

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

