# Session — 2026-09-03: the purge readout, and four attempts to fix it

A separate record from [the 2026-09-02 session](session-2026-09-02-tag-vocabulary-and-glossary.md),
because it is a different subject and because it deserves to be read as one
arc: **four attempts, three of them rejected, and each rejection was right.**

It started with a screenshot and one sentence.

> *"Look at the cache... The stale number is going up each purge."*

## Attempt 1 — I proposed relabelling it

I read the code, decided the edge was fine, and offered to fix the *wording*: the
Classic Admin cell said "still stale after 1 minute", which asserts a present
state from a single past probe with no recheck. That was a genuine defect and it
shipped. It was not the answer.

> *"No, fix it definitely. Not for what does it say now."*

Correct. I had verified freshness with a `<main>`-scoped HTML comparison while
the thing reporting staleness reads `<meta name="sn-render-epoch">` from
`<head>`. **My detector was structurally blind to what the other one measured.**
I could have "confirmed the edge was fine" indefinitely.

## Attempt 2 — I tuned the race

`sn_purge_verify_routes()` re-evicted Cloudflare at the top of every retry and
then waited, so each attempt invalidated the copy the previous wait was giving
time to land. It could only ever measure the edge ~1.5s after a purge. Real
defect, shipped as v12.18.1.

The next press failed. Because it was still **tuning a race inside a request a
human waits on**, and no few-second budget outlasts zone-purge propagation.

## Attempt 3 — the mechanism was already there

`sn_after_purge_schedule_verify()` opened with:

```php
if ( ! empty( $args['verified'] ) || ! sn_purge_is_edge_affecting( $args ) ) {
    return;   // "A verified (manual) purge already probed inline"
}
```

**Deferred verification existed and worked. Manual purges were the one case
opted out of it.** Auto purges get a cron re-probe at +75s; the button got only
the inline probe. That is why every auto purge resolved fresh while four of
eleven manual ones recorded stale.

Fixed in theme v12.18.2 + plugin v13.87.1. Then:

> *"It's going down each time I purge the cache. THAT'S NOT HOW IT'S SUPPOSED TO WORK."*

## Attempt 4 — the cause

Each purge appended a row to a bounded 20-row buffer, evicting the oldest. So
pressing Purge *mechanically* lowered the stale count. **The same defect
mirrored**: first it made the number climb, then it made it fall. Either way the
number answered *"how often did you press Purge?"* rather than *"do our purges
clear the edge?"* — and I had spent an hour narrating the decline as progress.

The evidence was in front of me the whole time, as manual rows crowded the
diagnostic out of its own window:

| read | manual | post-save |
|---|---|---|
| earlier | 10 / 20 | 10 |
| then | 13 / 20 | 7 |
| then | **15 / 20** | **5** |

My first instinct was to split the storage so the counter would stop moving.

> *"Fix the cause. Don't hide it."*

Right again. Partitioning the symptom is not removing it.

**The cause:** v13.70.0 answered a real complaint — *"I purged them, but that
didn't change"* — by writing an OPERATOR ACTION into a MEASUREMENT STORE. Every
defect in this document is downstream of that one duplication.

`sn_last_purge_report` already carried the purge's time, epoch and resolved, and
the deferred verify corrects it in place. The cell should read the source of
truth. So manual purges now write nothing to the probe log,
`snt_cf_freshness_summary()` reads the report, and
`inc/cloudflare-manual-purge-settle.php` — shipped an hour earlier to copy a
settled verdict into that log — was **deleted**. v13.87.2.

## And then the last one

I labelled the remaining two figures "Post-save probes" and "Stale (post-save)".

> *"Why this 'Post-save probes' and 'Stale (post-save)'?"*

I answered about the labels and offered better words.

> *"My question wasn't about the label. It was about the existence of those two
> things as a whole."*

**The ruling already existed, in the file I had been editing all night.**
`inc/dash-widgets-render.php` records that the Classic Admin cell answers ONE
question about ONE event, and that v13.70.1 removed its running count under
*"If it's fresh, it is fresh. If it isn't, it shouldn't say."* The OpenStation
tile kept a tally — the same construct, surviving on another surface, and the
direct cause of every misreading here.

v13.87.3 removes it. The tile shows the verdict and its age; a figure appears
only when there is a problem. The series keeps two homes, both pinned by the
suite so the removal cannot become a silent loss of evidence.

## What I keep doing wrong

**Scoped searches invented gaps, twice in one night.** Asked what amplifies per
purge, I grepped SCHEDULERS of the probe hook and concluded nothing did — never
grepping WRITERS of the log, where the answer was. Earlier I claimed nothing
could read the rows, from grepping readers of the SUMMARY function, which by
construction cannot find a reader going to the option directly.

