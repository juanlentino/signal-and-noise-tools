# Speed Brain blocks browser OAuth on the remote MCP host

**Status:** diagnosed 2026-08-13, **not yet applied** — the fix is a Cloudflare zone change,
owner-only.
**Blocks:** R3 §3D Increment 0's exit criterion, and therefore Increment 1's bridge half.
**Related:** [`../proposals/remote-mcp-transport.md`](../proposals/remote-mcp-transport.md),
[`../proposals/remote-mcp-increment1-origin-half.md`](../proposals/remote-mcp-increment1-origin-half.md)

---

## The symptom

Claude Code CLI connects to `https://mcp.juanlentino.com/mcp` successfully, using Cloudflare
Access service-token headers. claude.ai **web and mobile cannot** — they cannot send custom
headers, so they must complete browser OAuth, and that flow fails with **"Invalid nonce."**

## The mechanism

Speed Brain is on **zone-wide**, and it reaches the MCP hostname. Measured 2026-08-13:

```
$ curl -s -D- -o /dev/null https://mcp.juanlentino.com/.well-known/oauth-protected-resource
HTTP/2 200
cf-version: 20-c05df9e
speculation-rules: "/cdn-cgi/speculation"     ← Speed Brain, on the MCP host
```

The same header is present on `https://juanlentino.com/`, confirming the setting is zone-level
rather than something specific to this hostname.

Speed Brain injects speculation rules that make the browser **prefetch** likely next
navigations. An OAuth authorization URL is single-use by design: the `nonce`, the `state`, and
the authorization `code` are each valid for exactly one presentation. When the browser
prefetches that URL, the server consumes the nonce for a request the user never saw. The real
navigation then arrives presenting a nonce that has already been burned, and the
authorization server correctly rejects it.

**Why the CLI is unaffected:** the service-token path has no redirect chain and no nonce.
There is nothing for a prefetch to consume. This is exactly why the CLI working was NOT
evidence that the transport was fine — see
[[first-working-answer-ends-the-search]] in the reasoning that led here.

**Why this took so long to find:** the Worker's code is correct, the Access policy is correct,
and the OAuth implementation is correct. A zone-level feature mutates the response before any
of them are consulted. "I have eliminated every surface" was only ever true of the surfaces
that contain code.

---

## The fix — scope it to the hostname, do NOT disable zone-wide

Disabling Speed Brain across the zone would fix OAuth and cost the rest of the site its
prefetch benefit for no reason. More importantly, this zone has already been broken once by a
Cloudflare rule written **without a hostname filter**. Scope by host, both times, in both
directions.

### Configuration Rule

**Dashboard path:** Cloudflare → the zone → **Rules → Configuration Rules → Create rule**

| Field | Value |
| --- | --- |
| Rule name | `Disable Speed Brain on remote MCP host` |
| When incoming requests match | Custom filter expression (below) |
| Then the settings are | **Speed Brain → Off** |

**Filter expression:**

```
(http.host eq "mcp.juanlentino.com")
```

That is the whole expression. Deliberately **not** path-scoped: the OAuth flow touches
`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`, the
authorize endpoint, the callback, and `/mcp` itself. A path allowlist here would silently stop
covering any endpoint added later — the same reason `paths` allowlists are avoided in this
project's CI. The host has no non-MCP content to protect, so host scope costs nothing.

### If Configuration Rules are unavailable on the plan

Fall back to a **Cache Rule** on the same expression with Speed Brain disabled, or turn Speed
Brain off zone-wide as a temporary measure and record it here as a deviation. Do not attempt to
neutralise it with a Transform Rule adding response headers — that is the shape that caused the
earlier outage.

---

## Verification

### 1. The header is gone from the MCP host

```bash
curl -s -D- -o /dev/null https://mcp.juanlentino.com/.well-known/oauth-protected-resource | grep -i "speculation-rules\|cf-version"
```

**Before the fix:** prints `speculation-rules: "/cdn-cgi/speculation"`
**After the fix:** prints nothing for `speculation-rules`.

An empty result is the pass condition — so prove the instrument can emit a positive before
trusting silence:

```bash
curl -s -D- -o /dev/null https://juanlentino.com/ | grep -i "speculation-rules"
```

This must **still print** the header. If both commands are silent, the grep or the network is
broken, not Speed Brain. A check whose pass condition is "no output" is indistinguishable from
a check that did not run.

### 2. Both hosts, side by side

```bash
for h in https://mcp.juanlentino.com/.well-known/oauth-protected-resource https://juanlentino.com/; do echo "=== $h"; curl -s -D- -o /dev/null --max-time 15 "$h" | grep -iE "^(HTTP|speculation-rules)"; done
```

Expected after the fix: the MCP host shows `HTTP/2 200` and **no** speculation line; the apex
still shows both.

### 3. Allow for per-colo propagation

Cloudflare settings propagate per colo. Do **not** treat a single immediate check as
authoritative, and do **not** insert a fixed sleep and call it settled — re-run the check until
it flips, and note that a different `cf-ray` colo may still be serving the old config. See
[[deploy-checks-race-per-colo-propagation]].

### 4. The actual criterion — the flow, not the header

The header disappearing is necessary, not sufficient. Increment 0's exit criterion is:

> connector works end-to-end on phone; disconnect + edge revoke both stop further calls within
> one access-token TTL.

So the real test is **adding the custom connector on claude.ai (web or mobile) and completing
OAuth in a browser.** If "Invalid nonce" persists after the header is gone, Speed Brain was not
the only cause and the next suspects are other zone-level features that rewrite or pre-fetch
responses — Early Hints, Rocket Loader, and any Transform Rule lacking a hostname filter.

Record the result here either way. A negative result is a finding.

---

## What this unblocks

Increment 0's exit criterion is the gate on Increment 1's bridge half. Until browser OAuth
completes, the remote analytics door is reachable only from the CLI — that is, from the laptop,
which already reaches the full read door via application password. Enabling
`sn_mcp_remote_enabled` before this is fixed would light a door with no caller that needs it.

The origin-side permission boundary is already merged and inert
(`sn_mcp_remote_enabled` absent = OFF, capability held by nothing, no bridge). Nothing about
this fix activates it.
