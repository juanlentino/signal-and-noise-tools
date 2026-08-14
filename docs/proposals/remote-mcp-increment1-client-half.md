# Remote analytics MCP — Increment 1, client half (the Worker's origin channel)

**Date:** 2026-08-14
**Parent proposal:** [`remote-mcp-transport.md`](remote-mcp-transport.md) §"Increment 1"
**Siblings:** [`remote-mcp-increment1-origin-half.md`](remote-mcp-increment1-origin-half.md) (merged, PR #632)
· [`remote-mcp-increment1-bridge-half.md`](remote-mcp-increment1-bridge-half.md) (merged, PR #638, shipped v11.0.0)
**Scope:** `sn-remote-mcp-worker` **only**. No plugin code changes. This document lives here
because its two siblings do and they cross-reference each other; the implementation lands in the
other repo.
**Version impact:** Worker `v0.1.0 → v0.2.0` (MINOR — a new user-visible capability). **No plugin
version bump**, because no plugin file changes.

---

## Why this exists

The origin half shipped at v11.0.0 on 2026-08-14. WordPress now knows how to answer a bridge
call. **Nothing knows how to make one.** `src/index.mjs:6` still reads "No analytics ability, no
origin bridge, no write door", the routing table is `/mcp`, `/mcp/status`,
`/_sn/remote-mcp/status`, and `sn_remote_ping` is the only tool.

The door is half-built. This document specifies the other half.

---

## What the origin actually offers — verified, not assumed

`POST https://juanlentino.com/wp-json/signal-noise/v1/bridge`

| Element | Value |
| --- | --- |
| Auth | `Authorization: Bearer <SN_BRIDGE_TOKEN>`, compared with `hash_equals()` |
| Body | `{ "slug": "...", "args": { ... } }` |
| Success | `{ "ok": true, "data": <ability output> }` — the envelope is pinned (bridge-half mutation 5b) |
| 404 **pre-auth** | Route never registered (door off · secret absent) **or Bearer wrong** — core's `rest_no_route`, verbatim |
| 404 **post-auth** | Valid Bearer, but slug not on the remote list, or the ability does not resolve — `sn_bridge_not_found` |
| 400 | Malformed body (reachable only by a caller holding the secret) |
| Allowlisted slug | `signal-noise/remote-get-analytics-summary`, and only that one |

The route registers **only** when `sn_mcp_remote_enabled` is on **and** `SN_BRIDGE_TOKEN` is
defined in `wp-config.php`. Both default to off, so **the expected steady state is a 404.**

The two 404 shapes are the post-#642 split, and they are the subject of
[A signal the client does not yet consume](#a-signal-the-client-does-not-yet-consume): the client
maps both to one class in Increment 1, on purpose.

The ability's `input_schema` (`inc/abilities-remote-analytics.php:66-73` on `main`) accepts an object with
exactly two optional keys — `range` (string or integer) and `class` (string) — and
`additionalProperties: false`. Documented values are `7|14|30|90|365|all` and
`human|suspect|bot`.

---

## The origin moved while this was being scoped

An adversarial review of the shipped origin half ran during this scoping (Grok
`run-mssas366-uy2uh7`, 2026-08-14). Its verdict on the data path: **it holds.** It could not
extract analytics without the token, ride the capability grant onto another slug, persist the
capability, flip `SN_MCP_REMOTE_DISABLED` from the web, or reach the toggle as a non-admin.

It landed two low-severity reconnaissance findings, and **a concurrent session fixed both, then
found a third, while this document was being written.** All three are merged to `main`
(PR #641 → `a1e229f`, PR #642 → `fc60d63`):

| Finding | Fix |
| --- | --- |
| Handler answered **401** for a bad Bearer while an unregistered route answered 404, so the status code announced "this door is armed". The REST index listed `/bridge` only when both gates were open. | Bad Bearer now answers 404; route carries `show_in_index => false`. (#641) |
| The ability registered `show_in_rest => true`, so `POST /wp-abilities/v1/abilities/<slug>/run` existed on every install and leaked switch state via its error code. | `show_in_rest => false`. The bridge dispatches via `wp_get_ability()->execute()` and never needed that route. (#641) |
| **The oracle was closed on the status and left open on the BODY.** An unregistered route answers core's `rest_no_route` / *"No route was found matching the URL and request method."*; the handler answered `sn_bridge_not_found` / *"Not found."* A REST client reads JSON, not a status line — so an anonymous prober could still separate armed from shut. | Every **pre-authentication** refusal now returns core's `WP_Error` verbatim — code, message and `default` text domain alike, so the two bodies cannot diverge on a non-English install. (#642) |

The third is the one worth internalising: **closing a leak on one channel does not close it.**
Status and body are two channels, and the fix for the first made the second the tell.

**This was caught by checking, not by being told.** The line numbers cited in an earlier draft of
this document did not match the file, which is what exposed the divergence. Worth recording as
mechanism: the citations were load-bearing precisely because they were checkable.

### What that does to this design

**The 404 the Worker receives is now four-ways ambiguous, not two.** Switch off · secret absent at
the origin · **secret present but not matching ours** · slug not on the remote list. All identical
on the wire, to every caller — including one holding a valid secret, because the check that used
to separate them is the one that was removed.

That is correct for the anonymous prober the fix targeted. It has a real cost on this side, and
[Error handling](#error-handling) and [The rotation blind spot](#the-rotation-blind-spot) both
carry it rather than pretending the ambiguity is narrow.

**This document specifies behaviour that is correct against either origin version.** The Worker
maps 401 → `credential_rejected` *and* 404 → the ambiguous class. Against the fixed origin (now
`main`) the 401 branch is unreachable; against the pre-fix origin it was the credential answer.
Neither case needs a Worker change, which is the point of specifying both — and the 401 branch is
pinned anyway, because a mapping branch with no test is a branch nobody has checked.

### A signal the client does not yet consume

#642 split the refusals by **authentication state**, and that has a consequence for this client
that is worth recording before someone rediscovers it:

| Origin condition | Body |
| --- | --- |
| Door off · secret absent · **wrong secret** | `rest_no_route` (core's, verbatim) |
| Valid secret + off-list slug · ability missing | `sn_bridge_not_found` |

So **`sn_bridge_not_found` proves the Worker's secret matched** — the origin only reaches that
branch after `hash_equals()` passes. That is a positive credential signal the Worker currently
throws away, because it never reads an error body at all.

**Increment 1 deliberately does not consume it**, for two reasons and one non-reason:

- It buys almost nothing *now*. The Worker only ever sends the single allowlisted slug, so
  `sn_bridge_not_found` would mean the origin's remote list changed underneath it — real, but
  rare, and already visible as a `door_closed_or_credential_or_tool` in the logs.
- It does not close the [rotation blind spot](#the-rotation-blind-spot). A mismatched secret and
  a dark door both answer `rest_no_route`; they remain indistinguishable, which is the whole
  point of #642.
- **The non-reason:** it would *not* violate the never-echo property. Branching on a `code` field
  is not the same as putting origin text in a tool result. If a later increment wants this, the
  pin to preserve is "no origin *text* reaches the caller", not "no origin body is ever parsed".

**Increment 2 should reconsider**, because the moment there is more than one remote slug, the
difference between "your credential is wrong" and "that tool is not on the list" stops being
academic.

---

## Decisions carried in — not relitigated

Settled elsewhere, recorded so nobody re-opens them while reading this:

- **Binding β** (site-minted shared secret) over binding α (HMAC-signed requests). An HMAC only
  helps if the token leaks and the signing key does not, and both would live in the same Worker
  environment.
- **The origin principal is synthetic and request-scoped.** No WP user exists.
- **The secret is a `wp-config.php` constant, not an option**, because an option is readable by
  anything that reaches the database.
- **F1's brokered counter is a Durable Object keyed by token subject** (owner, 2026-08-12), because
  KV is eventually consistent and the `ratelimit` binding is colo-local.

---

## Architecture

Five files touched, one new. Nothing exceeds ~120 lines.

| File | Change |
| --- | --- |
| **`src/bridge.mjs`** *(new)* | The entire origin channel: destination pinning, timeout fetch, Bearer, subject header, status→class mapping |
| `src/mcp.mjs` | `ANALYTICS_TOOL` definition; the `tools/call` branch; `dispatch` becomes `async` |
| `src/edge-state.mjs` | `checkBridgeRate()` — the second counter |
| `src/config.mjs` | `bridgeOriginUrl`, `bridgeRatePerMin`, `bridgeSecretBound` |
| `src/status.mjs` | `bridge_secret_bound`, `bridge_origin`, `increment: 1` |

### 1. The tool

```
sn_remote_analytics_summary
  inputSchema: { type: "object",
                 properties: { range: { type: ["string","integer"] },
                               class: { type: "string" } },
                 additionalProperties: false }
```

**The edge pins the shape; the origin owns the values.** No value enums, and no `outputSchema`.

That split is the whole reasoning, and it is not fussiness. If the Worker declared
`enum: [7,14,30,90,365,"all"]` and the origin later widened its accepted set, the capability
would silently narrow with **no error anywhere** — the edge would refuse a value the origin
supports, and nothing in either repo would go red. Cross-repo schema skew lands at install, and
there is no shared CI that could catch it. If the Worker is *looser* than the origin, a bad value
gets a clean 400 from the component that owns the answer. Loose-toward-the-authority is the safe
direction.

`additionalProperties: false` and the two-key set **are** enforced at the edge, because the key
set cannot drift: adding a key is Increment 2 work that requires a Worker deploy regardless.

`outputSchema` is omitted for the same reason, more sharply. The ability's output schema already
exists **twice** — in `inc/abilities-analytics.php` and duplicated into
`inc/abilities-remote-analytics.php:77-91`, held in step by a parity test. A third copy in
another repo, in another language, with no cross-repo parity test possible, is a contract that
can only rot. `structuredContent` without a declared `outputSchema` is legal and drift-free.

Allowed values live in the tool **description**, where drift is a documentation bug rather than a
silently narrowed capability.

`tools/list` returns exactly **two** tools. The count is pinned.

### 2. The tool is advertised unconditionally

Even when `SN_BRIDGE_TOKEN` is unbound, `sn_remote_analytics_summary` appears in `tools/list`
and fails at call time.

A conditionally-present tool is a trap here: MCP connector schema snapshots go stale, and a tool
that appears and disappears produces `-32602` refusals against a cached list — a failure mode
already observed in this estate and one that a reconnect, not a code fix, resolves. A tool that
is always advertised and answers honestly when it cannot work is the more debuggable of the two.

### 3. `SN_BRIDGE_TOKEN` does NOT join `REQUIRED_KEYS`

`readConfig()` turns any missing required key into a 503 at the door for **every** path
(`src/index.mjs:44-56`). Adding the bridge secret there would mean a failed `wrangler secret put`
takes down `sn_remote_ping` and the entire transport with it — converting a bridge
misconfiguration into a total outage.

The secret's absence fails **only the bridge tool**. It surfaces as `bridge_secret_bound: false`
on the status endpoint.

### 4. The destination is pinned in code, not configuration

`bridge.mjs` holds `ORIGIN_HOST = "juanlentino.com"` as a module constant and refuses any
configured URL whose hostname is not exactly that, or whose scheme is not `https`.

This is the control I would least want to lose. **The Worker sends a Bearer token to whatever URL
the variable names.** A typo or a bad edit in `wrangler.jsonc` would deliver the origin secret to
an attacker-controlled host, and nothing else in the request path would notice. Configuration is
deployed; code is reviewed. A secret-bearing destination belongs on the reviewed side of that
line.

It is deliberately inconsistent with `MCP_RESOURCE_URI`, which *is* a var. That one names where
this Worker *is*; this one names where a secret *goes*.

### 5. Call path, in order

```
tools/call sn_remote_analytics_summary
  1. secret bound?            no  → origin_unavailable    (no budget spent)
  2. checkBridgeRate(sub)     no  → rate_limited          (store unreachable ⇒ DENY)
  3. fetch origin — 10s abort, Bearer, X-SN-Bridge-Subject
  4. map status → class
```

The rate check sits **before** the fetch. Protecting a shared host from the call is the entire
purpose of the second counter; a counter consulted after the origin has already been hit would
measure damage rather than prevent it.

Step 1 spends no budget. The caller is already Access-verified and already spent the shared
per-request budget in `guard()`; charging the origin budget for a call that never reaches the
origin would let a misconfiguration look like abuse.

### 6. Naming the brokered session — threat model §8.3 precondition 5

`guard()` already returns `{ sub, email }` from the verified Access JWT (`src/guard.mjs:90`).
The origin **cannot** name the Claude session — it only ever sees the Worker — so the Worker
logs it.

One structured line per `tools/call`:

```
{ evt: "bridge_call", sub, tool, outcome, origin_status, ms }
```

Never the token. Never response data. `range` and `class` are logged — they are not sensitive and
they are what makes a log line diagnostic.

The subject is **also** forwarded to the origin as a non-authoritative `X-SN-Bridge-Subject`
header, and the origin must never authenticate on it: that header is attacker-supplied on any
request reaching the origin outside the Worker.

**It is sanitized before it becomes a header.** Printable ASCII only (`0x20–0x7E`), capped at 200
characters, omitted entirely if the result is empty. An unsanitized `sub` containing CR or LF is
header injection into a request the Worker makes **with a Bearer attached** — the one request in
this system where injecting a header is worth an attacker's time. `access.mjs:135-137` accepts
either `sub` or `common_name` as the subject, and neither is shape-validated there, so this is
the sanitation point.

### 7. F1 — differentiating the counts

**F1 is already closed for this path, and it closed for free.** Every `POST /mcp` traverses
`guard()`, which calls `checkRate(env, who.subject, config.ratePerMin)` against the fail-closed
`EdgeState` Durable Object (`src/guard.mjs:73`). That path is already mutation-verified: porting
the origin read door's `null → 0 → allow` inversion into `checkRate` reds the fail-closed pin. The
analytics `tools/call` traverses the same guard `sn_remote_ping` does, so it inherits the control
without a line of new code.

What it does **not** inherit is a distinction. A cheap literal ping and an origin round trip on a
shared host cost the same against one budget. So this increment adds a **second, stricter
counter**, spent only by calls that actually reach the origin:

| Counter | Key | Limit | Spent by |
| --- | --- | --- | --- |
| Shared | `rate:<sub>` | `RATE_LIMIT_PER_MIN` (30) | Every `/mcp` request, in `guard()` |
| Brokered | `rate:bridge:<sub>` | `BRIDGE_RATE_LIMIT_PER_MIN` (10) | Only `sn_remote_analytics_summary`, at the call site |

**Why the brokered counter lives at the tool-call site and not in `guard()`.** `guard()` runs
before the JSON-RPC body is parsed, so at that point the Worker does not yet know whether the
request is a brokered call, a `tools/list`, or a notification. Metering there is necessarily
method-blind. The second increment therefore happens where the method is known and immediately
before the fetch it governs.

A brokered call spends **both** budgets, which is correct: it *is* an MCP request and it is *also*
an origin hit. Cheap pings cannot exhaust the origin's budget, and a brokered flood is stopped by
whichever counter is stricter.

Both keys live in the same global Durable Object (`idFromName("global")`), so there is no second
store to reason about, no second consistency model, and no collision — `rate:<sub>` and
`rate:bridge:<sub>` are distinct storage keys. `checkBridgeRate()` reuses `callEdge()` unchanged,
which means it inherits the existing "any failure is a deny" catch rather than reimplementing it.

`BRIDGE_RATE_LIMIT_PER_MIN` is clamped the way `ratePerMin` is (1–120): a cap of 0 would read as
an outage, and an unclamped cap would not be a cap.

---

## Data flow

```
Claude (phone)
  │  MCP over Streamable HTTP, Access-issued opaque token
  ▼
Cloudflare Access  ── resolves the token, forwards Cf-Access-Jwt-Assertion
  │
  ▼
Worker  guard()          → verify JWT signature, aud, iss  → { sub, email }
        checkRate        → rate:<sub>            fail closed
        dispatch         → tools/call
        checkBridgeRate  → rate:bridge:<sub>     fail closed
        bridge.mjs       → POST origin, Bearer + X-SN-Bridge-Subject, 10s
  │
  ▼
WordPress  registration gate → hash_equals → slug allowlist → request-scoped
           capability → wp_get_ability()->execute() → { ok, data }
```

Anthropic's cloud is a hop that sees tool results (R-3D-d). Unchanged by this increment, and
named again because the payload stops being a literal here for the first time.

---

## Error handling

The Worker's caller is **already Access-verified** — JWT signature checked against the team's
keys, `aud` bound, `iss` bound. The origin's opacity exists to defeat *anonymous internet probes*.
Mirroring it toward a named, verified subject protects nobody and costs the owner every
diagnosis. So the Worker is deliberately more informative than the origin, without undoing
anything the origin bought.

| Origin | Class | What Claude is told |
| --- | --- | --- |
| 200 `{ok:true,data}` | — | `structuredContent: data`, `isError: false` |
| 404 | `door_closed_or_credential_or_tool` | The remote door is off, **or** the bridge credential does not match, **or** this tool is not on the origin's remote list — **named as ambiguous, all three** — check the wp-admin MCP status panel |
| 401 | `credential_rejected` | The bridge credential was rejected. *(Unreachable against the fixed origin; retained so the mapping is correct against either version.)* |
| 400 | `bad_request` | The origin rejected the request body — **this indicates a Worker bug**, since the Worker composes the body itself |
| 5xx, timeout, network error, unparseable body, secret unbound, bad config | `origin_unavailable` | The origin did not answer |
| Brokered budget exhausted, or the counter store is unreachable | `rate_limited` | Too many analytics calls this minute; carries `retryAfter` |

Every refusal is `isError: true` with
`structuredContent: { ok: false, error: <class>, message: <string> }`.

**The origin's error body is never echoed.** Only `data` from a 200 crosses back. That is a pinned
property, not a convention — an echoed error body would put origin internals into the Anthropic
hop and couple the Worker to WordPress error text.

### Three things this table is careful about

**404 is the expected steady state, not an incident.** `sn_mcp_remote_enabled` defaults off, so a
correctly-installed, never-configured system returns 404 forever. The Worker therefore logs it at
**info**, not error, and no alerting hangs off it.

But it is still `isError: true`. Those are different questions: the MCP error flag tells the
*model* it received no data, which is what stops it fabricating analytics; the log level tells the
*operator* whether to care. Collapsing them — returning `isError: false` with an "unavailable"
payload — risks a model treating the refusal as a reading.

**The 404 message names all three possibilities and resolves none.** After #641 and #642 the Worker
cannot distinguish "door off" from "our secret is wrong" from "slug not allowlisted", and a
message that picked one would be a guess presented as a diagnosis. It points instead at the
authenticated surface that can tell *some* of them apart:
`inc/admin-forms/mcp-connect-status.php` renders
`constant_killed | option_off | secret_missing | bridge_ready`. That is the runbook's design —
diagnose in wp-admin, because the endpoint is deliberately mute.

**A 400 is a bug report about this Worker.** The Worker composes `{slug, args}` itself, so a
malformed body cannot originate with the user. The message should say so, because the natural
reading — "I passed a bad argument" — sends the owner looking in the wrong place. A bad *argument
value* comes back as a `WP_Error` from the ability with its own status, not as a 400.

### The rotation blind spot

Naming a hole the oracle fix opened on this side of the wire, because nothing else in the estate
records it.

`SN_BRIDGE_TOKEN` exists in two places that must agree: a `wp-config.php` constant and a Worker
secret. **Nothing can observe that they disagree.** After #641 and #642, a mismatch answers 404 —
identical to a dark door. Meanwhile:

- wp-admin reports `bridge_ready`, because the origin constant *is* defined;
- the Worker reports `bridge_secret_bound: true`, because its secret *is* bound;
- every call returns `door_closed_or_credential_or_tool`.

Both health surfaces are green and the door is shut. This is the "healthy readout that cannot
move" shape — each side truthfully reports its *own* half, and the property that actually matters
is the *agreement*, which is unobservable by construction.

It is not fixable at the endpoint: any response that separated "wrong secret" from "door off"
would rebuild exactly the oracle #641 and #642 closed. So the mitigations are procedural, and they
belong in the runbook rather than in code:

1. **Rotation is a two-step with an unavoidable dark window.** Whatever order it is done in, the
   door 404s between the two edits. Expect it; do not diagnose it.
2. **"Did I just rotate?" is the first question** when the door goes dark with both panels green.
3. The Worker's `bridge_call` log lines make a post-rotation 404 storm visible in Workers Logs,
   which is the closest thing to a signal that exists. That is observability, not a fix.

A future increment could close this properly with a dedicated authenticated health call — the
owner asks wp-admin to make a real bridge call and report the outcome, so agreement is tested
rather than assumed. That is out of scope here and recorded in
[Open questions](#open-questions-deliberately-left-open).

### Timeout and origin failure

`AbortSignal.timeout(10_000)`. On timeout, network error, or 5xx: `origin_unavailable`,
`isError: true`. **No retry.**

Cloudways is a shared host. A retry doubles load on an origin that is, by hypothesis, already
struggling, and doubles worst-case latency to ~20s — close enough to Claude's own client timeout
that the owner would see an opaque failure instead of the Worker's clean message. The read is
idempotent, so a retry would be *safe*; it would not be *kind*. The caller can simply ask again.

---

## Status endpoint

`GET /_sn/remote-mcp/status` gains:

```json
{ "increment": 1,
  "config": { "bridge_secret_bound": true, "bridge_origin": "https://juanlentino.com/..." } }
```

`bridge_secret_bound` is a **presence boolean**; no secret value is ever echoed, matching the
file's existing posture (`src/status.mjs:9-11`).

**This is disclosed on an unauthenticated endpoint, deliberately.** It tells an anonymous prober
that the Worker half is armed. That is accepted for the same reason `configured`,
`edge_state_bound` and `killed` are already there: without it, a failed `wrangler secret put` is
invisible from outside, and the tool would return `origin_unavailable` forever with no way to
separate a bad secret from a down origin. The house rule is that the readout moves when the thing
it describes breaks, and a readout that omits the newest failure mode does not.

Moving the status route behind Access was considered and rejected: `src/index.mjs:32-39` already
records why a health endpoint that goes dark with the thing it monitors tells you nothing on the
day it matters.

---

## Testing

The Worker suite runs in the real `workerd` runtime via vitest. Increment 0 shipped 63 tests with
two mutation-verified. This increment matches that standard: the security-relevant controls are
mutation-verified rather than trusted green.

**Assertion groups**

- **`tools/list` advertises exactly two tools**, named, with the analytics tool's `inputSchema`
  shape pinned — two keys, `additionalProperties: false`, no value enums.
- **The tool is advertised when the secret is unbound**, and calling it then returns
  `origin_unavailable` rather than a protocol error.
- **`sn_remote_ping` still works with `SN_BRIDGE_TOKEN` absent** — the transport does not depend
  on the bridge.
- **Status mapping matrix:** 200/404/401/400/500/timeout/unparseable each produce their named
  class, and none carries the origin's error body. The 401 row is pinned even though the fixed
  origin never sends it — the pin is what makes the mapping correct against both origin versions,
  and a mapping branch with no test is a branch nobody has checked.
- **The 404 message names all three conditions** — door, credential, tool — and commits to none.
- **The Bearer is sent**, and the destination is exactly the configured origin URL.
- **A non-`juanlentino.com` origin URL is refused** before any fetch is attempted.
- **`X-SN-Bridge-Subject` is present and sanitized**; a `sub` containing CRLF does not produce a
  second header.
- **Budget separation:** a brokered call spends `rate:bridge:<sub>`; a `sn_remote_ping` call does
  not.
- **Fail-closed:** with the DO binding throwing (`BROKEN_EDGE_ENV`), the brokered call is denied.
- **`dispatch` remains stateless** — no `Mcp-Session-Id` on any path.

**Mutations — each must red a named pin**

| # | Mutation | Must red |
| --- | --- | --- |
| 1 | `checkBridgeRate` catch → `{ allowed: true }` | the fail-closed brokered pin |
| 2 | Delete the `ORIGIN_HOST` check | the destination-pinning pin |
| 3 | Drop the `Authorization` header from the origin fetch | the "Bearer is sent" pin |
| 4 | 404 message commits to one cause instead of naming all three | the ambiguity pin |
| 5 | Echo the origin error body into the tool result | the "never echoes origin error body" pin |
| 6 | Forward `sub` unsanitized | the CRLF-injection pin |
| 7 | Remove the `checkBridgeRate` call entirely | the "brokered call spends the bridge budget" pin |
| 8 | Key the brokered counter with the shared key | the "ping does not spend the bridge budget" pin |
| 9 | Add `SN_BRIDGE_TOKEN` to `REQUIRED_KEYS` | the "ping survives a missing bridge secret" pin |

Commit before mutating. Verify each mutation actually landed — with `git diff` on the exact path —
before believing a "no pins fired" result: a mutation that failed to apply reports as green and
looks identical to a surviving one.

**One known ripple.** Making `dispatch` async requires `await` at three existing synchronous call
sites in `test/mcp.test.mjs` (lines 19, 44, 52 region). That is a real edit to passing tests, and
it is the honest cost of the change — the alternative, a function returning a `Response` on some
branches and a `Promise` on others, is worse than the edit.

---

## Versioning

Worker `v0.1.0 → v0.2.0`. MINOR: a new user-visible capability, no breaking change to the
existing tool or transport.

**No plugin version bump** — no plugin file changes. The plugin CHANGELOG gets an `[Unreleased]`
entry for this document, matching how the revoke runbook was recorded.

---

## Deployment — owner-only steps

Named here so the implementation is not mistaken for a live door.

1. **Generate `SN_BRIDGE_TOKEN`.** The owner generates it. This document does not, and no real
   secret appears in this repo, in the Worker repo, or in any commit.
2. `wrangler secret put SN_BRIDGE_TOKEN` on `sn-remote-mcp`.
3. Define the **same value** as a constant in `wp-config.php`.
4. Enable the wp-admin remote analytics toggle.

Until all four are done the tool answers `origin_unavailable` or
`door_closed_or_credential_or_tool`, which is the correct resting state.

**Steps 2 and 3 must carry an identical value, and nothing verifies that they do** — see
[The rotation blind spot](#the-rotation-blind-spot). The same applies to every future rotation:
there is an unavoidable window where the two disagree and the door is dark, and that window is
indistinguishable from a deliberate stop. Rotate when you can watch the Workers Logs afterwards.

---

## Explicitly out of scope

- **Increment 2** — additional remote tools. `sn_mcp_remote_slugs()` still returns one slug, and
  the origin half's [per-slug literal gap](remote-mcp-increment1-origin-half.md) becomes real at
  that point.
- **Origin-side bridge telemetry.** Left open by the bridge half on purpose; the Worker log is the
  session record for now.
- **Usage alerting.** The revoke runbook names it: nothing tells the owner the door was *used*.
  That is observability, not revocation, and it is a separate piece of work.
- **Any change to the read or rw doors**, or to the plugin at all.

---

## Dependency on the plugin repo — none outstanding

All three review findings are **merged to `main`** (#641, #642). Nothing is owed to the plugin
repo by this increment, and nothing in the Worker blocks on it.

The review's one *non-finding* observation is also closed, and by a better fix than the one it
suggested:

> The bridge dispatches with `execute()` alone, while `sn_mcp_call_tool()` calls
> `check_permissions()` **and** `execute()`. Real `WP_Ability::execute()` runs
> `check_permissions()` internally, so the three-gate design holds — but the suite's fixture
> `SNB_Ability::execute()` did not call it, so **nothing would have noticed if that stopped
> being true.**

`tests/mcp-bridge-permission-callback.php` now models core's real order, is built from the
ability's actual registration arguments (so a renamed callback reds it), and resolves
`current_user_can()` through the registered `user_has_cap` filters from a principal holding
nothing. The assertion that earns it is the counterfactual: **detach the grant filter and the
same call returns `ability_invalid_permissions`** — proving the bridge *satisfies* the callback
rather than bypassing it. That is the difference between testing that a gate is present and
testing that it is load-bearing.

The bridge-half doc's phrase "the same path the MCP door uses" remains inaccurate — the bridge
calls `execute()` alone — but the security property it describes now has a witness.

---

## Open questions deliberately left open

**Should the owner be able to test secret agreement from wp-admin?** The
[rotation blind spot](#the-rotation-blind-spot) is unobservable because each side reports only its
own half. A "test the bridge" button in the MCP status panel — authenticated, making one real
call and reporting the outcome — would test the *agreement* rather than the two halves. It is the
right shape and it is out of scope here: it is plugin-side, it needs its own rate limiting so it
cannot become a probe, and it should not be designed in the increment whose ambiguity motivated
it. Recorded so the next session finds the reasoning rather than the symptom.

**Should the brokered counter's window be longer than a minute?** A per-minute cap bounds a burst
but not a slow drain — 10/min sustained is 14,400 calls a day against a shared host. A second
window (per-hour, or per-day) would bound the drain, and the same Durable Object could hold it
with one more key. Deferred because it is additive, reversible, and the observability gap means
there is currently no measurement of what normal use looks like. Revisit once the door has run
long enough to have a baseline.
