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
- The MCP read door now validates arguments against the ability's declared
  input schema, as the write door and the REST run-route already did. One
  declaration was being enforced three different ways depending on the door,
  and on the read door not at all — while every read ability's docblock said
  "Validated against input_schema above". An undeclared argument is now a
  -32602 naming the key, not a silent drop. (#986)

## [13.96.0] - 2026-09-04 — the populations that were never complete

### Added
- `sn-posts` accepts `status` and `fields`. Both were previously ignored in silence: a caller passing a filter got the whole corpus and a success, indistinguishable from a filter that matched everything. An unknown field name is now a 422 naming it and listing the valid ones, and the `post_ids` scope reports `filtered` separately from `missing` — a post that exists but does not match the status is not the same as one that does not exist.

### Fixed
- The contrast health check now enumerates every stylesheet under `assets/` at
  any depth. It globbed `assets/*.css`, so the public verify route's
  `assets/css/prov-verify.css` was never scored while the check reported clean.
  `analytics-tokens.css` / `analytics-widget.css` are declared admin-only, which
  the widened population made visible for the first time. (#988)
- Guards that sweep the plugin's PHP now walk `inc/` at any depth. Fourteen
  enumerated it with `inc/*.php` or a hand-listed package, so 86 files - 17% of
  the tree - were invisible to them, and none announced the narrowing.
  `tests/lib/inc-population.php` is now the single source of that population and
  `tests/inc-population-guard.php` fails if a suite builds its own. (#987)
- `tools/editor-api-smoke.php` derives its editor requirements from the whole
  tree. It globbed `inc/*.php` and `assets/*.js`, so a `wp-*` handle or `wp.*`
  symbol whose only declaration sat inside a package was not a requirement -
  against the tool's own promise that nothing is hand-maintained. Measured: no
  handle or symbol was actually lost, so this closes a latent gap. (#992)

