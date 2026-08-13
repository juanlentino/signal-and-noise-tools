# Speed Brain blocked browser OAuth on the remote MCP host — CONFIRMED and worked around

**Status:** **CAUSE CONFIRMED 2026-08-13.** Speed Brain is currently **OFF ZONE-WIDE** as a
temporary workaround. The durable fix is not yet built.
**Unblocked:** R3 §3D Increment 0's OAuth path. Browser OAuth now completes and the connector
appears on the phone.
**Related:** [`../proposals/remote-mcp-transport.md`](../proposals/remote-mcp-transport.md),
[`../proposals/remote-mcp-increment1-origin-half.md`](../proposals/remote-mcp-increment1-origin-half.md)

> **If you are here because the site feels slower:** Speed Brain was turned off on the whole
> `juanlentino.com` zone on 2026-08-13 to unblock MCP OAuth. That is a **temporary** state, not
> a policy. Turning it back on requires the Worker-side guard in
> [The durable fix](#the-durable-fix-not-yet-built) first, or OAuth breaks again.

---

## The symptom

Claude Code CLI connected to `https://mcp.juanlentino.com/mcp` fine, using Cloudflare Access
service-token headers. claude.ai web and mobile **could not** — they cannot send custom headers,
so they must complete browser OAuth, and that flow failed with **"Invalid nonce."**

## The mechanism

Speed Brain was on **zone-wide** and reached the MCP hostname. Measured before the fix:

```
$ curl -s -D- -o /dev/null https://mcp.juanlentino.com/.well-known/oauth-protected-resource
HTTP/2 200
cf-version: 20-c05df9e
speculation-rules: "/cdn-cgi/speculation"
```

The same header was present on the apex, confirming a zone-level setting rather than anything
specific to this host.

Per [Cloudflare's Speed Brain docs](https://developers.cloudflare.com/speed/optimization/content/speed-brain/),
the injected configuration instructs the browser to prefetch future navigations at
`"eagerness": "conservative"`, and **those prefetch requests carry the `sec-purpose: prefetch`
request header**. An OAuth authorization URL is single-use by design — `nonce`, `state`, and
`code` are each valid for exactly one presentation. The prefetch consumed the nonce for a
request the user never saw; the real navigation then presented one already burned, and the
authorization server correctly rejected it.

**Why the CLI was unaffected:** the service-token path has no redirect chain and no nonce.
Nothing for a prefetch to consume. The CLI working was therefore never evidence that the
transport was healthy — it was evidence that one path avoided the broken layer.

**Why this took so long to find:** the Worker's code, the Access policy, and the OAuth
implementation are all correct. A zone-level feature mutated the response before any of them
were consulted. "I have eliminated every surface" was only ever true of the surfaces that
contain code.

---

## What was actually done (2026-08-13)

**Speed Brain toggled OFF zone-wide**, at Speed → Settings → Content Optimization.

### The fix originally drafted here was WRONG, and could not have worked

The first version of this runbook prescribed a **Configuration Rule** scoped to
`(http.host eq "mcp.juanlentino.com")` with the action *Speed Brain → Off*. That rule cannot
exist. **Speed Brain is not an available Configuration Rule setting on this zone.** Verified
against the complete, alphabetical settings list in the rule builder:

> Automatic HTTPS Rewrites · Browser Integrity Check · Disable RUM · Disable Zaraz ·
> Email Obfuscation · Fonts · Hotlink Protection · I'm Under Attack · Opportunistic Encryption ·
> Polish *(greyed out — Free plan)* · Request Body Buffering · Response Body Buffering ·
> Rocket Loader · SSL

The docs confirm it: Speed Brain is configurable **only** zone-wide, via the dashboard toggle,
`PATCH /zones/$ZONE_ID/settings/speed_brain`, or the Terraform
`cloudflare_zone_settings_override` resource. There is no per-host or per-path scoping.

Recorded because the plausible fix and the possible fix differed, and the difference was
invisible until someone opened the rule builder.

---

## The durable fix (NOT yet built)

Leaving Speed Brain off zone-wide costs the whole site its prefetch benefit to protect five
OAuth endpoints on one subdomain. The better control lives in the Worker.

**Refuse prefetches on the OAuth endpoints.** Cloudflare's docs state prefetch requests carry
`sec-purpose: prefetch`, and that *"prefetches that are not successful will respond with a 503
status code"* — so a 503 is the documented, expected outcome for a prefetch that does not
succeed, not an error condition invented here.

Sketch, for `juanlentino/sn-remote-mcp-worker`:

- On the authorize endpoint, the callback, and anything else that consumes a single-use value:
  if the request carries `Sec-Purpose` containing `prefetch`, return **503** without touching
  the nonce, code, or state.
- Do **not** apply it to `/mcp` itself or the `.well-known` documents — those are idempotent
  and safely prefetchable, and refusing them would slow the real flow for no gain.
- Test in the workerd runtime: a request with the header gets 503 and leaves the nonce
  unconsumed; the same request without it completes normally. Mutation-verify by deleting the
  header check and confirming the nonce-unconsumed assertion reds.

Why this is better than the zone toggle: it is version-controlled, unit-testable, costs the
apex nothing, and does not depend on a plan feature that could move. **The zone toggle is a
configuration claim; the header check is code with a test.**

Once it ships and is verified, **turn Speed Brain back on** and confirm OAuth still completes.

---

## Verification

### 1. The header is gone

```bash
curl -s -D- -o /dev/null https://mcp.juanlentino.com/.well-known/oauth-protected-resource | grep -iE "^(cf-ray|speculation-rules)"
```

**Pass:** prints a `cf-ray` line and **no** `speculation-rules` line.

The `cf-ray` half is the point. The pass condition for this check is an ABSENT header, and a
check whose success looks like silence is indistinguishable from a check that did not run. Grep
for something that must be present in the same breath, so a broken grep or a dead network
cannot masquerade as a fixed zone.

**Do not** use the apex as the control any more. Speed Brain is off zone-wide, so the apex lost
the header too — it now proves nothing.

Result 2026-08-13: both hosts returned `cf-ray`, neither returned `speculation-rules`. Same
colo (`MIA`) as the failing measurements twenty minutes earlier.

### 2. Per-colo propagation

Cloudflare settings propagate per colo. Re-run until it flips rather than inserting a fixed
sleep and calling it settled; a different `cf-ray` colo may still serve the old config.

### 3. The criterion that actually matters

The header disappearing is necessary, not sufficient. Increment 0's exit criterion is:

> connector works end-to-end on phone; disconnect + edge revoke both stop further calls within
> one access-token TTL.

| Half | Status 2026-08-13 |
| --- | --- |
| Browser OAuth completes; connector added and visible on phone | **CONFIRMED** |
| A tool call succeeds **from the phone** (e.g. `sn_remote_ping`) | not yet tested |
| Disconnect stops further calls within one access-token TTL | not yet tested |
| Edge revoke stops further calls within one access-token TTL | not yet tested |

The last row is what kill criterion 2 is about, and it is the one gating any real data path.

---

## What this unblocks, and what it does not

Increment 0's OAuth path is open. That is the gate Increment 1's **bridge half** was waiting
on — but the gate is not fully cleared until the revoke rows above are green.

The origin-side permission boundary (merged, PR #632) remains **inert**:
`sn_mcp_remote_enabled` absent means OFF, the `sn_read_remote_analytics` capability is held by
nothing, and no bridge exists. Nothing in this document activates it.

**Other zone-level features still on, and still unexamined** — named because they are the next
suspects if anything OAuth-shaped breaks again: **Early Hints** (on), **Rocket Loader**, and any
Transform Rule lacking a hostname filter. This zone has been broken once before by exactly that
last one.
