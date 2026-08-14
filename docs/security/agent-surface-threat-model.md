# Agent-Surface Threat Model — signal-and-noise-tools

- **Date:** 2026-08-07
- **Plugin version at writing:** 10.62.1
- **Scope:** every surface an AI agent can read from or write through — the MCP read and
  write doors, the native abilities run-route, and the content those surfaces return.
- **Status:** living document. This makes the roadmap's AI-column "written threat model"
  row real. It is also a **precondition gate**: the roadmap rows it names in §6 should not
  ship before their entry here is resolved.
- **Prior art:** [rest-audit-2026-08-03.md](rest-audit-2026-08-03.md) (who can call in),
  [REST-HARDENING.md](../REST-HARDENING.md) (anonymous surface). This document asks the
  question those did not: **what can a hostile paragraph reach once an agent reads it?**

## TL;DR

The write path is defended in depth and the honest headline is that **no single injected
instruction can publish**: the composing-caller path is sentence-scale only, staged as a
revision, and made live only by a human acceptance that is itself owner-gated. The genuine
exposures are at the edges — surfaces where agent-influenced text reaches a human or a
machine *without* passing the acceptance gate (digest/narration prose, staged-meta rows
applied as a side effect of acceptance), and blind spots where refusals happen upstream of
telemetry. §5 lists them; none is CRITICAL; three are worth closing before the AI column
grows.

## §1 — The adversary model

The primary adversary is **not** an unauthenticated attacker (the REST audit covers that
posture). It is:

- **A1 — the hostile paragraph.** Text stored in the corpus (a post body, an excerpt, a
  tag, a candidate snippet) that an agent reads and treats as instructions. The attacker
  is whoever last influenced that text — including a previous agent run.
- **A2 — a confused or over-eager agent.** No malice; a model that misreads a scan result
  as a mandate and writes something the owner never asked for.
- **A3 — a compromised routine (non-owner) credential.** The application password used by
  scheduled/automated calls leaks.
- **A4 — a malicious or buggy MCP client/proxy.** The desktop platform's connector sits
  between the model and the site; its schema snapshot can be stale and its refusals happen
  before the site sees the call.
- **A5 — the hostile caller.** Someone who reaches the site through a brokered
  entry point without holding the laptop's credential. **Not in scope today** — no such
  entry point exists — and modelled in **§8** against roadmap 3D, which would create one.
  A1–A4 are an authorized channel misbehaving; A5 is an unauthorized party becoming
  authorized.

Assets, in the order the site cares about them: (1) the published record (post content —
the provenance ledger signs its normalized prose), (2) the provenance chain itself,
(3) site options and configuration, (4) the audit trail's completeness, (5) AI spend.

## §2 — The doors (trust boundaries)

Two MCP doors, one native route, all converging on the same per-ability gates:

| Surface | Gate stack | Kill switch |
| --- | --- | --- |
| MCP **read** door | `manage_options` floor → per-ability `permission_callback` | `sn_mcp_read_enabled` ([mcp-read-guard.php](../../inc/mcp/mcp-read-guard.php)) |
| MCP **write** door | same floor → per-ability callback → rate limit (`sn_mcp_rw_rate_limit_gate`) → rw-audit trail | `sn_mcp_rw_enabled` ([mcp-rw-guard.php](../../inc/mcp/mcp-rw-guard.php)) |
| Native run-route `wp-abilities/v1/…/run` | per-ability `permission_callback` **only** (no MCP floor, no MCP rate limit) | none of its own |

The REST audit's §0 finding still governs: the run-route means **each ability's own
callback is the binding constraint**; the MCP floor is defense-in-depth. Registered
abilities are curated allowlists — an agent cannot reach arbitrary WordPress functions,
only the ~11 consolidated tools plus legacy singles pending retirement.

## §3 — The write path: why a hostile paragraph cannot publish

`sn-apply` ([abilities-sn-apply.php](../../inc/abilities-sn-apply.php)) is the only tool
that mutates post content. Its design collapses A1/A2 attacks at four independent points:

1. **`dry_run` defaults to true.** A caller has to actively ask to write.
2. **Fingerprint binding.** Every content-touching change type requires the live
   `content_hash` (422 missing, 409 stale). An injected instruction cannot target content
   it has not just read; concurrent edits invalidate it.
