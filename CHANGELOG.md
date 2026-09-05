# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- A 23rd health check: the plugin registry against `active_plugins`. The
  OpenStation Plugins window showed an empty installed list on a phone AND a
  desk, cleared only by remounting; it was chased as a client bug and was not
  one. That window prints `Could not load plugins: <error>` whenever its fetch
  fails, and no such message appeared — so `GET /wp/v2/plugins` had answered
  **zero plugins with a 200**. `WP_REST_Plugins_Controller` reads
  `get_plugins()`, which is memoised through the object cache, so a stale entry
  reports "no plugins installed" in exactly the shape of a site that has none,
  and every consumer believes it. `active_plugins` is a plain option and cannot
  fail for the same reason, which makes it a usable oracle: every basename in it
  must appear in the registry. Two findings, not one, because there are two
  repairs — a file present on disk but unregistered means the CACHE is wrong
  (`wp cache flush`), while a file absent means the PLUGIN is gone (deactivate
  the orphan). Surface `health`: it is a defect, it reaches zero and stays there,
  and no other surface owns it. (#1026)

- A runtime probe beside it. The poisoning is TRANSIENT — it can be served, be
  seen by a person, and be gone before the next scheduled scan, so a scheduled
  check alone would report a clean site for a fault someone watched happen.
  `rest_request_after_callbacks` now notices when `GET /wp/v2/plugins` answers
  an EMPTY collection with a success status while plugins are active, and
  writes down the time and the active count. The health check reports that
  observation for seven days, stating plainly that it is the observation and
  not the current state, then lets it expire so the check can reach zero again.
  It records and never repairs: flushing a cache from inside a read request
  would destroy the evidence. (#1026)

### Internal
- The check reports `skipped` rather than a silent zero whenever it could not
  run — `get_plugins()` unavailable, `active_plugins` unreadable, no active
  plugins to compare against, or a non-array registry. Its suite pins the split
  that matters: the two notes must never be the same sentence, since that is
  precisely what made the original incident unreadable. (#1026)
- Nine docblocks still described the admin tables as `.wp-list-table` after
  v13.96.5 removed the class from all fifteen of them. Prose drift from my own
  change: the code was right and the comments explaining it were not, which is
  the pairing most likely to send the next reader back down the wrong path.
  The two surviving mentions are deliberate — each states the rule the class
  would re-arm. `docs/changelog/` is untouched: it is history, and it correctly
  records what was true when written. (#1021)

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

