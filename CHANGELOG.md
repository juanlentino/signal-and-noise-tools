# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The connector is Tribar Connector for TypeSafe Jev now.** The WordPress.org directory review asked for a less generic name, so the connector's 0.4.6 renamed it (slug, text domain and folder `tribar-connector-for-typesafe-jev`; repo `juanlentino/tribar-connector-for-typesafe-jev`, GitHub redirects both older URLs). Its PHP API did not change (`JevConnector\ask()`, `Connector::get_api_key()`, the `typesafe` id, the setting names), and nothing here depends on its folder or slug, so only words and links move: every "Install … and add the key under Settings › Connectors" message (`SN_JEV_NOT_READY`, the tag-fit flash, the Tag consolidation and Search fit bands, the Dashboard's Jev budget and Content › Tags sections), the docblocks in `inc/typesafe-client.php`, and the links in `README.md` and `AI.md`. The two suites that pin the message and the connector stub's docblock follow.

## [2026.10.69] - 2026-10-26 — tables are cards on a phone

### Fixed
- **Tables are cards on a phone, everywhere.** The owner opened S&N Analytics › Posts on a phone: three columns crammed into 375px, and the "Not indexed" pill broke a letter per line. Three causes, one fix each. Every report table S&N Analytics builds (`apps/sn-analytics/native-tables.js`, all 15 tabs) now carries OpenStation's `stack-on-phone`, so each row is a card with its first column as the title and the rest as labelled lines (OpenStation 1.1.16+; older versions ignore the attribute and keep the grid). `snt_kit_table()` makes the same choice by default, so the 32 Dashboard tables that never asked for it now stack too; `'stack_on_phone' => false` keeps a grid. A pill never breaks inside a word (it wraps at spaces, so a sentence-long pill still fits). A stacked table has no header row, so a table's filter box is gone on a phone (Content › Scheduled posts' Title filter is the one this newly reaches). And the hour heatmap's screen-reader companion (and the reader-evidence charts') stays hidden: the native wrapper had turned it into a visible 25-column grid.