3. **Composing-caller confinement.** The only path for text the agent composed itself is
   `sentence_replace` — sentence-scale, plain prose, no HTML, no whole-body path *by
   design*. Every other body type is candidate-driven: its fingerprint is minted by a
   scan/suggest pipeline the composing caller cannot forge.
4. **The acceptance gate.** `mode:"revision"` stages; only `restore_revision`
   (PUBLISH-ONLY, refused to routine credentials by the server-side identity grant) makes
   staged work live. A3 is bounded by the same mechanism: a leaked routine credential can
   stage noise but cannot publish, cannot restore, cannot run the publish-only side-effect
   types (`og_card`, `anchor_sweep`, `roadmap_board`).

**Batched edits (`change.payload.edits`, v10.66.0) weaken none of the four.** Every edit in
a batch is independently validated, located and fingerprint-checked against the same
original content — a per-edit fingerprint for the drift family, the one whole-post
`content_hash` for `sentence_replace` — and any edit that fails refuses the entire batch
with zero writes. What it does change is blast radius per call: one authorized call can
now rewrite up to 50 prose spans rather than one. That is bounded deliberately
(`SNT_SN_APPLY_BATCH_EDITS_MAX`) and is a smaller surface than the thing it replaces, which
was 50 separate writes, each individually reviewable in theory and never reviewed in
practice. The markup-rewriting types (`link_insert`, `link_reshape`, `unlink`) are excluded
from batching, so tag structure stays reachable only one edit at a time.

Provenance is a backstop, not a gate: the ledger signs normalized prose, so any change
that survives to publish is signed and versioned — silent modification is the one thing
the whole system is built to make loud. Batching sharpens that backstop rather than
blunting it: one logical edit now produces one signed version, so the record describes what
was actually authored instead of interleaving half-converted intermediate states nobody
intended to publish.

Non-post write surfaces hold the same line: `roadmap_board` is wholesale-replace behind
its own fingerprint plus a banned-internal-token sweep (injected copy that would leak an
option name or endpoint path is refused at the door — the write-gate mirror of the public
page's leak-sweep test); `create_draft`/`delete_draft` are revision-only and trash-only.

## §4 — The read path: exfiltration and injection inward

Read tools return corpus content, site facts, telemetry, and candidate lists. Two risks:

- **Exfiltration (low).** The corpus is public by design; the read door's `manage_options`
  floor plus the audit-log's own `manage_options` gate keep the only sensitive read (the
  audit trail) owner-scoped. The leak-sweep pattern (§3) keeps internal names out of
  agent-visible planning copy.
- **Injection inward (the real one).** Every read result is untrusted input *to the
  agent*. The site cannot fix this — it is the model harness's problem — but the site's
  job is to keep the blast radius of a successfully injected agent inside §3's gates.
  It does. The residual exposures are the surfaces that bypass §3, listed next.

## §5 — Residual risks (ranked)

| # | Exposure | Severity | Disposition |
| --- | --- | --- | --- |
| R1 | **Staged-meta rows apply as a side effect of acceptance.** `restore_revision` applies queued surface meta (meta_description, og_card_title, seo_title, focus_keyword) for the same post_id. An owner accepting a *body* revision may co-publish meta they never reviewed. | ~~MEDIUM~~ **CLOSED v10.64.0** | The dry-run diff now carries `staged_meta_pending` — every queued row acceptance would co-publish, enumerated read-only — so the review sees the whole PR before deciding. The pre-index blind spot (rows staged v10.40.0–v10.41.2) is inherited and documented. `apply_staged_meta` keeps its `true` default: the stranding argument in [sn-apply-restore-revision.php](../../inc/sn-apply-restore-revision.php) stands, and the review gap it created is what this closes. |
| R2 | **Narrated prose is an unreviewed agent→human channel.** Digest, narration, and insights prose is AI-generated (Sonnet) from data influenced by content and read by the owner with authority. A hostile paragraph that steers a narration steers the owner. | MEDIUM → **MITIGATED v10.64.0** | `snt_ai_untrusted_display()` ([ai-markdown-strip.php](../../inc/ai-markdown-strip.php)) now normalizes all narration prose at its parse/store boundaries: HTML tags removed (an emitted link must never reach a surface that linkifies — the MCP narration abilities serve chat clients that do), control characters removed, zero-width and bidi-override characters removed (the RLO display-spoof). Residual, accepted: the *semantic* channel — prose that is plain text yet still misleading — cannot be closed mechanically; the owner reads narration as data, not instructions, and the Operations morning brief must re-examine this row before shipping. |
| R3 | **Proxy-side refusals are invisible.** A stale connector schema refuses calls with -32602 before the site sees them — invisible to rw-audit, scan telemetry, *and* the retirement baseline. A4 in its benign form; also a telemetry-integrity gap for the retirement decision. | LOW-MED | Known ([mcp-consolidation FINDINGS](../mcp-consolidation/FINDINGS.md)); treat absence-of-calls evidence as one-sided when retiring tools. |
| R4 | **Stranded alt_text staged rows.** Queued under attachment ids, structurally unreachable by `restore_revision`; no application path exists, so they accumulate as dead state. | LOW | Integrity/hygiene, not exploitable; add an application or expiry path when alt-text work resumes (Tier 2). |
| R5 | **No rate limit on the native run-route.** O(n²)/AI-billing scans are rate-limited only behind the MCP-rw door; the run-route relies on `manage_options` alone. Carried from the REST audit. | LOW | Extend `snt_ability_rate_gate` ([abilities-rate-gate.php](../../inc/abilities-rate-gate.php)) to billing-relevant abilities on the run-route path. |
| R6 | **`anchor_sweep` dispatches live HTTP.** Publish-only and worker-scoped, but it is the one change type whose side effect leaves the site. | LOW | Accepted; target is the site's own provenance Worker, not caller-controlled. |

