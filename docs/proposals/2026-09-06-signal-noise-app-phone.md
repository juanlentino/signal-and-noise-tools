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

## Amendments (2026-09-06, from the build)

### Task A

SEVEN deviations, each deliberate:

1. THE PLAN'S ONE FILE IS TWO. attention.php came out at 1145 lines — larger than any file in inc/ (the current maximum is insights.php at 1062) and past the house's 800 ceiling. Split along a real seam rather than by line count: apps/signal-noise/parts/attention-readers.php (597 lines) holds the nine readers and is THE ONLY HALF THAT CALLS THE ESTATE; apps/signal-noise/parts/attention.php (590) holds the vocabulary, ordering, cache, item shape and descriptor and calls no signal. Both are required from signal-noise.os.php (readers first). The seam is PINNED, not just stated: Group 8 of the new suite fails if the composition half calls any sn_/snt_ reader other than snt_os_app_section / snt_desktop_admin_url, and fails if a kind has no reader in the readers file. Verified it can fail (added a bogus snt_watches_ripe() call to the composition half → red, removed → green).

2. `empty_note` is a CALLABLE on the descriptor, not a literal, and payload.php gained `section_text()` to resolve a literal-or-callable for the OPEN section only. A literal would have to be composed inside the registration filter, which runs BEFORE snt_os_app_sections()'s capability gate — so every visitor without manage_options would pay for a reading only an administrator may see. `empty_heading` goes through the same helper (it is a literal today).

3. attention_integrity() emits ONE ROW PER FAILING NOTE with every failure sentence joined by '; ', not one row per failure code. The fleet-level key verdict (`last_sweep['keys']` = key_mismatch / keys_missing / keys_unreachable) is a SEPARATE row keyed `keys`, titled "The ledger's key file", stamped with `swept_at`, with no post to open and the same Trust door; its sentence is word for word the one `sn_prov_integrity_findings()` files for that verdict (`sn_prov_integrity_failure_sentence()` has no `keys_missing` leg, so it was never the right table). Tone: danger for key_mismatch and keys_missing, warning for keys_unreachable (an outage, said as one). `ok` and `skipped` make no row. Pinned in the attention suite, including a source-parity pin so the two sentences cannot drift apart silently.

4. attention_pending() GATES the row on the type's edit_others_* capability rather than scoping the count. wp_count_posts() is site-wide and WordPress offers no `perm` that scopes pending (readable guards `private` only — the same trap post-items.php:22-31 works around with a posts_where clause, which a count cannot use). Without that capability the reader emits nothing and the honest surface is the post section's own scoped Pending pill. Pinned both ways.

5. Discography's REGISTRATION GATE now calls albums_count(), not albums_items(). It was building every release — cover art, tracks, dossier — on every single resolution of the registry (once per dispatch plus once per section lookup) just to ask whether the list was empty. The plan asked only for the `count` callable; this is the same defect one layer up. Negative control in tests/openstation-app.php: an entry whose title is an object counts fine and makes albums_items() throw, so the count is measurably not going through the builder.

6. TWO PINS ADDED beyond the plan's list, both in tests/openstation-app.php: (a) a foreign section's emptyHeading/emptyNote read as '' — a section cannot inherit another's wording by omission; (b) with NONE of the nine readers installed (which is exactly that fixture) Attention counts 0 and emits no warning rows — the negative control on "an absent subsystem makes no claim", which the attention suite cannot express because its stubs are always defined.

7. `'version' => defined( 'SNT_VERSION' ) ? SNT_VERSION : ''` in both config() and payload(), not a bare constant: the app file also loads under OPENSTATION_STANDALONE, where the plugin constant may be absent, and a fatal there would take the window down to detect a stale build.

### Task B

Three, all narrow.

1. `sticky-columns` is written as `sticky-columns=${ phone ? '0' : '1' }` rather than being omitted on the phone. The plan said "sticky-columns only when not phone"; the component reads the attribute with `parseInt( getAttribute( 'sticky-columns' ) || '0' )` and treats <= 0 as none (os-table.ts:1779-1782), and `stacked` stands the whole sticky band down first (os-table.ts:1811-1812), so "0" is the same state, said out loud. There is no way to conditionally omit an attribute with this html tag without a second full template.

2. The coarse-pointer block and its mode-stamped twin cover FOUR selectors, not the two the mobile block had: `.snt-folder, .snt-cell, .snt-canvas, .snt-table`. `.snt-canvas` is load-bearing for this task — the long press I wired there opens a menu and iOS's callout would land on top of it — and `.snt-table` is the map's finding (rows live in os-table's shadow root, but `user-select` and `-webkit-touch-callout` inherit from the host). This matches the Explorer's own four-selector set (my-wordpress.css:1990-2006).

3. The Back control's explanatory note is a JS comment above `return html`, not an HTML comment inside the template. The core html tag does parse `<!--` (src/ui/core/html.ts:131,340), so a comment node would have been safe, but no app in either tree writes one and it would ship a comment node into the DOM for no reader.
