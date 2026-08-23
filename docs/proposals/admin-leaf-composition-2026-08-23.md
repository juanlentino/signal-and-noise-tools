# Admin leaf composition — design

**Date:** 2026-08-23 · **Status:** approved, pending live judgement
**Trigger:** the Machine Readers leaf renders 13 stacked sections in the left card
of an `sn-2up` while the right card holds only a worker readout and a settings
form. One column is ~20× the height of the other, so the right side is empty for
thousands of pixels. Owner: *"that leaf would need a redesign, or the box with
all the numbers should be placed somewhere else."*

## The rule

**A right column exists only when the leaf has a SECOND JOB.**

One job → one column. Two jobs → the `sn-2up`, primary job on the wide side,
secondary folded on the narrow side.

This is the generalization, and it is derived from what each leaf *does* rather
than from porting one silhouette everywhere (owner's framing: *"the
generalization should be done with what the leaf does"*).

## Three kinds

Classification is evidence-based — from what each leaf actually renders today.

| kind | composition | leaves |
| --- | --- | --- |
| **READOUT** — measurement only | hero (status + KPI row) → **one column**: evidence, then folded reference | Analytics views, Insights, Health, RSS, Audit log, Copilot Usage, Trust checks, Citations, Links |
| **SETTINGS** — config only | **one column**, no hero, no KPI row | the 15 leaves in `inc/admin-forms/` |
| **MIXED** — a readout that owns its config | hero → **`sn-2up`**: evidence wide, folded reference + settings narrow | Machine Readers, Analytics, IndexNow |

Only MIXED earns the 2-up. Nine files render a KPI row today; four use `sn-2up`;
three render both a form and tables. The 2-up is currently decoration in at least
one place, which is what produced the void.

**Scope of this change:** Machine Readers only. The rule is written down so the
other leaves can adopt it later, one at a time, on evidence. No other leaf is
touched here.

## Machine Readers, composed

Hero band, full width:
- sensor status pills (existing `snt_mr_render_sensor_status`)
- the KPI row (`snt_mr_render_summary_chips`) — this is "the box with all the
  numbers", moved out of the left card into the hero where it reads as a summary

`sn-2up` below:

- **Left (wide) — evidence.** The rights log, un-buried from position 11; delta
  cards; unknown agents.
- **Right (narrow) — reference, all folded.** purpose · vendor/purpose · family ·
  surface class · compliance · AI reconciliation · feed. Then the Edge sensor
  readout and the settings form.

Both columns are short because the reference tables are folded. Evidence gets the
wide side because it is the primary job.

## The rights log: our own CI is not a machine reader

Every hourly run of `SignalNoise-SmokeTest/1.0 (GitHub Actions)` writes three
rows (`/tdm-policy/`, `/.well-known/tdmrep.json`, `/license.xml`). That noise
buried the event that mattered: on 2026-08-23 at 16:22, `OAI-SearchBot/1.4` read
all three rights declarations in five seconds — a declared crawler going to look
at the terms on purpose, which is the whole point of the stack.

The taxonomy already distinguishes them: those rows carry `vendor =
signal-and-noise`, `purpose = ops`. The classifier is correct; only the renderer
ignores it.

**Behaviour:** the log default-hides `vendor = signal-and-noise` rows behind a
toggle (owner's choice). Nothing is discarded and no stored data changes.

**Counts stay inclusive.** The owner explicitly rejected subtracting ops traffic
from the counts, because a number that changes meaning mid-series makes any
comparison across the change invalid. So:

- the leaf's KPI row is untouched — same population it has always counted;
- the log's own summary splits honestly: *"3 external reads — show the log"* with
  a secondary *"+3 from our own CI"*, so a hidden row is still declared, never
  silently dropped.

A fold summary reading "6 events" above a 3-row table would be incoherent; naming
both numbers is what keeps the display honest while the default stays quiet.

## Constraints

- **Admin CSS is enqueued, never inline** (house rule). Changes land in
  `assets/machine-readers.css` / `assets/admin.css`.
- **Renderers stay pure and fixture-pinned.** Every `snt_mr_render_*` returns a
  string and escapes every cell; the 13 machine-readers suites depend on that.
- **`admin-registry` is a full-sweep contract.** Touching an admin surface means
  running the full sweep, not a targeted suite.
- No settings-schema change, so this is a PATCH.

## Verification

- The 13 `tests/machine-readers-*` suites plus `tests/admin-registry.php`.
- New assertions: the toggle's default state; the log's split summary; that a
  hidden row is declared rather than dropped; that ops rows are still present in
  the underlying data.
- Full sweep, phpcs, PHPStan.
- **Live judgement before merge** — the owner judges the rendered leaf, not a
  mockup. Rendered from the real `snt_mr_render_*` functions against fixture
  rows.
