# Proving the provenance thesis: gap analysis and plan

**Status**: planning only — nothing to implement yet
**Date**: 2026-08-15
**Audited against**: *Provenance Over Detection: A Cryptographic Framework for Human
Authorship Verification in Music Distribution* (Lentino, March 2026), read in full, 22pp.
**Not audited against**: *Provenance as Substrate* — not found on disk. This analysis is
therefore incomplete and must be re-run when that paper is available.

---

## The headline finding

**The notes ledger currently demonstrates the claim the paper argues is insufficient.**

The paper's central move is *Prove Creation, Not Just Existence*. It rejects C2PA and
blockchain registration not because they fail, but because they answer the wrong question:

> The standard covers what a file *is*. It does not yet cover how music was *made*.

and, of blockchain ownership registration:

> The record is created when an artist registers a finished work, not during the creation
> process itself.

The notes system signs finished `post_content` at save time, hashes it, signs it, and anchors
it to Bitcoin. That is existence-and-authorship of a finished artifact — precisely the
category the paper places in *Existing Frameworks and Their Limitations*.

As a demonstration, it currently proves the prior art rather than the thesis.

## The system already holds the missing ingredient

The paper's evidence for human authorship is **revision**:

> That is not a sequence that generative AI produces, because generative AI does not revise.
> It outputs.

Layer 1 asks for an *edit event log* — "hashed, not stored in full — count and type of edits,
not content" — plus session duration, and Layer 3 flags "edit event logs that show no revision
history" as a manipulation signal.

`sn_prov_chain` is already an edit event log. On 2026-08-15 it recorded v1 at 18:55, v2 at
19:00, v3 at 19:05 — a human revising prose across ten minutes. That is exactly the signal the
thesis says is load-bearing, and no generative process produces it.

### Consequence for the settle window shipped tonight

The settle window (commit `1e3e765`) collapses an editing pass into one signed version. For the
public record that is right — half-formed prose should not be published as though it were a
statement. But it currently **discards** the revision evidence rather than compacting it.

The paper already specifies the fix, and it is the same one: store a *hashed representation* of
the edit sequence, not its content. Applied to text, the settle window is the natural place to
compute it — it is the only component that knows a pass happened at all.

**APPROVED 2026-08-15, specified in "The `edit_log` specification" below. Not built.** Each
signed commit carries an `edit_log` summary — counts and coarse magnitudes, never content. One
public version per pass, plus the proof that the pass was a human revising. That converts the
settle window from evidence destroyer to evidence compactor, and it is Layer 1's design applied
to prose.

---

## Claim-by-claim: paper vs. shipped system

| Paper claim | Shipped | Status |
|---|---|---|
| **L1** Creator identity, cryptographically signed | Ed25519 signature via Worker, did:web | **partial** — key→person binding is the open question |
| **L1** Creation timestamp | `committed_at` + OTS Bitcoin anchor | **met**, and without an institution for time |
| **L1** Edit event log (hashed, count/type) | version chain exists; not summarized or carried in the record | **gap** — see above |
| **L1** Cumulative active session duration | not recorded | **gap** |
| **L1** Collaborator identities + contribution timestamps | single author only | **not demonstrated** |
| **L1** Tool/plugin environment | not recorded | **not applicable to prose?** open question |
| **L2** Rights and ownership data | rights-signals records in ledger | **partial** |
| **L2** AI training consent flag | `/tdm-policy/`, TDM headers, rights-signal worker | **met in substance** — the strongest existing match |
| **L2** Chain of custody for derivatives | `parent` hash chain per note | **met for self-derivation**, untested across works |
| **L3** Structural validity + unaltered since signing | `verify-records.mjs`, `verify-pages.mjs` | **met**, publicly runnable |
| **L3** Signing key resolves against an authority | did:web (DNS + TLS) | **partial** — see identity, below |
| **L3** Session metadata internally consistent | nothing to check — no session metadata exists | **gap**, follows from L1 |
| **Durability** hard binding (content hash) | `content_hash` in every record | **met** |
| **Durability** soft binding (survives stripping, registry lookup) | ledger is the registry; never tested without the origin site | **gap 4** |

### Limitations the paper already concedes — do not re-report as findings

- **Legacy catalog**: no retroactive session history is possible; a lighter-weight attestation
  credential is the stated migration path.
