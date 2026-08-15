# The ledger's convergence seam

**Status**: proposed, 2026-08-15
**Repos touched**: `signal-and-noise-provenance`, `signal-and-noise-tools`

## The complaint

The public ledger's `verify.yml` has gone red repeatedly over the last two
weeks. The owner's ask was "make the ledger never error ever again."

Taken literally that is the wrong target. The ledger's entire job is to shout
when the public page stops matching the signed content. A ledger that never
errors has been disconnected from reality, and the cheapest way to satisfy the
literal ask is to make the verifier tolerant — which silently converts a trust
guarantee into decoration.

The right target: **the ledger must never again go red for a reason that is not
a real defect.** It must stay fully strict on genuine drift.

## What actually failed

All 22 failures in the last 100 `verify.yml` runs, classified from run logs:

| Date | Error | Trigger | Cause |
|---|---|---|---|
| 08-02→03 (×9) | bot-challenge interstitial | push/PR | Imunify360 arc — **resolved** |
| 08-04 03:40 | `served-page drift start-here` (h=F, pt=T) | push `(pending)` | stale index — since fixed |
| 08-05 11:35 | `anchor is not confirmed` | push `(pending)` | **A** transitional |
| 08-08 21:10 | `index anchor disagrees — pending/null` | push `(pending)` | **B** defect |
| 08-11 01:45/50/55 | `HTTPS key mirror schema mismatch` | push `(pending)` | cross-repo skew — **resolved** |
| 08-11 14:00 | `index anchor disagrees — pending/null` | push `(pending)` | **B** defect |
| 08-11 18:22 (×2) | `no record exists at pages/…` | PR | dev-time, legitimate |
| 08-14 18:55 | `index anchor disagrees — pending/undefined` | push `(pending)` | **B** defect |
| 08-15 19:00 | `served-page drift` (h=F, pt=F) | push `(pending)` | **C** stale edge |
| 08-15 19:05 | `served-page drift` (h=T, pt=F) | push `(pending)` | **C** stale edge |

Three live causes remain: **A** transitional-state checks, **B** a real
comparison bug, **C** a per-URL cache purge that does not work.

### The layer claim

Every recurring operational red is a **live-world check** running on a **push
event that is by construction mid-transaction**. No offline integrity check
(`npm test`, `verify:records`, `verify:genesis`) has ever failed operationally.
Every `schedule` run in the last 100 is green.

The ledger's integrity has never been in doubt. What goes red is the world's
lag — plus two genuine bugs hiding inside that noise.

## Cause A — live checks on transitional pushes

`verify.yml` triggers on `push`. The provenance Worker commits a record and
pushes it; seconds later the full battery runs against a record that is
deliberately `pending`: the OTS Bitcoin anchor will not confirm for hours, the
index has not been rebuilt yet, the page cache has not cleared.

The npm scripts already split along the seam:

- **Integrity** — offline, deterministic, true instantly:
  `npm test`, `verify:records`, `verify:genesis`
- **Convergence** — live, eventually-true:
  `verify:coverage`, `verify:pages`, `verify:keys`, `verify:key-pins`,
  `scripts/build-index.mjs`

The workflow does not respect that seam. It should.

## Cause B — a missing `?? null` in `verify-coverage.mjs`

Line 47 normalizes both sides of the comparison:

```js
recordBlock !== (entry.standalone_bitcoin_block ?? null)
```

Line 58 normalizes only the record side:

```js
(anchorRecord.ots.bitcoin_block ?? null) !== entry.bitcoin_block
```

When an index row omits `bitcoin_block`, `entry.bitcoin_block` is `undefined`,
so `null !== undefined` throws — printing exactly the observed
`record says pending/null, index says pending/undefined`.

This is a real defect and caused three failures. It must be fixed on its own
merits; scoping the battery by trigger would otherwise hide it.

## Cause C — per-URL Cloudflare purge does not clear the edge

Evidence from 2026-08-15:

- The note was edited three times (18:55, 19:00, 19:05). Each save fires
  `wp_after_insert_post` → `sn_cf_purge_urls()` in `inc/cloudflare-purge.php`.
- At 19:45 the bare URL still served HTML with `last-modified: Fri, 14 Aug
  16:25:36 GMT` — 27 hours old — carrying a sentence the signed record did not.
