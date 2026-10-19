# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The JSON Resume carries the stats strip.** `meta.x-highlights` lists the page's highlights (`{n, label}`, blank ones dropped), so `/resume/json-resume.json` and `/cv/json-resume.json` hold every fact the page shows. JSON Resume's `meta` allows extra keys, and the `x-` prefix matches `x-provenance`. No stats, no key.

## [2026.10.49] - 2026-10-19 — a résumé panel in Machine readers; /cv PDF downloads counted

### Added
- **Which crawlers and agents read the résumé, and in which format.** The Machine readers view, classic and native, gains a Résumé panel: machine reads per format (HTML page, Markdown, JSON Resume, PDF, vCard, page JSON) with the top readers of each, from the rights-signals sensor's new `resume` surface class and `resume_format` column (sn-rights-signals-worker #100). Beside them, the people who downloaded the PDF in the last 7 days, from the North Star. The page links only the PDF, so the other formats count machines only, and the panel says so. `resume` joins the surface allowlist (12 classes); it is not a rights surface. This site's own tools, control tokens and impostors move no count, as on every card.

### Fixed
- **A PDF download on /cv now counts as a résumé download.** The North Star's résumé downloads matched `/resume` only, so the Spanish résumé's PDF never counted.

