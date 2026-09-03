# Session — 2026-09-04: the things that come due later

A separate record from [2026-09-03](session-2026-09-03-the-purge-readout.md),
which ended with four items sitting on future dates and no mechanism behind
them. One question turned that into a build:

> *"Can we make the future dated things durable like a routine or something?"*

## Why the answer was not a routine

A routine fires on a clock whether or not it has anything to say. A daily
message that usually reads "nothing yet" trains its reader to stop opening it,
and then it is worse than nothing — a surface everyone has learned to skip,
still costing a run.

That is the same failure this estate spent the previous day removing from the
cache readout: a signal that stops being about its subject. There it was a
counter that moved when the owner pressed a button. Here it would be a message
that arrives whether or not anything happened.

**So a watch is silent until it is ripe.**

## State over date, wherever a state exists

The sharper half. *"Check the shape ledger on Sept 10"* is a reminder somebody
has to honour. *"The shape ledger reports settled"* is a fact the site notices on
its own. The first decays the moment the estimate is wrong; the second cannot.

So the `reader-anomalies` twin watch carries **no date at all**. It ripens when
`sn_shape_stability()` says `settled`, whenever that is — and if the shape moves
and the countdown restarts, it stays quiet instead of announcing a date that
stopped being true. That is the same distinction that replaced a scheduled
reminder with the shape ledger (v13.84.0) and "check the drift watch tomorrow"
with a health check (v13.89.0). Third time; it is a pattern now, not an
instinct.

A date is the WEAK form, kept only where nothing can be measured — a re-read of
a number that will not announce itself. Those carry `date_only` so a reader can
see which kind it is looking at: a state-tested watch ripened because something
changed; a date-only one ripened because a clock passed and **nothing was
measured**. Different confidence, and the flag says so.

The `/notes` drift watch needs BOTH halves, deliberately. A date alone would
surface it on the 11th even if the drift had reverted — which is the answer, and
not one that needs anybody's attention.

## What was deliberately left out

The OpenStation tag is not registered. It would need a network read of their
releases and no local reader exists. Inventing one so the table looks complete
is how a watch starts lying, and a watch that lies is worse than an absent one
because it is trusted.

## Two surfaces, because one is the defect

The morning brief — which already mails the owner at 07:00 — gains a due-watch
section that renders **nothing** when nothing is ripe. Every other section there
speaks on every send, including to say "unavailable", because its subject always
exists. A watch is different. A ripe one also raises the subject line, or it
would arrive under a heading saying nothing needs attention.

`signal-noise/watches` is the agent reader, on the read door and as the
`watches` section of `sn-status`. **Shipping an instrument only one surface can
see is the defect this estate found twice in three days** — the purge log
written for eighteen versions and read by nothing, and the shape ledger filling
for four days with its verdict function called only from tests. Building a third
without a reader would have been the joke telling itself.

`pending` is reported rather than inferred: an empty `ripe` list alone cannot be
told apart from an empty registry, and those are different facts.

## What the guards caught, and what they missed

**A fixture caught a real bug.** The ripeness callbacks called `time()` directly,
so the injected `$now` never reached them and `snt_watches_ripe( $future )` was
silently evaluated against the wall clock. Threading `$now` fixed it; reverting
reds.

**Two mutations failed to red, and both were my tests, not the code.**

- The silence assertion matched only the marker string `'Watch due'`, so a
  mutation adding *"No watches are due."* slipped past it. The property is that
  the brief says nothing about watches at all, and it now asserts that.
- The malformed-row guards were unreachable against a fixed registry — nothing
  in `snt_watches()` is malformed, so deleting them changed no outcome. The
  registry is injectable now, and that mutation FATALS, which the sweep's
  summary-line gate catches as a failed suite.

An unreachable guard is an untested one. Both of these existed, read correctly,
and defended nothing.

## And one misreading of my own instrument

Verifying the release, I fetched tags while the ship chain was still pushing and
read `code=missing-tag`. The chain had not failed; I had checked early. The rule
in this estate is to judge by the `SHIPPED` marker line, and I ran the parity
check before reading it.

Fourth instrument-reading-the-wrong-thing in three days, and the second that was
mine rather than the codebase's.

## Where it stands

Plugin **13.90.0**, theme **12.18.2**. Both repos clean, no open PRs.

The four future-dated items no longer depend on anyone remembering. Three ripen
on a state or a state-plus-date; one is honestly a date. Nothing is due today,
which the readout says as `ripe: []` beside `pending: 4` — the second number
being what makes the first readable.
