# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.7] - 2026-11-02 — Two lanes on /resume: Music and Operations & AI Strategy, receipts, and a JSON Resume per lane

### Added
- **/resume has two lanes: Music and Operations & AI Strategy.** Owner brief 2026-11-01 #2. Once the operations résumé is set up, the English /resume carries both résumés behind a switch under the page title ("Two doors, one standard." beside it, verbatim from /services). Each lane is the full résumé from its own view of the document: its headline and subhead over its summary, its own Download (the music PDF, or the operations PDF), and its own bands. The operations lane shows "What I've Built", its experience order, earlier career without bullets, and only the sections it lists, numbered as they fall. The music lane is the music résumé as it was, with its headline added over the summary. /cv (Spanish) and a document with no operations settings keep the single body, unchanged.
  - **Both lanes are in the stored page; `?lane=` only hides one.** `/resume/?lane=operations` renders on the server, so it works with no script: a render filter (`inc/resume-page-lane-render.php`) sets `hidden` on the other lane and `aria-current` on the switch's current link, and labels the switch. It adds attributes only, so the page's text is the same whichever lane shows: if /resume is ever signed, both addresses read as one record, and /resume/ is the canonical of both. Unknown or missing `lane` is the music lane and is never echoed. The theme's script switches in place, and its stylesheet draws the switch (the theme's matching release).
  - **The operations PDF is linked now.** Its lane's Download is the link, and the formats list (`pdf_operations`, `json_resume_operations`), the agents manifest, the Markdown front matter and llms.txt name it. It stays noindex and out of every sitemap. The text that said it is never linked says where it is.
- **Receipts.** A quiet mark beside a claim on the English /resume that links the page backing it: `verify` (the issuer's verification page), `paper`, `code`. From the links already stored: the MBA's Parchment page (an education row's Verification URL), the SSRN papers, can-i-apply and Sealed Record ("What I've Built"). New fields carry more: experience and earlier entries take a Receipt URL and type (verification, publication, repository, membership), and each saved bullet takes one beside its "Shows in" (`bullet_receipts`, keyed by the bullet's text, so editing a bullet drops its receipt). https only; no URL, no mark. The PDFs, JSON Resume work entries and /cv show none. Both editors (classic and native) carry the fields.
- **JSON Resume per lane.** `/resume/json-resume.json?lane=operations` is the operations résumé: its headline and summary in `basics`, its order and sections, its extra links in `profiles`, and "What I've Built" as `projects`. `meta.x-lane` names the lane once /resume has two. Without `lane`, or with anything else, it is the music résumé as before.

### Changed
- The draft's page preview renders the page as it would be, one lane or two.
- **/resume re-renders once after the update** (`SN_RESUME_ENGINE_REV` 4, on the next admin page load), so the two lanes and the receipt marks reach the live page without waiting for a save. Install the theme's matching release first, so the switch is styled when the page re-renders.

  Review fixes (independent review of #2346):
  - The operations résumé never carries the phone: the music PDF's "include the phone" switch reached `basics.phone` in `/resume/json-resume.json?lane=operations`. Now the operations lane clears it, and the page never showed it.
  - The operations lane shows its Download only once the operations PDF exists (`sn_resume_ops_pdf_live()`), never a link to a 404. Every operations generation syncs /resume again (Generate syncs the page before it builds that file), and a PDF withdrawn for a phone drops its facts too, so nothing links it.
  - The format list and llms.txt name the operations files only while the operations résumé and its PDF both exist.
  - The single-body tests are held to main's own output (sha1 of 0ef1762f's English body without the marks, and its Spanish body), not to this code.

  Tests: `tests/resume-page-lanes.php` (31: one body or two, the render filter on core's own HTML API, the provenance normalizer reading the same text for both lanes, receipts, the operations JSON Resume; nine mutants checked red). Updated: `tests/resume-draft.php`, `tests/resume-variants.php`, `tests/os-leaf-content-resume.php` (the receipt fields match across both editors), `tests/admin-class-orphans.php` (the theme styles the new front-end classes).

