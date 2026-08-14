# Speed Brain, prefetch, and the remote MCP OAuth nonce — an unresolved investigation

> ## ✅ RESOLUTION-OF-SORTS 2026-08-13, ~23:40Z — A GUARD IS DEPLOYED; THE DIAGNOSIS IS NOT PROVEN
>
> **Speed Brain is ON. A WAF custom rule now blocks prefetch requests to this hostname.** Read
> that as a cheap precaution, not a fix for a proven cause — the nonce-burning diagnosis is
> *less* supported tonight than when the investigation started.
>
> **What was measured, immediately after deploying the rule** (all against
> `https://mcp.juanlentino.com/.well-known/oauth-protected-resource`, an **Access-served** path
> — the Worker serves no `/.well-known`):
>
> | Request | Status | Answered by |
> | --- | --- | --- |
> | no header | `200` | Access — normal traffic untouched |
> | `Sec-Purpose: prefetch` | `503` | **NOT the rule** — a Block returns 403 |
> | `Sec-Purpose: prefetch;prerender` | `403` | the WAF rule |
> | apex `https://juanlentino.com/` + `Sec-Purpose: prefetch` | `503` | zone-wide, **not covered by the rule** |
>
> The apex row is the load-bearing one: it proves the `503` is **Cloudflare's own zone-wide
> Speed Brain safeguard**, present before this rule existed and independent of it.
>
> **So the rule's measured contribution is narrower than the analysis predicted.** It closes the
> **compound** `prefetch;prerender` form on this hostname. The **plain** form — which is what
> Chrome actually sends — was already being stopped. The rule stays deployed because it is free,
> harmless, and closes a form the zone-wide safeguard demonstrably does not catch.
>
> **And it moves the original diagnosis further from proven, not closer.** If plain prefetches
> to Access-served paths already return `503`, they were not spending nonces on those paths.
>
> **The one path that matters is still untested:** the approve endpoint under `/cdn-cgi/access/`,
> which is what actually carries the single-use nonce. It cannot be reached from a terminal —
> only during a live browser flow. Do not generalise the `.well-known` measurement to it.