- **Identity verification**: conceded explicitly, with a **tiered model** — base tier is a
  consistent signing key plus an account in good standing ("pseudonymous but accountable"),
  higher tiers add professional affiliation, top tier adds government ID.
- **Adversarial resistance**: conceded — "no provenance system is manipulation-proof". The
  claim is that it *changes the economics of fraud at scale*, not that it detects perfectly.
- **Industry coordination**: conceded; adoption "follows demonstration, not consensus".

**Correction to an earlier reading of mine:** paper 1 does *not* claim identity without
institutions. It accepts "a trust list of certificate authorities" and proposes professional
associations as a practical anchor. Its bar is that an identity be *consistent over time and
not trivially duplicable* — explicitly "the goal is not to know who every artist is". did:web
substantially meets that bar. The stronger institution-free claim belongs to paper 3, which is
why sequencing gap 1 behind its publication is correct.

---

## The `edit_log` specification

Approved 2026-08-15. **Design only — explicitly not authorized for implementation.**

### What it records

A summary of the editing pass that produced this version. Counts and coarse magnitudes only.

```
"edit_log": {
  "algo":          "sn-editlog-v1",
  "saves":         3,                      // provenance-bearing saves in the pass
  "pass_bucket":   "10m",                  // coarse duration, see below
  "first_save":    "2026-08-15T18:55:12Z",
  "last_save":     "2026-08-15T19:05:41Z",
  "shape":         ["m:+", "s:-", "xs:+"]  // per-save magnitude + direction
}
```

`shape` carries one token per save after the first: a magnitude bucket and a direction —
`+` net insertion, `-` net deletion, `=` net-neutral rewrite. Magnitude buckets are logarithmic
over the normalized-prose length delta: `xs` <16 chars, `s` <128, `m` <1024, `l` <8192, `xl`
beyond. Nothing else. No content, no positions, no diffs.

### Why buckets rather than exact counts

The paper's discriminating example is orders-of-magnitude — "a session with 3,000 discrete edit
events across 14 hours from one with 12 events across 45 seconds". Coarse buckets preserve that
entirely. Exact character deltas would not add signal but would leak the shape of unpublished
intermediate prose, which the paper explicitly rules out: the framework must not "raise
legitimate privacy concerns for artists who do not want their full creative process recorded".

`pass_bucket` is rounded for the same reason — precise durations across many notes fingerprint
working habits without strengthening the claim. Suggested buckets: `<1m`, `1m`, `5m`, `10m`,
`30m`, `1h`, `2h+`.

### Where it lives in the record — and the one subtlety

`edit_log` goes in the **payload** (covered by `content_hash`, therefore signed and
tamper-evident) but **must NOT** be added to `sn_prov_bearing_fields()`.

The bearing hash exists solely to coalesce saves where nothing provenance-bearing changed. Put
`edit_log` in the bearing fields and every save produces a different bearing hash, the coalesce
never fires, and the markup-only-edits-coalesce-to-no-commit property is destroyed — the exact
behaviour the settle window was built to protect.

So: bearing fields stay `algo, author, content, note_uid, published_at, title`. The payload
gains `edit_log`.

### Accumulation

The settle window already computes the pass boundary; it is the only component that can. On
supersede, the head commit's `edit_log` is carried forward and extended with one new `shape`
token and an updated `last_save`, `saves`, `pass_bucket`. It freezes when the pass dispatches.

A note published in a single save records `saves: 1`, an empty `shape`, and `pass_bucket`
`<1m`. That is honest and must never be read as a negative signal — see below.

### Schema compatibility — a public append-only ledger

Forward-only. Records already published have no `edit_log` and stay valid.

**Absent must mean "not recorded", never zero and never invalid.** This is the same trap that
reddened CI three times this month: a record written before worker v1.10.1 omitted
`bitcoin_block` while the index wrote explicit `null`, and a verifier comparing them strictly
failed on shape alone. Any verifier reading `edit_log` must distinguish absent / empty /
present, and treat absent as unknown.

### What this newly enables — closing the Layer 3 gap

Layer 3 asks that "session metadata is internally consistent — that the production timeline,
edit volume, and export parameters form a coherent record of human production activity". Today
there is nothing to check. With `edit_log` a verifier can assert:

- `last_save >= first_save`, and both consistent with `pass_bucket`
- `saves >= 1`, and `len(shape) == saves - 1`
- version *N*'s `first_save` is at or after version *N−1*'s `last_save` — no overlapping passes
- `committed_at` falls within the pass

