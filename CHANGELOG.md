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
- **SN Systems says whose 5xx they were.** Beside the day's count, a row reads "Your dashboard · everything else": the session ping, the abilities and desktop polls, wp-admin and the OpenStation shell against the rest. On Oct 4 about 25 of the week's 30 were the dashboard's own. The count stays whole and stays amber; the split is counted from the day's top failing paths, so it never overstates the dashboard.
- **A save's purge is counted.** Since #1850 every save purges the theme's cache tag, which empties every tagged page, and the purge ledger recorded none of them, so "purges in the last 7 days" read low. A confirmed or queued tag purge is now a ledger row (`trigger: save`, `scope: tag`); a refused one records nothing.

### Documentation

- `AI.md`: corrected why Connector for TypeSafe Jev is not an `ai_provider`. Core clears an `ai_provider` key on save when no AI Client provider class is registered under that id, not because Jev is non-generative; the connector switches once the AI Client supports decision models (php-ai-client#296).

## [22.6.3] - 2026-10-06 — each card link closes the section it opens

### Fixed
- **SN Provenance: each link sits in the section it opens.** "Open Provenance" and "Open Machine Readers" sat together on the action row under both sections. Now "Open Provenance" closes the Provenance section and "Open Machine Readers" closes the Machine readers section, as SN Systems' links do. "Sweep now" stays on the action row, and a link whose section did not paint falls back to that row.
- **SN Systems: "Open Health" sits in the Health section, once.** It sat on the bottom row beside "Clear DB overrides", and a Health finding added a second "Open Health" inside the section. It now always closes the Health section, beside "Open Anthropic billing" when a check is paused.
  The card's two-minute repaint rebuilds its section links; a keyboard user on one now keeps focus on the rebuilt link instead of dropping to the page (Codex on #1927; the 22.6.2 section links had the same gap).

