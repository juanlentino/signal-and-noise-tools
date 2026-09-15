# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.9.2] - 2026-09-15 — the firewall refusal keeps the API's sentence and probes the account path

### Fixed
- **The firewall reading's hint named grants the docs never state (#1316).** `firewallEventsAdaptiveGroups` refused the zone path under Zone › Analytics › Read, then still with Firewall Services › Read and Logs › Read added (2026-09-15), and Cloudflare's docs name no grant for the dataset. The banner now keeps the API's own sentence, says the grant is undocumented and which three were tried, and on refusal the refresh probes the account viewer path (one GET for the zone's account id, one GraphQL with the zone as a dataset filter) and records which path answered; when the account path answers, the reading is taken from it and marked `path: account`. The one grant left in Cloudflare's "Read analytics and logs" template is Account › Account Analytics › Read, named as a candidate, not a claim. Pins: the refused reading keeps the sentence; a refusal costs one extra GET and, with an account id, one extra query; an answering account path fills the reading.

