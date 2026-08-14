# Runbook: stopping the remote analytics MCP door

**For:** the moment you suspect the remote door is being used and you want it stopped.
**Audience:** you, on a phone, under pressure, without this document's context loaded.
**Closes:** R3 §3D Increment 3 (phone-first revoke UX).

---

## Do this first

**Uncheck the remote analytics toggle in wp-admin.** It works on a phone, it is instant, and it
is one click to undo.

That is the whole emergency procedure. Everything below is either a stronger stop, a slower
stop, or a thing that looks like a stop and is not.

---

## The five controls, fastest first

| # | Action | Where | What it actually stops | Cost to undo |
| --- | --- | --- | --- | --- |
| 1 | Uncheck **remote analytics door enabled** | wp-admin → MCP status panel. **Works on a phone.** | Every bridge call, immediately. The route stops being registered. | One click |
| 2 | **Revoke sessions** | Cloudflare One → Team & Resources → Users → the user → Session management | The token itself stops resolving. Measured at **~2.5 min** against a 900s TTL. | Owner re-authorizes the connector |
| 3 | Disconnect the connector | Claude settings, on the phone | Claude from calling. **Not the session** — see below. | Re-add the connector |
| 4 | Delete `SN_BRIDGE_TOKEN` | `wp-config.php`. **Needs a laptop.** | The route stops registering — the secret is gone. | Worker stays broken until `wrangler secret put` |
| 5 | Set `SN_MCP_REMOTE_DISABLED` | `wp-config.php`. **Needs a laptop.** | Everything, unconditionally. The wp-admin toggle **cannot** re-open it. | Edit wp-config again |

**Steps 1 and 2 stop different things, and in a suspected leak you want both.** The toggle stops
the *origin* from answering. The revoke stops the *token* from resolving. Do 1 first because it
needs no dashboard and takes seconds; do 2 next because it is the one that defeats a stolen
credential.

---

## Step 3 is not a revoke, and this is the trap

**Disconnecting the connector in Claude does not end the Cloudflare Access session.**

Measured 2026-08-13: after a disconnect and a re-add, the Users page showed **two** active
sessions — the new one *and* the original, still live, still expiring ~24 hours later. The
disconnect had stopped Claude from calling and left the session untouched.

So:

- **Disconnect** stops the *honest client*. It is what you do when you are tidying up.
- **Revoke** (step 2) ends the session. It is what you do when you think a token is loose.

An attacker holding a token would never press disconnect. That is why kill criterion 2 and
adversary **A5** are about step 2, not step 3.

---

## Verifying it actually stopped

Do not trust a quiet phone. Absence of calls is indistinguishable from absence of anyone trying.

**After step 1**, the bridge route stops being registered, so the endpoint answers **404** —
identical to a closed door, a missing secret, and an unknown slug. That is deliberate: an
unauthenticated caller must not be able to tell those apart. It does mean *you* cannot diagnose
from the endpoint either.

**Diagnose in the admin status panel**, which is authenticated and distinguishes the states:

| Panel state | Meaning |
| --- | --- |
| `constant_killed` | `SN_MCP_REMOTE_DISABLED` is set (step 5) |
| `option_off` | The toggle is off (step 1) — the normal stopped state |
| `secret_missing` | Toggle on, but `SN_BRIDGE_TOKEN` undefined (step 4) |
| `bridge_ready` | Both gates open — **the door is live** |

**Door dark + both panels green (R3 §3D Increment 4, added 2026-08-14):** if `bridge_ready` and
the Worker's own status both read healthy but calls still 404, this is the [rotation blind
spot](remote-mcp-increment1-client-half.md#the-rotation-blind-spot) — a mismatched `SN_BRIDGE_TOKEN`
between the `wp-config.php` constant and the Worker secret reads identical to a closed door from
both sides. Do not stop at the panel: read the observability record's refused counts first —
`wp option get sn_mcp_remote_log_v1`. A climbing `refused_auth` count while the toggle is ON names
a botched rotation; it has no benign explanation. (`refused_shut` is a different fact — calls that
arrived while the toggle was off — and reads zero here because a shut door never registers the
route.)

**After step 2**, the Cloudflare Users page should show **0 active sessions** and the Session
identities table should read *"No results... yet!"*. Reload before believing the count — the page
renders a stale number immediately after a revoke.

---

## What the door's resting state is

If you are reading this because something felt wrong, check whether anything was ever open:

- `SN_BRIDGE_TOKEN` is defined in **no** committed file. Absent means the route never registers.
- `sn_mcp_remote_enabled` is **absent by default**, and absent means **off** — this switch
  inverts the read door's semantics deliberately.
- **No role holds** `sn_read_remote_analytics`. There are zero `add_cap` / `add_role` calls in
  `inc/`.

A stock install has the door shut three ways over. If all three are still true, nothing was
reachable regardless of what you saw.

---

## What this runbook cannot give you, and why

**A session list in wp-admin.** The proposal's Increment 3 asked for one. **It is not buildable
at the origin.** WordPress never sees the sessions — Cloudflare Access issues, holds and expires
them, and the origin only ever receives a resolved assertion forwarded by the Worker. The Users
page in Cloudflare One *is* the session list; there is no version of it that can live here.

This is the same wall the audit requirement hit. Threat model §8.3 precondition 5 asks that the
brokered session be *named*, and the origin cannot name it — only the Worker, which verifies the
Access JWT and holds `{ sub, email }`, can. Recorded so nobody re-proposes the wp-admin list a
third time.

**An alert when the door is used.** Nothing currently tells you the remote door was called. The
controls above all assume you already suspect something. Closing that gap is a different piece of
work — observability, not revocation — and it is the honest answer to "how would I know to open
this runbook at all."

---

## Increment 3 status

**Closed by this document.** Kill criterion 2 — *"no phone-reachable revoke that stops traffic
within one access-token TTL without a laptop"* — was satisfied at **v11.0.0** by the wp-admin
toggle, not by this runbook. What remained was ergonomics and documentation.

The magic-link kill URL the proposal sketched was **considered and declined**: it buys roughly
fifty seconds over step 1, and costs a new always-live endpoint plus a long-lived secret sitting
in browser history and sync — the exact class of standing credential this whole increment series
was built to avoid. It fails safe, which is not the same as being free.

**Still open elsewhere:** F1's fail-closed counter (edge Durable Object), the Worker's Access-`sub`
log line, and the observability gap named above.
