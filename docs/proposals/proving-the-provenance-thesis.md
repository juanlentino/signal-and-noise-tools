# Proving the provenance thesis: gap analysis and plan

**Status**: planning only — nothing to implement yet. Document stays local (owner decision).
**Date**: 2026-08-15
**Audited against**, both read in full:
- **P1** — *Provenance Over Detection: A Cryptographic Framework for Human Authorship
  Verification in Music Distribution* (Lentino, March 2026), 22pp.
- **P2** — *Provenance as Substrate: A Cryptographic Identifier Framework for Music Rights and
  Royalty Infrastructure* (Lentino, May 2026), 20pp, SSRN 6730343.

Owner selection 2026-08-15: **gaps 2 and 3 now**; gap 1 after the third paper is public; gap 4
retained because P2 promotes it from a nice-to-have to a core architectural claim (below).

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

## What P2 changes

### The signature is custodial, and P2 rules that out

P2's most consequential structural claim is **self-issuance**:

> There is no registrant, no agency, no allocation block… The token exists from that moment
> forward as a fact about the file.

and P1 specifies that the signature "uses the creator's private key".

The notes ledger does not do this. **`sn-provenance-worker` holds the signing key and signs on
the author's behalf**, with the key installed via `wrangler secret put`. The signature therefore
attests *"the Signal & Noise worker signed this"*, not *"Juan signed this"*. That is a custodial
signature with an issuing agency — the structure P2 defines the substrate against. The agency
happens to be run by the author, which makes it benign in practice and still wrong in kind.

This reframes gap 2. Opening the minting path makes the signer *auditable*; it does not make it
*not an intermediary*. Both papers' architecture wants the key at the point of authorship.

### D-1: Custodial signing — ACCEPTED as a deliberate deviation

Owner decision 2026-08-15. Should become a numbered ADR if this document is ever published.

**The deviation.** P2 requires self-issuance — the creator's key, no agency. In the notes
system a Worker under the author's sole control holds the key and signs on their behalf.

**Why it is accepted.**

1. **The notes system is one instantiation of the thesis, deliberately narrow**: a solo author,
   self-signing, over text. It is not the general implementation and must never be cited as
   though it were. Its scope is a feature — it isolates the authorship-and-integrity claim from
   the multi-party problems — provided the scope is stated wherever it is cited.

2. **The Worker is an instrument, not an authority.** P1's own Layer 1 puts signing *inside the
   DAW*: the creator's key, applied by software the creator runs. Nobody reads Ableton as an
   issuing agency. The Worker is architecturally the same move — automated signing infrastructure
   under sole author control. What P2 argues against is *external administrative authorities*
   (ISRC agencies, PROs, allocation blocks) whose records "can be inconsistent across
   territories, can have stale data, and can be inaccessible". None of that describes a service
   the author alone operates.

3. **It is reversible by an operation the system already supports.** `keys/key-history.json`
   models multiple key generations with **signed transitions**, per-key validity windows and an
   optional `next_key_commitment`. Moving to an author-held key is a signed key transition — not
   a schema break, not a ledger rewrite — and records signed under the Worker key stay
   verifiable against the historical key. The deviation does not compound.

**What it actually costs, stated rather than implied.**

The DAW applies the key at the moment of authorship, on the author's machine, under their
control. The Worker applies it *remotely, to whatever the site sends it*, gated by an HMAC
secret. The key is bound to the plugin's credential, not to the author's presence or intent.

Concretely: **a compromise of the site or the shared secret yields validly signed records.**
With an author-held key it would not. That is a real security property surrendered, and the
honest framing of the deviation has to say so.

**The claim the signature actually supports.**

Not *"Juan's hand signed this."* Rather: **"the author's own publishing infrastructure witnessed
this content at this time, and the record has not changed since."**

The owner's framing — the Worker *as* the attestation — is the accurate one, and public-facing
text should say it that way. An automated witness to a publication event is a weaker claim than
a hand-signature, and it is *true*, which is worth more than a stronger claim that is not.

