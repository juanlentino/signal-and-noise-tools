# The Signal & Noise app, phase one: the deeper note

Design spec, brainstormed with the owner on 2026-09-05. Phase one of four; the
others (the control surface, more sections, the phone) get their own spec when
their turn comes. Status: approved for planning.

## What this is

The Signal & Noise app (`apps/signal-noise/`, a client-view App Framework app
since v13.99.0) lists Notes and the Discography and opens a dossier beside the
list. Today the dossier is what the local database knows: the signed chain,
the ledger UID, the editor, the verify link. This phase makes the dossier
everything the estate knows about one note, in the owner's order: **trust,
then numbers, then operating state, then editorial.**

Three apps share the desktop and keep their jobs. Signal & Noise owns the
notes and the discography with their provenance. SN Dashboard is operations.
S&N Analytics is the numbers. For numbers and operating state this app shows
**a glance and a door**: the facts for this one note, each naming its source
and window, then a link into the app that owns the view. Nothing those two
apps already render is rebuilt here.

## Decisions made in the brainstorm

| Question | Decision |
|---|---|
| Which axis first? | All four, sequenced: deeper note, control surface, more sections, phone. |
| What first in a note? | Trust, numbers, operating state, editorial. |
| What joins the trust block? | Citations received, the anchor proof, the signer, a live re-check. All four. |
| Numbers window? | A 7 / 30 / 90 switch, default 30, kept for the session; every tile names its own window. |
| How does the dossier get its depth? | Fetched when it opens, through one ability; local facts stay inline. |
| Numbers and operating state? | A glance and a door into S&N Analytics and SN Dashboard, not a second home. |

## Architecture

- **PHP, one builder per block family**, each a pure function of a post id and
  a window that returns blocks: `apps/signal-noise/parts/dossier-trust.php`,
  `dossier-numbers.php`, `dossier-state.php`, `dossier-editorial.php`.
- **One ability, `signal-noise/note-dossier`** (input: `post_id`, `days` in
  {7, 30, 90}; permission: `edit_post` on that post), which composes the four
  builders into `{ blocks: [...], fetched_at }`. REST-reachable like every
  ability, so the client calls it through the runtime's `ctx.fetch`.
- **One server action, `verify`**, on the app: walks the three legs the public
  verifier walks (`sn_prov_verify_endpoints()`: DID and keys; the published
  twin; the ledger record) for the note's UID and head version, and returns a
  verdict block with a time. A round trip, on purpose: it is the one thing
  here that must be fresh.
- **The registry cleanup.** The dock entry in `inc/desktop-mode-dock.php`
  still registers `signal-noise` as the admin page; the app took that id in
  v13.98.0. The entry is retired so the id means the app and nothing else. SN
  Dashboard keeps its own icon and id.
- **The list payload is unchanged**; `items[].detail` keeps the local half
  (chain table, UID, verify link, editor) so a dossier still opens instantly.

## Block contract

Two kinds join `table`, `code` and `text`:

- `stats`: `{ heading, kind: 'stats', tiles: [ { label, value, window, note? } ] }`, a row of tiles; `window` is a label such as "30 days" or "daily snapshot"; `note` is a short qualifier ("not among the top documents").
- `status`: `{ heading, kind: 'status', tone, text, meta? }`, a pill in a kit tone with a sentence.

Every fetched block carries `source` (a short name: "analytics worker",
"Search Console sync", "public ledger") and, when it has one, `window`. A
block may carry `door: { label, url }`, rendered as a secondary button.
Sections added in phase three reuse these kinds unchanged.

## The four blocks

**Trust.**
- Inline (unchanged): the chain table, the UID, Verify, Open in editor.
- Fetched: the ledger record for the head version at
  `sn_prov_integrity_ledger_base() . '<ledger dir>/<uid>/v<n>.json'`, read as
  the anchor proof: anchor status and time as the ledger states them. The key
  id that signed it, against `keys/provenance-keys.json`: "signed by <id>, the
  followed key" or the mismatch, named.
- Citations received: `sn_cit_for_post( $post_id, false )`, every tier, as a
  table (tier pill, source, last checked).
- **Re-check now**: a button dispatching `verify`; its verdict block (tone,
  sentence, time) lands under the chain on the next paint.

**Numbers.** Three `stats` tiles and a door.
- Views and visits over the window from one analytics drilldown on the note's
  path (`sn_analytics_drilldown( 'path', $path, $from, $to )`).
- Impressions, clicks and position from the Search Console per-page rows
  (`snt_search_performance_impl()`'s `pages`), in the sync's own window.
- Machine reads from the daily snapshot (`snt_mr_snapshot()`): the note's row
  when it is among the snapshot's documents, else the tile says "not among the
  top documents" and prints no number.
- Door: Open in S&N Analytics on this path (the Analytics page's
  `?sn_drill=path:<path>` drill-down).

**Operating state.** `status` blocks and doors.
- The last edge verdict for this URL from the probe log
  (`SN_CF_PROBE_LOG_OPT`), with its time; "never probed" when there is none.
- Coverage from the Search Console sync (`snt_gsc_coverage_data()`).
- Whether the sitemap carries the URL.
- Scheduled fragments targeting this post (`sn_schedule_all()` filtered).
- Doors: Open in SN Dashboard on the tab that owns each fact (Cloudflare for
  the edge verdict, Content for the schedule).

**Editorial.**
- Tags, reading time (`sn_get_reading_time`), word count
  (`snt_corpus_word_count`), the excerpt as served.
- Related notes through the theme's `sn_related_notes_query()` when the
  function exists; the block is omitted otherwise, not faked.

## Data flow

1. A click opens the item locally, as today; the inline half paints at once.
2. The client calls the ability with `post_id` and the window; each block
   slot paints skeleton rows.
3. One response fills the four blocks. The client caches it per note and
   window in the window's `ctx.ui()` bag for the session.
4. Changing the window switch re-fetches; the cache answers a window already
   seen.
5. Re-check now dispatches `verify`; the runtime re-renders with the verdict
   block in `data`.

An unpublished note (draft, scheduled, pending, private) gets the trust block
as "signed on publish" and no numbers or operating state: it has no URL a
reader reaches.

## Error handling

- Every builder is its own try. A source that fails yields a `status` block in
  the warn tone naming the source ("the analytics worker could not be read");
  the other blocks still paint.
- The ledger unreachable is a gap in evidence, never "not anchored". The
  signer line says "could not be checked", never "mismatch".
- A note absent from a top-N source says so; it is never printed as zero.
- The ability answers a WP_Error only for a missing or unreadable post; a
  degraded source is a block, not an error, so the client never loses the
  whole dossier to one source.

## Testing

- One standalone test per builder over stubbed sources: the happy row, the
  absent-document case, the unreachable case, the unpublished note.
- One test for the ability: input validation, the permission callback, the
  composed shape, a degraded source surfacing as a block.
- One test for the client's rendering of each block kind, pinned by kind.
- The sandbox pass: six seeded notes, desk and phone, with the analytics and
  ledger sources stubbed at the HTTP layer.

## Out of scope

Actions beyond re-check (phase two), bulk selection and drag-out (phase two),
new sections (phase three), the phone queue and gestures (phase four). Porting
SN Dashboard and S&N Analytics to the App Framework is a separate program the
owner has asked for; when it lands, the doors here become framework deep
links instead of admin URLs, a small change confined to the door builders.