**I fixed the thing I had just been looking at.** Three times the proposal was a
smaller version of the real answer, because I proposed from wherever I happened
to be reading rather than from the whole path.

**A number that reacts to the operator is not a measurement.** Both directions
should have told me the same thing, and the second direction only registered
because the owner said so.

**"Should this exist" beats "make it clearer."** When someone questions a thing,
a rename is the cheap answer. It is also how a construct that has already been
ruled out survives another release.

## Guard notes

Every fix here needed its guard repaired before it was worth anything:

- A mutation deleting the retry wait **passed every assertion** while `usleep`
  lived inline — the suite runs with `backoff_us = 0`. Extracting the decision
  is what made it testable.
- The guard on the deferred-verify branch kept passing through the change,
  correctly: its scenario's inline probe resolves, which is still a no-schedule
  case. The untested branch was the other one.
- `S9` asserted "a confirmed purge whose edge is STILL stale records stale" —
  right for v13.70.0, exactly backwards afterwards. Rewritten, not adjusted.
- Removals need tests too. Three mutations guard the tile's *absence*: the tally
  returning, bad news suppressed, and the empty section still drawing its rule.
- Deleting a cron HANDLER does not delete its scheduled EVENTS. A pending
  `snt_cf_settle_manual_purge` would have fired into nothing forever — and this
  plugin reports that as an ORPHANED cron, so the removal would have surfaced on
  the dashboard as a defect of its own. Caught by the owner asking "these are all
  fixes, right?".

## Versioning

Owner: *"This should be a patch/fix, not minor."* Four over-bumps in one day —
13.85.0, 13.87.0, theme 12.18.0, and 12.19.0 caught before shipping. Each added
a function, a field or a registration, and I let "there is something new in the
diff" stand in for "the user can do something new".

The test that would have caught all four: **name the capability a caller gains,
in one sentence, without using the words fix, correct, restore or complete.** If
you cannot, it is a PATCH. Shipped tags were left alone — the updater reads tags,
and re-cutting means force-pushing over published ones.

## Verified, finally, by a non-event

The log froze at `04:37:36` and stayed there across two purges and two plugin
updates — every figure byte-identical between reads forty-four minutes apart.

Every other check that night looked for a value to move the right way. This one
required watching a measurement hold still while the system around it was
operated, which is the only shape of evidence that supports "a diagnostic must
not react to the operator".

Final: plugin **13.87.3**, theme **12.18.2**.

---

# Part two — the instruments nobody could read

The purge arc closed. What followed was the same defect wearing four different
costumes, and I shipped three of them myself.

## The ledger had a writer and no reader

Asked what was outstanding, I checked rather than recited — and found that
`sn_shape_stability()`, the function the whole shape-ledger module exists to
expose, was **called from tests and nowhere else**. No ability, no door, no
admin surface. v13.84.0 built it, v13.85.0 gave it an hourly writer, and for
four days it filled correctly into a store nothing could read.

The decision it was built to inform — freezing the `reader-anomalies` payload
into a remote twin — was reachable only by `wp eval`. Which is the position the
ledger replaced: a judgement made from recollection.

**That is the same defect `purge-verification-log` was added for the day
before.** An instrument with no reader reports to nobody. I fixed one and then
built another.

### The plan missed two contracts; the suite caught both

Adding one ability moved five things beyond the obvious files:

| contract | |
|---|---|
| two pinned read-door counts | 30 → 31 |
| `ability-permission-policy.php` | **exact-set** sweep — reds until listed |
| `abilities-sn-status.php` | its own copy of the section map |
| same file | that map's **pinned size**, 19 → 20 ← *missed* |
| `mcp-remote-verdicts.php` | **totality pin** ← *missed* |

The remote-verdict pin is the good kind of contract, and its docblock says why
it is a test rather than a document: the remote allowlist could have been "the
read door minus some", but an exclusion list **fails open** — the next person
adding a local section silently widens what a phone-reachable path can read.
Demanding an explicit verdict per section fails closed instead.

`shape_stability` is recorded as NOT twinned, for a reason that is nearly funny:
this section decides whether a shape is stable enough to freeze *into a remote
twin*. That question is asked at a laptop in the minutes before cutting a
contract bump. From a phone there is nothing to do with the answer.

## Then three omissions of the same shape

Used the new reader an hour after shipping it. It said `settling`, 17 of 24
readings, `since` 02:35. And `since` being recent has two opposite meanings —
the clock STARTING, or the countdown RESTARTING because the shape moved. The
first says wait; the second says the payload is still changing and waiting is
not the answer.

v13.88.1 added `changes[]` and `ever_changed`. Answer: `false` — the clock
started. Settles **2026-09-10 02:35 UTC**.

