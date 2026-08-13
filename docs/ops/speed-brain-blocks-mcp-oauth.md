# Speed Brain blocked browser OAuth on the remote MCP host — CONFIRMED and worked around

**Status:** **CAUSE CONFIRMED and RESOLVED 2026-08-13.** Speed Brain is **OFF ZONE-WIDE**, and
that is the settled answer, not a workaround awaiting a better one.
**Unblocked:** R3 §3D Increment 0's OAuth path. Browser OAuth completes and the connector
appears on the phone.
**Related:** [`../proposals/remote-mcp-transport.md`](../proposals/remote-mcp-transport.md),
[`../proposals/remote-mcp-increment1-origin-half.md`](../proposals/remote-mcp-increment1-origin-half.md)

> **If you are here because the site feels slower:** Speed Brain is off on the whole
> `juanlentino.com` zone, deliberately, since 2026-08-13. It is what makes remote MCP OAuth
> work. **Re-enabling it breaks the MCP connector**, and no code-side guard can prevent that —
> see [A Worker-side guard was scoped, and it CANNOT work](#a-worker-side-guard-was-scoped-and-it-cannot-work).
>
> Reversing this is cheap and legitimate — one toggle, owner decision, `Speed → Settings →
> Content Optimization`. Just know what it costs: the phone loses the connector. If the apex
> ever needs prefetch badly enough, the path is a separate zone for `mcp.`, not a re-enable.

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

## A Worker-side guard was scoped, and it CANNOT work

The obvious durable fix is to refuse prefetches rather than disable prefetching. Cloudflare's
docs state prefetch requests carry `sec-purpose: prefetch`, and that *"prefetches that are not
successful will respond with a 503 status code"* — so refusing one with a 503 is documented
behaviour, not an invention. A guard in the Worker would be version-controlled, unit-testable,
and would cost the apex nothing.

**It cannot be built, because the Worker never sees the requests that matter.**

From `sn-remote-mcp-worker/src/index.mjs:8-17`:

> AUTHORIZATION IS CLOUDFLARE ACCESS MANAGED OAUTH. Access is the authorization server: it
> issues tokens, publishes the RFC 8414 + RFC 9728 discovery documents on the team domain, runs
> the browser flow… Consequently this Worker serves NO /.well-known route and NO /authorize or
> /token endpoints.

The Worker's entire routing table is `/mcp`, `/mcp/status`, and `/_sn/remote-mcp/status`. The
nonce is issued and consumed by **Cloudflare Access**, at a layer the Worker sits *behind*. A
header check in Worker code cannot reach a request Access answers.

And that is also the confirmation of the mechanism. Access serves its endpoints **on our
hostname** — the 401 from `/mcp/status` returns
`resource_metadata: https://mcp.juanlentino.com/.well-known/cloudflare-access-protected-resource/mcp/status`
— which is exactly how a zone-level feature reached them.

**This is the second control in this document scoped to the wrong layer.** The first was a
Configuration Rule for a setting Configuration Rules do not expose; the second was Worker code
for requests the Worker never receives. Both failed the same way: the control was scoped to the
layer whose source was easiest to read. The standing lesson —
*zone config sits above Access, and Access sits above the Worker* — now has two witnesses in one
incident.

## Where that leaves the durable fix

Speed Brain has no per-hostname scoping, and the endpoints that need protecting are not ours to
guard. Three options, honestly ranked:

1. **Leave Speed Brain off zone-wide. ← CHOSEN (owner, 2026-08-13).** Costs the apex a beta
   prefetch feature on a personal site; buys a working OAuth door. Chosen on the reasoning that
   the trade is small and **reversible at any time** — one toggle, with the connector as the
   known cost.
2. **Move `mcp.` to its own Cloudflare zone**, where the setting can differ independently. Real
   isolation. Costs a zone to set up and keep configured correctly, and adds a second place
   where a wrong setting can break this door. This is the path **if the apex ever needs
   prefetch back** — not a re-enable on the shared zone.
3. Per-hostname Speed Brain from Cloudflare. Not available, not announced. Not a plan.

**Do not** re-enable Speed Brain expecting a Worker-side guard to protect the flow. That is the
specific wrong turn this section exists to prevent.

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
