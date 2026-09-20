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

## What is open

Gaps #5 to #11 (the login memo unseen in the window, the Spend section painted
nowhere, resume row reorder, two Site Health rows, the evergreen column, the
slow-HTTP panel): readings without a home, each a fold onto an existing leaf,
none built without a said yes. The buttons the Suggest script mints inside the
kit (Discard, Apply, the modal) are still wp-admin `.button`s. The live shell
is the remaining witness for the modal on the real Posts window and the
Pages-window toast.
