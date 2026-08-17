# Text provenance related work, surveyed 2026-08-17

Working brief for the notes/SSRN pipeline. Source: ecosystem sweep run 17 August 2026.
Feeds the third paper ("Provenance Without Institutions: A Weighted Identity Framework
for Music Contributors") and any future note that needs named, shipping exemplars of
text provenance. "Two kinds of provenance" published 25 July 2026 and already claims
the authorship-versus-behavioral distinction; this brief supplies the originator-versus-
asset split with citations, which that note gestured at without named exemplars.

Not for publication as written. Chunks may be lifted into paper prose after a voice pass.

## The finding in one paragraph

The field has split into two levels that verify different things. Originator-level
provenance establishes who operates a publication; asset-level provenance binds a
signature to one specific text. Japan's national newspapers now ship the first at
scale. The second exists as a months-old spec appendix with two implementations and
almost no deployment. A small independent site doing per-article cryptographic signing
with a public ledger sits ahead of the adoption curve on the asset side, and now has
citable company on both sides.

## Thread 1: the spec is real and has independent implementers

Text embedding entered C2PA at version 2.4, appendices A.7 through A.9. Encypher
authored the text section and co-chairs the C2PA Text Task Force, so their reference
implementation is authorship rather than adoption. The datum that matters for a
related-work section is the second implementation: dualeai/c2patxt independently
implements the A.8 unstructured-text method. Two implementations of a new appendix is
thin evidence, but it is the specific kind of thin evidence that precedes standards
traction, and it is checkable.

Lineage note: Encypher's own library (encypher-ai on GitHub) predates the spec and
already carried the design that became A.8, an Ed25519 signature in a COSE_Sign1
envelope, hash-bound to the text. Useful when the paper needs to show the design did
not originate inside the standards body.

- C2PA 2.4 explainer: https://spec.c2pa.org/specifications/specifications/2.4/explainer/Explainer.html
- Independent implementation: https://github.com/dualeai/c2patxt
- Reference lineage: https://github.com/encypherai/encypher-ai and https://docs.encypherai.com/package/user-guide/c2pa-relationship/

## Thread 2: news industry practice, and the level it chose

Originator Profile (OP), the Japanese consortium, has the strongest deployment story
in text provenance anywhere: Yomiuri, Asahi, and NHK signed on, driven by a wave of
imposter clone sites, with a verification browser extension shipped July 2026. The
design point worth citing precisely: OP does not claim an article is true. It
cryptographically establishes who publishes the site. That is the provenance-over-
detection posture deployed by national newspapers, at the originator level rather
than the asset level.

The UK/Irish broadcaster effort (BBC, ITV, RTE, ITN, the "Accelerator Project") built
open-source C2PA stamping and verification tooling, so practice exists on the asset
side too. Against that, the Reuters Institute puts actual C2PA presence in published
news media under one percent. Direction set, adoption thin. Both halves of that
sentence are citable and the paper should carry both.

- Nieman Lab on OP: https://www.niemanlab.org/2026/08/japanese-publishers-are-fighting-imposter-news-sites-with-a-cryptographic-signature/
- OP overview: https://originator-profile.org/en-US/overview/
- Yomiuri/Asahi coverage: https://ppc.land/yomiuri-and-asahi-gain-cryptographic-ids-as-fake-clones-of-their-sites-spread/
- Accelerator/adoption reality: https://www.softwareseni.com/c2pa-adoption-in-2026-hardware-platforms-and-verification-reality/

## Thread 3: the academic pair, boosters and critics

Three papers, and the critical two matter more than the supportive one.

1. "The Verification Crisis: ... the Case for Reproducible Provenance"
   (arXiv 2602.02100, Feb 2026). Survey evidence that expert consensus is moving
   from detection to reproducible provenance. This is the SSRN thesis with data
   behind it, and it should be cited as convergent, not as confirmation.

2. "Verifying Provenance of Digital Media: Why the C2PA Specifications Fall Short"
   (arXiv 2604.24890, Apr 2026). First formal-methods security analysis of C2PA;
   finds the spec does not meet its stated security goals.

3. "Authenticated Contradictions from Desynchronized Provenance and Watermarking"
   (arXiv 2603.02378). Shows the provenance layer and the watermarking layer can be
   driven into contradicting each other while both verify.

The critical pair is the opening the third paper occupies: provenance as the right
substrate while the dominant institutional implementation has demonstrated gaps.
An identity framework that does not depend on those institutions is a response to
papers 2 and 3, not just to the detection literature.

## Where this site sits, stated carefully

Per-note signing here is asset-level, the rarer level in deployment. The honest
comparison: OP verifies the publisher and would treat every article on a verified
site identically; this site's ledger binds each note's prose to a key and a
timestamp individually, with public verification. Neither subsumes the other. Any
note drawing this comparison must not re-argue the authorship/behavioral distinction,
which "Two kinds of provenance" already owns; the originator/asset split is a
different axis and remains an open lane.

## Open questions the sweep did not settle

- Whether OP intends an asset-level layer later, or is committed to originator-only.
- Whether the A.8 method survives the formal-methods critique in paper 2 above,
  which analyzed the media spec; nobody has yet analyzed the text appendices.
- Adoption numbers for C2PA text specifically (the under-one-percent figure is for
  media in news; text embedding has no measured baseline at all).