Then the GSC drift watch came due and read `accruing`, which was true and
useless: indistinguishable from "stuck and will never flip" without reading the
derive source. v13.88.2 made it say `6.0 of 7 days across 7 snapshots` — one day
short, flipping tomorrow, producer healthy.

Three in one day, and it is one habit:

| version | reported | omitted |
|---|---|---|
| v13.87.0 | `algo` | `source` |
| v13.88.1 | `since` | `changes` |
| v13.88.2 | `accruing` | its span |

Each time I shipped the *verdict* and left out the field that says how to read
it. The rule that would have caught all three: **when a payload reports a state,
ask what a reader needs in order to act on it — not just what the function
knows.**

## I read the backlog from the middle

Asked twice more whether anything was outstanding, I said no twice. The second
"wasn't there anything else?" was right: I had read `docs/BACKLOG.md` from
"Ready to build" downward and skipped the two sections above it, where the
OpenStation watch lives.

## Headline and exposure pointed opposite ways

OpenStation trunk is **32 commits past v1.1.5**, and the log reads like a compat
emergency: the App Framework landed and four native windows were rebuilt on it,
each *"legacy window deleted whole"*. Plus a mobile layer, multisite desktops, a
PWA shell.

One query settled it. `window.openStationWidgets[ id ]` is unchanged on trunk —
the churn is in WINDOWS, not widgets, and our seven mount through the widget
registry. **Reading the release notes would have produced the wrong alarm; the
registry is the thing to check.**

Live: #717 (the `wp.hooks` re-execution fix) is merged and UNRELEASED, so v1.1.5
still carries that bug. #702/#703/#705 all shipped in v1.1.5 — the palette
should now invoke our 19 commands rather than listing them and doing nothing.

## Housekeeping worth naming

A memory still filed the 2026-08-31 extraction survey as "four proposals, none
yet in the backlog". All four had shipped and the branch was gone. A stale
memory does not fail loudly — it sends the next session hunting for finished
work — so it is now marked closed and kept as reference.

## Where it ended

Plugin **13.88.2**, theme 12.18.2. Nine releases across the two sessions, every
one verified on the six release checks. Dated items: shape ledger **Sept 10**,
`search_coverage` **Sept 14**, wave-4 telemetry **Sept 25**, and the OpenStation
tag whenever it is cut.

---

# Part three — a timer, or a thing that notices

Asked whether to schedule a routine or make it automatic in code, and the answer
was code — but only because there was something real to instrument. That
distinction is the whole of this part.

## The half nothing watched

`cron_health` reports when `sn_gsc_sync_daily` stops FIRING.
`snt_gsc_history_append()` returns SILENTLY on a payload with no window end or
no page rows. So the sync can run while the history stops growing: `synced_at`
stays fresh, the newest snapshot ages, and `search_drift` reads `accruing` —
indistinguishable from "still accumulating".

That is why "check it tomorrow" was the wrong shape of answer. v13.88.2 made the
state report its span so a human could tell "one day short" from "stalled";
v13.89.0 made the stall announce itself, so the right day stops mattering.

21st health check: last sync against newest snapshot, threshold 6 days against a
healthy gap of 2–3. It deliberately does NOT flag a history that never grew —
undefined against `synced_at`, and it would fire on every fresh property — with
three distinct SKIPPED reasons, because a check that could not run is not a
check that passed.

## A check needs four registrations, and then a judgement

The mechanical four are enforced: the scan registry, the family map, the surface
map, and an optional render report. Two of them I missed and the totality pins
caught — *"every check in the scan registry has an explicit family"*, *"declares
a surface"*. Same fail-closed design as the remote-verdict pin earlier in the
day.

**The fifth step is choosing the surface correctly, and nothing guards it.** I
filed the check as `integrity`, reasoning "nothing on the SITE is wrong, a
measurement stopped arriving". `sn_health_check_total()` counts
`$scan['checks']` AFTER `sn_health_scan_for_surface()`, so the health readout
counts the `health` surface only. The check ran, found nothing, and reported
where nobody looks: a fresh 31.8-second scan still said `checks_total: 8`.

The criterion is recorded in the file I was editing and I misread it. Health
earns a check when its finding is a **DEFECT** — not when the defect sits in
SITE CONTENT. `integrity` is the REPORT-ONLY tier.

**A wrong-but-VALID enum member is silent** in a way an invented one is not. The
class-parity guards catch made-up names; nothing catches picking the wrong real
option. Fixed in v13.89.1, pinned, with a vacuity guard keeping the two
report-only checks on `integrity` so the assertion is about this check rather
than "everything is health".

Verified live afterwards: `checks_total` 8 → **9**, `checks_skipped` **0** — it
ran rather than bailing, which is the assertion worth having, since
`checks_passed: 9` alone would also be satisfied by a check that quit early.

