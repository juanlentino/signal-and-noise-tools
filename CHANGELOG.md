# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.6.0] - 2026-10-05 — SN Systems and SN Provenance carry more

### Added
- **SN Systems and SN Provenance carry more of the story, as the owner approved.** Systems adds incidents over 30 days and the slowest monitor (from the uptime read it already makes), an Edge section (5xx on the newest day the rollup has covered against the day before, in words), cron runs recorded over 24 hours with any recorded failures named (a run that dies fatally leaves no record, so "0 failed" is never shown), and a Cache section (the last full purge and edge freshness). Provenance adds the integrity checks that pass (hash, twin, ledger, key; subjects not reached yet are said, never counted as passing), where the newest rights-evidence month stands and when a record was last posted, and DOIs minted. Every figure is a local read already stored, held five minutes; a source that cannot answer leaves its row out. Card budgets: Systems 580, Provenance 620.

### Changed
- **Gap analysis: gap 3 step 2 is shipped and the provenance work has a stop rule.** `docs/proposals/proving-the-provenance-thesis.md` records the author's countersigning key as live (ledger #41 to #43, Worker 1.25.0, plugin 22.5.0, the first batch attesting all 105 passing records), marks the second-active-key item resolved by 22.5.0, describes the author key in the README's Provenance section, and adds "Where the provenance work stops": the remaining gap 3 steps, gap 2, gap 4 and the custody move stay designed and unbuilt until a concrete need names one. Docs only.

