# Remote analytics MCP — Increment 1, bridge half (Worker → origin)

**Date:** 2026-08-14
**Parent proposal:** [`remote-mcp-transport.md`](remote-mcp-transport.md) §"Increment 1"
**Sibling:** [`remote-mcp-increment1-origin-half.md`](remote-mcp-increment1-origin-half.md) (merged, PR #632)
**Scope:** plugin only. One Worker change is named but belongs to `sn-remote-mcp-worker`.
**Version impact:** MINOR — a new admin control is user-visible. Not the v11.0.0 MAJOR; that
remains the increment requiring owner setup action.

---

## Why this can be built now

Increment 0's exit criterion was met on 2026-08-13, all four rows:

| Row | Evidence |
| --- | --- |
| Browser OAuth completes | connector added, visible on phone |
| A phone tool call succeeds | `sn_remote_ping` ×3 over 100 min, `ok: true` |
| Disconnect stops calls | immediate |
| **Edge revoke stops calls** | revoke `23:57:39Z` → refused by `00:00Z`, ~2.5 min ≪ 900s TTL |

Kill criterion 2 — *"no phone-reachable revoke that stops traffic within one access-token TTL
without a laptop"* — is satisfied on the **timing** guarantee. It is **not** satisfied on
ergonomics: that revoke was performed from a laptop dashboard. This document adds the first
control that closes part of that gap, and §"What this still does not do" says what remains.

---

## The design in one paragraph

The Worker gains an origin channel: it calls one new REST route with a shared secret held in
`wp-config.php`. The origin verifies the secret, grants the remote capability **for that request
only** via a filter it removes immediately, dispatches exactly one allowlisted ability, and
returns JSON. A new wp-admin toggle lets the owner darken the whole path from a phone. That is
the entire increment.

### Scope accounting — what is the data path, and what was added on purpose

This document was first described as "simpler," which conflated two different claims. The
*credential layer* got much simpler. The *piece count* did not, because two things were added
deliberately. Recorded plainly so a reader can tell required from chosen:

| Piece | Required for the data path? |
| --- | --- |
| Bridge route | **Yes** — something must receive the call |
| `hash_equals()` against the constant | **Yes** — something must authenticate |
| Request-scoped capability filter | **Yes** — the origin half gates on `current_user_can()` |
| wp-admin toggle | **No** — owner-requested operational reach |
| Worker logs the Access `sub` | **No** — satisfies threat-model constraint 4; one line |

What was actually deleted, relative to the first draft: hashed token records, token ids, per-token
scopes, per-token expiry, rotation-with-overlap, and a mint UI. That is the simplification. The
toggle is not a simplification and is not claimed as one.

Deleting per-token scope also removed a **second** status-code decision. The design still has one
— slug-not-on-the-list answers 404 rather than 403 (§3) — but a separate scope check would have
added a second, whose difference from the first would itself leak whether a slug exists but is
out of scope. One oracle to get right instead of two.

**The toggle was kept on this reasoning:** `sn_mcp_remote_enabled` is absent by default, so
without an admin control the door needs WP-CLI to turn **on** *and* WP-CLI to turn **off**. The
toggle is what makes the increment operable without a terminal in either direction — and the
"off" half is the one that matters at 2am from a phone.

**The route-registration gate is not a fourth piece.** The ability's own callback already
consults the kill switch through `sn_remote_analytics_allows()`. Wrapping `register_rest_route`
in one `if` is belt-and-braces on an existing check, which is why it costs a line rather than a
component.

---

## What this replaces — a design that was three layers of individually-justified scope

The first draft of this spec proposed a token *service*: hashed token records in an option array
with ids, scopes, expiries, rotation-with-overlap, per-entry revocation, and a mint UI. Each
layer was justified by the one before it. Grok's review named the flaw exactly:

> It is a multi-tenant token service for a single-caller door.

Three corrections came out of that review, and each is load-bearing:

1. **Inbound Worker→WP verification is already a house pattern**, not a new one.
   `inc/analytics-refresh-rest.php:50-68` verifies `SN_SRV_TOKEN` with `hash_equals()`. The
   earlier claim that this was novel came from checking only `SN_MR_READ_TOKEN`
   (`inc/machine-readers-api.php:68-91`), which is **outbound** — WP calling a Worker. One file
   was mistaken for the whole survey.
2. **The audit requirement cannot be satisfied at the origin, and the token store never
   satisfied it.** Threat model §8.3 precondition 5 asks *which brokered session*. With one
   owner and one Worker, a `token.id` is a constant — every call looks identical. The session
   identity exists only where the Access JWT is verified: `guard()` in the Worker returns
   `{ sub, email }`. **The origin cannot name the Claude session; only the Worker can.**
3. **A constant is stronger than an option for a secret, not weaker.** An option is readable by
   anything reaching the database — an admin-level compromise, a plugin vulnerability, a leaked
   SQL backup. `wp-config.php` is readable by no web request. This is the same reasoning that
   put `SN_MCP_READ_DISABLED` and `SN_MCP_REMOTE_DISABLED` in constants.

---

## Architecture

### 1. The secret — `SN_BRIDGE_TOKEN`

A constant in `wp-config.php`. Compared with `hash_equals()` against the `Authorization: Bearer`
value. **Absent constant = the route refuses**, unconditionally, before anything else.

No expiry on the origin secret. The *expiring session* is the Cloudflare Access assertion the
Worker already verifies (900s TTL); putting a second expiry on the origin service secret buys no
security and forces Worker secret rotation on a schedule. The proposal's Constraint 1 wording —
*"Token scoped AND expiring"* — is satisfied by the Access assertion, not by this secret, and
this document states that rather than quietly reinterpreting it.

**Rotation** is editing `wp-config.php` and running `wrangler secret put`. That is not a Worker
*code* deploy, so Constraint 2's "revocable without redeploying Anthropic" holds — Anthropic
talks only to the Worker and never learns this secret exists.

### 2. The route — `POST /signal-noise/v1/bridge`

Body: `{ "slug": "...", "args": { ... } }`.

**Registered only when the remote kill switch is on.** With `sn_mcp_remote_enabled` absent or
false, the route is never registered and the path is a **404 — not a 403**. An unregistered
route cannot be reached by a handler bug, a filter ordering mistake, or a future refactor. This
converts *"we check a flag"* into *"the code path does not exist."*

`permission_callback` returns `true` and performs **no** authentication. All verification happens
in the handler in one ordered place, so there is never a state where a partially-authenticated
request is already inside the abilities layer.

### 3. Verification order, each step failing closed

```
0. REGISTRATION GATE: switch on AND constant present → else the route does not exist (404)
1. Bearer matches (hash_equals) → else 401
2. slug ∈ sn_mcp_remote_slugs() → else 404   (never 403 — see below)
3. body shape valid             → else 400
```

**The constant check lives in registration, not the handler.** An earlier draft answered 503 when
`SN_BRIDGE_TOKEN` was undefined, to distinguish misconfiguration from a client error. That leaks:
a 503 tells an unauthenticated caller the route exists and the site intends to serve it, which is
exactly the reconnaissance a 404 denies. Folding the check into registration makes both failure
modes — switch off, secret absent — indistinguishable from the outside **and** removes a handler
branch. One condition, one outcome.

**The operational cost, and where it is paid.** A 404 that means "misconfigured" is harder to
debug than a 503 that says so. That diagnosis belongs in wp-admin, not on the endpoint:
`inc/admin-forms/mcp-connect-status.php` already renders read/rw door states as pills
(`constant_killed | option_off | inactive | bound | unresolvable`). The remote row must
distinguish **switch off** from **secret missing** there, so the owner can tell a deliberate
dark switch from a broken deploy without the endpoint ever revealing which.

**Step 3 returns 404, not 403.** A 403 confirms the slug exists and turns the endpoint into an
enumeration oracle for the remote allowlist. `sn_mcp_call_tool()` already answers unknown tools
with `-32602 Unknown tool` rather than a permission error; this mirrors that.

There is no separate scope check. **The remote slug list IS the scope** — with one secret and
one list, a per-token scope field would encode the same information twice and could drift.

### 4. The principal — synthetic and request-scoped

After verification, add a `user_has_cap` filter granting `sn_read_remote_analytics`, dispatch,
then remove the filter in a `finally`. **Nothing persists.** No user row, no password, no
application password, nothing in the users list to harden or to forget about.

The filter consults a module-scoped flag set only by this handler after step 4, so it cannot
grant the capability on any request that did not pass verification. The origin half needs **zero
changes**: `sn_remote_analytics_allows()` already calls `current_user_can()`, which returns true
because the filter says so.

Dispatch is `wp_get_ability( $slug )->execute( $args )` — the same path the MCP door uses, so
the ability's own `permission_callback` still runs. The bridge does not bypass it; it satisfies
it.

### 5. The wp-admin toggle — the phone-reachable control

A checkbox writing `sn_mcp_remote_enabled`, on the existing MCP admin surface.

**Why this and not a token UI.** The two operations have opposite urgency and opposite exposure
requirements:

| Operation | Urgency | Should be web-reachable? |
| --- | --- | --- |
| Stop the door | Immediate — from anywhere, on a phone | **Yes** |
| Rotate the secret | Rare — only after a leak | **No** |

Exposing the *switch* gives the owner an emergency stop from a phone without the secret ever
touching the database. Exposing the *secret* to gain the same convenience would trade a real
security property for one obtainable another way.

**Note the current state this corrects:** `inc/admin-forms/mcp-connect-status.php` is a
**read-only** display — zero `<form>`, zero `<input>`. No MCP kill switch has an admin control
today; read, rw and remote are all WP-CLI (`wp option update sn_mcp_*_enabled 0|1`). This adds
the first one.

**Deliberately out of scope:** toggles for the read and rw doors. They have the same gap and it
is worth a separate pass; widening this increment to cover them would put three doors' controls
behind one increment's testing.

`inc/admin-tabs-data.php` is a full-sweep contract — touching it means running the entire suite,
not the affected file's tests.

### 6. Audit — at the Worker, not the origin

The Worker logs the Access `sub` from its verified JWT on each `tools/call`. That is the analog
of the rw door's `app_pw_uuid` binding, and it is the only place the Claude session has a name.

The origin **may** receive it as a non-authoritative `X-SN-Bridge-Subject` header for
correlation. It must never be used for authentication or authorization — it is attacker-supplied
on any request that reaches the origin outside the Worker.

This is the one change in `sn-remote-mcp-worker` and it is named here so it is not forgotten.

---

## Error handling

| Condition | Response |
| --- | --- |
| Kill switch off | 404 (route not registered) |
| `SN_BRIDGE_TOKEN` undefined or empty | 404 (route not registered) |
| Bearer missing or wrong | 401 |
| Slug not on the remote list | 404 |
| Malformed body | 400 |
| Ability returns `WP_Error` | pass through with its own status |

Three distinct conditions answer 404, and that is the point: an unauthenticated caller cannot
tell a dark switch from a missing secret from an unknown slug. Diagnosis lives in the admin
status panel, which is authenticated.

No response distinguishes *which* check failed beyond these codes. In particular a wrong token
and a valid token with an unknown slug must not be separable by an unauthenticated caller.

---

## Testing

- **Verification matrix:** each of the four steps refuses in isolation while the other three pass.
- **Registration gate, both conditions.** Switch off → no bridge key in
  `rest_get_server()->get_routes()`. Constant absent → likewise. Assert the route's **absence**,
  not that a request 403s — a handler that returns 404 would pass a status assertion while
  leaving the code path reachable.
- **The admin status panel distinguishes them** even though the endpoint does not: switch-off and
  secret-missing render as different states.
- **The capability does not leak:** after a bridge dispatch, `current_user_can(
  'sn_read_remote_analytics' )` is false again. Assert the filter was removed even when the
  ability throws.
- **Unverified request cannot grant:** invoke the `user_has_cap` filter directly without the
  handler's flag set; it must not grant.
- **Slug refusal is a 404**, and an unknown slug and a bad token are indistinguishable.
- **REST flank unchanged:** the bridge principal cannot reach `get-post-content`, `sn-apply`, or
  `mcp-rw`.
- **Admin toggle round-trips** the option and respects the `SN_MCP_REMOTE_DISABLED` constant
  (constant set → the UI shows killed and the toggle cannot re-enable).
- **Mutations:** delete the `hash_equals` check; delete the `finally` that removes the filter;
  change the slug refusal from 404 to 403. Each must red a named pin.

---

## What this still does not do

- **F1 fail-closed rate limiting.** The remote path's counter is the edge Durable Object. The
  origin's ceiling remains the inherited fail-open one. **This increment does not close F1.**
- **Increment 3's phone-first revoke.** The toggle darkens the path from a phone, which is most
  of the operational need — but rotating a leaked secret still requires a laptop, and there is
  no magic-link revoke.
- **More than one remote tool.** `sn_mcp_remote_slugs()` still returns exactly one slug.
  Widening it is Increment 2, and the [per-slug literal gap](remote-mcp-increment1-origin-half.md)
  becomes real at that point.
- **Any change to the read or rw doors.** Both are untouched.

---

## Open question deliberately left open

**Does the bridge need its own telemetry row, or is the Worker's log sufficient?** The origin
records MCP calls via `sn_mcp_telemetry_record()`, but the bridge is not an MCP door. Recording
there would mix two transports in one table; not recording leaves the origin with no local trace.
Deferred because it is reversible and does not block the data path.
