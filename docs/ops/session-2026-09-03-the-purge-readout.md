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
