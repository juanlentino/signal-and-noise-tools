# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.103.0] - 2026-09-06 — the phone

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
  transition inside 24 hours, posts and pages pending review (composed for
  everyone, shown only to a reader with the type's `edit_others_*`
  capability, decided when the queue is read and never when it is cached
  -- for anyone else the section's own scoped Pending pill is the honest
  surface), the last health scan's failing checks, ripe watches, and a
  stale machine-reader snapshot. A failing note whose only failures are
  outages or gaps (an unreachable twin or ledger, an unresolved subject
  kind) is toned warning, never danger -- the sweep's own distinction; a
  citations store that cannot answer is one warning row, not a zero; the
  anchors row says its source reads the newest hundred signed subjects. It
  reads; it never triggers a scan, a sweep, a probe or a re-check -- every
  reader gates on `function_exists()` and wraps its call in try/catch, so
  an absent subsystem makes no claim and a subsystem that cannot answer
  yields exactly one warning row, never a zero. Every row carries the
  reading's own stamp ("as of `<UTC>`", or "not stamped" when the signal
  carries none), and a row that names a post offers "Open the note" (or
  "Open the page") -- a new `jump` server action that sets the section and
  the item together, so the reader lands on the dossier without hunting
  for it -- but only when the section that would list the post actually
  holds it (Notes lists the note category, Pages the signed pages), so the
  jump never lands on nothing. The composed
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
  branches -- "The installed build (X) is not the one this window was
  built from (Y).", which is all the compare knows -- with a Reload button
  the reader must click; the click awaits the shell's own session flush
  before reloading, and nothing reloads on its own. (#1071)

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
- A status pill that filters a list empty now says "Nothing under this
  filter." rather than the section's own empty wording -- Attention's
  "Nothing needs you" is a claim about the queue, not about the filter. A
  long press arms its tap-swallow only when it opened something, and one
  finger arms one press: a press that began on a tile no longer also arms
  the canvas's, which stranded a swallow that could eat the next tap.
  (#1071)

### Changed
- Discography's registration gate now asks `albums_count()` instead of
  `albums_items()` -- it was building every release's cover art, tracks
  and dossier on every single resolution of the registry just to answer
  whether the list was empty. It was the one section without a `count`
  callable, so that cost landed on every root paint, including the
  phone's first screen. (#1071)

