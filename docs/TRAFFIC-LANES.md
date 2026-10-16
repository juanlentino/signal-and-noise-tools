# SN Traffic Lanes: live snapshots and publish windows

The lanes themselves (nine lanes, the beacon and machine sensors, the nightly
rollup into `wp_sn_analytics_lanes`) are described in `inc/analytics-lanes.php`
and `inc/analytics-lanes-beacon.php`. The plan those files cite,
`docs/plans/traffic-lanes-2026-10-15.md`, is not in this repository. This page
covers the two follow-ups of 2026-10-16.

## Live snapshots

**What.** Each completed 5-minute slot of the last 24 hours, as the live panel
would have shown it: human readers (the hour bar's own figure), readers by
traffic class `{ human, suspect, bot }`, the top five pages (published,
indexable notes and pages only, the live panel's resolver) and the top five
sources (the dashboard's source names).

**Classes, not lanes.** A snapshot uses the live panel's three traffic classes.
Lanes are derived per site-local visitor-day at rollup time; a 5-minute slot
cannot carry them.

**How.** The realtime refresh (`inc/analytics-realtime.php`) runs on demand
while someone watches (at most every 30 s) and every 15 minutes from the
analytics Worker's cron. After it reads the hour it calls
`sn_analytics_live_slots_step()` (`inc/analytics-live-slots.php`). When the hour
holds a completed slot the store does not have, three grouped Analytics Engine
reads (classes, pages and sources per slot over the last 60 minutes) store
every completed slot of the hour. A refresh inside the same 5 minutes reads
nothing. The live answer's own queries are unchanged.

**How long.** 24 hours, in the non-autoloaded option `sn_live_slot_detail`.
Older slots are deleted on every write and on every read (the read writes the
pruned set back), so they leave the option even when no refresh has run. A
slot is stored once: a later read of the same slot never overwrites it, since
a 5-minute boundary passing between the hour read and the slot reads can cut
the oldest slot of that later read short.

**What a snapshot never contains.** A visitor id, an IP, a user agent, a
cookie, a query string or any other request text. Pages are labels and URLs
that already pass the public-page resolver; sources are names.

**No floor.** Like the live panel, a slot shows a page read by one person.
That is the owner's decision (2026-10-16); snapshots are admin-only.

**Where.** `signal-noise/live-now` with `hours_back` (1 to 24), and the grid
under the admin Right now block on S&N Analytics' Overview, classic and
native: one row per hour, one cell per slot. A cell is a link; following it
paints that slot's class split, pages and sources. No script: on the classic
page it is a page load, in the native window the host turns the own-page link
into a `go`, and `sn_slot` is window state.

## Publish windows

**Definition.** A note's publish window is `[published_at, published_at +
length)`. `published_at` is the post meta `_sn_published_at` (unix seconds),
written from `time()` when a note first moves to publish: the real go-live
moment, whether WP-Cron published a scheduled note late or the owner pressed
Publish. Half-open: a hit at exactly `published_at + length` is after the
window.

**The stamp.** Written once. An update never touches it; a republish keeps
it. The one exception: if `post_date_gmt` is moved past the stored moment the
note relaunched, and the stamp resets (to `time()` on a transition to publish,
to the new date when a published note's date is edited in place). No
backfill: a note published before 2026-10-16 has no window, and its report
says "Published before publish windows existed."

**The length.** `analytics.publish_window_minutes`, default 60, 5 to 1440.
Set under Measurement › Analytics › Setup › Publish windows (native) or the
Publish windows fold of the classic Analytics settings. A changed length
re-reads closed windows on their next report.

**Computed on demand.** No stored series. When a report opens, the SN Traffic
Lanes beacon derivation (`sn_lanes_public_sql()`, `sn_lanes_owner_sql()`,
`sn_lanes_beacon_derive()`) runs on that note's path alone, from
`published_at`, with a `part` column that labels each row `window` or `after`.
Every row lands in exactly one part, so window + after is the total page
views (hits), lane by lane. That holds for hits only: visits and visitor-days
count a visitor-day that straddles the window's end once in each part, so
their window + after can exceed the total. And once the window is cached the
after half is read on its own, so the bot-score maximum per visitor-day there
can differ from the one the window was read with; a visitor-day active on both
sides of the end can then sit in different lanes in the two halves. Once the window has closed its figures and its top sources are cached in
`sn_publish_window_cache` (not autoloaded; entries older than Analytics
Engine's 90 days are dropped, and at most 200 are kept); after that only the
after-window half is read, from the window's end to now.

**Never a short count.** A read that fails, comes back truncated, or runs
while the over-cap visitor list cannot be read (so the human cap would not
apply) is `unread`, with `why` saying which (`read_failed`, `truncated`,
`overcap_unread`, `path_unreadable`); the dossier says so in words.

**A label, not a lane.** `publish_window` sits beside the lanes. There is no
ninth lane.

**Reading window against after.** The window is the launch burst: the owner
checking the page, automation that follows a new URL, and the first readers
from wherever it was announced. Read the after-window human figure first; it
is what the note earns once the burst is over. The window's split says how
much of the burst was the owner (owner, owner agent, unverified owner) or
suspected automation against presumed humans. Own infrastructure comes from
the machine sensor, which keeps no paths, so it is never counted per note.

**Where.** The Signal & Noise app's note dossier, under Numbers ("First hour"):
the human readers after the window lead, then the window by lane, then its top
sources; while it is open the tile says "window open, N min left", as fresh as
the dossier. Locally, `signal-noise/posts-signals` with `publish_window` (a note
id). The remote door is unchanged.
