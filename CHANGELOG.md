# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The résumé editor says "On /resume: Shown / Hidden" instead of "Shows in: Both résumés / Music only / Operations only".** Since the one résumé (2026.11.30) the per-row choice only decides whether a row is on /resume, and the old words described two résumés that no longer exist. The stored values are unchanged ('' Shown, `music` Hidden, `operations` "Shown, not on the music PDF"), so nothing moves on the page. The Operations résumé setup now says it is a one-time starting point and that running it again hides rows; the section hint says the same. Classic and native forms (`inc/admin-forms/resume-variants.php`, `apps/sn-dashboard/parts/leaves/content-resume-variants.php`, `content-resume-sections.php`). Test: `tests/os-leaf-content-resume.php`.
- **The Spanish CV is stored text, so a Spanish update is never a plugin release again (owner, 2026-11-12).** /cv/ used to read `inc/seed-content/resume-data-es.json`, so every Spanish change shipped as a release. It now reads the option `sn_resume_doc_es`; the bundled file only seeds a fresh install (`sn_resume_es_source_json()`, `inc/resume-es.php`). The stale-CV alert and its Health check compare against the résumé /resume actually shows (`sn_resume_shown_doc()`), not the old music view.
- **/cv uses the one-résumé layout once its text carries operations settings**, as /resume does: kicker, headline, subhead, What I've Built ("LO QUE CONSTRUÍ"), "Créditos verificados" to /musica, the formats line to /cv.md and /cv/json-resume.json, and its own PDF in the same layout (`JuanLentino_CV.pdf`). The Spanish text as shipped has none, so /cv is unchanged until the Spanish text is written.

### Added
- **`signal-noise/resume-es-apply` on the write door.** Without a document it returns the Spanish text, its source, the English version code and the stale diff; with one it refuses a phone anywhere and a document that does not render, returns the line diff, and with `dry_run: false` stores it and re-renders /cv/ and its PDF (`inc/abilities-resume-es.php`). Test: `tests/resume-es-apply.php` (new); `tests/resume-one.php` covers the Spanish one-résumé render; the door's pinned lists move to 19 write tools.

### Fixed
- **The résumé PDF is two pages again.** With the earlier-career bullets back on /resume (owner, 2026-11-12) the PDF ran to three pages, and its own page check flagged it. The PDF now always shows earlier career as one line per role, whatever the page shows (`inc/resume-pdf/operations.php`); the editor's checkbox now says it governs /resume and that the PDF is always compact. Test: `tests/resume-ops-pdf-build.php`.

## [2026.11.31] - 2026-11-12 — Exact page views from the analytics worker's counter

### Added
- **Exact page views, from the analytics worker's own counter.** Analytics Engine samples a busy visitor-day when it writes it, so its figures are weighted estimates (on 2026-11-11 one visitor-day kept 7 page-view rows standing for 17). Analytics worker 2.2.0 counts every public page view in D1 and sends today and yesterday with each 15-minute refresh poke. The poke validates that block and holds it in memory. After the response, `inc/analytics-exact.php` and `inc/analytics-exact-reconcile.php` (both new) classify each visitor-day from Analytics Engine's own rows, with the same human rule and over-cap list every figure uses, in one read per day. They then store only per-day totals per class (`sn_analytics_exact_v1`). No visitor hash is stored or returned.
  - **A day is called exact only when nothing contradicts it.** It must be on or after the counter's first full day, with no read cut short and the over-cap list read whole. Every counted visitor-day must have exactly one class in Analytics Engine, and none may have fewer counted page views than Analytics Engine kept. Otherwise the reading says why, and the estimate stands.
  - **Where it shows, while the reading is under 45 minutes old:**
    - SN Traffic's Today so far
    - the Analytics header strip, human class
    - Overview Views today ("exact count")
    - `live-now` `today` with `today_exact: true`
    - /stats Views today (owner 2026-11-11), with the same label and the same public answer shape
  - Tests: `tests/analytics-exact.php` (new; the guards fail when the reconcile is forced exact) and `tests/analytics-refresh-rest.php`.

