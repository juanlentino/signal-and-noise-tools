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
- **The last 24 hours under Right now is a compact heat grid that never scrolls.** It was a 24-row table of red numbers, most of them 0, that scrolled. Now each hour is a column (now at the right) and each 5-minute slot a small square, colored by its human readers with no number inside: zero is a quiet gray, and up to three steps, scaled to the window's busiest slot, start at the accent and deepen toward the text color, so each non-empty step keeps at least the accent's contrast in both themes. A slot that was never recorded is dotted, not a zero. The grid is about 140px tall and shrinks to fit the panel down to 320px wide. An hour axis every 6 hours marks now; a legend names the real steps; a line under it names the busiest slot and how many slots had readers. Each slot is still the link that opens its pages, sources and traffic classes, now named for screen readers as its time range and count ("18:15 to 18:20, 3 human readers"), with the same text shown as a hint on hover and focus. The classic page and the native Overview share the one painter.

## [2026.10.49] - 2026-10-19 — a résumé panel in Machine readers; /cv PDF downloads counted

### Added
- **Which crawlers and agents read the résumé, and in which format.** The Machine readers view, classic and native, gains a Résumé panel: machine reads per format (HTML page, Markdown, JSON Resume, PDF, vCard, page JSON) with the top readers of each, from the rights-signals sensor's new `resume` surface class and `resume_format` column (sn-rights-signals-worker #100). Beside them, the people who downloaded the PDF in the last 7 days, from the North Star. The page links only the PDF, so the other formats count machines only, and the panel says so. `resume` joins the surface allowlist (12 classes); it is not a rights surface. This site's own tools, control tokens and impostors move no count, as on every card.

### Fixed
- **A PDF download on /cv now counts as a résumé download.** The North Star's résumé downloads matched `/resume` only, so the Spanish résumé's PDF never counted.