## §6 — Preconditions this document places on future roadmap rows

- **Richer edit primitives (considering):** must not create a second composing-caller
  path that escapes §3's sentence-scale confinement; R1 closes first.
- **Scheduled read-only agent runs (considering):** run under a routine credential
  (revision-only by grant); their outputs are R2-class prose and inherit its rule.
- **In-page verification tool surface (R5, the verification quartet):** exposes calls to
  *anonymous* agents — covered by **§9** (written 2026-08-14, before the arc opened).
  Build sessions gate on §9.5's preconditions P-51…P-56; the sharpest is P-51 — the
  origin publishes inputs and never asserts a verdict.
- **Read door from web/phone (R3 3D, planned):** covered by **§8**, which is model
  only and recommends fixing two current-build defects (F1, F2) before any broker.
- **Native desktop agents migration (planned/parked):** the same fences (§2–§3) must be
  demonstrably enforced under the new runner's identity model before the channel moves.

## §7 — Maintenance

Update this document when: a new change type lands in `sn-apply`; a new door or runner
carries agent traffic; any R-item closes (record the closing version); or a roadmap row
in §6 starts. The row's promise is a *written* threat model — stale is broken.

## §8 — The read door beyond the laptop (roadmap 3D)

**Status: model only. No code exists, and none should until the preconditions below
are met.** Written 2026-08-11 when R3's other three gates closed, because the row's
own prep says the model comes first.

### §8.1 — The row, and what it actually changes

The row: *an authorized entry point at the edge that brokers the sign-in and holds the
secret, so the same allowlist, kill switch and audit trail hold from any device.* Read
only — the write door stays deliberately attended.

Today the read door's population is **whoever holds an application password on one
laptop**, which is the owner and nobody else. The row's whole point is that it should
not have to be. That replaces the population with **whoever completes an OAuth flow**.

This is not a wider version of the existing model. It is a **different adversary**:

- **A5 — the hostile caller.** Someone who reaches the edge broker without the laptop:
  a stolen or phished session, a flaw in the broker's own sign-in, a token that outlives
  its owner's intent, or the broker itself compromised. A1–A4 all presuppose an
  *authorized* channel misbehaving. A5 is an *unauthorized party becoming authorized*.

A5's target is asset (1) in a form the published record does not cover: the read door
serves **scheduled and draft bodies** — writing the owner has not chosen to publish —
plus the operational picture.

### §8.2 — Three findings, verified against the code today

These are properties of the current build, checked while writing this section. All three
are harmless while the population is one laptop, and all three become load-bearing the
moment it is not.

**F1 — The read door had no rate limit. BOUNDED, see §8.7 — but fail-open, so
not yet closed.** The write door's gate stack ends in
`sn_mcp_rw_rate_limit_gate()`; the read door's is `kill switch → sn_mcp_permission()`
and nothing else. `mcp-tools.php` applies the limiter only when the door is `RW`. A
door reachable from one laptop does not need one. A door reachable from the internet
without one is an **exfiltration channel with no ceiling** — the corpus can be drained
at whatever rate the edge allows, and the audit trail records reads it cannot slow.

