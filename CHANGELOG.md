# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.101.0] - 2026-09-06 — the control surface

### Added
- The Notes section of the Signal & Noise app gains a control surface: click,
  Cmd/Ctrl-click and Shift-click selection (plus a marquee on the empty
  canvas, desktop only) through the framework's own `applySelection` /
  `createMarquee`; a context menu reached by right-click, a row and dossier
  "More actions" button, or a 500 ms / 10 px long press on touch, offering
  Open in editor, View on site, Copy link, Copy ID, Re-check now, Purge edge
  cache, Retry anchor dispatch, Publish and Move to Trash -- each row
  disabled or hidden by the item's own `canEdit` / `canDelete` / `canPublish`
  / `unanchored` flags and the payload's `can.purge` / `can.anchor`. Four
  gated server actions (`trash`, `publish`, `purge`, `anchor`) re-derive the
  target set from server-held selection state rather than trusting the
  client's claim, so a forged multi-select still only ever acts on notes the
  server itself holds selected; Trash and Publish confirm client-side first
  (Trash danger-styled, Publish naming the permanence of a signed version),
  Purge and the anchor retry are idempotent and answer with a toast instead.
  Purge re-uses the exact deferred probe a save already schedules and never
  writes the probe log itself; anchor retry re-dispatches only a note's
  `unanchored` commits. A status footer reads "%1$d of %2$d items" plus
  " — %d selected". A note (or the whole selection) drags out as a
  `shortcut` payload the shell's existing Recycle Bin and folder drop
  targets already understand, desktop only. The Discography section and
  phase one's dossier are untouched. (#1065)

