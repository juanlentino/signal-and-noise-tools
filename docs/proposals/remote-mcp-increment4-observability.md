# Remote analytics door — observability (R3 §3D Increment 4, first slice)

**Status:** BUILT 2026-08-14. Execution plan (with two corrections found during execution):
`docs/proposals/remote-mcp-increment4-observability-plan.md`.
**Closes:** part of **R-3D-c** (read volume as a signal), and the "how would I know to open
the runbook" gap left by Increment 3.
**Does NOT close:** the §8.4 *"audit the caller, not just the call"* requirement. See
[§7](#7-what-this-cannot-do-and-why-it-is-recorded-here) — that one cannot be satisfied at the origin at all.

---

## 1. The problem, stated precisely

`docs/ops/remote-mcp-revoke-runbook.md` gives five controls for stopping the remote analytics
door, ordered fastest-first. Every one of them assumes the owner **already suspects something**.

Nothing on the site records that the door was ever used. So the question the runbook implicitly
depends on — *what would make me open this?* — currently has no answer. A door with five ways to
shut it and no way to know it opened is a strange shape, and it is the shape shipped in v11.0.0.

This is worth more than Increment 2's additional remote tools, and the reasoning is not a
preference: **more tools widen a surface nobody is watching.** Observability is the increment
that makes the others safe to consider.

## 2. What the owner decided

Four decisions, taken 2026-08-14, recorded here because each closed off work that would otherwise
look reasonable later.

| Decision | Chosen | Rejected, and why it matters |
| --- | --- | --- |
| Signal type | **Passive record only** | Push-on-use and push-on-anomaly both deferred. You cannot threshold what you do not count, so the record is the prerequisite either way. Volume-anomaly alerting in particular needs a baseline, and with one owner on one phone the sample is far too small to learn one — the threshold would be invented, not measured. |
| Granularity | **Counters + a small capped ring** | Counters answer *how much*; the ring answers *what exactly, most recently*. A full row-per-call log was rejected: this is an unauthenticated-reachable write path while the door is armed, so an uncapped row log is a flood amplifier. |
| Refusal counting | **Count, with coalesced writes** | Counting refusals is the probe signal and the only thing that would raise an alarm unprompted. Not counting them was rejected as throwing away the security-valuable half. Counting them uncoalesced was rejected because `/wp-json/signal-noise/v1/bridge` is a public origin route — it is **not** behind Access the way `mcp.juanlentino.com` is. |
| Storage home | **Separate option** | Extending `sn_audit_log_v1` was rejected on the isolation doctrine already written into `inc/mcp/mcp-remote-guard.php`: the remote door mirrors other guards' predicates rather than sharing them. That blob is also autoloaded and written on every failed login. |

## 3. Module and store

New file `inc/mcp/mcp-remote-observability.php`, isolated exactly as `mcp-remote-guard.php` is:
it mirrors the login audit log's proven shape and shares none of its storage or code.

Option `sn_mcp_remote_log_v1`, **autoload `false`**. It is read by the admin panel and by nothing
on a front-end request, so autoloading it would tax every page view for data only one screen
reads.

**All times are in the site timezone**, via `wp_date()` — the same call `snt_audit_today_key()`
uses. The remote log sits beside the login audit log in the same admin area and is read by the
same human; two security readouts disagreeing about what "today" means would be a defect, and a
UTC bucket would read as wrong to anyone looking at the panel in the evening. The cost is that
changing the site timezone reinterprets stored values — acceptable for a diagnostic line, and the
same trade the audit log already makes.

```php
array(
    'schema'    => 1,
    'last_used' => null,   // 'Y-m-d H:i:s' (site timezone) of the last SUCCESSFUL dispatch, or null
    'counters'  => array(
        // 'Y-m-d' (site timezone) => array( outcome => int )
        '2026-08-14' => array( 'dispatched' => 3, 'refused_auth' => 12 ),
    ),
    'recent'    => array(
        // capped ring, NEWEST FIRST
        array( 'ts' => '2026-08-14 02:41:51', 'slug' => 'signal-noise/…', 'outcome' => 'dispatched' ),
    ),
)
```

`last_used` is denormalised out of `recent` deliberately. It is the single most valuable fact on
the screen, it must survive the ring rolling over, and it must survive a prune.

### 3.1 The outcomes

A fixed const list, mirroring `SN_AUDIT_COUNTER_TYPES`, so an unknown outcome is dropped rather
than silently creating a key:

| Outcome | Handler step |
| --- | --- |
| `dispatched` | the ability executed |
| `refused_shut` | step 0 — a gate was closed at dispatch |
| `refused_auth` | step 1 — Bearer absent or wrong |
| `refused_slug` | step 2/3 — off-list slug, or the ability did not resolve |
| `refused_request` | missing slug (400) |

**`refused_shut` and `refused_auth` are byte-identical *to the caller*** — that is exactly what
[#642](https://github.com/juanlentino/signal-and-noise-tools/pull/642) established, and it must
stay true. They are separable **only in the record**, which is admin-only and never echoed. That
distinction is what tells the owner "calls arrived while I had the door switched off", which is a
different and more alarming fact than "someone guessed at the token".

> **Do not "fix the inconsistency."** A test pins both halves: the two outcomes are recorded
> distinctly AND the two responses remain identical in code, message and data. Collapsing the
> record to match the wire loses the signal; leaking the distinction to the wire reopens the
> oracle. Both directions red.

## 4. Coalescing the refusal writes

Refusals accumulate in a `sn_mcp_remote_pending` transient holding an outcome→count map, the day
key those counts belong to, and a `first_seen` GMT timestamp set when the set is created. It folds
into the persisted option when **any** of:

1. `first_seen` is more than **60 seconds** ago, or
2. a successful dispatch occurs (which is writing anyway), or
3. the admin panel reads the record.

A flood then costs one option write per minute rather than one per request, while a real probe
still lands in the record within a minute.

**The transient TTL is 1 hour — far longer than the 60-second flush window, and deliberately so.**
Nothing schedules a flush: if a probe stops, the last sub-minute of counts sits in the transient
with no further request to trigger condition 1. Condition 3 is what collects them, and the long
TTL is what guarantees they are still there to collect. A TTL near the flush window would silently
discard exactly the tail of an attack that stopped — the counts most worth having.

The day key is stored **with** the pending set rather than recomputed at flush time. A pending set
created at 23:59:58 UTC and flushed at 00:00:05 belongs to the day it was recorded, not the day it
was written; recomputing would file it under the wrong date and understate the busy day.

Two constraints on the implementation:

- **The flush decision is a pure predicate** — `sn_mcp_remote_should_flush( $pending_age, $is_dispatch )`
  — so it is testable without mocking a clock. The live wrapper reads the clock and calls it.
  Same split as `sn_mcp_remote_kill_switch_decision()` / `…_engaged()`.
- **The read path folds pending into what it returns.** Otherwise the panel under-reports by up
  to a minute, and a readout that is quietly wrong is worse than one that is absent — the owner
  would be reading "0 refused" while a probe was in progress.

## 5. Recording, and its subordination to the door

One call per outcome branch in `sn_bridge_handle_request()`, each guarded by `function_exists()`
— the optional-module pattern the bridge already uses for `sn_mcp_remote_slugs()`.

**Recording is observational and must never be load-bearing.** It must not alter the response, and
it must not be able to throw a request. A broken or absent log must not be able to shut the door,
and must not be able to *open* it either.

The pin that establishes this is the discriminator for the whole file: **with the module absent,
the bridge behaves byte-identically.** Without it, "the log is optional" is a claim rather than a
property.

## 6. Retention, and why not cron

Day-buckets older than **90 days** (matching `SN_AUDIT_RETENTION_DAYS`) are dropped **on write**,
not by a scheduled event.

- A cron can drift, be unscheduled, or fail silently; an opportunistic prune cannot get out of
  step with the data it prunes.
- It avoids touching the cron-events registry, which is a full-sweep contract.
- The ceiling is trivial regardless: five ints per day, 90 days.

The ring is capped at **50 entries**, independently of the day-bucket prune, so a single busy day
cannot evict the record that the door was used last month. Fifty is chosen to be small enough that
the option stays trivial and large enough to survive one ordinary phone session without rolling —
it is a display aid, and the counters are the durable record.

## 7. What this cannot do, and why it is recorded here

**The origin cannot record *who*.** Cloudflare Access issues, holds and expires the session;
WordPress never sees it. At the origin a bridge call is a valid Bearer token and nothing more.

So the threat model's §8.4 requirement — *"Audit the caller, not just the call … the read path
needs to record which brokered session did it, or a leaked session is indistinguishable from the
owner in the record"* — **is not satisfied by this increment and cannot be.** This design answers
*that*, *when* and *what*. It never answers *who*.

That work belongs to the Worker, where `src/guard.mjs` already returns `{ sub, email }`. It is
tracked as the "Worker `sub` log line" item and is the same wall the wp-admin session list hit in
Increment 3 — recorded there so it is not proposed a third time.

Stating this in the design is the point. Two controls in the Speed Brain investigation were
scoped to a layer that could not answer them, and the lesson recorded from that was to ask **which
layer answers this question** before building. An origin-side log that implied it identified the
caller would be the same error with a longer fuse.

## 8. Admin surface

The presenter lives in the new module and returns a formatted string; `inc/admin-forms/mcp-connect-status.php`
prints it under the existing remote card and gains no logic. That file is already 318 lines.

- Never used: **"Never used."**
- Used: **"Last used 2 hours ago · 3 calls today · 12 refused"**

The refused count is shown next to the dispatch count on purpose. A dispatch count alone reads as
reassuring; the pair is what makes a probe legible.

## 9. Tests

New `tests/mcp-remote-observability.php`, plus additions to `tests/mcp-bridge-route.php`.

| Pin | Why it is not decorative |
| --- | --- |
| **The bridge behaves byte-identically with the module absent** | The discriminator for §5. Establishes recording is observational rather than a dependency. |
| **Coalesced refusals are not lost before a flush** | Record refusals, read back before any flush, counts must appear. Guards §4's under-reporting failure directly. |
| Each handler branch records its own outcome | Otherwise one shared "something happened" counter satisfies every label while distinguishing nothing. |
| `refused_shut` ≠ `refused_auth` in the record, `==` on the wire | Both directions red. See §3.1. |
| Day buckets use `wp_date()`, not `gmdate()` | Pins agreement with `snt_audit_today_key()`. Two security readouts in one admin area must not disagree about "today". |
| A pending set files under the day it was **recorded**, not flushed | Record with a day key of *yesterday*, flush, assert yesterday's bucket grew. Without this, a midnight flush silently moves counts to the wrong day and understates the busy one. |
| The ring is capped and newest-first | A cap asserted only by "it has ≤ N" cannot tell capping from never having filled. Overfill it, then assert the oldest is gone AND the newest is at index 0. |
| Prune drops old buckets and **keeps recent ones** | A prune that deleted everything would satisfy a drop-only assertion. |
| `last_used` survives both ring rollover and prune | It is denormalised precisely so it can; nothing else proves it does. |
| An unknown outcome is dropped, not stored | Mirrors `SN_AUDIT_COUNTER_TYPES`' guard. |

## 10. Out of scope

- **No MCP ability or REST view** for this data. The panel is the surface. Adding a read tool
  would widen the very surface this increment exists to watch.
- **No alerting.** Deferred by decision, not oversight — see §2. **Ownership settled
  2026-08-14 by cross-session agreement:** R-3D-c volume alerting is Worker-side, keyed
  per-`sub`, because an anomaly worth alerting on is per-session and only Workers Logs can
  name the session (the same wall as §7). This record is the display layer and the
  origin-side cross-check.
- **No `who`.** See §7.
- **No Worker changes.** This is an origin-side increment end to end.

## 11. Forward notes (recorded so nobody rediscovers them)

- **This record partially opens the rotation blind spot.** The client-half spec
  (`docs/proposals/remote-mcp-increment1-client-half.md`) records that a `SN_BRIDGE_TOKEN`
  mismatch is unobservable by construction — the wire collapses every anonymous refusal into
  one `rest_no_route`, deliberately. The record does not: it counts which **branch** fired.
  **`refused_auth` climbing while the toggle is ON and the Worker believes itself healthy is
  the specific signature of the two secret halves disagreeing** — a botched rotation's first
  observable symptom anywhere in the estate. `refused_shut` climbing is the different fact
  that calls arrived while the owner had the door off. The wire stays sealed; the diagnosis
  moved to an authenticated surface, which is where the original 503's job was supposed to
  live all along.
- **Per-tool counters are a schema v2, when Increment 2 lands.** Increment 2 widens
  `sn_mcp_remote_slugs()` from 1 to 8, at which point per-tool volume becomes meaningful.
  Today the ring's per-slug rows carry per-tool recency and the counters are day→outcome
  only, deliberately (YAGNI at one tool). The blob carries a `schema` field for exactly this
  migration; key the v2 buckets by the `tool`/slug field rather than assuming one tool.
