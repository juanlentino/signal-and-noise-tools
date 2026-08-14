# Remote analytics MCP — Increments 2 + 4 (the named set, and the hardening around it)

**Date:** 2026-08-14
**Parent proposal:** [`remote-mcp-transport.md`](remote-mcp-transport.md) §"Increment 2", §"Increment 4"
**Siblings:** [`remote-mcp-increment1-origin-half.md`](remote-mcp-increment1-origin-half.md) ·
[`remote-mcp-increment1-bridge-half.md`](remote-mcp-increment1-bridge-half.md) ·
[`remote-mcp-increment1-client-half.md`](remote-mcp-increment1-client-half.md) ·
[`remote-mcp-increment4-observability.md`](remote-mcp-increment4-observability.md) (peer session, landing separately)
**Scope:** both repos. Origin half in this plugin; client half in `sn-remote-mcp-worker`.
**Version impact:** plugin **MINOR** (v11.x — new user-visible capability; the door is already
live at the owner's install, so installing this update widens a running surface — named plainly
in §1). Worker **v0.2.0 → v0.3.0** (MINOR).
**Owner decisions carried in (2026-08-14):** the full candidate set (all four groups); increments
2 and 4 are **required, not optional**; R-3D-d accepted for insights/narration prose.

---

## Why one document for two increments

