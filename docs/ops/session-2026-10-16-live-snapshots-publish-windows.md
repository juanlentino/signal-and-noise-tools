# Session 2026-10-16: live snapshots and publish windows

The owner's brief "SN Traffic Lanes follow-up: live snapshots and publish
windows", with the owner's decisions of 2026-10-16. Plugin only: the
analytics Worker, the remote MCP Worker, the beacon and the theme are
untouched. No version bump, no tag.

## Step-2 findings (read in code on main, 3b2d2b42)

- `signal-noise/live-now` returns `sn_analytics_live_payload(true)`, a pure read
  of the `sn_analytics_realtime` transient. `sn_analytics_realtime_refresh()`
  writes it: per-class counts and `pages` and `sources` over a ROLLING
  `now() - 5 MINUTE`, `hour` over twelve ALIGNED 5-minute slots. Refreshed on
  demand when older than 30 s and every 15 minutes from the Worker cron.
- A rolling window cannot be rebuilt from aligned slots, and distinct counts
  do not add up across slots, so "derive today's pages and sources from the
  per-slot result" could not be byte-identical. The live queries are kept as
  they are; the snapshots are three new per-slot reads beside them.
- Both admin surfaces (classic Overview, native view/overview painter) print
  `sn_analytics_live_admin_html()`. The live bars are an SVG rebuilt by
  `assets/live-now.js` every 30 s, so nothing server-painted inside them
  survives a tick: a click on those bars would need new JS.
- The native window's state is the classic page's URL params; any own-page
  link becomes a `go`, any GET form a `go` (inc/openstation-host.php). A new
  param needs a state key (apps/sn-analytics/parts/state.php).
- Lanes: `wp_sn_analytics_lanes` has no path. `sn_owner_hits` carries the path
  in blob2 (the excluded-path SQL already filters it there), so the owner
  lanes split per note. `own_infra` comes only from the machine sensor, which
  keeps no paths: it can never be split per note.
- Scheduled publishing (`wp_publish_post()`) fires `transition_post_status`,
  not `save_post`.
- Per-note analytics live in the Signal & Noise app's note dossier
  (`inc/note-dossier*.php`). `note-dossier` is not on an MCP door; the local MCP
  tool that reports per note is `posts-signals`.
- The lanes plan doc the code cites (`docs/plans/traffic-lanes-2026-10-15.md`)
  is not in this repo; session logs live in `docs/ops/`.

## Owner decisions applied

Part 1: per-slot reads over the last 60 minutes; completed slots stored 24 h
in `sn_live_slot_detail` (not autoloaded); readers by class, top pages (live
rules), top sources (dashboard names); aggregates only; no floor;
`live-now` `hours_back` 1 to 24, default byte-identical, input schema one
optional property; remote unchanged; a click in wp-admin, both twins, no new JS.

Part 2: `_sn_published_at` written once at the first transition to publish
from `time()`, kept on updates and republish, reset when `post_date_gmt` moves
past it; no backfill; a length setting (default 60) with its native Setup fold
and classic twin; on-demand reads with the lanes beacon derivation, half-open
window, window + after = total per lane (hits only, see below); closed windows cached in
`sn_publish_window_cache`, bounded by AE's 90 days; `publish_window` a label,
not a lane; per-note readout leading with the after-window human figure;
remote door and `sn_remote_analytics_query` unchanged.

## Deviations from the brief

- **Classes, not lanes, in snapshots.** A 5-minute slot cannot carry a lane:
  lanes are per site-local visitor-day at rollup time.
- **No floor**, by owner decision, as live has none.
- **On-demand windows** (option C): no stored series; the after-window half is
  read from the window's end to now on each open.
- **No remote filter**: the contract bump is deferred by the owner.
- **The live bars are not the control.** The click target is a server-painted
  grid of the last 24 hours under the Right now block (an hour per row, a
  slot per cell, each cell a link with `sn_slot`), because the bars are
  repainted by script every 30 s. `sn_slot` is a tenth native state param.