That is the paper's "export timestamps that precede session creation dates" check, in the text
domain.

### What it does NOT prove — stated so the doc cannot overclaim

- **Not a detector.** Paper: "The absence of a credential does not prove AI generation." The
  same holds here — a one-save note is a weak signal, not a negative one, and must never be
  surfaced as a verdict. This is consistent with the standing rule that the ML kernel issues no
  provenance verdicts.
- **It proves revision happened in the editor, not that a human composed the prose.** Someone
  could paste generated text and revise it. The paper concedes the equivalent for audio and
  rests the claim on economics: "no provenance system is manipulation-proof… What the framework
  does is change the economics of fraud at scale."
- **It is fabricable** by anyone controlling the signing path. Which is precisely why gap 2
  (opening the minting path) is sequenced first — an unauditable signer makes every field it
  emits, including this one, an assertion rather than evidence.

---

## Plan

Owner decision 2026-08-15: **do gaps 2 and 4 now; gap 1 after the third paper is public.**
`edit_log` approved as design. Planning only — no implementation authorized.

### Gap 2 — open the minting path

**Why the paper requires it.** Layer 3 asks third parties to verify credentials, and adoption
is expected to "follow demonstration, not consensus". A demonstration whose signer cannot be
inspected asks reviewers to take the credential on trust — the posture the paper rejects. The
framework is also positioned as an extension of an *open-source* specification.

**Scope.** Make `sn-provenance-worker` auditable: publish the source, or publish a
specification precise enough for a third party to reimplement and reproduce a signature from
the same inputs. Reproducibility is the real target; open source is the cheapest route to it.

**Preconditions.** History is clean — full-history scan found no key material, only test
fixtures (OTS proof hex, a public Bitcoin txid); keys enter via `wrangler secret put`.

**Deliverable.** A signing specification: canonical input bytes, signature scheme, key
identifier, ledger path derivation — such that an independent implementation produces an
identical signature.

### Gap 4 — survivability

**Why the paper requires it.** *Credential Durability* is explicit: a credential "is only
useful if it survives the distribution chain intact", with the soft binding existing so a
stripped file can still resolve to "the full credential record held in a provenance registry".

**Scope.** Demonstrate complete verification with `juanlentino.com` unavailable — ledger only.
Establishes the ledger as the registry rather than a mirror of the site.

**Known blocker.** `verify-pages.mjs` fetches the live site by design, and correctly so: it
verifies what a stranger is served. Survivability needs a *separate* verification mode that
proves record integrity, chain continuity and anchoring without any live-site fetch — not a
weakening of the existing one. `verify-records.mjs`, `verify-genesis.mjs` and
`verify-coverage.mjs --offline` are already close to this; the work is to define the offline
verdict and document what it does and does not establish.

**Deliverable.** An offline verification path plus a written statement of exactly which claims
survive the origin disappearing.

### Gap 1 — identity (deferred)

Blocked on publication of *Provenance Without Institutions: A Weighted Identity Framework for
Music Contributors*. Paper 1's tiered model is the interim position and is already largely met.

### Gap 3 — multi-contributor (needs an owner decision)

Not selected, but note that paper 1 **already scopes it**: Layer 1 lists "collaborator
identities and contribution timestamps", and *Derivative Chain Tracking* is a full section. It
is not solely paper 3 material. The notes corpus cannot demonstrate it — one author — so it
would need a different proving ground.

---

## Open questions for the owner

1. **Gap 3 or gap 4?** The selection said "2 and 4, and 1 when the third paper is public.
   Maybe we'd add 4, too" — 4 appears twice. Assumed the second was **3** (multi-contributor);
   confirm.
2. ***Provenance as Substrate*** is not on disk. Supply it, or this analysis stays partial.
3. ~~Should `edit_log` be added to the settle window?~~ **Resolved 2026-08-15: yes.** Specified
   above; not built.
4. **Where should this document live?** Currently in the plugin repo, which is public. The
   ledger repo is the system's canonical home but publishing a roadmap that names the closed
   minting path as a weakness is a disclosure decision, not a filing decision.
5. **Sequencing.** `edit_log` is a schema change to a public append-only ledger, so its first
   emission is permanent. Gap 2 opens the minting path and makes every emitted field auditable.
   Recommend gap 2 lands **before** the first `edit_log` record is written, so the field is
   evidence from its first appearance rather than an assertion retrofitted with credibility.
