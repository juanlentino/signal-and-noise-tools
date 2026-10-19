# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.48] - 2026-10-19 — the résumé in five formats, with its credentials and a verify link


- **Saving the résumé no longer rewrites an unchanged page.** The English résumé sync called `wp_update_post` on every save, even when the rendered body was identical. The page is signed, so a save that only changed data the page does not show (a credential's verification URL) could still touch it. It now skips an identical body, as the Spanish sync already did.

### Added
- **The résumé as Markdown written for AI readers.** `/resume.md` (and `/cv.md`) is a purpose-built Markdown résumé instead of the generic page conversion, which began with "Skip to content", split each stat from its label and turned the footer icons into empty links. It opens with YAML front matter (name, headline, location, the page URL, the last change, the public email and profiles, every format, and the authorship proof with what it means), then Summary, Highlights, Experience, Research & Publications, Education, Memberships & Credentials, Skills and Languages, with ISO dates and no tables. It is built from the JSON Resume document, so the two cannot disagree on an employer or a date. `/resume/?output_format=markdown` now returns the same file, and the résumé page sends a `Link` header naming it so the edge can serve it to `Accept: text/markdown` readers too.
- **A contact card.** `/resume/contact.vcf` is a vCard 3.0 with the name, headline, the public email, Orlando, FL, the site and the profiles, plus the résumé and verify links. It holds only what the résumé already publishes: no phone unless the owner made it public.
- **Degrees and certificates in the structured data.** The Person schema now lists each degree and certificate on the résumé as a schema.org `hasCredential` (`EducationalOccupationalCredential`, the university as `recognizedBy`), read from the résumé itself. The Recording Academy memberships were already there as `memberOf`.
- **A credential can point to the issuer's own verification page.** Each Education and Affiliations entry in the résumé editor (the classic form and the dashboard) has an optional Verification URL, https only. When set, the schema.org credential gets it as `url`, the JSON Resume carries it (a certificate's own `url`, `x-credentialUrl` on a degree or membership), and `/resume.md` prints a `Verify:` line under the entry. A membership with one also becomes a `hasCredential`. Blank changes nothing. The MBA now points to its Westcliff University diploma on Parchment in the shipped English and Spanish documents; the live English résumé gets it once the owner pastes the URL into the MBA's Verification URL field and publishes.
- **The agents manifest lists the résumé's formats** (HTML, Markdown, JSON Resume, PDF, vCard, and the Spanish ones), and the résumé pages link the Markdown and the vCard as alternates.
- **The résumé as JSON Resume.** `/resume/json-resume.json` (and `/cv/json-resume.json` for the Spanish résumé) serve the résumé in the open [JSON Resume v1.0.0](https://jsonresume.org/schema) format that résumé tools and themes read. It is built from the same document as the page and the PDF, so the three cannot disagree: work (one entry per role, earlier career included), education, the Recording Academy memberships and the Full Sail honors as awards, certificates, publications, skills, languages, and the profiles from the site's structured data. Dates are read in English and Spanish; a date that does not parse is left out, never guessed. Contact details are only the ones the public PDF already shows. `meta` carries the page URL, its last change and the page's own authorship proof (`x-provenance`: the verify link and note ID). The résumé pages advertise it with a `<link rel="alternate">`. The existing `/resume.json` page twin is unchanged. A new suite, `tests/resume-jsonresume.php`, validates both résumés against the official schema, vendored with its MIT license under `tests/fixtures/json-resume/`.