- **Old live queries kept.** See the step-2 finding on rolling vs aligned.
- **The local MCP surface is `posts-signals`' optional `publish_window`** (one
  note id), not a per-row field: a per-row split would cost two or three AE
  reads per note on every call.
- **The dossier's default output gains blocks** (the "First hour" readout under
  Numbers). That is surface (a); the four existing builders are unchanged.
- **The cache also keeps at most 200 entries**, newest first, beyond the 90-day
  bound (a `ponytail:` note says when to raise it).

## Query cost

- Per realtime refresh: 0 new reads when no completed slot is new; 3 when one
  is (classes, pages, sources per slot over 60 minutes), so at most 3 per 5
  minutes while watched, 3 per 15 minutes from the cron.
- Per report open (dossier or `posts-signals` `publish_window`), for a note with
  a stamp: open or uncached window, 2 or 3 (lanes public, lanes owner once the
  beacon's `lanes_since` is set, window sources); cached closed window, 1 or 2
  (the after-window lanes only). A note with no stamp: 0.

## Proof the tests can fail

Each guard was broken and its suite watched go red (15 of 15): the PHP and
SQL boundary flipped to `<=`; no prune on write; no 24 h bound on read;
`live-now`'s default gaining `slots`; a stamp on update; a stamp overwritten on
republish; the path filter dropped; the cache cap dropped; the partial slot
stored; raw rows stored (identifiers); the derive ignoring `part` (window +
after against the total); `posts-signals`' default gaining a key; no gating
(reads on every refresh); the native `slot` state dropped.

## Reviewer findings and dispositions

Both reviewers said MERGE.

Privacy (low notes):
- At-rest expiry: slots were pruned only on write, so with no refresh for a
  day they stayed in `sn_live_slot_detail`. **Fixed**: every read writes the
  pruned set back (`sn_analytics_live_slots()`); pinned.
- The dossier's First hour block is gated on `edit_post`: **accepted**. A
  single-owner site; it carries lane names and counts only.
- No uninstall cleanup of the two options and the meta: **deferred**; the
  slots expire on their own, the window cache is bounded.
- Possible double-encoding of the slot links: **checked, not real**. Values
  are `rawurlencode()`d once and handed to `add_query_arg()`, which does not
  encode (the same pattern as `snt_os_analytics_request_uri()`); `esc_url()`
  keeps the `%XX`. `country:US` reaches the link as `country%3AUS`, once.

Data integrity:
- M1, slots overwritten on merge: a 5-minute boundary between the hour read
  and the slot reads could cut the oldest slot short and overwrite a good
  copy. **Fixed**: a slot is stored once, never overwritten; pinned.
- M2, silent undercount: `sn_publish_window_read()` ignored a truncated
  answer and the over-cap list's `ok`. **Fixed**: every read goes through
  `sn_lanes_read()` (truncated is a failure), an unread over-cap list is a
  failure, and the report is `unread` with `why` (`read_failed`, `truncated`,
  `overcap_unread`, `path_unreadable`); the dossier says which; pinned.
- M3, the "window + after = total" claim: **fixed in the docs**. It holds for
  hits only. Visits and visitor-days count a visitor-day that straddles the
  window's end in both parts, and once the window is cached the after half is
  read alone, so a visitor-day's bot-score maximum there can differ from the
  window's read and a row near the end can change lane. Qualified in
  `docs/TRAFFIC-LANES.md`, the header of `inc/publish-window-parts.php` and the
  catalog.
- The new AE SQL shapes (`GROUP BY slot, class`, `ORDER BY slot, readers
  DESC`) need a live check after deploy: **owed post-install**. A refusal
  shows as `[sn-live-slots]` in the error log and an empty store.

Each fix was broken back and its suite watched go red (4 of 4).

## What's left

- A remote `publish_window` filter on `sn_remote_analytics_query` (contract bump,
  deferred by the owner).
- Posts tab rows have no window column (most notes have no stamp; a per-row
  read would be N reads per load).
- The live readout of an open window is as fresh as the dossier, not ticking.
- Verify live after deploy: one refresh writes `sn_live_slot_detail`; the
  grid's links navigate in the native window; the next published note gets
  `_sn_published_at`.
