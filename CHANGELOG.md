# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- The desktop icon that opens the admin page is titled "S&N Dashboard", to
  match "S&N Analytics" beside it. Same id, same URL, same spot on the desktop.
  (#1060)

## [13.99.1] - 2026-09-05 — the Citations leaf at full width

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

