# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.7.0] - 2026-09-16 — the essays cite as essays


- **Added:** pillar pages (`_sn_pillar` meta) carry an `Article` in the JSON-LD graph, as notes do: headline, dates, author, canonical, the provenance identifier when signed. The bridge's `get-citation` reads the page's own Article, and without one the site's most citable pages, the essays, answered "not a note"; search engines saw a plain WebPage for a 5,000-word essay. `sn_schema_is_pillar_page()` decides; a plain page still builds none.