**F2 — The read kill switch guarded ONE route, not the read path. CLOSED, see §8.6.**
`sn_mcp_read_permission()` is referenced in exactly one place:
[mcp-endpoint.php](../../inc/mcp/mcp-endpoint.php)'s read route. The native
`wp-abilities/v1/…/run` route does not consult it — §2's table already says the run
route carries "no MCP floor, no MCP rate limit" and no kill switch of its own, and the
REST audit's §0 finding says each ability's own `permission_callback` is the binding
constraint.

So an owner-identity caller reaches every read ability through the run route **with
`sn_mcp_read_enabled` set to off**. Today the only such caller is the owner. Under this
row, the broker becomes one — and the kill switch becomes a lock on one of two doors
while reading as though it closed the building. **This is the sharpest precondition in
the row and it is not what the prep doc anticipated:** the prep asked whether the kill
switch can *reach the edge*; the prior question is whether it reaches the *site's own
second route*.

**F3 — The edge would hold a credential, which is a new secret at a new location.**
The site's existing posture keeps exactly one MCP endpoint ([mcp-doors-ground-truth]);
a broker that stores a credential creates a second place worth attacking, one the
owner does not administer and cannot rotate from wp-admin.

### §8.3 — Preconditions before any code

1. **Close F2 first, and independently of this row. — DONE 2026-08-11, §8.6.** The read kill switch must gate
   the read path, not one route on it. That is a correctness fix the current build
   wants regardless of whether 3D ever ships, and it should not arrive bundled with a
   new trust boundary where its absence would be load-bearing.
2. **Give the read door a rate limit — DONE 2026-08-11, §8.7 — and then make it
   fail CLOSED**, which it does not yet. The ceiling exists; the boundary does not.
3. **Decide what the edge holds**, in writing, before building it. A broker that holds
   no long-lived secret — exchanging a short-lived token per session — is a materially
   different object from one that stores an application password.
