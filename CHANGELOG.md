# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Two résumés from one document: music (the public one) and operations (its own PDF, never linked).** Owner brief 2026-11-01. A résumé made outside the editor had drifted (it still said Juan runs Panacea Studio day to day), so the site now builds every version from the same entries.
  - **Shows in.** Every entry, role, education and affiliation row, publication and skills row has a choice: both (the default, what every row had), music only or operations only. Each role lists its saved bullets with the same choice, keyed by the bullet's text.
  - **New sections.** "What I've Built" (an intro and one card per project) and "Operations résumé": headline, subhead, location, extra links, summary, section order, experience order, earlier career one line per role, file name.
  - **Set up the operations résumé** writes the brief's appendix into the draft: settings, What I've Built, four skills rows, two affiliations, and "music only" on what the operations résumé leaves out. It finds rows by their text, never rewords them, lists what it cannot find, and on a second run only fills what is missing, never undoing an edit.
  - **Generate PDF builds both.** The operations PDF is served by WordPress at `/resume/JuanLentino_Resume_Operations.pdf` with `X-Robots-Tag: noindex, nofollow`, from bytes kept in an option, not a public file. It is linked from no page, sitemap, llms file or format list, and never prints the phone.
  - **Nothing public changes.** `sn_resume_doc_get()` now returns the music view in the shape every reader already knew, so /resume, its PDF, the Spanish CV, JSON Resume, Markdown and vCard see exactly what they saw before; the editor reads the stored document. The music PDF's HTML is identical before and after the operations setup (pinned).
  - **A check on every generation.** A built-in reader for Dompdf's output (held word for word to pdftotext) reads each PDF back. The PDF box flags control characters, ligature code points, a phone number in the text or metadata, an operations PDF over two pages, and a missing `X-Robots-Tag: noindex` on the operations address.

  Review fixes (independent review of #2344):
  - The setup only fills what is missing. A second run used to reset an edited operations summary and What I've Built, and turned skills rows added later into music-only.
  - The operations PDF never prints the phone because the template refuses it whenever the variant says none. One that reads back with a phone is never stored or served.
  - The metadata phone check decodes Dompdf's UTF-16 Info strings. It could not fire on a real file before.
  - The stored phone's digits are looked for in any format.
  - A read too short for a résumé is flagged.
  - The address is matched before the document is read, so other requests never pay for it.
  - The draft preview and the private copy use the music layout.
  - An operations generation that fails says so in the PDF box.
  - A blank operations section list falls back to the music order.
  - The file name pattern ends at `\z`.
  - The bullet choices say that editing a bullet's text resets its choice.
  - The "music unchanged" test is held to a hash of main's own output, not the new template. Pins kill the review's thirteen surviving mutants.

  Tests:
  - `tests/resume-variants.php` (41), `tests/resume-pdf-text-check.php` (18) and `tests/resume-ops-pdf-route.php` (6).
  - The existing résumé suites pass unchanged except for their pins on the new call signatures.

## [2026.11.5] - 2026-11-01 — Reverted the per-view scroll count; stored scroll per view is the weighted ratio again

### Fixed
- **Reverted the per-view scroll count from 2026.11.4 (#2340); stored scroll per view is the weighted ratio again.** Read live after the 2026.11.4 install: the stored `/about` on 2026-10-31 went from 162.5% to 0%.

  Analytics Engine samples a heavy visitor-day row by row. The dual-write check shows one human visitor-day's 2026-10-31 rows as 36 stored standing for 81, and its page views as 5 standing for 11. A view's pageview and scroll rows are therefore kept or dropped independently. Joining them by pageview ID drops the scroll of views whose pageview was sampled out, and scores views whose scroll was sampled out as 0, so the per-view figure runs low.

  The weighted ratio, 25 × Σ scroll weights / Σ pageview weights, is the estimator that holds under sampling. On a group of one or two views it can read above 100%, which is sampling noise, not lost pageviews. The daily roll rewrites the trailing seven days to the weighted ratio. Do not run the history recompute for this; no earlier day took the per-view count.

