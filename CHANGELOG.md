# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.109.10] - 2026-09-10 — auto-fit, so four tiles fill the row

### Fixed
- Three leaves painted their status tiles into half a row. `.snt-systems` used `repeat( auto-fill, … )`, and **`auto-fill` keeps the empty tracks it creates** — so a row with fewer items than tracks leaves the remainder blank instead of letting the items grow. Measured live 2026-09-10 on AI → MCP Clients: a **1,702px row held eight 202px tracks for four tiles**, so the tiles sat at 202px and the right half of the section was empty. `auto-fit` collapses the empty tracks; the same four tiles measure **417px and span the full row**. It was the lone `auto-fill` in the codebase against three `auto-fit`, and it is shared by AI → MCP Clients, Connections → Cloudways and the Dashboard's own Systems row.

