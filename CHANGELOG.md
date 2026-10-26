# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.70] - 2026-10-26 — the connector is Tribar Connector for TypeSafe Jev

### Changed
- **The connector is Tribar Connector for TypeSafe Jev now.** The WordPress.org directory review asked for a less generic name, so the connector's 0.4.6 renamed it (slug, text domain and folder `tribar-connector-for-typesafe-jev`; repo `juanlentino/tribar-connector-for-typesafe-jev`, GitHub redirects both older URLs). Its PHP API did not change (`JevConnector\ask()`, `Connector::get_api_key()`, the `typesafe` id, the setting names), and nothing here depends on its folder or slug, so only words and links move: every "Install … and add the key under Settings › Connectors" message (`SN_JEV_NOT_READY`, the tag-fit flash, the Tag consolidation and Search fit bands, the Dashboard's Jev budget and Content › Tags sections), the docblocks in `inc/typesafe-client.php`, and the links in `README.md` and `AI.md`. The two suites that pin the message and the connector stub's docblock follow.

