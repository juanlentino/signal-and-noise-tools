# Session — 2026-09-02: the tag vocabulary, and the glossary that reads it

Cross-repo. Four theme releases (12.14.0 → 12.16.0), no plugin release. The
content work — splitting an over-broad tag and retagging 59 notes — came first
and everything else followed from it.

## What I set out to do, and what actually needed doing

The stated task was the tag archives. The real find was upstream of them.

`Provenance` was on 26 of 38 published notes (68%), co-occurring 100% with
Authorship, AI Detection and C2PA — a tag that had stopped discriminating. It
split into **Creation-Time Capture**, **Verification Limits** and **Provenance
Adoption**, applied by WP-CLI.

Then the owner asked whether the *scheduled* notes needed the same treatment,
and that question is the most valuable thing in the session.

### WordPress tag counts are publish-only

`wp_update_term_count()` counts only `post_status = 'publish'` for `post_tag`.
`Provenance` read **count 0** while still attached to **13 of 21 scheduled
notes**. Two ways that bites:

- delete the term → those 13 silently lose a tag;
- leave it → it resurrects to 13 over the next ten weeks as they publish, and
  the split is undone.

The safe pre-delete check is `--post_status=any`, never the count:

```bash
wp post list --post_type=post --post_status=any --tag=provenance --format=count
```

All 13 were retagged (3 / 5 / 5). Final projection once the queue drains:
Creation-Time Capture 11, Verification Limits 15, Provenance Adoption 13,
against 59 notes.

## /notes/tags/ — a glossary, not a tag cloud

All 25 tags carry owner-written descriptions, which is what makes a glossary
possible and a cloud unnecessary. A cloud encodes **frequency** as type size,
and frequency is the one property a reader cannot act on. Worse here: the thin
tags are the most *specific* ones, so frequency-as-salience inverts the page's
usefulness. Every term renders at exactly one size — measured in the browser
(`distinctSizes: ["12px"]` across all 25), not asserted.

Four editorial groups, each a section with a dek. The row is the site's
split-hero composition at row scale: term left in the label register, prose
right.

**The guard that matters** is `sn_notes_tag_groups_resolved()`. A hardcoded
grouping drifts the moment a tag is added and the failure is SILENT — the tag
simply is not on the page. Any in-use tag named in no group falls through to a
trailing "Not yet filed" section, which is loud by comparison.

## The pattern that cost three of four releases

Three releases today were fixes for things I introduced and shipped green:

| shipped | wrong | caught by |
|---|---|---|
| 12.13.1 | subscribe line kept a type register its own comment called unreadable | the owner, looking at the page |
| 12.15.0 | three container class names that do not exist | the owner, looking at the page |
| (pre-ship) | `--wp--preset--color--rule`, an invented token | my own token check |

Every one is a claim about a **vocabulary** — CSS class names, custom
properties, type registers — and nothing in the pipeline validates that a name
refers to something real. PHP lints clean, HTML validates, all 111 suites pass,
the page returns 200 with every row present. An invented CSS class is
indistinguishable from a correct one, because CSS has no such thing as an
unresolved selector.

`tests/notes-tags-class-parity.php` (theme) closes the class half: every class
the renderer emits must exist in `notes.css` or in the index renderer it
borrows its shell from. It carries vacuity guards, strips comments first, fails
on the exact code that shipped, and **found a fourth orphan on its first run**
(`sn-tags-page`, a hook with no rule) — which I removed rather than exempted.

The type-register vocabulary still has no guard. That one is still "a human
reads it", worth knowing before touching the hero again.

## Measurement artifacts, twice

- `grep -c` counts **lines**, not occurrences. It reported "1 group, 1 row" on
  a live page that had 4 and 25. `grep -o … | wc -l` is the honest count.
- I checked the combined stylesheet (`sn-styles-*.css`) for the glossary rules,
  found zero, and nearly reported the page unstyled. The rules were in
  `notes.css`, linked separately on the same page. I checked the wrong file.

Both are the standing rule in practice: before concluding from tool output,
rule out that the instrument measured something other than what you asked.

## Also shipped

- **12.14.0** — reader names back in the hero (NetNewsWire, Reeder, Feedbin,
  "among others" as a deliberate hedge); retired-tag 301 map, matched on the
  REQUEST PATH so the URL outlives the deleted term.
