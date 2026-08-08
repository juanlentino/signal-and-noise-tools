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

Provenance is a backstop, not a gate: the ledger signs normalized prose, so any change
that survives to publish is signed and versioned — silent modification is the one thing
the whole system is built to make loud.

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
| R1 | **Staged-meta rows apply as a side effect of acceptance.** `restore_revision` applies queued surface meta (meta_description, og_card_title, seo_title, focus_keyword) for the same post_id. An owner accepting a *body* revision may co-publish meta they never reviewed. | MEDIUM | Close before richer edit primitives: surface pending staged-meta in the `restore_revision` dry-run diff, or require `apply_staged_meta:true` to be explicit rather than default. |
| R2 | **Narrated prose is an unreviewed agent→human channel.** Digest, narration, and insights prose is generated from data influenced by content and read by the owner with authority. A hostile paragraph that steers a narration steers the owner. | MEDIUM | Keep narration strictly extractive/deterministic where possible; treat any generative narration as untrusted display, never as instructions. Re-examine before the Operations morning brief ships. |
| R3 | **Proxy-side refusals are invisible.** A stale connector schema refuses calls with -32602 before the site sees them — invisible to rw-audit, scan telemetry, *and* the retirement baseline. A4 in its benign form; also a telemetry-integrity gap for the retirement decision. | LOW-MED | Known ([mcp-consolidation FINDINGS](../mcp-consolidation/FINDINGS.md)); treat absence-of-calls evidence as one-sided when retiring tools. |
| R4 | **Stranded alt_text staged rows.** Queued under attachment ids, structurally unreachable by `restore_revision`; no application path exists, so they accumulate as dead state. | LOW | Integrity/hygiene, not exploitable; add an application or expiry path when alt-text work resumes (Tier 2). |
| R5 | **No rate limit on the native run-route.** O(n²)/AI-billing scans are rate-limited only behind the MCP-rw door; the run-route relies on `manage_options` alone. Carried from the REST audit. | LOW | Extend `snt_ability_rate_gate` ([abilities-rate-gate.php](../../inc/abilities-rate-gate.php)) to billing-relevant abilities on the run-route path. |
| R6 | **`anchor_sweep` dispatches live HTTP.** Publish-only and worker-scoped, but it is the one change type whose side effect leaves the site. | LOW | Accepted; target is the site's own provenance Worker, not caller-controlled. |

## §6 — Preconditions this document places on future roadmap rows

- **Richer edit primitives (considering):** must not create a second composing-caller
  path that escapes §3's sentence-scale confinement; R1 closes first.
- **Scheduled read-only agent runs (considering):** run under a routine credential
  (revision-only by grant); their outputs are R2-class prose and inherit its rule.
- **In-page verification tool surface (considering):** exposes calls to *anonymous*
  agents — a new trust boundary this document does not yet cover. Requires its own §
  before shipping: enumerate what a hostile page-context caller can reach (must be
  read-only verification, no state, no billing).
- **Native desktop agents migration (planned/parked):** the same fences (§2–§3) must be
  demonstrably enforced under the new runner's identity model before the channel moves.

## §7 — Maintenance

Update this document when: a new change type lands in `sn-apply`; a new door or runner
carries agent traffic; any R-item closes (record the closing version); or a roadmap row
in §6 starts. The row's promise is a *written* threat model — stale is broken.
