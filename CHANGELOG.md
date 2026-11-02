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
- **The JSON Resume split a skill at a comma inside parentheses.** "Content Provenance Standards (C2PA, TDMRep, RSL)" reached agents as three keywords, the last two "TDMRep" and "RSL)", on both lanes; the operations résumé's "cross-border market entry (U.S., Latin America)" as two. Skills now split on commas outside parentheses only. The Markdown joins the keywords back with commas, so it read right all along.
- **The operations JSON Resume put "(open to relocation)" in the region.** `basics.location` read city "Orlando", region "FL (open to relocation)". A trailing parenthetical now rides in `meta.x-locationNote`, the region reads "FL", and the Markdown front matter still says "Orlando, FL (open to relocation)".
- **The JSON Resume route could be indexed and named no canonical.** `/resume/json-resume.json` (and `?lane=operations`) now answers `X-Robots-Tag: noindex` and `Link: <https://juanlentino.com/resume/>; rel="canonical"`, the headers the Markdown and the vCard already sent, from one shared set.
- **The JSON Resume alternate ignored the lane.** On `/resume/?lane=operations` the page's `<link rel="alternate" type="application/json">` named the music JSON Resume; it now names `?lane=operations`, as the Markdown alternate already did.
- **The format list did not name the operations Markdown, and its front matter did not name its lane.** `markdown_operations` joins the format list (front matter and agents manifest) while the operations PDF is live, the operations Markdown says `lane: "operations"`, and no Markdown section prints an empty heading any more (Experience, Education, Memberships & Credentials, Skills, Languages were unconditional).
- **The agents manifest claimed an authorship proof /resume does not have.** Its résumé entry said "The page carries a Bitcoin-anchored authorship proof" unconditionally; it now says so only when the page has an anchored version, as the Markdown and the JSON Resume already did.
- **The two résumés on /resume had no names a screen reader could find.** Each lane is now a region named "Music résumé" or "Operations & AI Strategy résumé", set at render time like `hidden`, so the stored page is unchanged.
- **The music lane's page document kept the phone fields.** Only the operations lane's were blanked; both are now. Nothing public changed: the page never printed them, and the music PDF and JSON Resume read the stored document.
- **A résumé save left agents reading the old copies.** `/resume.md`, `/resume/json-resume.json` (both lanes of each), `/resume/contact.vcf`, `/cv.md` and `/cv/json-resume.json` are cached at the edge untagged, so the tag purge never reached them. A save of /resume or /cv now purges them by URL, on every save path.
- **The résumé editor said the PDF-only fields never show on /resume.** Since the two lanes, the Headline and Tagline head the Music lane. The PDF only hint (classic and dashboard), the Operations résumé hint ("the switch links both PDFs"), the docblocks and `docs/RESUME-PDF.md` now say what the page shows, and the docs catch up on drafts, Publish and Revert rebuilding the PDF, the receipts' https rule, the check's read flags and the test list. The page itself is unchanged.

## [2026.11.8] - 2026-11-02 — The résumé's Markdown follows the lane: /resume.md?lane=operations

### Changed
- **The résumé's Markdown follows the lane.** `/resume.md?lane=operations` (and `?output_format=markdown` on the operations lane) is the operations résumé: its headline and summary, What I've Built, its experience and sections, and no phone. On `/resume/?lane=operations` the page's `Link` header and head alternate name it, so an agent asking that address for Markdown gets that lane. The rights-signals Worker 1.37.2 reads the lane query; until then it converts the visible lane, as of 1.37.1. A section with nothing in it is left out of the Markdown. The music Markdown is unchanged byte for byte, pinned to main's output. `tests/resume-page-lanes.php` (+4).