There is a non-obvious upside. With `edit_log` (approved above), the Worker's witness statement
extends from the artifact to the **editing pass** — process evidence recorded by an observer
rather than asserted by the party with the incentive. For P1's specific claim, which is about a
human *creation event* rather than a finished file, an observed process is arguably better
evidence than a self-signature over an output. The witness's independence is bounded by author
control, which is precisely why **gap 2 matters**: opening the minting path makes the witness's
method inspectable, and an inspectable method is what makes a witness statement worth anything.

**When the deviation must end.** Whichever comes first:

- **Gap 3, step 2.** Additive co-author signatures are meaningless under one shared Worker key —
  a second party must hold their own. This is the hard boundary.
- **Any presentation of the notes system as the general implementation** rather than one narrow
  instantiation.
- **Paper 3**, whose subject is identity and key custody directly.

### Gap 4 is a core claim, not a refinement

P2 makes site-independent verification the substrate's defining advantage over the legacy stack:

> ISRC, ISWC, and ISNI all rely on a registry as the source of truth… A cryptographic identifier
> carries its truth in its construction. Verification does not require querying anything; it
> requires only computing the hash and comparing.

and: "Verification requires the public key, the file, and the token. Nothing else."

Today a full `npm run verify` cannot complete without `juanlentino.com`. `verify-pages.mjs`
fetches the live site — correctly, because it answers a *different* and worthwhile question
(what is a stranger actually served). But the **identity and integrity verdict must not depend
on the origin**, and today the two are not separated. That is the system contradicting P2's
central differentiator, which is why gap 4 stays in scope even though it was not selected.

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

### P2's substrate claims vs. shipped system

| P2 claim | Shipped | Status |
|---|---|---|
| Hash over a canonical representation | `sn_prov_canonical_json` + `normalize_v1`, pinned by parity tests | **met**, and unusually well-specified |
| Self-issuing — no agency, no registration step | Worker signs on the author's behalf | **divergence** — see above |
| Signed authorship attestation naming author(s) **and roles** | single `author` string, no roles | **gap 3** |
| Signature "can only be added to" — co-authors sign later | one signature per record, no additive path | **gap 3** |
| Dispute annotation via a separate dispute-record format | none | **gap 3** |
| Public key reference via W3C DID | `provenance-did.php`, did:web | **met** — P2 explicitly endorses DID alignment |
| Issuance timestamp from a trusted timestamp authority | OTS + Bitcoin anchor | **met, and stronger** than P2 asks |
| **Ownership pointer, mutable, separate from authorship** | absent entirely | **gap** — benign for a single-author corpus, but unimplemented |
| Signed transfer records forming an auditable ownership chain | absent | follows from the above |
| Derivation: claim block names the parent token | `parent` hash per note | **met within a note**, absent across works |
| Algorithm agility (EdDSA default, PQ available) | `algo` field exists; single scheme | **partial** — the field is declared, the agility is not |
| Verification needs only key + file + token | blocked by the live-site fetch | **gap 4** |

### Limitations the papers already concede — do not re-report as findings

- **Legacy catalog**: no retroactive session history is possible; a lighter-weight attestation
  credential is the stated migration path.
- **Identity verification**: conceded explicitly, with a **tiered model** — base tier is a
  consistent signing key plus an account in good standing ("pseudonymous but accountable"),
  higher tiers add professional affiliation, top tier adds government ID.
- **Adversarial resistance**: conceded — "no provenance system is manipulation-proof". The
  claim is that it *changes the economics of fraud at scale*, not that it detects perfectly.
- **Industry coordination**: conceded; adoption "follows demonstration, not consensus".

**P2's own open questions**, named in its *Open Implementation Questions* section and not to be
re-reported as findings: canonicalization (the likely answer is a hybrid — cryptographic hash
over the source master plus a perceptual fingerprint alongside); signature scheme selection and
algorithm agility; key management and revocation, especially "who runs the key infrastructure
for independent artists?"; ownership transfer under bankruptcy, court order and contested
estates; and legacy catalog migration. P2 states plainly that it "is not a finished design".

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