4. **The kill switch must be observable at the edge**, and its failure mode stated: if
   the edge cannot read `sn_mcp_read_enabled`, does it fail open or closed? The site's
   existing switches **fail open on absence** by deliberate design ("an untouched switch
   means the owner never turned it off"). That default is right for a local option and
   **wrong for a remote read** — an edge that cannot reach the site must not conclude
   the door is open.
5. **Audit the caller, not just the call.** The rw door's audit trail records what was
   done; with A5 in scope the read path needs to record *which brokered session* did it,
   or a leaked session is indistinguishable from the owner in the record.

### §8.4 — Residual risks this row would introduce

- **R-3D-a — Session longevity.** An OAuth session that outlives the owner's intent is
  A5 with no attacker required. Needs an expiry the owner can state and revoke.
- **R-3D-b — The broker's own supply chain.** A worker's dependencies run with the
  credential's reach; the site's dependency-provenance gate (Operations, planned) exists
  for exactly this and is not yet built.
- **R-3D-c — Read volume as a signal.** Even rate-limited, a brokered read path makes
  drafts reachable from anywhere. The mitigation is scope, not just throttling: the
  broker should reach a **named subset** of read abilities, not the read door entire.

  **Partially addressed 2026-08-14** by `inc/mcp/mcp-remote-observability.php`: per-day
  counters by outcome, a denormalised last-used timestamp, and a capped ring, surfaced
  beside the toggle in the MCP status panel. Volume is now *recorded* at the origin; it
  is not yet *alerted on*, and the row stays open until it is. **Alerting is owned by
  the Worker side** (coordinated 2026-08-14): an anomaly worth alerting on is per-`sub`,
  which only Workers Logs can see — the origin's record is the display layer and the
  cross-check (a Worker `outcome` that disagrees with the origin's counter is the first
  observable symptom of a `SN_BRIDGE_TOKEN` mismatch). §8.4's "audit the caller" is
  untouched and cannot be satisfied at the origin — see
  `docs/proposals/remote-mcp-increment4-observability.md` §7.

### §8.6 — F2, closed (2026-08-11)

`rest_pre_dispatch` now carries `sn_mcp_read_guard_run_route()`, so the read kill
switch refuses **read-allowlisted abilities on the native run route** with the same
code and status the MCP door returns: one switch, one verdict, whichever route the
caller arrived on.

Scope is deliberately narrow, and the narrowness is the point. The guard claims only
slugs on the **read** allowlist. The two doors' guards are isolated by design, and the
allowlists are **disjoint** — 38 read, 36 write, zero overlap, checked. A read kill
that also killed writes would be a worse bug than the one it replaced; the test
asserts that negative directly, and mutating the guard to include the write allowlist
reds it.

Coverage is asserted over the **whole allowlist, not a sample**: all 38 read abilities
refuse, all 36 write abilities do not. `SN_MCP_READ_DISABLED` still wins over the
option, and fail-open-on-absence is unchanged — an untouched switch still means the
owner never turned it off.

**F1 (no rate limit on the read door) remains open**, and remains a precondition.

### §8.7 — F1, bounded but not closed (2026-08-11)

The read path now carries a ceiling: **120 calls per minute per identity**, four
times the write door's cap because reads are cheap and bursty. It applies to
**both** routes — the MCP read door and the native run route for a
read-allowlisted ability — on the same reasoning as §8.6: gate the path, not one
route on it. A refusal is **429**, deliberately not 403; the kill switch runs at
an earlier priority so a disabled door still answers "closed" rather than "slow
down".

The primitives **duplicate** mcp-rw-guard.php's rather than calling them. This
file's header states the doors' guards stay isolated, and sharing a limiter would
couple them at exactly the layer the read/write split exists to keep apart. A
test asserts the read guard still contains no reference to the rw limiter.

**This is a ceiling, not a boundary, and the distinction is the whole point.**
It is **fail-open**: an unavailable backing store yields a null count, which
reads as zero and allows — identical to the write door, and correct for a
throttle that must not harden into an outage. Against a runaway loop that is
sufficient. Against **A5**, a caller who can induce store unavailability gets an
unbounded read path back.

**So F1 is bounded, not closed.** Before any broker exists, the ceiling must fail
**closed** on the brokered path specifically — which is a different decision from
changing the local default, and should be made where the broker is designed
rather than retrofitted here.

### §8.8 — THE DECISION (2026-08-11): do not build the broker

§8.5 recommended not building it *next*. Asked to settle the row, the answer is
stronger than that: **do not build it at all on the current evidence**, and stop
carrying it as `planned`.

**1. The row's unique value is narrower than its sentence.** "Reach the read
door from the web and the phone" reads like *see my site from anywhere*. But
wp-admin already exposes every fact the read door serves, from any browser,
behind the login guard. What a broker uniquely adds is **pointing an AI agent at
the site from a phone** — a convenience, not a capability the site lacks.

**2. The asset is unpublished writing.** The read door serves scheduled and
draft bodies. That is the one class of content the entire provenance stack
exists to protect *before* it is public, and it is what A5 would reach.

**3. The cost is permanent and structural.** A broker holds a credential at a
location the owner does not administer and cannot rotate from wp-admin, creating
a second place worth attacking — against the standing invariant that the native
server is the only MCP endpoint.

**4. This surface's existing invariants just proved leaky.** F2 — a kill switch
covering one of two routes — lived undetected in a shipped security surface, and
F1's absence with it. Neither was exotic; both were invariants everyone assumed
held. **Adding a new trust boundary to a surface whose existing boundaries were
wrong last week is the wrong order of operations.** Earn confidence in what is
there before extending it.

**5. It runs against the owner's own settled pattern.** The write door stays
attended by choice. Agents are disabled by choice. MCP is the channel *because*
it is the attended one. A brokered read door is the first thing here that would
trade attendance for reach.

**6. A cheaper shape captures most of the value.** `inc/security-digest.php`
already proves the pattern: deterministic, opt-in, weekly, no AI in the path,
and a zero-week heartbeat so silence never reads as health. If the real want is
*know what is happening without opening the laptop*, the answer is **push, not
pull** — extend an outbound summary. Push needs no new credential, no new
endpoint, no inbound door, and introduces no A5.

**The disposition:**

| option | verdict |
|---|---|
| Edge broker holding a credential | **No.** Cost is permanent and structural; benefit is convenience. |
| Scoped, expiring, read-only token | **Not yet.** Right shape, no named user story. A boundary without a use case is a liability with a roadmap entry. |
| Extend the outbound digest | **The actionable alternative**, if the underlying want is real. |

**What would change this** — and it is one specific thing, not a mood: a
**concrete, repeated task the owner actually wants to do from a phone that
requires an agent reading the corpus.** If that appears, build the scoped token,
not the broker: a named subset of read abilities, a short expiry, revocable from
wp-admin, and **failing closed** (§8.7's outstanding condition) on that path.

**Board implication, for the owner to action.** The row currently sits in AI
`planned`, which promises it will ship. On this decision it should not. Moving it
is deliberately NOT done here: `never` is owner-edit only, and walking a
`planned` row backwards is a statement about the roadmap rather than about the
threat model.

### §8.5 — The recommendation this section makes

**Do not build the broker next.** Preconditions 1 and 2 are real defects in the current
build — a kill switch that covers one of two routes, and a read path with no ceiling —
and they are worth fixing on their own merits, today, with the door still behind one
laptop. Ship those, then decide whether the row still wants an edge broker or whether
something narrower (a scoped, expiring, read-only token) buys most of the value at a
fraction of the boundary.

## §9 — The verification quartet: anonymous callers arrive on purpose (roadmap R5)

**Status: model only. No code exists, and none should until the preconditions in §9.5
are met.** Written 2026-08-14, before R5 opens, because §6 said this section must exist
before the in-page surface ships — and because the quartet's four rows are not one
boundary but two boundaries and two non-boundaries, and building them as one arc without
saying which is which is how a verdict endpoint gets shipped by accident.

### §9.1 — The rows, sorted by what they actually open

| Row | Opens a new boundary? |
| --- | --- |
| An in-page tool surface for verification | **YES** — machine-readable *calls* published to anonymous page-context callers |
| Provenance pointers in the machine surfaces | **YES, the same one** — pointers are the same class of published instruction-shaped data |
| A standalone verifier anyone can run | No new *caller* boundary — but it moves CODE outside the trust domain (§9.4-T6) |
| Provenance for the software itself | No boundary; it is the standalone verifier's own **completion** (§9.4-T6), threaded to Operations |

**Configuration drift is not in this section.** It is an owner-facing Operations row with
no agent surface and no anonymous caller; modelling it here would only dilute the section
that §6 actually demanded. It needs ops design (what is snapshotted, where the diff
lives), not an adversary model.

### §9.2 — The new adversary: A6, the anonymous page-context caller

§1's A5 was an unauthorized party *becoming* authorized through a broker. **A6 never
authorizes at all**: a reader's browser, a crawler, or an agent that landed on a page and
follows the verification calls the page itself advertises. A6 is not an intruder — the
quartet's entire point is to INVITE A6. That inversion is the section's organizing fact:
every prior boundary in this document exists to keep callers out; this one exists to let
strangers in and still have nothing to lose.

What A6 holds: whatever a page says, plus unlimited anonymous HTTP. What A6 must never
be able to do: make the origin compute, make the origin spend, make the origin *assert*,
or be steered by a poisoned pointer into carrying data somewhere hostile.

### §9.3 — What an anonymous caller can already reach (the posture to extend)

The site has run anonymous verification surfaces for months, and their shared shape is
the precedent the quartet must extend rather than replace:

| Surface | Origin compute per request | Verdict computed by |
| --- | --- | --- |
| `/verify` shell ([provenance-verify.php](../../inc/provenance-verify.php)) | emit static HTML + endpoint data-attributes | **the reader's browser** (assets/js/prov-verify.js: crypto client-side, `credentials:'omit'`, abort timeouts, browser-direct fetches to ledger/mempool/DID/keys) |
| `/credential/(uid)` REST route | read stored data, serialize | the caller |
| DID document, key history | static document emit | the caller |
| The public ledger (GitHub) + mempool | none — not our origin | the caller |

The invariant those four share, stated once so §9.5 can bind it: **the origin publishes
verifiable INPUTS; the caller computes the verdict.** No surface asserts "verified" on
the site's behalf — which is not an implementation accident but the standalone-verifier
row's own argument ("don't trust the site's own button") applied uniformly.

### §9.4 — The attacks, argued gate by gate

- **T1 — the verdict endpoint (the attack the site commits against itself).** A
  `/wp-json/...?verify=uid` route that fetches the ledger, checks the signature, and
  returns `{verified: true}` would be: free per-request crypto for any anonymous caller
  (CPU amplification), an origin-paid upstream fetch (SSRF-shaped, quota-spending —
  every anonymous request becomes an origin request to GitHub/mempool), and a
  self-asserted verdict that defeats the quartet's premise. **Forbidden by P-51/P-52.**
  This is the sharpest line in the section because it is the *convenient* design.
- **T2 — poisoned pointers (the confused deputy).** An agent follows the pointers the
  machine surfaces publish. A pointer rewritten to a hostile host turns every verifying
  agent into a courier — and pointer fields ride surfaces the write door can influence.
  Gates today: board/machine copy passes leak sweeps, and pointers in code are reviewed.
  Precondition P-53: pointer TARGETS are pinned in reviewed code (same-origin, or the
  fixed ledger/DID hosts) — never assembled from options or post content. The client-half
  spec's lesson ("a typo in a var would deliver the secret to another host") applies with
  the payload reversed: here a bad pointer delivers the *caller*.
- **T3 — instruction-shaped machine surfaces.** The in-page tool descriptions are text an
  agent reads and acts on — the R2/A1 hostile-paragraph channel, now deliberately
  published to strangers. P-56: call descriptions are schema-shaped data (method, URL,
  params), never imperative prose; and the "injection self-sweep" later-row becomes the
  lint for it when it lands.
- **T4 — origin resource exhaustion on the non-static remainder.** `/credential/(uid)`
  computes a read+serialize per request. Bounded and cacheable — but every NEW anonymous
  route the quartet adds must clear P-52 (static or edge-cacheable, no per-request
  crypto, no upstream fetch). The worker caveat carries from operations memory: worker
  `ratelimit` bindings are colo-local, so a ceiling there is per-colo, not global.
- **T5 — reflection.** The current shell renders no caller input. Any quartet page that
  accepts a uid/hash parameter must treat it as hostile (escape, never reflect raw,
  whitelist charset — `[A-Za-z0-9-]` as the credential route already does).
- **T6 — the tampered verifier.** A standalone verifier is code the site hands to
  strangers with the instruction "trust this instead of us" — so its *distribution* is
  the asset. A tampered copy lies with the site's own credibility. P-54: the verifier
  lives in the public ledger repo (versioned, diffable, hash-pinned in release notes),
  and its integrity story is completed by the software-provenance row — which is why
  those two rows are one thread, not siblings: **a verifier whose own provenance is
  unverifiable is the site's-own-button problem moved one layer down.**
- **T7 — enumeration, declined as a non-threat.** Verification surfaces reveal which
  posts carry records. The ledger is public by design; there is nothing to enumerate
  that is not already published. Recorded so nobody "fixes" it with an auth wall that
  would break the quartet's purpose.

### §9.5 — Preconditions (the build sessions' gate)

- **P-51 — the origin never asserts.** No endpoint returns a verdict. The origin
  publishes inputs (documents, credentials, keys, pointers); verification executes in
  the caller's runtime. The standalone verifier and the in-page tool are CONSUMERS of
  published inputs, never oracles.
- **P-52 — no new anonymous origin compute.** Any new anonymous route serves static or
  edge-cacheable content: no per-request cryptography, no origin-paid upstream fetches,
  charset-whitelisted params. If it cannot be static, it does not ship on the origin.
- **P-53 — pointers are pinned in reviewed code.** Same-origin or the fixed public
  artifact hosts; never assembled from options, meta, or content. A pointer test pins
  the allowed host set the way the board pins its banned tokens.
- **P-54 — the verifier's distribution is verifiable.** Public repo, versioned,
  hash-stated; the software-provenance row gates any "independent verification" claim
  in site copy — until it lands, the copy says what the verifier is, not what trusting
  it proves.
- **P-55 — no state, no billing (restating §6 as a hard gate).** Nothing an anonymous
  caller reaches may write anything (no logging tables keyed by caller input, no
  counters that grow unboundedly with anonymous traffic — the refusal-buffer lesson
  from the bridge applies) and nothing may touch AI spend.
- **P-56 — machine-published call descriptions are data, not prose.** Schema-shaped
  fields only; no imperative sentences in any surface an anonymous agent parses.

### §9.6 — What this section deliberately declines

No CAPTCHA or bot-gating on verification surfaces (the callers we want ARE bots); no
per-caller identity or logging (P-55 — and the door's no-identity property at the origin
is a feature here, not a gap); no rate ceiling on the static surfaces (the edge cache is
the ceiling; a ceiling below it would only throttle the honest). The quartet's rows may
be built in any order EXCEPT one: no site copy may claim independent verifiability
before P-54's gating row lands.