> ## ⚠️ CORRECTION 2026-08-13, ~23:05Z — THE CAUSAL CLAIM BELOW IS NOT SUPPORTED
>
> **Everything in this file about Speed Brain *causing* the OAuth failure is retracted.** The
> mechanism is real and documented; the causation was never established. Two independent
> reasons, either one sufficient:
>
> **1. The connector was already working before the change.** A `sn_remote_ping` call from the
> phone succeeded at **21:20:38Z**. My first measurement of `speculation-rules` was at
> **22:31:47Z**, and I disabled Speed Brain *after* that — so the phone was working at least
> **71 minutes before** the fix. The phone-side Claude flagged this itself and I read past it:
> *"that's since I started checking, not since it started working. It may well have been up for
> days."*
>
> **2. Pings are not evidence about OAuth in the first place.** `sn_remote_ping` runs against an
> **already-issued token**. The nonce is consumed exactly once, during authorization. Three
> pings across 100 minutes prove the token still resolves; they exercise the OAuth flow **zero
> times**. So neither the pings before the change nor the ones after say anything about whether
> the flow was broken or fixed.
>
> **What this was:** post hoc. I changed a setting, observed a success, and reported causation —
> the failure mode the house rule *"a first working answer ends the search"* exists to catch. I
> never asked the one question that would have caught it: **was it already working before I
> changed anything?**
>
> **What is still true, and it is more than "plausible":** a prior session diagnosed this
> mechanism specifically — the Access approve step is a **GET carrying a single-use nonce**, and
> Speed Brain's prefetch (`eagerness: conservative`, `href_matches: /*`) spends it on
> pointerdown, producing *"Invalid nonce"* rather than *"expired"*. That diagnosis stands. Speed
> Brain *was* on and *did* inject `speculation-rules` here (measured, both hosts, 22:31:47Z).
>
> **The reconciliation — and this is the actually useful finding.** A real past failure and a
> working connector tonight are not in conflict, because **the hazard is intermittent by
> design**. `conservative` eagerness means the browser prefetches on pointerdown over a link,
> not on every navigation, and Cloudflare's own dashboard warns that *"even when enabled, it
> might not be actively running at all times on your website."* So OAuth can succeed with Speed
> Brain on, repeatedly, and still fail the next time.
>
> That makes this **a flaky failure mode, not a deterministic one** — which changes what counts
> as evidence. A single successful authorization does not clear Speed Brain, and a single
> failure does not convict it. Neither does turning it off and watching one success, which is
> precisely the error made above.
>
> **What would settle it — harder than it first looks.** Pings cannot do it (they reuse an
> issued token), and *neither can a single connector re-add*, because an intermittent hazard
> passes single trials routinely. A `conservative`-eagerness prefetch that fires on pointerdown
> will simply not fire on many attempts.
>
> A test that actually discriminates needs **repeated fresh authorizations**, and ideally the
> failing signal rather than the passing one:
>
> 1. Speed Brain **on** (restored 2026-08-13 ~23:10Z — no evidence justified leaving it off).
> 2. Remove and re-add the connector **several times**, deliberately hovering/pointer-downing
>    the approve button before clicking, since that is what triggers a conservative prefetch.
> 3. Any *"Invalid nonce"* convicts it — one failure is significant where one success is not,
>    because the mechanism is documented and the failure is hard to produce by other means.
>
> Given the asymmetry, the pragmatic posture is: **leave Speed Brain on, and if OAuth ever fails
> with "Invalid nonce" again, turn it off immediately and treat that as confirmation.** The cost
> of being wrong is one retry of a flow the owner runs a handful of times a year.
>
> **Updated ~23:40Z:** a WAF rule now blocks prefetch to this hostname *before* Access, so the
> posture is unchanged but better defended. The rule is deployed and **not verified against a
> real prefetch** — only against synthetic `curl` headers. Verifying it properly needs
> [Cloudflare Trace](https://developers.cloudflare.com/rules/trace-request/) to confirm which
> rule fires, plus DevTools with forced **pointerdown** on the approve control, since
> `conservative` eagerness fires on pointer/touch down rather than on every navigation
> ([Chrome eagerness](https://developer.chrome.com/docs/web-platform/prerender-pages#eagerness)).
> **That check has not been run.**
>
> The asymmetry still governs: **one prefetch reaching Access convicts; one clean login clears
> nothing.** And `curl -D-` remains valid for *"is the header gone?"* and never for *"OAuth is
> fixed."*
>
> The rest of this document is left intact rather than rewritten, because the reasoning that
> produced a wrong conclusion is worth more to a future reader than a tidy record. Read it as
> evidence-gathering, not as findings.

**Status:** ~~**CAUSE CONFIRMED and RESOLVED 2026-08-13.**~~ **RETRACTED — see the correction
above.** Speed Brain is **OFF ZONE-WIDE** as an unproven precaution.
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

## The pipeline — why every control tried before this one was in the wrong place

Cloudflare's [phases list](https://developers.cloudflare.com/ruleset-engine/reference/phases-list/)
gives the order:

```
WAF custom rules → rate limiting → managed WAF → bot fight → ACCESS →
bulk redirects → request header transforms → CACHE → Snippets → Cloud Connector → origin/Workers
```

Two consequences fall straight out of it, and they explain both dead ends above:

1. **Everything I reached for sits at or below Access.** Configuration Rules, Snippets, Cloud
   Connector, Workers — all of them run *after* Access has already answered. That is the
   structural reason a Worker-side `Sec-Purpose` guard could never work, stated as ordering
   rather than as the anecdote it was recorded as earlier in this file.
2. **WAF custom rules run *before* Access**, in `http_request_firewall_custom`, and a **Block**
   is terminating — later phases never execute. That is the only surface we control that sits
   above Access.

Speed Brain's own safeguard is documented as cache-based: a prefetch carrying
`sec-purpose: prefetch` is served only from CDN cache, otherwise Cloudflare answers `503`
without forwarding
([Speed Brain](https://developers.cloudflare.com/speed/optimization/content/speed-brain/)).

**The inference that followed — and that measurement partly contradicts.** Because Access
answers before cache, the reasoning went, that safeguard cannot protect the approve GET, so the
nonce is spent. It is a clean argument from documented ordering. But an **Access-served** path
returned `503` under a plain prefetch in the table at the top of this file, which means the
safeguard *does* reach at least some Access paths. Either the ordering is more subtle than the
phases list suggests, or prefetch handling is not purely cache-phase.

Grok, which produced this pipeline analysis, flagged the step as inference rather than citation
at the time — *"docs do not say the sentence 'WAF custom rules apply to Access login/approve
HTML'"*. That caution was warranted and is why the claim is recorded here as narrowed rather
than as a finding.

## The control that is deployed

A **WAF custom rule**, created 2026-08-13, rule 4 of 5 (Free plan allows 5):

| Field | Value |
| --- | --- |
| Name | `Block prefetch on remote MCP host (protects Access OAuth nonce)` |
| Expression | `(http.host eq "mcp.juanlentino.com" and any(http.request.headers["sec-purpose"][*] contains "prefetch"))` |
| Action | **Block** (terminating — later phases, including Access, never run) |
| Order | Last |
| Status | Active |

`contains`, not `eq`: Chrome sends `prefetch` **or** `prefetch;prerender`, and an equality match
misses the compound form — which, per the measurements, is the only form this rule actually
catches.

Both conditions are ANDed and **the hostname is pinned**. A rule matching `sec-purpose` alone
would block prefetches zone-wide including the apex, which is the shape that broke this zone
once before.

**What it does not do:** it does not remove the `Speculation-Rules` header. The browser may
still attempt a prefetch. It simply never reaches Access. Speed Brain stays on for the whole
zone.

## Where that leaves the durable fix

> **Superseded by the WAF rule above.** The ranking below was written when every candidate sat
> at or below Access, and it concluded no deterministic control existed. That conclusion was
> wrong — it was a search that never looked *up* the pipeline. Kept because the alternatives and
> their verdicts remain accurate and someone will re-ask each of them.

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
| A tool call succeeds **from the phone** (`sn_remote_ping`) | **CONFIRMED** — 3 calls over 100 min, `ok: true`, advancing timestamps |
| Disconnect stops further calls within one access-token TTL | **CONFIRMED — and immediately**, not TTL-bound |
| Edge revoke stops further calls within one access-token TTL | **CONFIRMED** — revoke `23:57:39Z`, call refused by `00:00Z`, ~2.5 min ≪ 900s |

## ✅ Increment 0's exit criterion is MET (2026-08-13)

All four rows are green. The edge revoke was run from Cloudflare One → Team & Resources → Users
→ `juan.lentino@gmail.com` → **Session management → Revoke sessions**, and the evidence is
unambiguous on both sides of the boundary:

**At the edge**, active sessions went **2 → 0** and the Session identities table went from four
rows to *"No results... yet!"* — including `Bh6ppUzcANer6MmK`, the session that the client-side
disconnect had left fully alive.

**At the client**, the next `sn_remote_ping` from the phone did not error — it returned
**"SN MCP — Claude needs access to continue"** with a Connect button. That is the correct
outcome: Access refused the resolved token and Claude surfaced a re-authorization prompt rather
than a failure.

**The finding that makes the two revoke paths genuinely different.** Before the edge revoke, the
user had **2** active sessions: the original `Bh6ppUzcANer6MmK` from 02:13 PM *and* a new
`3TmiV0scc9buFEgx` from the 07:53 PM re-add. **Disconnecting the connector had not ended the
first one** — it was still live, still expiring Aug 14. So:

- **Disconnect** stops the honest client from calling. The edge session survives.
- **Edge revoke** ends the session itself, and the token stops being accepted.

Only the second is a control against adversary **A5** — a hostile caller holding a token would
never press disconnect. That distinction was written into this file as a caveat before the test
and the test confirmed it directly, which is the rare case of a prediction surviving contact.

**What this unblocks.** Kill criterion 2 — *"no phone-reachable revoke that stops traffic within
one access-token TTL without a laptop"* — is satisfied. It was the stated gate on Increment 1's
**bridge half**. That work is no longer blocked by Increment 0.

**What it does not settle.** The revoke was performed from a laptop dashboard, not from a phone.
Increment 3 ("phone-first revoke UX") remains real work: a magic link or equivalent that reaches
the same control without a laptop. The criterion's phrasing is about the *timing* guarantee,
which is met; the *ergonomics* are not.

**The disconnect result is better than the criterion asked for, and weaker than it sounds** —
and the edge-revoke test above confirmed exactly this, by finding the disconnected session still
alive.
Disconnecting the connector in Claude made the next call fail *immediately* rather than after the
900s access-token TTL. But a client-side disconnect proves that **the honest client stops
calling**. It does not prove that a **stolen token stops working** — nothing was revoked at the
edge, and an attacker holding that token would not have pressed disconnect.

Kill criterion 2 and adversary **A5** are about the hostile caller. So the row that actually
bears on them is the **edge revoke**, and it is the one still open. Do not read three green rows
as the exit criterion being met.

**How to run the remaining half:** Cloudflare One → Team & Resources → Users →
`juan.lentino@gmail.com` → **Session management → Revoke sessions**. As of 2026-08-13 that user
had exactly **1** active session (`Bh6ppUzcANer6MmK`, application *SN Remote MCP*), logged in
02:13 PM with an expiration of **Aug 14, 05:19 PM** — roughly 24 hours.

That ~24h session expiry is a second clock, distinct from the 900s access token, and which one
governs after a revoke is precisely what the test measures. Revoke, then call from the phone
immediately; if it still succeeds, retry at ~5 and ~15 minutes to find the governing clock.

**A note on what the re-add incidentally proved.** Re-adding the connector ran a genuinely fresh
authorization **with Speed Brain ON and the WAF prefetch rule live**, and it completed. That is
the first real OAuth flow under the current configuration, and it establishes that the WAF rule
does not break the flow — a live risk when it was deployed. By this document's own asymmetry it
clears nothing about the prefetch hypothesis: one success never does.

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
