# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The 24-hour grid sits beside the live block.** The grid is about 290px wide, so it no longer takes a row of its own: Right now is two columns that wrap, the last hour, its surge line and the live lists on the left, the grid with its chosen slot on the right. On a panel narrower than about 800px the grid drops under the live block as before. No scroller at any width.

## [2026.10.50] - 2026-10-19 — the 24-hour grid fits one glance; the JSON résumé carries its highlights


- **The JSON Resume carries the stats strip.** `meta.x-highlights` lists the page's highlights (`{n, label}`, blank ones dropped), so `/resume/json-resume.json` and `/cv/json-resume.json` hold every fact the page shows. JSON Resume's `meta` allows extra keys, and the `x-` prefix matches `x-provenance`. No stats, no key.
- **The last 24 hours under Right now is a compact heat grid that never scrolls.** It was a 24-row table of red numbers, most of them 0, that scrolled. Now each hour is a column (now at the right) and each 5-minute slot a small square, colored by its human readers with no number inside: zero is a quiet gray, and up to three steps, scaled to the window's busiest slot, start at the accent and deepen toward the text color, so each non-empty step keeps at least the accent's contrast in both themes. A slot that was never recorded is dotted, not a zero. The grid is about 140px tall and shrinks to fit the panel down to 320px wide. An hour axis every 6 hours marks now; a legend names the real steps; a line under it names the busiest slot and how many slots had readers. Each slot is still the link that opens its pages, sources and traffic classes, now named for screen readers as its time range and count ("18:15 to 18:20, 3 human readers"), with the same text shown as a hint on hover and focus. The classic page and the native Overview share the one painter.