- A cache-busted URL returned `last-modified: Sat, 15 Aug 19:10:36 GMT`, fresh
  and matching the record. The `.json` twin matched the record too.
- A manual zone purge (`purge-all-caches`) cleared it.

So: three per-URL purges ran and did not work; one zone purge did. Cloudflare
single-file purge must match the exact cache key and does not reliably clear
upper tiers under Tiered Cache; a zone purge always does.

Nothing in the code can observe this. `sn_cf_purge_urls()` is documented as
"Fire-and-forget (non-blocking); Caller doesn't get a success signal." The
Cloudflare admin tab reports purge **dispatch**, never edge **freshness** for
the purged URL. The readout measures the wrong call.

## Cause D — the health chip watches the wrong runs

`inc/health-check-ledger-ci.php` reads:

```
workflows/verify.yml/runs?status=completed&per_page=1
```

No event filter. The chip built to watch the ledger has been reading exactly
the transitional push failures described above and reporting "the trust repo is
reporting a problem nobody may have seen." The alarm rings on the wrong signal.

## Design

### 1. Trigger-scoped battery (`verify.yml`)

When the head commit message matches `^provenance: .* v\d+` — the Worker's
record push — run **integrity only**. The Worker commits under the owner's
name, so the commit message is the reliable discriminator, not the author.

Full battery continues to run on `schedule`, `workflow_dispatch`,
`pull_request`, and ordinary human pushes.

A pending record can no longer fail a check that asks whether the world has
caught up to it yet. Secondary benefit: those pushes stop billing roughly 45
seconds of live-fetch job time each, and they are frequent.

### 2. Fix `verify-coverage.mjs:58`

Add `?? null` to the index side so it matches line 47, with a regression test
pinning `undefined` and `null` as equivalent on **both** sides.

### 3. Name the layer when a page is stale (`verify-pages.mjs`)

Today `served-page drift` is emitted whether the page is tampered with or
merely stale. Those need different verdicts.

On drift, re-fetch the page with a cache-busting query string. If the
cache-busted origin response reproduces the signed record, the origin is
correct and the edge is stale: fail with **`stale edge cache`**, naming the
`age`, `cf-cache-status` and `last-modified` observed. If the cache-busted
response *also* disagrees, the content genuinely drifted: keep the existing
`served-page drift` verdict.

This is not tolerance. Both outcomes are still red on the scheduled run. It
converts an ambiguous failure into a precise one, and it is the instrument that
resolves the Cloudflare-tier-versus-origin question the next time it occurs.

### 4. Verify the per-URL purge (`inc/cloudflare-purge.php`)

After the per-post purge dispatches, schedule a single delayed freshness probe
(via `wp_schedule_single_event`) against the purged permalink. The probe
compares the bare URL against a cache-busted fetch of the same URL. If the bare
URL is still stale, escalate once to `sn_cf_purge_everything()` and record the
outcome for the admin tab.

Escalation is bounded — one retry, one zone purge, recorded — so a broken
single-file purge self-heals instead of silently shipping a stale page to
readers and a red ledger to CI.

### 5. Point the health chip at the authoritative run

Add `&event=schedule` to `SN_LEDGER_CI_RUNS_URL`. Combined with change 1, the
chip then reflects the daily full-strictness audit rather than transitional
push noise.

## Explicitly not doing

- **No tolerance in any convergence check.** `verify:pages` keeps fetching the
  bare URL — what a stranger sees — and keeps failing on genuine drift. The
  daily scheduled run remains a full-strictness audit of the live world.
- **No weakening of the anchor check.** A pending OTS anchor stays pending
  until Bitcoin confirms it.
- **No unrelated refactoring** of the verify battery.

## Residual risk

After change 1, a stale page or unconfirmed anchor surfaces on the next
scheduled run rather than within a minute of the push. That is the correct
trade — a pending OTS anchor legitimately takes hours, so sub-minute detection
of it was never meaningful — and change 4 closes the page-staleness case at
publish time, which is earlier than CI caught it before.

## Success criteria

- A Worker record push runs integrity checks only and is green.
- The scheduled run stays full-strictness and red on genuine drift.
- `verify-coverage.mjs` has a regression test covering `undefined` vs `null`.
- A stale edge produces a verdict that says "stale edge cache", not "drift".
- A failed per-URL purge escalates to a zone purge and is recorded.
- The health chip reads only scheduled runs.
