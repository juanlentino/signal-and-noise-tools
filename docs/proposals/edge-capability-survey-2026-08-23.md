# Edge capability survey — what the five workers could do next

Research, 2026-08-23. Not a plan; no design has been approved and nothing here
is scheduled. The brief was "push the boundaries with the workers without
stepping into things that are expensive or that break rules like the cookies
for analytics."

## The frame changed tonight

A scan run while writing this returned **level 5, "Agent-Native"** — the top of
the isitagentready.com ladder, with no `nextLevel` object. On 2026-08-22 the
site was at 4. So the external scoreboard that has been pulling the agent-facing
work forward is **exhausted**: it can confirm the position, but it can no longer
say what to build. Anything further is past where anyone else is measuring.

That is the actual answer to "push the boundaries": there is no longer a
boundary someone else has drawn for us to reach.

## The envelope

**Cost.** On Workers Paid the meters that matter are requests, CPU-ms, Analytics
Engine data points, and KV/DO operations. At this site's volume, edge-local
compute and cron are effectively free. The shapes that are NOT free:

- per-request subrequest fan-out (each one is a billable request),
- a single Durable Object used as a global coordination point,
- anything turning one pageview into several writes,
- metered add-ons: Browser Rendering, Workers AI, Vectorize.

**Rules.** No cookies, no cross-day identifier, no consent banner. From the ML
kernel: no reader profiling, no provenance verdicts by model, no models in the
reader's browser. The binding one for most ideas below is **no reader
profiling** — which is why every measurement idea here aggregates at write time
rather than storing something aggregatable later.

**Already shipped — do not re-propose.** Verified by grep before writing:
country (`blob4`) and edge colo (`blob13`) are already dimensions on
`sn_pageviews`; `markdown_requested` landed as `blob10` on the machine-readers
dataset today (rights-signals 1.18.0); the provenance worker's hourly
`sweep.mjs` already queues-and-replays failed WordPress confirmations; the
agent discovery surfaces (`agent-discovery`, `agent-auth-md`, `agent-a2a`,
`agent-ard`, `agent-skills`) all exist at the origin.

---

## A. Agent-facing surfaces

### A1. Verify Web Bot Auth signatures in the worker ★

The strongest item in this survey.

