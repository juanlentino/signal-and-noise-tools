# The Signal & Noise app, phase four: the phone

Phase four of the app program (#1071). Phases one to three made the app read the
estate about a note, act on notes, and list the estate's other subjects. Phase
four makes it worth opening on a phone: what needs the operator now, in one
place; the phone's own defects fixed; and the one trap the phone PWA already
sprang once, a stale client, said out loud.

## Decisions (from the code map, `map4/*.md`)

| Question | Decision | Why |
|---|---|---|
| What is "the phone queue"? | An **Attention** section, kind `entry`, position 5 (first at the root), capability `manage_options`, that lists the estate's attention signals as rows. | The shell owns the phone's four surfaces and leaves an app exactly its window body; a queue has nowhere else to live. An entry section costs one PHP file and is inert to the control surface by absence. |
| Which signals? | Only those with a READER that reads state something else computed: the last integrity sweep's failures (`sn_prov_integrity_state()`, pure findings), commits unanchored or pending (`sn_prov_admin_status()`, both types), stale probe rows (the probe log), citations never checked (`sn_cit_counts()['never_checked']`) and due (`sn_cit_due_for_check()`), fragments and posts with a transition within 24 h (`sn_admin_schedule_ordered_rows()` and its transition timestamp), posts pending review (`wp_count_posts()`), the last health scan's failing checks (`sn_health_last_scan()`), ripe watches (`snt_watches_ripe()`), a stale reader snapshot (`snt_mr_snapshot_is_stale() === true`). | "It reads, it never computes" is the estate's own rule for the attention badge; a queue must never trigger a scan, a sweep or a probe. |
| How is a row shaped? | `id` = `a-<kind>-<key>`, `title` = the subject, `subtitle` = the fact, `status` = the kind, `dateLabel` = the signal's own stamp ("as of", "scanned", "probed"), badge tone by severity (danger for a failure, warning for a stale or overdue state, neutral for a due date), `detail` facts, actions = a door to the owning leaf and, when the row names a post, "Open the note" (a new server action `jump` that sets section and item together). | The entry item shape phases three fixed; the dossier is the deep view and it exists already. |
| Absent vs zero | A reader that throws or returns null yields ONE row per signal: "<signal> could not be read" (warning), never a zero; a signal that measured nothing yields no row; an empty queue paints "Nothing needs you" with the newest stamp among its readers. | Four "absent is not zero" contracts in the readers; the sandbox's SQLite refuses the citations-due SQL, which is exactly the case. |
| Cost | `items()` runs the readers once per call and caches the composed rows in a 60-second transient with its `read_at`; `count` reads the same cache. | Without a cheap `count`, the payload runs `items()` on every root paint; the readers include a chain walk and an unbounded schedule read. The cache is stated on the section ("as of"). |
| Capability | `manage_options` for the section; the pending-review row uses the same author scope as the post sections. | The rows are operational; the entry sections already sit at `manage_options`. |
| Gestures | None added. Long press stays the phone's menu gesture; the shell's edge-back and switcher stay the shell's. | The shell exposes no swipe-to-action, no pull-to-refresh, no haptics to an app; its gesture module is not in the client API. A control the Explorer lacks is not invented. |
| The list view on a phone | `<os-table stacked>` on the phone, no sticky column. | `docs/mobile.md` names the sideways table with a pinned column as the shape never to ship; `stackOnPhone` is what the shell's own lists use. |
| Band crossings | The client subscribes to the shell's mode change (`os-mode-changed`, as WP Explorer does), repaints, and mounts or tears down the desk-only listeners (marquee, drag) per band. | A window born on the desk kept its drag listeners into the phone band; one born on the phone never got them back. |
| The way back | Every item page on the phone gets a "‹ Back" control in its header beside the crumb, including entry sections. | The crumb was the only exit and the More button is post-sections-only. |
| The canvas menu by finger | The long press on the empty canvas opens the canvas menu (Refresh). | iOS never synthesises `contextmenu` from a held finger. |
| Callouts | The suppression rules run under `@media (pointer: coarse)` as well as the mode stamp; the dead `is-phone` class is removed. | The Explorer writes both; the tablet band is coarse without the stamp. |
| The stale build | `SNT_VERSION` ships in `App::config()` (frozen at document render, `ctx.extra.version`) and in `payload()` (fresh on every dispatch, `ctx.data.version`). When they differ the client paints one line beside the crumbs, present in both view branches: "A newer build is installed (X); this window is running Y." with a Reload button that calls `location.reload()` on the click only. | OpenStation's update toast is keyed to its own asset stamp; a plugin release never reaches it; the shell's flushed reload is not reachable from an app, so the unflushed reload is a booked cost on an operator's explicit click. |
| Discography's root cost | Discography gains a `count` callable. | The one section without one builds its whole list on every root paint, the phone's first screen. |

## Out of scope

Swipe rows, pull-to-refresh, a notification centre, haptics, push; the tablet band; any new operation (phase two closed that list); a "long-pending" threshold for anchors (no such number exists in the code; the row says how long, and lets the reader judge).
