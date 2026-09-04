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

## [13.96.4] - 2026-09-04 — a window is not a contract

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
- `tests/ios-form-zoom-guard.php` derives its control population from the MARKUP
  — the class names our PHP actually puts on an `<input|select|textarea>` — not
  from words in a selector. `.sn-rsm-items` is a `<textarea>` in three admin forms
  whose CSS rule names no element, so a word-match skipped it silently. The guard
  also requires that a 782px block re-raise `font-size`, rather than merely
  mentioning the selector; a block adjusting padding at phone width would
  otherwise have satisfied it. Both corrections came from the guard disagreeing
  with the scan that produced the finding: nine "violations" were really four,
  because five were already guarded and the scan counted "under 16px" instead of
  "under 16px AND unguarded". Its value parser resolves `max()`, `min()` and
  `clamp()` by function rather than by taking the smallest length — the theme's
  notes search field is `max(0.9rem, 12px)`, which a digit-anchored regex misses
  entirely, while `max(1.1rem, 12px)` computes to a perfectly safe 17.6px that a
  smallest-wins rule would have flagged. Pseudo-element rules are excluded:
  a `::placeholder` size does not zoom anything. (#1018)
- `tests/openstation-pwa-icons.php` reads each shipped PNG's IHDR and compares
  real dimensions and colour type against what the manifest declares. The defect
  was a manifest that DESCRIBED its icons wrongly, so a test asserting only that
  four entries exist would have passed against the broken one. (#1017)
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

