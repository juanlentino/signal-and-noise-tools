# Plan — moving Signal & Noise onto OpenStation's Posts surface

Source: the 2026-09-12 evening session (plugin 14.1.1 → 14.3.1, Worker 1.6.0).
The first move — the **Provenance** column — shipped and is verified on live
(`assets/os-posts-provenance.js`, `snt_os_posts_window_query_args()`,
`docs/openstation-compat.md`). This plan is the next three, in the order that
defers decisions until the surface has earned them.

The question every section of our app now has to answer: **what does our
window show that the shell cannot?** OpenStation 1.1.8 gives the Posts window
six seams — `openstation.postsWindow.{columns,statusSegments,bulkActions,
toolbarTrailing,opened,dataLoaded}` — plus the PHP `openstation_posts_window_query_args`
filter for what rides the list. Pages has no seams. No workspace has a side-panel slot.

Facts established while measuring (do not re-derive):

- The window trims its `/wp/v2/posts` fetch with a `_fields` allowlist. A
  registered REST field rides only if appended there (14.3.1's lesson).
- The desk cards paint only the shell's stats; plugin columns render in the
  **table** and the **inspector** (one node per render call).
- `statusSegments` values go verbatim to core as `?status=`; only post statuses
  work there. "Needs attention" cannot be a tab.
- "Cadence" in this plugin is the ops cadence (cron intervals, `cadence-flags`
  = duplicate-body scan). The Schedules section lists **schedule-engine rows**
  (`sn_schedule_all()`), not post publishing. There is no per-post cadence
  value; the "cadence column" idea from earlier tonight is withdrawn.
- The Attention queue is composed by nine readers inside the App Framework
  namespace and cached 60 s in the `snt_os_attention` transient
  (`attention_rows()`, `apps/signal-noise/parts/attention.php`).
- The app's Purge / Retry anchor / Publish call `sn_cf_purge_urls()`,
  `sn_prov_reconcile_post()`, `wp_update_post()` directly. `sn-apply` mutates
  post *content* only. No per-post purge/anchor ability exists; the write door
  is pinned at 8 slugs by `tests/mcp-capabilities.php` and the README.

Each phase is one PR. Sweep the full suite on every one — the compat census
caught 14.3.1 consuming an undocumented seam because I ran only the touched
suite.

---

## Phase 1 — Edge column (read-only, no decisions) — plugin PR, MINOR

Replaces the withdrawn cadence column with the per-post signal that exists:
edge-cache freshness. The Cache widget already reports `post_save: probes 20,
stale 11, escalated 11` — that verdict is per post, from the deferred probe
that runs after every save.

1. `register_rest_field( 'post', 'sn_edge', … )` next to `sn_provenance` in
   `inc/desktop-mode-explorer.php` (same guard shape: null when the module or
   the post is out of scope). Payload: `{ state: fresh|stale|unprobed,
   verified_at, escalated: bool }`, read from the probe log the Cache widget
   reads (`inc/cloudflare-purge.php` verification log — find the reader the
   widget uses and call *that*, never re-probe on a list render).
2. Append `sn_edge` in `snt_os_posts_window_query_args()`.
3. Column in `assets/os-posts-provenance.js` → rename to `os-posts.js` in the
   same PR (one script, two columns; update the enqueue handle and the guard
   that pins the filename). Cell: dot + `fresh` / `stale` / nothing when
   unprobed. Same palette rule: absent paints nothing.
4. Guards in `tests/openstation-preferences.php`: field appended, column key,
   idempotence. Plus a PHP unit for the field callback: unprobed → null,
   stale → `{state:'stale'}`. Mutate the `_fields` append red.
5. `docs/openstation-compat.md`: no new seam (same filter), note the second
   field on the existing row.

Exit: Posts → Details table shows Provenance and Edge on published notes;
a note whose last probe read stale shows `stale`.

## Phase 2 — Attention pill (one decision: what the pill reads) — plugin PR, MINOR

The count the shell page needs, without running nine scans per window open.

1. REST route `signal-noise/v1/openstation/attention` (GET, `manage_options`,
   cookie+nonce) in `inc/openstation-preferences.php` beside `/openstation/preferences`.
   It **reads the transient only**: `get_transient( 'snt_os_attention' )` →
   `{ count, stamp, stale: bool }`; when absent it answers `count: null,
   stale: true` and does NOT compose. **Decision made here:** the pill never
   pays for a composition; the app window does, and the pill shows what the
   app last knew. A null count paints the pill without a number.
   (`attention_rows()` lives in the app namespace and needs the part loaded;
   reading the transient needs nothing — that is the whole reason for the rule.)
2. `openstation.postsWindow.toolbarTrailing` in `os-posts.js`: one
   `<button>` "Attention · N" (or "Attention" when null), `aria-live="polite"`,
   click → `wp.os.openApp('signal-noise', { section: 'attention' })` (confirm
   the app-open API name in `docs/javascript-reference.md`; our own client
   already deep-links sections). Fetch once on `openstation.postsWindow.opened`,
   refresh on `dataLoaded` no more than once per 60 s (the transient's own TTL).
3. REST census: `tests/rest-routes.php` 21 → 22, gated, reason on the line.
4. Guards: route registered with `snt_os_preferences_rest_permission`; the
   handler returns `stale:true, count:null` with no transient and never calls
   `attention_compose`; the JS pins the hook name, the 60 s throttle, and that
   the click opens our app rather than navigating.

Exit: with the app having composed within the minute, Posts shows
"Attention · N"; after 60 s idle, the pill still shows the last N, marked stale
in its title. The Attention section stays ours; the pill only points at it.

## Phase 3 — Bulk actions (the write-surface decision) — plugin PR, MINOR

Two candidates only: **Purge edge** and **Retry anchor**. Not Publish (core's
own bulk edit does it), not Re-check (a read; make it a column refresh instead).

**Decision to make first, in this order of preference:**

- (a) Two write-door abilities, `signal-noise/purge-post` and
  `signal-noise/anchor-retry`, each taking `post_ids[]`, each calling the same
  functions the app's actions call, each behind the rw guard's four controls
  and the audit log. The Posts bulk action then runs
  `POST /wp-abilities/v1/abilities/signal-noise/<slug>/run` with cookie+nonce
  — the route wp-admin's own buttons already use, which the rw guard lets
  through untouched. Door 8 → 10: update `tests/mcp-capabilities.php`, the
  README's write-door paragraph, `docs/openstation-compat.md` unchanged.
  *This is the plan-from-the-ability shape and gets audit + kill switch free.*
- (b) A cookie+nonce REST route mirroring the app's actions — cheaper, but a
  second mutation path outside the ability layer, with no audit row. Only if
  (a) is refused.

Then:

1. `openstation.postsWindow.bulkActions` in `os-posts.js`: two entries with
   `confirm( count )` copy that names the count and the irreversibility
   ("Purge the edge cache for N posts?" / "Re-dispatch the anchor for N posts?
   Nothing is re-signed."), `run( ids, ctx )` posting to the ability, then
   `ctx` refresh. Per-id results surfaced: the ability returns `{ sent, skipped,
   errors[] }` and the action reports it in the shell's own notice.
2. The app's `purge_action()` / `anchor_action()` call the new abilities
   instead of the functions directly, so the two surfaces cannot drift.
3. Guards: abilities registered with the rw door's permission; `dry_run` not
   applicable (no content); an id outside `post` refused; the bulk action's
   confirm copy carries the count; a failing id does not abort the batch.

Exit: select three published notes → Purge edge → three purge requests in
the audit log; Retry anchor on an anchored note is a no-op reported as such.

---

## Then: the app rethink, with three moves shipped

Sections after these phases: Notes' list, provenance, edge state and its two
write actions live on the shell; Schedules stays (it is schedule-engine rows,
not posts — the shell has nothing for it); Discography is an Explorer entity
kind; Citations and Attention stay ours; Pages waits on upstream.

## Upstream, filed when they bite (byproducts, framed as shell personalization)

- Pages workspace seams — the moment a Pages column is attempted.
- A side-panel slot on Posts/Pages — the moment Attention would move in.
- (Observed, not blocking) the desk cards do not paint plugin columns; a card
  slot would let Provenance/Edge show where the owner actually reads.
