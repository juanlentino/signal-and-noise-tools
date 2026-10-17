# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Search Console cleanup: MailPoet's page and the feeds leave the index, and five old paths 301.** A request carrying `mailpoet_page` (the subscription page) now gets `noindex` in the robots meta, an `X-Robots-Tag: noindex` header, and no canonical; the canonical was this plugin's own, printing the page's `?mailpoet_page=` permalink. Every feed (`/feed/`, `/notes/feed/`, tag and page feeds, `/feed/json/`) sends `X-Robots-Tag: noindex, follow` and keeps working, `<link rel="alternate">` tags included. `/provenance/verify` goes to `/verify/`, `/es/` to `/inicio/` and `/es/about/` to `/sobre-mi/` through the 404-only old-page-path map (`inc/maturity-legacy-redirects.php`, which now matches whole paths), and `/sitemap/` joins `/sitemap.xml` on its way to `/wp-sitemap.xml`. `/es/notes/` stays a 404; `/notes/` has no Spanish page.

## [2026.10.32] - 2026-10-17 — the correction detector reads every correction notice


- **The correction detector sees every correction notice, not only the bold one.** `editorial_conventions` now matches a paragraph whose text opens "Correction," whatever wraps it (`<em>`, `<b>`, `<i>`, nested, or bare), so note 1514's italic colon-form notice would have been reported. A new form finding names a notice that is not in the house lead-in `<strong>Correction, Month D, YYYY.</strong>`, with no fix (signed prose; the owner applies it), and the class-only fix is now offered only when the lead-in is already right. The corpus-integrity scan also counts the colon form ("Correction, October 1, 2026: …") as a dated notice instead of date drift, while still judging a later date the notice text cites.


