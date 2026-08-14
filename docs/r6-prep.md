# R6 prep — the split, proposed before the first build session opens

Build sessions start **here**, not by re-deriving. Written 2026-08-14, the same
day R5 closed end to end (v11.7.0), immediately after the attestation-coverage
audit cleared R6's one hard gate
([the audit](ops/attestation-coverage-audit-2026-08-14.md)). Recon is current
against `origin/main` after #665.

Sequence and economics: [roadmap-release-sequence.md](roadmap-release-sequence.md).
Previous releases: [r1-prep.md](r1-prep.md) · [r2-prep.md](r2-prep.md) ·
[r3-prep.md](r3-prep.md) · [r4-prep.md](r4-prep.md).

The sequence doc said it plainly: *R6 is the heaviest release and the most
gate-dependent; expect it to split.* This document is the split. Eight rows —
the table's seven plus **Configuration drift**, which R5's table carried but
R5's arc deliberately excluded from §9 and never shipped — sort into three
sub-releases and one spike, by gate, not by family.

## The proposal

### R6a — plugin-native, no external gates (ships first)

| Family | Row | What recon says |
|---|---|---|
| Operations | A morning brief | Pure composition: `analytics-narrator.php`, `insights-narration.php`, `machine-readers-narration.php`, `security-digest.php` already exist — the brief points the digest pattern at health/cron/uptime/deploys. R2's prose rule applies. |
| Operations | Configuration drift | Snapshot + diff of the settings surface (`settings.php`, `admin-tabs-data.php`). Carried from R5's table; excluded from §9 by design, still owed. Touching `admin-tabs-data.php` triggers the full-sweep contract. |
| Machine readability | Corpus schema as a machine surface | Author-stated tier/number/relation published, extending the existing machine-surface family (llms.txt, pointers). |
| AI | Scheduled read-only agent runs | Recurring reports over the READ door only — the door's posture (F1 fails closed, read-only slice) is already the owner direction. |

Four rows is a full release on its own; if a build session runs long, split
again along the family seam (Ops pair first, MR+AI pair second). Everything
here is single-repo plugin work with existing machinery to compose over.

### R6b — the GSC arc (gated on owner-side credentials)

| Family | Row |
|---|---|
| Analytics | Search-side metrics from Search Console |
| Machine readability | Google's crawl and robots reports vs the ledger |

ONE arc per the tier list: **one client, three consumers** — the dashboard's
search tab, the crawl/robots cross-examination against the worker's crawler
ledger, and the digest's search section. Recon: **no GSC client exists
anywhere in the repo** — this is greenfield, and it needs Google-side setup
that only the owner can do (API access for the Search Console property:
service account or OAuth client in Google Cloud, plus property permission).

**Owner action, requested now because it is the long-lead item:** create the
GSC API credential early — R6a neither needs it nor waits for it, but R6b
cannot live-verify without it. Client + parsers can be built against recorded
fixtures in the meantime; the arc doesn't close until it reads the real
property.

The cross-exam leg *reads* worker ledger data but ships no worker code — it
stays a plugin release unless recon inside the arc finds otherwise.

### R6c `[w]` — the worker batch (gated on the audit's conditions)

| Item | Status |
|---|---|
| Toolchain refresh across the five workers | The audit's condition #1 — the four Oct-2025 lockfiles first; lifts attestation coverage from 69.5% to an estimated low-80s on its own |
| Operations: Dependency provenance gate | Audit **cleared** (2026-08-14): verify-what-attests + name-pinned allowlist (~58 names, growth = reviewed event) + packument-dated cooldown |
| Pool: a second, independent anchor | Standing rule — **may jump the queue into any `[w]` release**; this is the first `[w]` since the rule was written. Decide at arc open, not before |

Deploy split applies: only `sn-analytics` auto-deploys from main; the others
ship manually, and a git-connected worker never runs its own deploy script.

### The spike — restore proof, scoped before it is sequenced

The board row's own caveat is the instruction: *scope against where backups
actually run first.* Backups live at the Cloudways layer (server-scheduled),
not in any repo this program touches — so the row's shape (what "a backup
actually restores" means, and what a periodic check can honestly probe from
the plugin's side) is unknown until a scoping pass against the real Cloudways
backup surface. **The spike decides which sub-release the row joins — or
whether it is an operations runbook rather than code.** Not scheduled into
R6a/b/c until then.

## Ordering and the one owner decision

```
R6a (now) ──► R6c [w] ──► R6b (when credentials land)
                 ▲
   GSC credential request goes out FIRST, in parallel
```

R6a first because it is gate-free. R6c second because its gate is self-serve
(the toolchain refresh). R6b last **only** because its gate is external — if
the credential lands early, R6b and R6c swap freely; nothing couples them.

The one decision that is the owner's: **kick off the GSC credential setup
now**, so the external clock starts today. Everything else in this document
proceeds without input.

## Economics

Per the sequence doc's release loop: each sub-release is its own session
(prep-doc open, Sonnet implements from the brief, ~80–100 turn cap), Opus on
the main thread, Fable off unless a task needs it. Three sub-releases ≈ three
sessions ≈ well inside the per-week program budget. After R6: the standing
rule — re-triage `later` as a whole; that re-triage, not this file, produces
the next sequence document.