Increment 2 widens the surface; Increment 4 is what makes widening safe. Building them apart
would ship eight tools with hardening "to follow" — the exact individually-justified sequencing
this estate's memory warns about. One spec, one review, interleaved delivery
([§ Sequencing](#sequencing)).

---

## Increment 2 — the named set

### 1. The set, and what installing it does

The remote set grows **1 → 8**:

| Remote slug (new) | Shares execute with | Input keys (verified on `main`) |
| --- | --- | --- |
| `signal-noise/remote-get-analytics-summary` | *(live since v11.0.0)* | `range`, `class` |
| `signal-noise/remote-get-analytics-events` | `sn_ability_get_analytics_events` | `range` — **no `class`**, and the twin must not invent one |
| `signal-noise/remote-get-insights` | `snt_ability_get_insights` | *(none)* |
| `signal-noise/remote-get-narration` | `snt_ability_get_narration` | *(none)* |
| `signal-noise/remote-uptime-status` | `snt_ability_uptime_status` | `force_refresh` locally — **stripped remotely, §3** |
| `signal-noise/remote-get-health-scan` | `snt_ability_get_health_scan` | *(none)* |
| `signal-noise/remote-get-rss-stats` | `snt_ability_get_rss_stats` | *(none)* |
| `signal-noise/remote-get-deploy-status` | `snt_ability_get_deploy_status` | `force_refresh` locally — **stripped remotely, §3** |

All seven currently gate on `snt_ability_perm_manage_options` — the same starting point the
summary had before Increment 1. Every admin registration stays **byte-identical**; the isolation
argument from the origin-half doc applies unchanged.

**Installing this update widens a LIVE surface.** Increment 1 shipped with every gate shut;
this one lands on an install whose door the owner has already opened. The moment the plugin
updates and the Worker deploys, eight tools answer where one did. That is the intended effect
of an owner-directed widening, and it is stated here so the CHANGELOG can say it rather than
imply it. The kill switch is unchanged and still closes everything at one stroke.

**R-3D-d, accepted:** `get-insights` and `get-narration` return operational commentary in the
owner's voice — prose that may name content titles — into the Anthropic cloud hop. The owner
selected them knowing this. Recorded as a decision, not a discovery.

### 2. Origin half — the Increment 1 pattern, applied honestly at scale

One new file, `inc/abilities-remote-set.php`: seven twin registrations plus seven per-slug
permission callbacks, each passing its own slug as a **literal** (the nesting-confusion
rationale from the origin-half doc §3, unchanged). `sn_mcp_remote_slugs()` grows to eight
members. The twins are born with `show_in_rest => false` — the #641 lesson applied at birth
rather than patched after.

**Schema duplication scales to seven parity tests, and that is the honest cost.** Increment 1
duplicated the summary's schemas rather than extracting shared constants, because extracting
would modify registrations the increment promised to leave unchanged. The same promise holds
here — seven more admin registrations stay byte-identical — so the same mechanism holds:
each twin's schemas are duplicated and **pinned by a per-slug parity test**. Seven duplications
guarded by seven pins is bulk, not drift. The alternative (a shared-schema refactor across six
admin files) is a fine future janitor task; it must not ride a surface-widening increment.

**Guard coverage is inherited, and inheritance is verified rather than assumed.**
`sn_mcp_remote_guard_run_route()` and `sn_remote_analytics_allows()` both consume
`sn_mcp_remote_slugs()`; widening the list widens their coverage automatically. The test
obligation is the negative space: all eight remote slugs stay **off** `sn_mcp_allowlist()`
(cardinality pins on both lists), and the read door's own count pins must not move.

**The owed test becomes real, and it is the point of this increment's suite.** The origin-half
doc recorded that with one member, a per-slug callback carrying the *wrong* member's literal was
untestable — every wrong literal was either the same string or a non-member. With eight members
that gap is live: a callback carrying a sibling's literal passes every Increment 1-era
assertion. **The matrix test:** each of the eight callbacks is allowed for its own slug and
refused for every other member (8 × 8, loop-generated). Its mutation partner: swap one
callback's literal for a sibling's — the matrix must red on exactly that callback's row.

### 3. `force_refresh` is stripped at the edge AND absent from the twins

`uptime-status` and `get-deploy-status` accept `force_refresh` locally — a deliberate cache
bypass that hits the Uptime API / GitHub fresh. The remote twins **do not carry the key**, and
the Worker's shape gates refuse it. Two independent layers, deliberately redundant: the twin's
`additionalProperties: false` refuses it at the origin even if the edge gate regresses, and the
edge gate refuses it without spending an origin round trip.

Reasoning: a phone caller must not be able to spend the origin's **upstream** quotas. The 2am
case the door exists for needs the *last known* answer, not a fresh probe — and a brokered
caller triggering third-party API fetches on a shared host is a cost amplifier the aggregate
rate cap does not model (10 calls/min × a GitHub fetch each is a different load than 10 cached
reads). The stripped key is pinned in both repos.

### 4. Client half — eight tools, one generalised handler

`src/bridge.mjs` generalises from one hardcoded tool to a **tool table**: MCP name → origin
slug → allowed key set. `handleAnalyticsCall` becomes `handleBridgeToolCall(toolName, args,
deps)` with the table row injected; the ordered path (shape gate → secret gate → target gate →
rate-before-fetch → one log line per exit) is **shared machinery, unchanged** — eight tools, one
path, not eight paths.

| MCP tool name | Allowed keys |
| --- | --- |
| `sn_remote_analytics_summary` *(live)* | `range`, `class` |
| `sn_remote_analytics_events` | `range` |
| `sn_remote_insights` · `sn_remote_narration` · `sn_remote_uptime_status` · `sn_remote_health_scan` · `sn_remote_rss_stats` · `sn_remote_deploy_status` | *(none)* |

Everything Increment 1 decided per-tool holds per-table-row: shape pinned at the edge, values
owned by the origin, no enums, no `outputSchema`, descriptions carrying units and traps
**inline** (the PR #644 lesson — the remote surface is read without estate context and must
explain itself). `tools/list` returns exactly **nine** entries (ping + eight), cardinality
pinned. The `bridge_call` log line's `tool` field already exists; the peer's ring already keys
by slug.

**The brokered budget stays aggregate — a named deviation.** The transport doc's Increment 2
row says "Cap + audit per tool." Audit per tool exists (the log line). A per-tool *cap* would
multiply the budget by eight: eight 10/min budgets are an 80/min aggregate against a shared
host, **looser** than today's single 10/min, and a per-tool cap tight enough to matter (1–2/min)
would break legitimate use ("summary, then events, then narration" is one owner question). One
budget across all brokered calls is the stricter and simpler control. Revisit only with usage
data — which the peer's counters and Increment 4's alerting will produce.

### 5. Stale-snapshot note, operational

Increment 1's deploy proved connector schema snapshots go stale: the phone listed one tool
until a reconnect. The same will happen here — after the Worker deploys, **the connector must
be removed and re-added** to see the eight. The CHANGELOG and the deploy notes both say so;
nobody debugs a "missing tool" that a reconnect fixes.

---

## Increment 4 — the hardening

### 6. Ownership settled with the peer session (2026-08-14)

The observability arc (`remote-mcp-increment4-observability.md`, peer session) is
**counting + display, origin-side, no alerting** — per-day outcome counters, a capped ring, one
status-panel line. Recorded reciprocally in both specs:

- The peer **owns the display layer and the origin counters.**
- **This increment owns R-3D-c volume alerting, Worker-side**, because anomaly detection needs
  the one thing the origin structurally lacks: *who*. The origin's "audit the caller"
  incapacity is recorded in the peer's spec §7; the Worker's `bridge_call` line carries `sub`.

### 7. R-3D-c — volume anomaly detection, in the Durable Object, surfaced through Health

**Where the counting lives:** the `EdgeState` DO already counts brokered calls per subject in
fixed windows — the anomaly counter is a second read of the same motion, not new machinery. A
`bridge_day:<sub>` key (fixed 24h UTC window, same storage, same single-writer consistency)
accumulates per-subject daily totals.

**What an anomaly is (defaults, clamped config):** a subject exceeding
`BRIDGE_DAILY_ANOMALY_PER_SUB` (default **200**/day) or all subjects together exceeding
`BRIDGE_DAILY_ANOMALY_TOTAL` (default **500**/day). At 10/min the hard ceiling is 14,400/day,
so these thresholds fire at ~1.4% of what the rate cap alone would allow — they detect a *slow
drain*, the abuse shape the per-minute cap cannot see (client-half spec's open question, now
closed).

**Fail-open, deliberately — the inverse of the rate counter, stated so nobody "fixes" it.**
The rate counter is a gate: store unreachable ⇒ deny. The anomaly counter is an instrument:
store unreachable ⇒ **the call proceeds** and the anomaly state degrades to unknown. An
observability failure must never become an availability failure; the gate half of F1 already
fails closed in front of it. A mutation pin guards each direction *separately* so the two
opposite postures cannot be cross-contaminated by a well-meaning refactor.

**How the owner finds out — reuse the estate's alert path, build no new channel:**

```
DO (bridge_day counts) → /_sn/remote-mcp/status: "anomaly": { flagged, total_today, subjects_over }
        → inc/health-edge-workers.php fifth-worker check (§8) → Health scan → existing digest/alerting
```

The status block carries **counts only, never subject identities** — `sub` is an email on an
unauthenticated endpoint; `subjects_over` is a number. The per-sub detail lives in Workers Logs
where it already exists. The Health check flags `anomaly.flagged === true` as a warning
distinct from "worker down" — a flood and an outage must never share a symptom.

**The reconciliation check — adopted from the peer, and it partially opens the rotation blind
spot.** The client-half spec claims secret disagreement is "unobservable by construction." The
peer's outcome counters falsify half of that: the origin counts *which branch refused*
(`refused_auth` vs `refused_shut`) even though the wire collapses them into one 404. So:

> **Worker logs show `door_closed_or_credential_or_tool` + the origin panel shows
> `refused_auth` climbing + the toggle is ON ⇒ the two `SN_BRIDGE_TOKEN` halves disagree.**

That is the first observable symptom of a botched rotation anywhere in the estate. This
increment amends the client-half spec's blind-spot section to point at
`remote-mcp-increment4-observability.md` §11 as the diagnostic, and adds the signature to the
revoke runbook's diagnosis table. No code — the diagnostic is free once both sides exist; what
was missing was anyone writing down that the two readouts compose.

### 8. The fifth worker in the health panel

`inc/health-edge-workers.php` gains `sn-remote-mcp`, reading `/_sn/remote-mcp/status`:

| Field | Healthy | Flags as |
| --- | --- | --- |
| `configured` | `true` | outage |
| `killed` | `false` | **info, not outage** — a deliberately dark door is a state, not a failure |
| `bridge_secret_bound` | present (boolean) | absent field ⇒ outage (a deploy lost the readout) |
| `anomaly.flagged` | `false` | warning, distinct from outage |
| `version` | present | stale-deploy hint (deferred — carried, not yet compared; needs a known-current source) |

The probe follows the existing four workers' idiom, including the estate rule that a host
false-positive gets added to the shared classifier, not inline.

### 9. Dependency pin

The Worker's devDependencies move from caret ranges to **exact pins** (`wrangler`, `vitest`,
`@cloudflare/vitest-pool-workers`), with the `overrides` block kept. This is the estate's one
internet-facing auth surface; R-3D-b named its dependency chain a risk, and a caret range is a
standing invitation for an unreviewed minor to land in CI. Updates become deliberate diffs.
(Runtime dependency count remains **zero**, which is the real control — this pins the toolchain.)

### 10. Explicitly out of scope

- **Per-tool caps** (§4's named deviation) and **per-tool origin counters** (the peer's schema
  v2 forward note) — both wait for usage data.
- **Alert delivery beyond the Health surface** (push/email direct from the Worker). The Health
  scan's existing digest is the channel; a second channel needs its own justification.
- **The shared-schema refactor** of the seven duplicated twins (§2) — a janitor task, not a
  widening rider.
- **The wp-admin "test the bridge" control** (client-half spec's open question) — §7's
  reconciliation diagnostic reduces its urgency; still the only *active* agreement test, still
  deferred.

---

## Sequencing

1. **Wait for the peer's observability PR to merge** (`claude/remote-door-observability`,
   landing tonight) — `sn_mcp_remote_slugs()`'s neighbourhood and the CHANGELOG are contended;
   this work rebases over it.
2. **Origin half** (plugin): twins + callbacks + matrix test + guard pins. Ships inert-ish —
   the new slugs answer only through a door the owner already controls.
3. **Client half** (Worker): tool table + shape gates + anomaly counter + status block.
   v0.3.0. Deploy; owner reconnects the connector.
4. **Hardening remainder** (plugin): fifth-worker health check + runbook/spec amendments
   (§7 reconciliation, client-half blind-spot section).
5. **Adversarial review** (Grok, verified available today): same brief shape as Increment 1's —
   attack the matrix, the stripped `force_refresh`, the fail-open/fail-closed boundary in the
   DO, and the anomaly thresholds' bypass space.

Each step carries the house testing discipline: TDD, named pins, and a mutation sweep whose
rows include — at minimum — the sibling-literal swap (§2), the `force_refresh` re-add (both
repos), the anomaly fail-open inversion and the rate fail-closed inversion (§7, one pin per
direction), the cardinality pins on both allowlists, and the tools/list count.

---

## Kill criteria (inherited, plus two of this increment's own)

The transport proposal's kill criteria continue to bind. Two additions:

1. **Any remote twin becomes reachable through the read or rw doors** — the cardinality pins
   are the tripwire; if holding them requires exempting a slug, stop and re-scope.
2. **The anomaly instrument acquires gate behaviour** (a deny path that consults
   `bridge_day:*`) — that is F1's fail-closed counter growing a fail-open sibling in the same
   store; the two postures must never share a decision.

---

## Open questions deliberately left open

**Should `get-narration`'s remote twin redact content titles?** R-3D-d is accepted for the
prose as-is, but a title-redacting variant would narrow the confidentiality delta at real
implementation cost (the narration is generated upstream; redaction here is string surgery on
finished prose). Deferred: accepted-as-is today, revisit if the narration's vocabulary widens.

**Anomaly thresholds are guesses until the counters have history.** 200/sub/day and 500/day
total are set from first principles (≥10× any plausible owner usage, ≤2% of the rate-cap
ceiling). The peer's day counters will produce the baseline that turns them into measurements.
Revisit after two weeks of live counts.