### Gap 3 — multi-contributor attribution (SELECTED)

Confirmed by the owner 2026-08-15 as the second item alongside gap 2.

**It is not paper-3 material.** P1 Layer 1 lists "collaborator identities and contribution
timestamps" and devotes a full section to *Derivative Chain Tracking*. P2 specifies the
mechanics precisely, which means this can be designed against published text rather than
against an unpublished paper:

> Authorship is permanent and cryptographically bound. The signature on a token cannot be
> replaced; it can only be added to (in the case of co-authors signing later) or annotated (in
> the case of disputes, which require a separate dispute-record format). Ownership is mutable.

So the design has four separable pieces, in dependency order:

1. **Roles in the claim block.** P2: the claim block "names the author or authors, their roles
   (composer, lyricist, performer, producer)". Today there is one `author` string. First change
   is a structured claim block — a list of parties with roles — replacing the scalar.
2. **Additive signatures.** A record must accept co-author signatures *after* issuance without
   altering the original attestation or its hash. This is the hard part: the current record has
   exactly one signature and `content_hash` covers the whole payload, so a later signature
   cannot live inside the hashed payload. It needs a sidecar signature set that references the
   record hash — append-only, each entry independently verifiable.
3. **Ownership pointer.** Mutable, separate from the immutable authorship attestation, updated
   by signed transfer records that accumulate into an auditable chain. Absent today. Benign for
   a single-author text corpus and structurally required by the substrate.
4. **Dispute records.** A distinct format that annotates rather than modifies. P2 is explicit
   that disputes must not touch the attestation.

**The proving-ground problem stands.** The notes corpus has one author, so it can demonstrate
(1) and (3) structurally but cannot exercise (2) or (4) honestly — a second real signer is
required, holding their own key. Choosing that proving ground is an owner decision and is the
first thing this gap needs; everything else is downstream of it.

**Sequencing note.** (2) is where the custodial-signing divergence bites hardest: co-authors
signing "later" is meaningless if a single worker key signs for everyone. Gap 3 step 2 is
therefore partly blocked on the key-custody question, which is gap 1's territory and deferred
to paper 3. Steps 1, 3 and 4 are not blocked.

### Gap 4 — retained, not selected

Not chosen, but P2 promotes it from durability nicety to the substrate's defining property
(see *What P2 changes*). Recommend it rides along with gap 2, since both are about making the
verification story stand on its own: an auditable signer that still cannot be verified without
the origin site proves less than either change suggests alone.

---

## Open questions for the owner

Resolved 2026-08-15: the second selected gap is **3**, not a repeat of 4. *Provenance as
Substrate* supplied and audited. `edit_log` approved as design. This document stays **local**.

Still open:

1. **A proving ground for gap 3.** Multi-contributor attribution needs a second real signer
   holding their own key. The notes corpus cannot supply one. This is the first decision gap 3
   needs and everything else in it is downstream.
2. ~~Custodial signing — argue it or change it.~~ **Resolved 2026-08-15: documented as
   deliberate deviation D-1**, with its cost stated and its end conditions named. One follow-on
   remains: the public-facing wording. `VERIFY.md` and the provenance surfaces should describe
   what the signature attests — the author's publishing infrastructure witnessing a publication
   event — rather than implying a hand-signature. That is a copy change to live public text and
   has not been made.
3. **Sequencing of `edit_log` against gap 2.** Its first emission is permanent on an append-only
   ledger. Gap 2 makes every emitted field auditable. Recommend gap 2 lands **before** the first
   `edit_log` record is written, so the field is evidence from its first appearance rather than
   an assertion retrofitted with credibility later.
4. **Whether gap 4 rides with gap 2.** An auditable signer that still cannot be verified without
   the origin site proves less than either change suggests alone.
