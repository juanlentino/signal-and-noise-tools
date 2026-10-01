# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [20.3.0] - 2026-10-01 — a month waits three days, then posts itself


- **Rights evidence posts on its own, after a 72-hour review window, with automatic holds.** Owner-approved. The first daily pass of a month composes the last complete month's records and stores them `composed` with `review_until` (compose + 72 h) and a `summary` `{reads, train}`; it posts nothing. A later pass posts a composed record at or after `review_until` when the month is not held, so a month no longer needs a hold and a lift to go out. A held month still composes (View now streams the stored bytes that would post) and never posts. Two rules hold a month with a stored reason (`sn_rights_evidence_hold_reasons`): a compose or ledger-walk error, which today only stopped the pass; and a per-family `crawling.train` that moved more than 3x either way against the previous month's posted value, both at 50 or more (it applies from the month after this ships, the first with a stored summary). The worker's coming `422 {error:"rights-evidence refused", divergences}` (parallel worker PR) marks the entry `refused`, drops its bytes so they are never re-sent, holds the month with the divergences as the reason, and lets the next pass recompose; a transport failure, or a 422 not in exactly that shape (`ok` false, that `error`, a non-empty list of `[string, string]` divergences), stays `unanchored` and retried. A held backlog month whose family was refused is still worked to recompose it (never to post), and that held pass is not counted a backlog failure. Each pass reads the hold once, so a Lift during a running pass cannot make it post. The month's window shown everywhere is the earliest `review_until` of its unposted records; hold reasons are cut at 300 bytes without splitting a UTF-8 character. Every pass first queues any stored month other than the current one that still has unposted work (composed, unanchored, or refused awaiting recompose), so a month composed late or lifted after the turnover posts once its window passes and is never left to Post now alone. A backlog month waiting out its window is neither a target nor a failure: it stays queued, uncounted, and leaves once posted. Monitoring > Machine Readers, both twins: per waiting month View, Hold and Post now (the owner's bypass of the window, behind a confirm; `rights_evidence_hold`, `rights_evidence_post_now`, map 78 to 80), per held month the rule's reason and Lift (which now clears the reason); the status line names the months in review. A new watch, `rights_evidence_review`, ripens on a waiting month (with the hours left) or a rule's hold (with the reason), so the morning brief carries it; no email. The `rights-evidence` read reports `review_until`, `summary`, `divergences`, `hold_reasons` and `in_review`; `rights-evidence-now` says it posts only past the window and reports `in_review` and `refused`. Pins changed with the intent kept (held never posts): H1 (a held month composes, posts nothing), H2 (the held pass now takes and releases the lock), H4 (an unheld month enters its window), plus D1/D8, E1 to E4, I6, K4 and K5, whose first pass no longer posts. `docs/MACHINE-READERS.md` (new section: the review window, holds and refusals). `tests/rights-evidence.php`, `tests/rights-evidence-admin.php` and `tests/watches.php` pin it.


