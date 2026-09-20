# Session, 2026-09-20: the native view reaches everything

One rule, said once, and a day of finding where the house had quietly broken
it. "Everything has to have the OpenStation native compatibility." The owner
lives in the desktop, and in the phone's PWA of it, and a control that only
exists on a classic wp-admin screen is a control the owner cannot reach. An
audit of every classic control against every native leaf came back with
eleven real gaps. Four shipped today in one release, 17.3.0, with a fifth fix
the audit did not name and the owner's one question did: "I hope everything
is compatible with the PWA in desktop and mobile."

## The audit (141 agents, two skeptic rounds)

I ran the survey as a workflow: readers over every classic surface and every
native leaf, one finder per gap, then two rounds of skeptics prompted to
refute. Eleven survived, ranked by what the owner could not do. I told the
owner the ranking and my order (#1, then #3, then #2 and #4 as a release) and
built nothing until "Go with your recs. #1 first."

## #1: a refusal that only fired on the screen the owner never uses

Mode A (13.58.0) refuses a breached password at set time, fail-closed, on the
two hooks core's profile and reset forms fire. OpenStation's profile window
saves through `PUT wp/v2/users/{id}`, and core's users controller hands the
request's `password` to `wp_update_user()` with neither hook fired. On the
owner's own path a breached password reached the hash unchecked; the file's
header promised otherwise.

The first draft hooked `rest_pre_insert_user`, the obvious seam, and returned
a `WP_Error`. My security-review sub-agent said core returns that error as the
response. It does not. The workflow's core-flow skeptic fetched
`class-wp-rest-users-controller.php` and quoted `update_item()` lines 809-814:
no `is_wp_error()` check, `->ID` set on whatever came back, `(array)` of it
written. A `WP_Error` there has no `user_pass`, so the breached password is
not written, the client is told 200 with the old record, and every other
field in the PUT is dropped. The posts controller checks; the users
controller never did.

The refusal moved to `rest_dispatch_request`, the short-circuit every
controller honours: it runs after the route's permission callback (no
unauthenticated caller can turn the breach client into an oracle) and a
non-null return is the response. Scoped to writes whose handler is the users
controller. 39 pins, one of which keeps the file off `rest_pre_insert_user`
by name. The lesson went to memory: a verifier that argues core's behaviour
must quote core's source. Mine recalled it.

## #3: a script that walked table cells the kit never paints

`assets/health-suggest-actions.js` was enqueued in the window and its document
listener already reached every kit `<os-button>`; Dismiss worked. Every DOM
walk was wp-admin-shaped: `closest('td,th')` for the cell, `closest('tr')`
for the row, `.sn-fieldset` for Suggest all. A button with no cell returned
in silence, and the two Content leaves shipped Suggest disabled with a title
pointing at the classic page while Monitoring › Health painted no Suggest at
all, its findings inside an `<os-table>` whose cells are JSON in a shadow
root.

One cell selector, one row selector, one section selector serve both shapes;
the Content leaves enable the button; Health paints its findings as the Block
Migrations row shape with the AI fix column on the classic gate, the data
contract split out of the classic cell renderer byte-identical. A verifier
drove the whole loop in a browser against OpenStation's real components and
found the one thing no test could: inside an `<os-cluster>` the editor the
script paints is a flex item with no basis and took 152px of a 286px cell.
One CSS rule.

## #2 and #4: the two seams the shell offers and the house had not taken

The Posts window has no bulk dropdown, so "Reschedule (Signal & Noise)" was
unreachable from it. The shell offers `openstation.postsWindow.bulkActions`;
its confirm is yes/no only, so the date is asked in an `<os-modal>` holding a
native `datetime-local` input labelled as site time, the same wire value the
classic field posts. The write moved into one shared
`snt_batch_schedule_apply()` that both surfaces call, with per-id
`edit_post` and post-type guards at the trust boundary counted as skipped;
this tightened the classic path too, and the skipped count became a fourth
sentence and a fourth query arg rather than a silent drop.

Connections › Cron's native leaf painted the events table alone and its
header said the classic tab had no forms. The tab is the whole
`sn_admin_cron_tab` hook, and two of its three callbacks carry one. The
oracle had captured the priority-10 callback alone, so parity passed as
`[] === []`. Both boxes now share one row under the ledger, drift as a notice
on top, each secondary verb its own kit form. And the load-bearing find: both
handlers read the toggle with `isset()`, and a native unchecked box arrives
as `''`, so a window Save could switch either toggle on but never off.

## The PWA question

"I hope everything is compatible with the PWA in desktop and mobile." The
leaves were (kit markup, `.snt-cols` to one column under 640px). The Suggest
loop was (`wp.apiFetch` refreshes its nonce on a 403). The reschedule write,
as mapped, was not: it carried the plugin's existing pattern, a `wp_rest`
nonce localized at page load, which is the trap already on record for links
("a PWA page cannot carry a nonce") wearing a different coat. OpenStation's
`wp.os.fetch` stamps the shell's own nonce at call time and the heartbeat
refreshes it every tick. Four scripts carried the load-time nonce; all four
moved, the localized values are gone, and the pins refuse the header, the
config key and a raw fetch in those files.

Rendering the leaves and the modal at 375px in the in-app pane, against the
station's real theme tokens and component bundle, found two more things: the
date input sat at its intrinsic 211px in a 300px modal, and a column-header
`os-row` stacks on the phone into four labels with nothing beside them.
Both fixed before the cut.

## What shipped

