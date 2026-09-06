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