- **12.14.1** — the subscribe line into the prose register.
- **12.16.0** — the class fix, the parity guard, and the glossary's only
  inbound link, in the index hero's closing stamp (`38 entries · Last updated
  … · All tags`). Not the top nav: a tag map is a fact about the corpus, which
  is what that stamp states. It inherits the stamp's suppression in filtered
  views — verified live: absent on tag archives and search, present only on the
  unfiltered index.

## Corrections I made to my own reporting

- I claimed `apply-tag-description` mutates on `dry_run: true`. **Wrong** — the
  ability has no `dry_run` parameter; I invented it and misread `status:
  "written"`. The real, much smaller finding: `input_schema` declares
  `additionalProperties: false` but nothing enforces it. Input validation is
  deliberately delegated to the client (`sn_mcp_output_schema_violation()` is
  output-only by design), so an undeclared key is dropped rather than rejected.
  **Open question, unanswered: should the write door validate inputs?**
- The docblock on `sn_notes_is_tag_request()` said archives live at
  `/notes/tag/{slug}/`. They do not — that 404s. Canonical is `/tag/{slug}/`.
  Corrected in 12.15.0. The v12.14.0 redirect matched the right path only
  because I wrote it from live URLs rather than from that comment; written from
  the comment it would have matched nothing and its test would have passed,
  both from the same wrong premise.

## Housekeeping

I wrote a `.claude/launch.json` into the theme repo for a CSS preview harness
and deleted it afterward. `preview_start` was never using it — it resolved
`theme-css-harness` from the plugin repo's config, which is intact. Git shows
the theme repo never tracked a `launch.json`, so nothing versioned was lost,
but I cannot rule out having overwritten an untracked one. Use the scratchpad
for harnesses; do not write into a repo you are only reading from.

---

# Part two — the ML arc, and what the sweep could not see

The session did not end at the tag vocabulary. Eleven more plugin releases
(13.73.0 → 13.83.0) and four more theme releases (12.14.1 → 12.17.2) followed,
almost all of them driven by one thing: **the reader-anomalies pipeline meeting
real data.**

## The ML question, answered by measurement

The owner asked whether we could "do ML" in analytics and machine readers. Two
corrections landed before any design did.

**Analytics already ships the full ladder.** Descriptive → predictive →
prescriptive, complete since July (I1–I6): median/MAD anomalies, Theil–Sen
trajectories, Holt forecasts with a rolling-origin backtest, a narrator, and a
recommendations layer. I was about to propose building what exists.

**The subsystem with no ML consumer was machine readers**, and it has ~500× the
data: 69,833 machine requests in 30 days against roughly 130 human visits. The
analytics engine was not missing capability — it was STARVED. So the work became
supplying a denser input, not writing new statistics.

## The eligibility floor was derived, not chosen

A family needs hits on **≥ 20 of 30 days**. Days present across the twelve
families with traffic: **2, 9, 10, 11, 14 … 23, 24, 31, 31, 31, 31, 31** —
bimodal with a nine-day gap, so the threshold only has to land in the empty
region. Any floor from 15 to 22 selects the same seven families, and that
robustness is asserted.

A VOLUME floor would have been wrong: `amazon-ai` shows a median of 160 across 9
present days and outranks `openai`, which is present every day at a median of 8.
There is no series there, only bursts. Presence is the axis; size is what the
statistics already handle.

## Five defects, all from first contact with real data

None was findable from fixtures:

1. **The forecast gate rejected everything.** `uptime` (median 480, MAD 0) scored
   skill −6.89 because the guard tested `mae_naive > 0` — which catches a
   PERFECTLY rigid series and misses a NEARLY rigid one. Fixed with a floor
   relative to the series level. "Is the denominator exactly zero" was the wrong
   question; "is it large enough for the ratio to mean anything" was the right one.
2. **A MAD-0 family could never fire an anomaly at all** — the most rigid reader,
   the one whose deviation matters most, was the one structurally excluded.
3. **The DOWN side was unreachable, and I had shipped a claim that it worked.**
4. **Two pre-existing trajectory bugs** on the composer shared with the human
   dashboard: `baseline_days` hardcoded 0, and a percentage that could read −231%.
5. **A read-time gap**: the apple-ai exemption was applied at COMPUTE time only,
   so every stored record still rendered the old sentence on an installed,
   correct plugin.

### The one worth generalising

**On count data bounded below by zero, a symmetric robust-z detector is
structurally one-sided.** The furthest a value can fall from the median is the
median itself, so the most negative z obtainable is `0.6745 × median / MAD` — a
ceiling set by the data's shape, not the threshold. With MADs at 0.29–0.92× their
medians, that ceiling is ~2.3 against a 3.5 threshold. Measured: total silence
scored |z| 0.74–2.30 for EVERY eligible family. No threshold fixes that; a
different KIND of rule does, so silence became a binary presence rule.

## The instruments were the weak link, not the code

**Not one of the day's defects was caught by the 543-suite sweep.** What caught
them: the owner looking at screens, `php -l`, PHPStan's duplicate-key check, and
mutation testing.

**Five separate scope errors in my own measurements**, each producing a
confident wrong conclusion:

- searching `.sn-an-*` for column primitives and concluding none existed — three
  do, under other names, and one of them documented a `min-width: 0` guard my row
  was missing;
- `grep -c` counting LINES not occurrences ("1 group, 1 row" on a page with 4 and 25);
- reading a top-3-truncated family list as the whole population;
- a `baseline_days` grep matching a different function and reporting the fix absent;
- a two-tab string matching inside a three-tab line.

**Four vacuous tests**, each passing green while testing nothing: a harness that
stubbed neither function so `function_exists` made new code inert; fourteen
assertions placed below a suite's `exit()`; a fixture with `MAD/median 0.09`
where robust z CAN reach zero; `(float) null === 0.0` erasing the very
distinction under test.

Only the third of those is statically detectable. `tests/suite-shape.php`
(v13.83.0) now catches it, self-proving against a planted violation. An
inert-code guard was **measured and rejected**: 1,911 of 3,472 `function_exists`
guards are unsatisfied under test (55%, 291 suites) — that is the normal state,
and a check firing on 291 suites gets switched off within a day.

## Verified in production, not inferred

The side-by-side row and the KPI strip were the two things I could not render
locally. Both were checked in the live admin at the end: panels 959px each at
identical top offset, `min-width: 0` applied, no table overflow. The same
screenshot caught the skill gate working on human data —
*"Views: no forecast — the model does not beat a same-value baseline on this
history (skill −0.22 over 44 checks)"* — which is the whole v13.75.0 chain
rendering in the Insights band.

## Still parked

**The remote twin for reader-anomalies.** It costs contract 4 → 5 plus a worker
release, and the byte-identical rule freezes the payload shape on the day it
ships. That shape changed five times in one afternoon. It waits until the payload
survives a few days unchanged.

---

# Part three — the parked thing, and the rate nobody measured

Part two ended with the remote twin parked behind "it waits until the payload
survives a few days unchanged". I offered to set a reminder to come back and
look. That got rejected, correctly:

> *"Can't it be a live learning of the thing and then do the rest of the thing?"*

A reminder to check is not an instrument. It is the remember-to-look pattern
this codebase keeps replacing, and it fails in the one way that matters: it
tells you nothing when it does not fire.

## v13.84.0 — the shape ledger

So the question became measured. `inc/shape-ledger.php` fingerprints a payload's
STRUCTURE — types and keys, never values — and records when it last changed.
`sn_shape_stability()` answers `unknown | settling | settled`.

Generic by construction: nothing in it knows what `reader-anomalies` is. A
subject is a string, its open paths are declared by the caller. The next twin
candidate reuses it unchanged, which is the point, because the decision recurs
for every twin.

The hard part was STRUCTURE versus content. `reader-anomalies` carries an
`excluded` map keyed by family name, so a family crossing the eligibility floor
adds or removes a key. That is data moving, not shape moving, and an instrument
that reported it as a change would cry wolf weekly. Hence declared-open paths.

## Then one question took the whole thing apart

> *"So... The payload is already generated, that means we have results?"*

I had written the ledger to record on every real pipeline run and argued that
no cron was needed, because the payload "is already being produced". That is
true. I never measured **how often**.

The callers are the MCP read door and the admin surface — both on demand, both
capable of going untouched for weeks — plus WordPress's site-health check, which
is the only GUARANTEED one and runs **weekly**. The gate wants 24 readings. At
one a week that is roughly twenty-four weeks.

**The gate was correct and unreachable.** And the failure is invisible by
construction: an empty ledger and a slow-filling ledger look identical from
outside. Nothing would have reported it. I would have come back in a week,
found `settling`, and assumed the payload was still moving.

The generalisable form: **a design that hangs on a RATE cannot be checked for
existence.** I verified that a producer existed and stopped there. "Is there a
caller?" and "how often does it call?" are different questions and I answered
the first one twice.

## v13.85.0 — and the priority is the whole argument

`snt_ml_reader_anomalies_record_shape()` now rides the machine-reader snapshot's
existing hourly cron. It adds **zero outbound requests**, because both callers
land on the same transient: the snapshot asks `snt_mr_fetch( SN_MR_SNAPSHOT_DAYS )`
and the pipeline asks `snt_mr_fetch( SN_MR_SERIES_WINDOW, 'aggregate' )` — 30 and
30 against that parameter's own default — so both build `sn_mr_rows_30_aggregate`
under a 15-minute TTL. Running second is a warm read.

Which means the reduction to zero rests entirely on **priority 20** against the
refresher's default 10. A bare integer carrying a cost argument, of the kind
that normally rots into a comment nobody re-reads.

So it is asserted — and asserted against the snapshot's **registered** priority
rather than a hardcoded `10`. Mutation M4 is why:

| mutation | production cost |
|---|---|
| priority 20 → 10 | a real HTTPS request every hour, forever, silently |
| registration removed | ledger never fills; no other symptom |
| snapshot window 30 → 14 | cache keys diverge; the zero-cost claim breaks |
| **snapshot priority 10 → 30** | **ordering inverts — a hardcoded `10` stays green** |

M4 is the one that justifies the design. A test that remembered today's value of
something *another file owns* sails through exactly the failure it exists to
catch.

The registration needs a `function_exists( 'add_action' )` guard, because the CLI
harnesses load the file with no WordPress. That guard converts *broken* into
*absent*, so one assertion exists purely to prove the registration is not inert.

## Theme v12.18.0 — "the tags page is a bit hidden, don't you think?"

It was worse than hidden.

`/notes/tags/` had exactly one site-wide link, at the tail of the corpus meta
stamp — inside `if ( ! $sn_filtered )`. Since
`$sn_filtered = $sn_searching || $sn_tag`, and real `/tag/<slug>/` archives route
through that same renderer, **the only route to the tag index disappeared on
every tag archive and every search view.**

The reader who had just clicked a tag was the one reader who could not reach the
tag index. That shipped in v12.16.0 and stood for two versions.

The suppression is right for what it was written for: "59 entries" and "Last
updated" describe the CORPUS and would mislabel a filtered result set. A
corpus-wide navigation link carries no such claim.

**The link inherited a visibility rule written for the numbers beside it, purely
by sitting in their `<p>`.** A placement decision became a behavioural one — and
the comment I wrote at the time defends the placement at length without noticing
it had just acquired the stamp's conditions.

The counter-argument was already in the same file, twenty lines up, where Start
Here is rendered unconditionally *because* "wayfinding is most useful precisely
when a newcomer landed on a tag or search view". I made the argument and then
made the opposite call for the glossary.

Fixed with `.sn-notes-wayfinding`, an unconditional row holding both links, side
by side. Deliberately still NOT in the top nav — seven entries already, and a
secondary index does not rank beside Home and About. That half of the original
reasoning held; the half about the stamp did not.

## The guard passed against the broken code

The most useful thing in this part. `tests/notes-tags-reachability.php` had to
prove a link was NOT inside a block. First draft:

```php
$sup_start = strpos( $code, 'if ( ! $sn_filtered ) :' );
$sup_end   = strpos( $code, 'endif;', $sup_start );   // WRONG
```

The block contains a nested `if ( $latest_date ) : … endif;`. So `$sup_end` lands
on the INNER close, the extracted region stops early, and the link — which sat
after it — fell outside the search window entirely. **The assertion passed
against the actual pre-fix code.**

A second hole in the same file: `strpos( $css, '.sn-notes-wayfinding' )` matches
inside `.sn-notes-wayfindingX`, so the renamed-class control could not go red.
Substring where a token was needed.

Neither is visible from a green run. Both were found the same way, and it is
worth stating plainly because it is not what I did first:

**Control against the real pre-fix COMMIT, not against a mutation you write
yourself.** I ran four hand-made mutations and all four passed. A hand-made
mutation encodes what I already believe the bug was; the historical commit is
the only control that does not share my assumption. `git show origin/main:path`
red-flagged both defects immediately.

The theme's own keyboard-parity invariant also caught me: a guarded `:hover` must
declare exactly what its `:focus-visible` sibling declares, and mine had drifted.
My custom outline was overriding the global focus ring in `base.css` besides.

## Verified live, both halves

Not inferred from source. On `/tag/creation-time-capture/`:

```html
<div class="sn-notes-wayfinding">
  <p class="sn-notes-start-here"><a href="…/notes/start-here/">First time? Start here…</a></p>
  <p class="sn-notes-all-tags"><a href="…/notes/tags/">All tags</a></p>
</div>
```

And the half that had to keep working — on that same filtered view,
`Last updated` → 0, `sn-notes-meta` → 0. The link moved out of the stamp without
weakening the suppression it had been wrongly sharing. A fix that made "All tags"
appear by deleting the suppression would have passed a naive presence check and
put the corpus figures onto every filtered view.

## Where this leaves the twin

Plugin 13.85.0 and theme 12.18.0 are both live and reporting `state: ok`; all 19
recurring cron jobs are firing on schedule. The ledger now fills hourly.

For contrast on what that bought: `wp_site_health_scheduled_check` — the entire
input budget before today — next runs in **seven days**.

So the twin decision is now a read, not a memory: check
`sn_shape_stability( 'reader-anomalies', time() )` in about a week and ship only
on `settled`. Which is what the rejected reminder was pretending to be.