Plugin 17.3.0, one cut for the arc (#1554): #1544, #1547, #1550, #1551, #1553.
Memory: the obvious REST seam reads no refusal back; a PWA page's scripts
cannot carry a nonce either; the audit's gaps #5 to #11 with their files.

## The second arc, the same day: readings find their homes (17.4.0)

"Go." The seven remaining items went as one arc: eight mappers (one per gap,
one placement critic), seven builders in parallel worktrees, two verifiers
and a repair round each, seven security reviews, seven gated merges, one cut.
The critic's rule for each was the house's: a reading lives where its question
is asked. So the breached-password figures and the login memo landed on
Security › Login defense (a second box beside the lone one, the memo as the
notice on top); the Action Scheduler backlog on Connections › Cron, painted
directly on the on-demand leaf rather than behind a fold; the
platform-reported spend on AI › Models & Budget under the plugin's own token
estimate, a missing key saying so in words; the slow admin requests on
Site › Performance, hosts only; the Evergreen flag as a Posts-window column;
and the resume arrows as kit buttons the classic script already handles,
after tracing that DOM order survives the kit form's harvest, the replay's
expand and the normalizer's reindex. The Suggest script mints kit buttons
inside the kit now.

The verifiers earned their keep again: a silent-green toggle pin the new box
had disarmed (a substring grep over the whole leaf), an accessible name on an
`<os-button>` host that the kit never forwards to its inner button, a warning
that promised a Verify verdict for a key that has no probe, a raw and a
formatted rendering of the same number inside one box.

## The third arc: Enter on Cancel applied (#1572)

A keyboard reading of the Suggest modal, not a screen. The document keydown
handler read every Enter outside a textarea as Apply: `preventDefault()` ate
the focused button's own click and `accept()` ran, so Tab to Cancel (or the
close x), press Enter, and the write went through on every Apply ability the
modal fronts. Pre-existing on the classic box since the modal replaced
`window.confirm()` in 4.0.3; the `<os-modal>` twin in flight in another
worktree carried the same line unchanged.

The fix is five lines in the one handler: read the Enter target through the
shadow root (`e.composedPath()[ 0 ]`; at the document a keydown inside a kit
host retargets to the `<os-button>`, or to `<os-modal>` for its own close x),
and when it is a button, return before `preventDefault` so the button runs
its own click. Cancel cancels, Apply applies once through its listener, Enter
anywhere else still applies. The `composedPath` read is the one deviation
from the brief, which named `e.target`: that read cannot see the kit modal's
close x, which sits inside `<os-modal>`'s shadow root, and Enter there would
still have committed.

The verification taught me the day's second instrument lesson. The desktop
pane's key tool delivers a keydown with no activation: Chrome never
synthesizes the button's click from it, so on the fixed page "modal still
open, no apply" looked like a pass and on the unfixed page nothing happened
at all. A control button with its own click listener, pressed the same way,
showed no click and named the artifact in one step. Playwright's
`press('Enter')` carries the text the browser needs; driven headless against
four fixture pages (classic and kit, each unfixed and fixed, one Health row
and a stubbed `sntAbilityRun` that logs calls), both cancel controls
committed on both unfixed pages and both cancelled on both fixed ones, Apply
applied once, Enter elsewhere applied. The kit page ran a scratch copy of the
17.4.1 script with the same five lines; the sibling worktree stayed untouched.

Three pins in the file's own `substr_count`/`preg_match` shape, red against
the unfixed script by `git apply -R`, green with it; the sweep at 706 suites
and 31,580 assertions. Merged on `CLEAN` after a fresh re-read, squash, no
bump; rides the next cut.

## The fourth arc: boxes match their partners (17.4.1)

Two screenshots, one sentence each. AI › Models & Budget: "You could've done
this more tidily." Content › Tags: "The tags leaf is impossible, too." Both
showed the same shape: a row pairing a short box with a tall one, so a
column sat empty for a screen or two, and in Tags a per-tag reading painted
as nineteen paragraphs beside a box a fifth its height. My critic had judged
each 17.4.0 placement by the question it answered and never looked at the
whole leaf.

I measured instead of guessing: through the owner's own session, the
dashboard app's cross-tab door opened every leaf in turn and a script read
the two sides of every row and every column of every two-column split, with
the live data. Eight leaves were past 1.5x. Eight worktrees, one rule ("a
row pairs boxes of comparable height; a long per-item reading is a capped
table; a tall box stands alone"), two verifiers each, and a chain of eight
gated merges with a CHANGELOG-only conflict resolver, then one fix cut.

The chain taught me two things at the owner's expense. It rebased each PR
before its gate but only pushed after a conflicting rebase, so a clean one
left CI gating the stale head; and the Tags fixture's mean of 0.225 formats
as 0.23 on PHP 8.3 (CI's sweep, pre-rounding) and 0.22 on 8.4 and after
(production and my machine), which one pin had hard-coded. A local PHP 8.3
reproduced it before the fix. The same day also closed the dead S&N Health
widget module (652 lines nothing had called since 11.30.0), moved the
Suggest preview onto the kit's modal, and sent two upstream OpenStation PRs
(#856 the bulk-actions filter learns the window mode, #857 os-button forwards
a host aria-label) with their issues filed first and no duplicate found.

Re-measured after the install: Tags 2950 to 1789 tall, Models & Budget 1336
to 1053, Insights 1247 to 958, RSS 1345 to 864, and every row within about
1.5x. The Standards tag description, rewritten to what its seven notes argue,
took the archive's mean from 0.96 to 1.37 with two shrugs left.

## What is open

The Suggest modal itself is still a wp-admin box appended outside the kit
shell; `<os-modal>` is the twin and a larger diff, in flight in a sibling
worktree as 17.4.1, and the Enter fix rebases onto it byte for byte. The shell-level breach
banner waits on OpenStation's window-notices surface reading Stable. The live
shell is the remaining witness for the Reschedule modal on the real Posts
window and the Pages-window toast.