## The false green underneath the false green

The first mutation run on that fix reported BOTH controls passing. That was my
instrument, not the code. The suite's `ok()` prints `"  FAIL $l"` with leading
spaces; my harness counted `grep -cE '^FAIL'` and matched nothing, so "0
failures" meant "I could not see the failures". Counting the summary line showed
**1 and 5**.

A mutation harness reading the wrong pattern reports calm about a check that was
itself reporting calm. Fourth instrument-reading-the-wrong-thing in two days,
and the only one that was mine rather than the codebase's.

## Registered is not reporting

Between shipping v13.89.0 and seeing it, the health scan is a CACHED artifact
refreshed daily at 08:00 UTC. The check existed in code the moment the plugin
updated and could not appear until a scan ran — and `run-health-scan` is not on
a door I can reach, deliberately, because a scan walks every post and probes
links.

Same gap as the MCP schema cache earlier: shipping and reporting are separated
by a cache, and neither the code nor the readout is wrong during the interval.

## OpenStation is ramping

Owner: *"they're ramping up for the next release."* The commit rate says so
plainly — **1, 1, 1, 10, 6, 14 per day** since v1.1.5 (2026-08-29), 32 commits,
still untagged.

Three arcs: the App Framework (a window in one `.osx.php`), with Station Home,
Trash, Preferences and WP Explorer each rebuilt and their *"legacy window
deleted whole"*; a mobile layer (`wp.os.mode`, phone desks, list windows as
cards); and multisite site-scoped desktops plus a PWA shell.

**Our exposure is small, and the check that settles it is narrow.**
`includes/registries/widgets.php` does not appear in the changed-file list at
all, and `docs/migration-lazy-window-scripts.md` puts us out of scope
explicitly: window, wallpaper and widget bundles now load on demand, and *"a
widget registered with `openstation_register_widget()` needs no change at all"*.
Verified on our side that we register **no native windows**, which is the case
the migration actually targets, and our palette commands come from
`assets/command-palette.js`, enqueued normally rather than from a window bundle.

Still unreleased and still ours to watch: **#717**, the `wp.hooks` re-execution
fix, merged and carried by no tag — so v1.1.5 has the bug today. #702, #703 and
#705 all shipped in v1.1.5.

One honest non-finding: I tried to scan our widget bundles for module-scope side
effects with a brace-depth heuristic and it returned inconsistent readings on
IIFE-wrapped files. Not reported as evidence. The real verification is the compat
ritual when they tag.

## Where it ended

Plugin **13.89.1**, theme 12.18.2. Twelve releases across the two sessions, each
verified on the six release checks. Dated: shape ledger **Sept 10**,
`search_coverage` **Sept 14**, wave-4 telemetry **Sept 25**, and the drift watch
flipping tomorrow — now with a health check that will say so if it does not.

---

# Coda — the watch resolved, and why the row is not a finding

Part three ended on the drift watch flipping "tomorrow, now with a health check
that will say so if it does not". It flipped, on the day, and the payoff is
smaller and more interesting than a green tick.

`search_drift` moved `accruing` → **`measured`** with one row:

```
/notes    6.3 → 11.5    drift 5.2    impressions 11
```

The last unverified surface of the v13.11.0 work, verified end to end.

**The row is the instrument working, not a finding.** The rule is 5.0+ drift
with 10+ impressions and this clears both by a hair. An average position
computed over ELEVEN impressions swings several points on a couple of deep-page
appearances — so it went into the backlog with a re-read date (~2026-09-11)
rather than a conclusion: still drifting with more impressions behind it is a
finding; reverting means it was sample noise.

**What makes the row readable at all is the other half of the same readout.**
`gsc_history_stalled` passed in the same scan — 9 checks, 9 passed, 0 skipped —
so the history feeding the comparison is current rather than starved. Without
that check, a drifting page and a stalled producer produce the same
`search_drift` output, and yesterday there would have been no way to tell them
apart. The build from Part three arrived one day later, on the first real
reading, and its whole contribution was making a single row interpretable.

The watch itself is now struck in `docs/BACKLOG.md`. A satisfied watch left
sitting in the watches table is the same half-done shape as a stale date, and
this session got told about that once already.

## Standing state

Plugin **13.89.1**, theme **12.18.2**. Both repos clean, no open PRs. Twelve
releases across the two sessions, each verified on the six release checks.

Everything remaining is future-dated: shape ledger settles **Sept 10**, the
`/notes` drift re-read **~Sept 11**, `search_coverage` **Sept 14**, wave-4
telemetry **Sept 25**, and the OpenStation tag whenever it is cut — with #717
merged and unreleased, so v1.1.5 carries the `wp.hooks` bug until then.
