# Session, 2026-09-25 to 26: a number for the reading, and a door for the spam

Five plugin releases (18.7.0, 18.8.0 to 18.8.4), one worker release
(sn-remote-mcp 1.11.0), three upstream OpenStation contributions, and one
live fix to the contact form that had been silently dropping its routed mail.

## Upstream OpenStation

- **#888** (agent usage and model, issue #861): CI was red after a trunk
  merge left a duplicate `turns` key in the forced-answer return, which PHPCS
  refuses. The trunk value (#912's real turn count) stays; accrual was
  re-checked on every generate path. A multisite job then died building its
  container before any test ran; an empty commit re-ran it. All seven green.
- **#913 / #914** (floating widgets reflow on resize), upstreamed from the
  fork. The screenshots went through three rounds: blurred whole, blurred by
  region, then unblurred originals at the owner's call, with before and after
  first named the wrong way round.
- **#915**, a discussion: Shortcuts on OpenStation, abilities as actions. It
  honours #73 (automations were kept out of the thin core) and asks only for
  the smallest seam the desktop would need. Deterministic runs spend no tokens;
  the unattended AI step is the one place spend needs a cap.

## 18.7.0: a north star

"Weekly engaged readers": human visitor-days with a core page (notes,
provenance, resume, about; settable) read past 50% scroll OR 30 s. Under it,
three layers adapted from a vanity-versus-business-metrics talk to a practice
that sells nothing: intent (deep readers, downloads and outbound clicks,
resume and contact visits), return (readers per note published; DOI downloads
from Zenodo's public stats by ORCID, diffed from daily snapshots; inquiries
from the forms plugin's entries), and inputs. It heads S&N Home and, at the
owner's suggestion, lives on SN Site Views rather than as a twelfth widget.
Returning readers was dropped: the visitor hash rotates daily by design.

## 18.8.0 to 18.8.3: spam, caught on our side of Forms

The owner declined an upstream issue: solve it in our plugin. A filter on
Forms' `alltfo_spam_verdict` adds content rules after its own honeypot, time
trap and rate limit, with a read sweep and a guarded write (marks only what
the rules flag, through Forms' own setter, so Not spam undoes it).

It took four releases to be right, each one found by measuring rather than
trusting:

- **18.8.0** scanned 338 entries and flagged none. Forms stores values as a
  JSON string; the sweep cast it to an array. The test had built entries as
  arrays, looser than the store. 18.8.1 decodes, and the test now stores as
  Forms does.
- **18.8.1** still flagged none, which proved nothing: the spam was already in
  the Spam folder. 18.8.2 measures the rules against that folder,
  report-only: 4 of 8.
- **18.8.3** read the four misses the owner opened. A sales-pitch signal
  catches all four. A "name echoed into every field" signal was built and
  dropped the same hour when the owner pointed out required fields and
  freelancers. The form's own export gave the strongest rule: a person only
  sees the fields their choice shows, so answers in two or more hidden
  branches are a script. 8 of 8, inbox still clean.

## The notifications that never sent

The form export also showed five notification rules written `research`,
`press`, `speaking`, `music`, `role` against stored values `Research`,
`Press`... Forms compares `is` exactly, so no routed notification had ever
fired. 18.8.3 finds case-only mismatches and fixes them on request; applied
live after the owner's OK, re-read and verified. Past entries were not
re-sent.

## 18.8.4 and worker 1.11.0: which pages return 520 (#1006)

A third errors dim, `err_path_status` ("520 dynamic /wp-json/..."), capped at
the column by cutting the path, never the status. The remote twin shares the
schema, so the contract moved 9 to 10 in lockstep with sn-remote-mcp-worker
#40, merged after 18.8.4 published. Both sides read 10. A one-off check reads
the first recorded paths after the 2026-09-27 rollup, for the Cloudways
ticket.

## What went wrong, and what it teaches

- **Queued release chains that do not stop on error.** One rebase conflict
  left a chain looping on a PR that was never opened; the owner noticed first.
  Chains are `&&` from here, and a squash-merged branch is replayed by
  cherry-picking its own commit, never rebased.
- **A PR opened before the local run finished** claimed 724/724 while one
  suite was red (a two-dims pin the new dim broke). Read the sweep, then push.
- **The MCP tool list is cached per connection.** New abilities and new input
  fields are invisible until sn and sn-write reconnect.
- **A green scan is a claim about its input.** Twice an empty result was the
  instrument, not the inbox.