[Web Bot Auth](https://developers.cloudflare.com/bots/reference/bot-verification/web-bot-auth/)
lets a bot prove its identity cryptographically: Ed25519 HTTP Message
Signatures (RFC 9421) carried in `Signature`, `Signature-Input` and
`Signature-Agent`, with the agent's public keys published at a
`/.well-known/http-message-signatures-directory`. It replaces a spoofable
user-agent string with something checkable. Two IETF drafts define it —
[architecture](https://datatracker.ietf.org/doc/html/draft-meunier-web-bot-auth-architecture-02)
and [directory](https://datatracker.ietf.org/doc/html/draft-meunier-http-message-signatures-directory-03).

Cloudflare exposes the verdict as `cf.bot_management.signed_agent`, which
**requires Enterprise with Bot Management** — out of reach on this zone. But the
signature material is in the request headers and Ed25519 verification is a
WebCrypto call, so the worker can do it itself. That is precisely the move
already made for markdown negotiation: the capability is ours, implemented at
our layer, not rented from a plan tier.

- **Cost:** one cached fetch per key directory per TTL (KV or Cache API, hours),
  then sub-millisecond verification. No per-request fan-out.
- **Rules:** identifies *bots*, not readers. Nothing about a human is observed.
- **Unlocks:** the difference between an agent that *claims* to be Claude and
  one that *is*. Everything in A2, C1 and D1 depends on it.
- **Watch:** signature verification must fail OPEN — an unverifiable signature
  means "unknown agent", never a blocked request. Same discipline as the login
  guard.

### A2. Make the attribution licence machine-actionable

Today the rights position *declares* that AI training is reserved with a
conditional attribution licence offered on top. A declaration is addressed to a
reader who may or may not exist. With A1 in place, a **verified** agent could be
answered differently from an unverified one — the licence terms returned as a
response header alongside the content it applies to, keyed to a proven identity.

This is the first thing in the estate that would make the rights position
*operational* rather than declaratory. It is also where to be careful: this is a
licensing handshake, not a paywall. Cloudflare sells the paywall version
([pay per crawl](https://developers.cloudflare.com/ai-crawl-control/features/pay-per-crawl/use-pay-per-crawl-as-ai-owner/verify-ai-crawler/),
402 + `crawler-price`, Stripe-backed); building a homebrew charging mechanism is
out of scope and off-brand.

- **Cost:** headers only. Free.
- **Depends on:** A1.

### A3. Agent capability manifest at the edge — mostly done

The origin already serves the discovery surfaces. The only genuinely new part is
serving them when WordPress is down, which is direction B, not this one.

---

## B. Resilience

### B1. Stale-serve when the origin fails

Cloudways goes down; the site goes with it. A worker that falls back to a cached
copy on a 5xx or timeout turns an outage into a stale page with an honest
banner. Cheap, and `sn-rights-signals` is already on `juanlentino.com/*` — the
hook point exists.

- **Cost:** Cache API reads. Free.
- **Watch:** the memory that an overwritten layer is untestable from outside —
  a live probe only ever reads the edge, so "is the origin actually down?" needs
  its own signal, not an inference from what the edge served.

### B2. Read-only MCP answers from edge state

`sn-remote-mcp` brokers nine tools to the WordPress bridge. When the bridge is
unreachable every tool fails. Several of them (deploy status, uptime, analytics
summary) are reads of data that changes slowly and could be answered from the
`EdgeState` DO's last-known snapshot, explicitly labelled stale with its
timestamp.

- **Cost:** DO reads, already provisioned.
- **Watch:** a stale answer that does not *say* it is stale is worse than an
  error. The label is the feature.

---

## C. Cookieless measurement depth

### C1. `signed_agent` as an eleventh blob

The same additive pattern as `markdown_requested`: append a dimension recording
whether the request carried a *verified* Web Bot Auth signature. It answers a
question nothing can answer today — how much of the declared AI crawler traffic
is provably who it says it is. Appended, never inserted, since blob order is the
read query's contract.

- **Depends on:** A1. Without it this is unmeasurable.

### C2–C3. Geography, colo, agent taxonomy — already shipped

Listed only so they are not proposed again.

---

## D. Provenance at the edge

### D1. Provenance pointers in response headers

Every Note has a ledger entry with an ed25519 signature and an OpenTimestamps
proof. None of that is visible to a client that merely fetches the page. A
`Link: rel="provenance"` header — or the content hash and ledger pointer served
directly — would let any client check the record without knowing WordPress
exists.

- **Cost:** one KV read per content request, or zero if the pointer is baked
  into the cached response. Needs a per-URL map the provenance worker maintains.

### D2. A public verification endpoint at the edge

`/_sn/verify?url=…` answering, from KV alone: is this URL in the ledger, does
its signature check out, is the timestamp anchored? Served without touching
WordPress or GitHub.

This is the one with a second life outside the site. "Provenance without
institutions" is the thesis of the third paper; an endpoint that verifies a
record with no institution behind it — no login, no API key, no authority to
appeal to — is that argument as running code. It is the item here most likely to
matter to someone other than us.

- **Cost:** KV reads. Free at any plausible volume.
- **Watch:** the ML kernel's never — the endpoint reports what the ledger says,
  it does not *judge* provenance.

---

## E. Other directions considered

| Idea | Verdict |
|---|---|
| Edge search over a KV/D1 corpus mirror, synced by cron | Plausible. Serves agents and humans without the origin; overlaps B1. Needs a real look at index size before anyone plans it. |
| Move REST/abuse hardening from PHP to the edge | Plausible, and arguably more correct — a rule enforced at the origin cannot protect the origin. Overlaps existing `rest-hardening`. |
| Multi-colo synthetic checks from a cron worker → AE | Marginal. BetterStack already does this and is not the bottleneck. |
| R2 offload for media / OG cards | Cost saving, not a capability. Not what was asked for. |
| Browser Rendering for OG cards | **Cost-flagged.** Metered per session, and OG cards already generate in PHP. |
| Workers AI at the edge | **Rejected.** Duplicates the Anthropic path, adds neuron cost, and buys nothing the origin cannot already do. |
| Vectorize for edge retrieval | **Deferred.** Embeddings were adopted after measurement at the origin; moving them costs money to solve a problem no one has reported. |
| Zaraz | **Rejected on rules.** Third-party script delivery is the opposite of this estate's position. |
| Turnstile on forms | Possible, but verify the cookie behaviour of the mode chosen before going near it. |
| `cf.bot_management.*` fields | **Unavailable.** Enterprise + Bot Management. A1 is the way to get the same answer. |

---

## Shortlist

If only one thing were built: **A1**. It is cheap, it breaks no rule, it is the
same "our layer, not our plan tier" move that markdown negotiation already
proved, and three other items on this list (A2, C1, D1) are gated behind it.
It is also the only item that changes what the site can *know* rather than what
it can *say*.

If two: **A1 + D2**, because D2 is the one that serves the research track and
would exist for people who have never heard of this site.

## Verify before any of this becomes a plan

- Whether the agents actually reaching this site sign at all — measure first.
  A1 is worth building only if the traffic carries signatures; a week of
  `markdown_requested`-style counting answers it before a line of verification
  code is written.
- Which key directories are live, and their cache TTLs.
- The current `sn-rights-signals` CPU headroom, since A1 adds work to a worker
  that already runs on every request to the zone.
