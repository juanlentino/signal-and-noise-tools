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
- Five admin surfaces now meet core's list-table responsive contract instead of
  only claiming it. At `max-width: 782px` core turns every `.wp-list-table` row
  into a flex container sized by `column-primary` and labels each cell from its
  `data-colname` — which the bot-networks, cache-probe, anchor-status,
  scheduled-content and tag-consolidation tables never emitted, so on a phone
  the header painted over the first cell and "Path" over "/notes" read as
  `Paothes`. They now emit both, so they stack and label like every other table
  rather than losing the chrome. The anchor-status table's rows are built in
  JavaScript, so `assets/provenance-admin.js` carries the same two attributes.
  The one-cell "No scheduled content" notice has no columns to stack and drops
  the class instead. (#1015)

### Internal
- `tests/admin-table-mobile-contract.php` pins the contract, and its docblock
  records why it is scoped to the FILE. The first audit used a 60-line window
  *forward* from each `<table>` tag and reported eight violations; three were
  fabricated. `inc/analytics-panels.php` sets the primary class at line 714 and
  opens its table at 734 — above the tag, invisible to a forward window — and
  `inc/machine-readers-render.php` splits the header and body across five
  sibling functions, invisible to a function-scoped one. Neither window matched
  the contract, because the contract is "this table, wherever its cells are
  written". The guard states the one place file scope still cannot decide
  (a file holding both a list table and a plain `widefat` one) and prints its
  own coverage count, so a future narrowing shows as a number that moved rather
  than as silence. (#1015)

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

