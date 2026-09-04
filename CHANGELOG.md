# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- sn-apply-* implementation files live under inc/sn-apply/; inc/abilities-sn-apply.php is the loader. No behaviour change.

## [13.95.1] - 2026-09-04 — a refused batch stops reading like a successful one

Found by driving the live door after the app restart, not by the suite. The
write was correctly refused and nothing was written; the REFUSAL ENVELOPE was
wrong in two ways, and both made a refusal legible as a benign success.

### 1. A plan failure is not a fingerprint failure

The response reported:

```
gates.fingerprint.passed   false
gates.fingerprint.expected dd5df6ac…
gates.fingerprint.observed dd5df6ac…   <- identical
```

Gate 1 does double duty for the batching types: it proves the whole-post hash
AND runs the planner. A planner refusal came back through the fingerprint gate,
so the readout said the hash failed while its own two values matched — a
contradiction that sends the caller to re-fetch a hash nothing was wrong with.

Gate 1 now returns `fingerprint_ok` when the hash was proven and only the plan
was refused, and the conflict is reported as a **validation** finding — which is
what a payload conflict is, and which already carries the 422 the refusal
returns. `gates.validation.checks` gains `plan`; the error code is unchanged.

**This predates the batch work.** `payload.edits` (v10.66.0) had the same shape
and is fixed in the same pass.

### 2. A refused batch reported applied changes and a ledger verdict

The dry-run diff resolved `after` as `$gate1['new_content'] ?? $before`. On a
refused plan `new_content` is null, so the post was diffed against ITSELF:

```
changes_applied  2          <- nothing was applied
prose_changed    false
ledger_impact    "coalesces"  <- "applied, no new version"
```

An agent reading that could reasonably conclude the batch went through
harmlessly. Now a refused plan returns `after: null`, `changes_applied: 0`,
`ledger_impact: null`, and a new `changes_requested` naming what was ASKED for,
kept distinct from what was applied. `diff.before` is still carried, because
`roadmap_board` documents reading it from a refusal to bootstrap its
fingerprint.

### Why the suite missed both

Every existing assertion checked the refusal CODE. None checked the shape of
the envelope around it, so a correct refusal wearing a successful-looking
readout passed. `BATCH.12`–`BATCH.22` pin it now, and both fixes are
mutation-proven: reverting the fingerprint logic turns BATCH.13 red, reverting
the diff fallback turns BATCH.18/20/21 red with exactly the live values
(`changes_applied: 2`, `coalesces`).

The same class as everything else this week — a readout that could not
distinguish two states. Here: "stale fingerprint" from "planner conflict", and
"applied two changes" from "planned two, applied none".

### Files

- `inc/sn-apply-validation.php` — gate 1 returns fingerprint_ok + plan_error
  (batch AND payload.edits)
- `inc/abilities-sn-apply.php` — honest fingerprint gate, conflict routed to
  validation
- `inc/sn-apply-executors.php` — the refusal diff
- `tests/abilities-sn-apply-block-edit.php` — 11 assertions on the envelope

Suite: 555 suites, 22429 assertions, 0 failed, 1 skipped.
