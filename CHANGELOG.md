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
- An **Attention** section opens first at the root (position 5) -- what a
  phone opens on. Nine readers over signals something else already
  computed: the last provenance-integrity sweep's failing notes and its
  fleet-level key verdict (a mismatched, missing or unreachable ledger key
  file is one row about every signed subject, in the findings' own words),
  commits
  still unanchored or pending (both post types), the newest-per-post stale
  edge-probe verdict from the last twenty saves, citations never checked
  and citations due for a check, fragments and posts with a schedule
  transition inside 24 hours, posts and pages pending review (gated on the
  type's `edit_others_*` capability -- for anyone else the section's own
  scoped Pending pill is the honest surface), the last health scan's
  failing checks, ripe watches, and a stale machine-reader snapshot. It
  reads; it never triggers a scan, a sweep, a probe or a re-check -- every
  reader gates on `function_exists()` and wraps its call in try/catch, so
  an absent subsystem makes no claim and a subsystem that cannot answer
  yields exactly one warning row, never a zero. Every row carries the
  reading's own stamp ("as of `<UTC>`", or "not stamped" when the signal
  carries none), and a row that names a post offers "Open the note" -- a
  new `jump` server action that sets the section and the item together, so
  the reader lands on the dossier without hunting for it. The composed
  queue is cached for sixty seconds with its own `read_at`, so the nine
  readers run once a minute rather than on every root paint; the empty
  state ("Nothing needs you") says when its readers last looked. (#1071)
- The window now says when it is running a stale build. `SNT_VERSION`
  ships frozen into the document at render (`ctx.extra.version`) and fresh
  on every dispatch (`ctx.data.version`); OpenStation's own update toast is
  keyed to OpenStation's own asset stamp and can never see a plugin
  release, so on an installed phone PWA -- which can keep the same document
  alive for days -- a stale window had nothing telling it so. When the two
  disagree the client paints one line beside the crumbs, in both view
  branches, naming both versions with a Reload button the reader must
  click; nothing reloads on its own. (#1071)

### Fixed
- Five defects measured on the phone. The list view now stacks into a
  card per row (`<os-table stacked>`, `sticky-columns` stood down) instead
  of a sideways scroll fighting a pinned column. A crossing between the
  desk and the phone band now repaints the window and mounts or tears down
  the desk-only marquee and drag listeners for the band it is in, rather
  than leaving a window painted for the band it was born in. The phone's
  item page gains a "‹ Back" control in its detail header, ahead of the
  title, on every section -- the crumb was the only way out, and the
  shell's own Back leaves the app rather than the item. A long press on
  the empty canvas now opens the canvas menu (Refresh); iOS never
  synthesises `contextmenu` from a held finger, so Refresh was unreachable
  there before. The iOS callout and text-selection suppression now runs
  under `@media (pointer: coarse)` as well as under the mobile-mode stamp,
  and covers the canvas and the table (whose rows live in a shadow root
  but inherit `user-select` from the host) alongside the folder tile and
  the cell; the dead `is-phone` class, which styled nothing, is gone.
  (#1071)

### Changed
- Discography's registration gate now asks `albums_count()` instead of
  `albums_items()` -- it was building every release's cover art, tracks
  and dossier on every single resolution of the registry just to answer
  whether the list was empty. It was the one section without a `count`
  callable, so that cost landed on every root paint, including the
  phone's first screen. (#1071)

## [13.102.0] - 2026-09-06 — more sections

### Added
- Three sections join the Signal & Noise app beside Notes and Discography.
  **Pages** lists every page opted into provenance signing (whether or not a
  version has been signed yet), sharing Notes' item builder, dossier, menu
  and drag-out -- the same control surface, over `wp/v2/pages`, gated
  `edit_pages`; its Verify action is withheld because the public verifier is
  Notes-only, and its ledger record is reached through the dossier's anchor-
  proof door instead. **Citations** lists the verified citation graph one
  entry per row, wearing its tier as status and badge tone, with facts for
  source, target, tier, last-checked and last-status, and a door to Integrity
  → Citations plus a link to the cited note when the target resolves.
  **Scheduled fragments** lists the scheduled-content queue's fragment rows
  soonest-first, with the window, action, purge-URL count and table, and a
  door to Connections → Scheduled plus a link to the host note. Both entry
  sections are read-only by construction -- no `restPath`, `edit_url` or
  `hasDossier` -- and register even at zero, an honest measured empty rather
  than a hidden folder. (#1068)

### Changed
- `snt_os_app_note_provenance()` now answers for any provenance subject
  (`sn_prov_subject_kind()` resolves `note` or `page`) instead of refusing
  every post that is not a Notes-category note; a signed page now gets the
  badge, the versions column, the signed-chain table and the UID block the
  same way a note does.
- The note dossier (`sn_note_dossier_post()`) and the `note-dossier` ability
  accept a signed page as well as a note, under the same `edit_post` gate.
- The Notes items and count queries add `'perm' => 'readable'`, matching the
  new Pages query, so an Author sees only their own unpublished posts of
  either type rather than every author's.
- `inc/citations-store.php` gains `sn_cit_all( $limit )`, an ordered read of
  every citation row for the app's Citations section (the admin leaf keeps
  its own inline query). `inc/schedule-engine.php` gains `SN_SCHEDULE_STATUSES`
  (`queued`, `active`, `done`, `error`) and `sn_schedule_count( $target_type )`,
  a SQL count backing the Scheduled section's root tile. (#1068)

