# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### New
- **A north star: weekly engaged readers.** One number for what the site is for: human visitors, counted per day, who read at least one core page (notes, provenance, resume, about by default) past 50% scroll OR 30 seconds. Listings (the notes index, its pages, tags, feed) never count. It heads S&N Home with the change against last week and the three-week average, and under it three layers adapted from the vanity-versus-business-metrics frame to a site that sells nothing: **intent** (deep readers of 2+ core pages, `download`/`outbound` actions, resume and contact visits), **return** (readers per note published over four weeks; DOI downloads and inquiries are named and show "not wired yet", never a zero), and **inputs** (notes published, RSS readers, search clicks). The sections and both floors are settings (Measurement › Analytics › North star). Read ability `signal-noise/north-star` (read door 49 to 50), a desktop widget `SN North Star`, a one-hour cache so polls never re-query the Analytics Engine. The visitor hash rotates daily, so a reader on two days counts twice; the card says so. `tests/north-star.php` pins the tally, with negative controls on the OR and on the intent count.
- **`tests/resume-integrity.php` pins the resume copy against the schema.** The rendered /resume page carries no space before punctuation, every Person `award` appears in the visible copy (the schema never claims what the page does not show), and the years figure is 15+ in the stat and the summary. It runs the real renderer over the seed; the live document is a database option. Fails on the previous seed (20+ years, no Valedictorian line), passes now.

### Changed
- **The resume seed matches the live resume.** Stat and summary read 15+ years, Full Sail carries "Valedictorian · Advanced Achiever Award", and "Provenance as Substrate" reads "SSRN Working Paper · May 2026 · In submission, Journal of the Audio Engineering Society". The live document was updated through the form on 2026-09-25; the seed only fills a fresh install. `docs/RESUME-PDF.md` now says where resume content lives: one document, the single source for the page and the generated PDF.

## [18.6.2] - 2026-09-25 — the resume download row is the button alone

### Changed
- **The resume hero's download row is the Download PDF button alone.** The filename link beside it read RESUME (the saved PDF link label), repeating the page's own title right above. The page builder now writes core/file's own no-filename shape: one button, no dangling `aria-describedby`. The PDF link label field is gone from both resume forms, the document schema and the seed; a label saved before is ignored. The live page picks this up on the next resume save or PDF generation, since the page is rebuilt from the form.

