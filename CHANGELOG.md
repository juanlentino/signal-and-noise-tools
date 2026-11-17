# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.
- **Preview PDF previews the résumé PDF.** With résumé settings in the draft it streams the one résumé's layout (two pages, What I've Built, verified credits), the file Publish would build, instead of the music layout nothing publishes (`sn_resume_pdf_draft_parts()`, layout shared with Generate through `sn_resume_ops_pdf_layout()`). Test: `tests/resume-ops-pdf-build.php`.

## [Unreleased]

### Added
- **Two observe-only session flags: agent pairing and direct to contact.** Both join by time (and page) only, never by visitor code, place or network, and subtract nothing.
  - `paired`: a human session whose source is (direct) and that overlaps in time with, or starts or ends within 60 seconds of, a bot- or suspect-class page view. The read is one row per second of bot- and suspect-class page views. The Sessions tab adds "near an automated hit" beside the source, and the live readers list marks a paired reader the same way.
  - `contact_direct`: a human session whose source is (direct), whose entry page is a contact page (`/contact` or under it) or whose second page is one after an entry view under 10 seconds, and in which no contact form was submitted. The contact form sends no beacon event, so a submission is an AllTerrain Forms entry (inbox, read, spam or archived; not a partial save) created between the session's start and 60 seconds after its end. The Sessions tab adds "direct to contact, nothing sent".
  - The sessions ability returns both on each session (null for other classes, or when its read failed) and `flags_by_day` (sessions, direct, paired and contact_direct per site day over the whole window; ask from six days ago for the last 7 days). A flag that was not read is null on every day, and the tab's header says so. When a wide window's hits read caps, the page reads its own span, so only the per-day count goes null.
  - Code: `inc/analytics-pairing.php`, `inc/analytics-contact-direct.php`. Tests: `tests/analytics-pairing.php` (Niigata and Yerevan, the 60-second edge, a 61-second miss), `tests/analytics-contact-direct.php` (Niigata, Yerevan, Vilnius, a referred session, a session with a submission), `tests/analytics-sessions-paired.php`.
- **Returning to an open tab.** A visitor-day that sends engagement rows now but has no page view since its New York midnight (a tab opened before the visitor code rotated) is counted apart in the admin live answer as `open_tab`, taken out of `now`, the human class and the Overview's Reading now, and its line in Readers now reads "returning to an open tab" with the page it is on. Before this they counted in `now` and listed as a reader with no pages. The public answer is unchanged. The hour bars, stored slots, surge and Being read now still count them. Code: `inc/analytics-live-open-tab.php`. Test: `tests/analytics-live-open-tab.php`.

### Changed
- **Sampled page views say so.** The session read selects Analytics Engine's sample interval, and each session gains `pageviews_stored` and `pageviews_est` (`pageviews` stays the stored rows). The Sessions tab gains a Page views column that reads "about N, from M stored" when a visitor-day was sampled, and the sampled-today note uses the same wording. Remote contract 33 to 34 (additive); the Worker's `CONTRACT_VERSION` and its fixture copy follow separately.
- **The live-now description says what `today` is.** Since analytics worker 2.2.0, `today` is the exact D1 count when `today_exact` is true, else Analytics Engine's weighted sum. `today_exact` and `open_tab` join its output schema.

## [2026.11.46] - 2026-11-15 — The Shares note describes how shares are joined

### Fixed
- **The Shares note and the sessions ability say how shares are joined now.** The note under Links › Shares still said a Meta fetch counts only on an `/r/` link and that shares are joined by page and time only. It now says a visit whose source names an app counts only a share through that app, a share of an `/r/` link stays on the link when the app then fetches the page it names, and Meta's preview renderer, recognized by its network, counts on any page while its crawler counts only on `/r/` links. The `share` field in the sessions ability description now names the app match; its output schema, which the remote contract hashes, is unchanged.

