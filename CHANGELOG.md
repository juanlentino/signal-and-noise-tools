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
- The installed PWA's home-screen tile is no longer a black square. OpenStation
  builds the manifest from the WordPress Site Icon when one is set, and measured
  against the live manifest that path shipped three faults at once: the 192 entry
  pointed at core's `-300x300` crop while declaring `sizes: "192x192"`; both files
  were RGBA with 61.6% fully transparent pixels, and iOS composites home-screen
  transparency to **black** behind a mark measuring luminance 23/255; and neither
  declared `maskable`, so Android had no full-bleed art to crop. The plugin now
  supplies its own opaque set through the `openstation_pwa_manifest` filter —
  `any` at 192/512 full-bleed, `maskable` at 192/512 inset to 80%. Flattened onto
  white, not the manifest's `background_color`: the mark is dark ink, so a dark
  ground would have erased it. The icons ship with the PLUGIN because the manifest
  describes a wp-admin surface, which has to survive a theme switch. (#1017)
- Four form controls no longer zoom the page on an iPhone and leave it magnified.
  WebKit zooms a focused control under 16px and does not zoom back out; core
  guards this with `textarea, input { font-size: 16px }` at 782px, but that is
  specificity 0,0,1 and loses to any class, id or attribute selector. The
  post-settings fields (1,2,1) outranked it, and `prov-verify.css` is a front-end
  sheet where core's admin CSS never loads and which had no 782px block at all.
  Desktop sizes are unchanged. (#1018)

### Internal
- `tests/ios-form-zoom-guard.php` derives its control population from the MARKUP
  — the class names our PHP actually puts on an `<input|select|textarea>` — not
  from words in a selector. `.sn-rsm-items` is a `<textarea>` in three admin forms
  whose CSS rule names no element, so a word-match skipped it silently. The guard
  also requires that a 782px block re-raise `font-size`, rather than merely
  mentioning the selector; a block adjusting padding at phone width would
  otherwise have satisfied it. Both corrections came from the guard disagreeing
  with the scan that produced the finding: nine "violations" were really four,
  because five were already guarded and the scan counted "under 16px" instead of
  "under 16px AND unguarded". (#1018)
- `tests/openstation-pwa-icons.php` reads each shipped PNG's IHDR and compares
  real dimensions and colour type against what the manifest declares. The defect
  was a manifest that DESCRIBED its icons wrongly, so a test asserting only that
  four entries exist would have passed against the broken one. (#1017)

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
  than as silence. That counter immediately earned itself: dropping the class
  from the one-cell notice made `inc/schedule-admin.php` a mixed-table file, so
  the sweep's label check went from nine files to eight. Those two tables are
  now pinned in `tests/schedule-admin.php` against the RENDERED markup instead.
  All new assertions were run against the pre-fix commit and go red there.
  (#1015)

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

