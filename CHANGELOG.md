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
- Integrity → Citations rendered inside a 260px stat card: the leaf is
  registered `wide` (no wrapper), and its renderer wrapped everything in
  `.sn-card`, the Dashboard's glance-grid card, so a 1,400px window showed a
  strip down the left edge with the claims table overflowing the card's own
  border and four fifths of the window empty. Now the sibling leaves' idiom: a
  glance hero with one card per tier (every tier printed even at zero, a
  five-word gloss under each number, a warn pill only where there is something
  to act on), then one wide fieldset with the tally sentence, a two-sentence
  intro, the inbox, the four tier sentences folded into a legend, and the table
  at full width -- which now names the cited Note, prints the source's host
  beneath its title, adds First seen, and wears the tier as a pill in its tone.
  No new class; the unstyled `sn-cit-legend` leaves the orphan baseline.
  Pinned by a render test over a stub `$wpdb`. (#1055)

## [13.99.0] - 2026-09-05 — the window in the Explorer's idiom

### Changed
- The Signal & Noise window is rebuilt as a client view, in WP Explorer's
  idiom and from the framework's own parts. v13.98.0 shipped it as a flat,
  server-rendered list -- newest post date first, so a queue of scheduled
  notes filled the first pages; grey squares where icons should be, because the
  frame passed `<os-icon icon>` to a component that reads `name`; every filter
  a round trip; verified against one seeded note. Now: the root is folder
  tiles with counts (click selects, double click or Enter opens, a tap opens
  under a finger); a section is an `<os-tile>` canvas at the Explorer's pitch,
  the kit's own ribbons for scheduled / draft / pending / private, and a
  version badge in the anchor's tone beside each signed tile; the shared
  `statusControl` pills (a picker on the phone, Published by default for
  Notes), the list-window search, an Icons | List switch and an `<os-table>`
  list view with the section's columns; the dossier is the Explorer's detail
  column (hero, facts, the signed chain as a table, the ledger UID, the editor
  as a window, Verify, View on site; Spotify / Muso for a release) and a page
  on the phone. Selection, search, filtering and the view switch are `local`
  reducers and never leave the browser; only entering a section and opening
  the editor dispatch. The client is plain JS on the runtime's documented
  `openStationAppsPending` queue -- no build -- and rides the window through
  the framework's own `client()` registration, with the plugin's fallback
  handle for a symlinked install. The section registry is unchanged in shape
  and still PHP-only: a section is one descriptor whose items carry everything
  the view paints. Verified in the WP 7.1 + OpenStation 1.1.6 sandbox against
  fifteen notes across every chain state and six releases, desktop and phone.
  (#1049)

