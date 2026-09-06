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

## [13.100.0] - 2026-09-06 — the deeper note

### Added
- The Signal & Noise window's dossier is now everything the estate knows about
  one note, in the order trust, numbers, operating state, editorial. Trust reads
  the public ledger's record of the newest confirmed version (block, txid,
  confirmations, and whether it attests the same hash), names the signing key
  against the keys the ledger publishes, and lists the citations received;
  "Re-check now" walks the twin, the ledger record and the published key ids
  and says exactly that. Numbers: views and visits over a 7 / 30 / 90 window
  from the durable analytics table (both spellings of the path, a new read:
  `sn_analytics_path_window()`), impressions and clicks from the Search Console
  sync in ITS window, and machine reads said honestly -- the sensor keeps no
  document paths, so the line names the site-wide figure and nothing per note.
  Operating state: the last edge verdict for the URL from the probe log, coverage,
  sitemap membership, scheduled fragments. Editorial: tags, reading time (read,
  never computed on read), word count, the excerpt agents receive, related notes
  from the kernel. Every fetched block names the source it came from, and its
  window where it has one; a source that could not be read is a warning block
  naming it, never a zero; each fact that another app owns carries a door into
  the S&N Dashboard or S&N Analytics view that owns it, opened as a window.
  Fetched when the dossier opens through one new ability,
  `signal-noise/note-dossier` (GET, `edit_post` on the note), cached per note
  and window for the session and refetched after a re-check; a failed fetch is
  held briefly and retried, never pinned. The fetched half never enters the
  list payload: the list gains only the re-check action and the verdict slot.
  The Signer line reads the key probe's VERDICT, not only its id list, so a
  ledger key file that publishes the followed id with different bytes is a red
  line and a failed re-check, never "the followed key". Builders live in
  `inc/note-dossier*.php` and work without OpenStation. (#1058)

### Changed
- The admin page's dock entry is `sn-dashboard`, titled S&N Dashboard with the
  shield: since v13.98.0 it shared the id `signal-noise` with the App Framework
  app, so one id named two things on the same dock. Re-keyed so each id names
  one thing; the app keeps `signal-noise` and the megaphone; the entry keeps its
  badge and submenu. (#1058)
- The eleven failure sentences of `sn_prov_integrity_findings()` are one table,
  `sn_prov_integrity_failure_sentence()`, joined by three the app's re-check
  needs (`keys_unreachable`, `key_mismatch`, `keys_not_configured`), so the
  sweep, the health check and the re-check say the same thing about the same
  leg. (#1058)
- `signal-noise/note-dossier` is the first ability gated on `edit_post` rather
  than `manage_options`: the dossier is the editor's own view of one note they
  may edit, one note at a time. Scope, not sensitivity, is what changed; the
  tier is recorded in `docs/ops/ability-permission-policy.md`. (#1058)

### Fixed
- `tests/public-stats.php` dated its render fixture in absolute August days,
  which sat inside the shortcode's live 30-day window until 2026-09-06 UTC and
  then left it: the chart assertions went red on this cut, which changed
  nothing they measure. The fixture now dates its rows from the live window.
  (#1064)
