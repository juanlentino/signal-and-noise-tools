# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.96.5] - 2026-09-04 — the property that was necessary and not sufficient

### Fixed
- Admin tables no longer print their own header over their first column, and no
  longer hide every column after it. This retracts the diagnosis in v13.96.4:
  the defect was never a MISSING `column-primary`, it was `data-colname` on the
  primary cell. Core's only `::before` rule at 782px targets every non-check
  `td` — the primary included — and absolutely positions `content:
  attr(data-colname)` at `left: 10px`, while the 35% left padding that would
  make room for it is scoped to `td.column-primary ~ td`. So the label lands on
  the cell's own text: "Top sources" over "(direct)". Worse,
  `td.column-primary ~ td { display: none }` hides the remaining columns until a
  row gets `.is-expanded`, which only core's `.toggle-row` disclosure button
  sets — and we render none. Fifteen tables across ten files drop
  `wp-list-table` and keep `widefat striped`; they are readouts, not list
  tables. Twenty primary cells stop labelling themselves. (#1021)
- The iPhone home-screen tile is no longer a black square. v13.96.4 fixed the
  web app MANIFEST, which is Android's path; iOS reads
  `<link rel="apple-touch-icon">`, which OpenStation prints into `admin_head`
  from `get_site_icon_url( 180 )` — the Site Icon, PNG colour type 6 with 61.6%
  transparency, which iOS composites to black behind a mark measuring luminance
  23/255. `openstation_pwa_apple_touch_icon_url()` has no filter, so the seam is
  core's `get_site_icon_url`, scoped to admin and size 180 only. The browser-tab
  favicon keeps its transparency. (#1022)

### Internal
- `tests/admin-table-mobile-contract.php` is rewritten. The version shipped in
  v13.96.4 pinned "every table wearing `wp-list-table` also emits
  `column-primary`" — a property that is necessary for core's mobile layout and
  sufficient for nothing, since emitting `column-primary` is precisely what
  turns the column-hiding rule on. It was green on the broken markup, and the
  change it guarded ADDED `data-colname` to five more primary cells. The guard
  now asserts what we actually build: no table claims the class without a
  `.toggle-row`, and no primary cell labels itself. Verified red against the
  v13.96.4 tree it previously certified. (#1021)

