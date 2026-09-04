# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.96.3] - 2026-09-04 — the instrument that could not see a server error

### Fixed
- Edge analytics can see a 5xx. The attack-surface probe filters
  `edgeResponseStatus_geq:400 … _leq:499`, so our own reporting was
  structurally blind to a server error — fourteen assets failed with HTTP 503
  and nothing recorded it. A separate query now collects 5xx as `err_path` and
  `err_source`, the latter carrying `originResponseStatus` so the responder is
  named: `edge=503 origin=503` is the origin failing, `edge=503 origin=-` is
  Cloudflare or a Worker answering alone. The 4xx probe is unchanged — a server
  error is not scan pressure. (#1002)

