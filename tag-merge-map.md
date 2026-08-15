# Tag merge map — juanlentino.com notes corpus

Prepared 2026-08-15. **Planning document. Nothing here has been written to the live site.**

**To execute it: `./tag-merge-apply.sh` (dry run) then `--apply`.** That script is generated from
this file's own per-post table, so the two cannot drift; regenerate it rather than hand-editing.

There is no MCP path to reassign tags on existing posts: `suggest-tags` is read-only,
`prune-unused-tags` only deletes terms that already have zero posts, and the only place tags
appear in a write payload is `create_draft`. This is a WP admin pass, executed by hand.

Source of truth: `sn-posts` across all 42 notes, read 2026-08-15.

## Current state

| Measure | Value |
|---|---|
| Notes | 42 (33 published, 9 scheduled through 2026-09-19) |
| Distinct tags | 83 |
| Tags used exactly once | 47 (57%) |
| Surviving vocabulary | 23 |

## Terminology convention

"Provenance" stays in titles, slugs, URLs, and SEO focus keywords. "Proof of origin" appears in
prose only. **Tags follow the "provenance" side of that line** — which is why `Provenance`
survives and absorbs `Music Provenance` rather than the reverse.

---

## 1. The surviving vocabulary (23 terms)

| Term | Covers | Posts after |
|---|---|---:|
| **Provenance** | The core argument: proof of origin bound to the work, settled at creation. The primary archive. | 24 |
| **Cryptographic Signatures** | The primitive itself — keys, signing, verification, the maths. | 13 |
| **Content Authenticity** | Authenticity/authentication framing, incl. the cross-media vocabulary. | 8 |
| **C2PA** | The specific standard, as a named body of work. | 3 |
| **Standards** | Interoperability, open vs proprietary primitives, backward compatibility. | 3 |
| **Digital Identity** | Identity infrastructure: who a signer is, how identity is anchored. | 4 |
| **Artist Verification** | Verifying a specific artist or profile, as distinct from identity infrastructure. | 6 |
| **Authorship** | Who made the work, and credit for it. | 9 |
| **Independent Artists** | The long tail: creators outside institutional trust anchors. | 3 |
| **Music Metadata** | The metadata and identifier layer, incl. fingerprinting and identification codes. | 9 |
| **Music Rights** | Rights, ownership, and the legal/litigation layer. | 10 |
| **Music Royalties** | The money: distribution, disputes, statements, unmatched pools. | 7 |
| **Black Box Royalties** | The specific unmatched-royalty problem, as a recurring named concept. | 3 |
| **Music Distribution** | Distributors, DSPs, streaming platforms, the delivery pipeline. | 8 |
| **Music Industry** | Industry structure, power, incentives, and its own language. | 5 |
| **AI Detection** | Detection-after-the-fact as the rival approach. | 3 |
| **AI Music** | Generative music and AI-generated content. | 8 |
| **AI Disclosure** | Labeling and disclosure regimes. | 3 |
| **AI Training** | Training data, crawlers, TDM, opt-out mechanics. | 4 |
| **Legacy Catalog** | Back catalog and work created before signing infrastructure. | 2 |
| **Music Production** | The studio craft: DAW, engineering, production workflow. | 4 |
| **Freelance Business** | Running an independent practice. The two personal business notes. | 2 |
| **Writing** | Plain language and craft-of-writing. | 1 |

Two notes on the shape of this list:

- **`Provenance` vs `Music Provenance` was splitting the primary archive** (15 + 12). Merged, the
  main archive goes from 15 posts to 24 — the single largest gain in the sweep.
- **`Artist Verification` and `Digital Identity` are deliberately kept apart.** They look like
  synonyms but aren't: one verifies a named artist, the other is the infrastructure an identity
  claim hangs on. Several notes turn on exactly that distinction.

---

## 2. From → to, all 83 current terms

`=` means the term survives unchanged. `DELETE` means drop it; the post keeps its other tags.

| Current term | Uses | → | Becomes |
|---|---:|---|---|
| AI Detection | 3 | = | AI Detection |
| AI Disclosure | 2 | = | AI Disclosure |
| AI Labeling | 1 | → | AI Disclosure |
| AI Music | 3 | = | AI Music |
| AI Tools | 1 | → | Music Production |
| AI Training | 4 | = | AI Training |
| AI-Generated Music | 2 | → | AI Music |
| Argentina | 1 | → | Freelance Business |
| Art Market | 1 | ✗ | DELETE |
| Artificial Intelligence | 1 | → | AI Music |
| Artist Credit | 1 | → | Authorship |
| Artist Verification | 6 | = | Artist Verification |
| Audio Engineering | 1 | → | Music Production |
| Audio Fingerprinting | 1 | → | Music Metadata |
| Authorship | 5 | = | Authorship |
| Authorship Verification | 1 | → | Authorship |
| Backward Compatibility | 1 | → | Standards |
| Black Box | 1 | → | Black Box Royalties |
| Black Box Royalties | 2 | = | Black Box Royalties |
| C2PA | 3 | = | C2PA |
| Content Attribution | 2 | → | Authorship |
| Content Authentication | 1 | → | Content Authenticity |
| Content Authenticity | 3 | = | Content Authenticity |
| Content Credentials | 1 | → | Content Authenticity |
| Content Labeling | 1 | → | AI Disclosure |
| Copyright Litigation | 1 | → | Music Rights |
| Court Of Appeal | 1 | ✗ | DELETE |
| Cross-Cultural Work | 1 | → | Freelance Business |
| Cryptographic Identifiers | 1 | → | Music Metadata |
| Cryptographic Provenance | 2 | → | Provenance |
| Cryptographic Signatures | 6 | = | Cryptographic Signatures |
| Cryptographic Signing | 1 | → | Cryptographic Signatures |
| Cryptography | 3 | → | Cryptographic Signatures |
| Currency Controls | 1 | → | Freelance Business |
| Digital Audio Workstation | 2 | → | Music Production |
| Digital Authorship | 1 | → | Authorship |
| Digital Identity | 4 | = | Digital Identity |
| Digital Signatures | 6 | → | Cryptographic Signatures |
| Evidence | 1 | ✗ | DELETE |
| Falsifiability | 1 | → | Provenance |
| Freelance | 1 | → | Freelance Business |
| Freelance Business | 1 | = | Freelance Business |
| Generative AI | 2 | → | AI Music |
| Generative Music | 1 | → | AI Music |
| Human Attribution | 1 | → | Authorship |
| IFPI | 1 | ✗ | DELETE |
| Immutability | 1 | ✗ | DELETE |
| Independent Artists | 3 | = | Independent Artists |
| Insider Jargon | 1 | → | Music Industry |
| Legacy Catalog | 2 | = | Legacy Catalog |
| Memorization | 1 | ✗ | DELETE |
| Metadata | 2 | → | Music Metadata |
| Music Authentication | 2 | → | Content Authenticity |
| Music Authenticity | 1 | → | Content Authenticity |
| Music Distribution | 6 | = | Music Distribution |
| Music Identification | 1 | → | Music Metadata |
| Music Industry | 5 | = | Music Industry |
| Music Metadata | 7 | = | Music Metadata |
| Music Production | 1 | = | Music Production |
| Music Provenance | 12 | → | Provenance |
| Music Rights | 9 | = | Music Rights |
| Music Royalties | 2 | = | Music Royalties |
| Plain Language | 1 | → | Writing |
| Pricing Strategy | 1 | → | Freelance Business |
| Provenance | 15 | = | Provenance |
| Recording Studio | 1 | → | Freelance Business |
| Remote Freelance | 1 | → | Freelance Business |
| Remote Work | 1 | → | Freelance Business |
| Robots.txt | 2 | → | AI Training |
| Royalties | 2 | → | Music Royalties |
| Royalty Disputes | 2 | → | Music Royalties |
| Royalty Distribution | 1 | → | Music Royalties |
| Royalty Statements | 1 | → | Music Royalties |
| Sample Marketplace | 1 | → | Music Production |
| Scope Management | 1 | → | Freelance Business |
| Spotify | 1 | ✗ | DELETE |
| Standards | 2 | = | Standards |
| Stem Separation | 1 | → | Music Production |
| Streaming Platforms | 3 | → | Music Distribution |
| TDMRep | 1 | → | AI Training |
| Text And Data Mining | 2 | → | AI Training |
| Web Crawlers | 2 | → | AI Training |
| Writing | 1 | = | Writing |

**Totals:** 83 in → 23 survive, 53 merged away, 7 deleted.

The 7 DELETEs are proper nouns or one-off abstractions (`Art Market`, `Court Of Appeal`,
`Evidence`, `IFPI`, `Immutability`, `Memorization`, `Spotify`) where merging would have been
misleading and the post retains full topical coverage without them.

---

## 3. Per-post before → after

Sorted newest first, matching the notes index. **After** lists are final, deduplicated, and
alphabetical. Nine scheduled notes are marked `[sched]`.

| # | Post | Before | After |
|---|---|---|---|
| 2213 | A list binds nobody `[sched]` | AI Training, Authorship, Music Rights, Provenance | AI Training, Authorship, Music Rights, Provenance |
| 2184 | Nobody is paid to check `[sched]` | Cryptographic Signatures, Music Distribution, Provenance, Standards, Streaming Platforms | Cryptographic Signatures, Music Distribution, Provenance, Standards |
| 2180 | The estate cannot sign `[sched]` | Authorship, Cryptographic Signatures, Digital Signatures, Legacy Catalog, Music Rights | Authorship, Cryptographic Signatures, Legacy Catalog, Music Rights |
| 2183 | Being read is not being cited `[sched]` | AI Training, Content Attribution, Robots.txt, Text And Data Mining, Web Crawlers | AI Training, Authorship |
| 1969 | The signer keeps moving `[sched]` | Artist Verification, Content Authentication, Digital Signatures, Music Provenance | Artist Verification, Content Authenticity, Cryptographic Signatures, Provenance |
| 1986 | Provenance is the wrong half `[sched]` | Art Market, Music Authentication, Provenance | Content Authenticity, Provenance |
| 2286 | An empty field says nothing `[sched]` | AI Disclosure, Authorship, Provenance | AI Disclosure, Authorship, Provenance |
| 2071 | The rights files nobody reads `[sched]` | AI Training, Robots.txt, TDMRep, Text And Data Mining, Web Crawlers | AI Training, Music Rights ⚠️ |
| 2088 | Payment systems pay what they can name `[sched]` | Black Box Royalties, Music Metadata, Music Royalties, Royalty Distribution | Black Box Royalties, Music Metadata, Music Royalties |
| 1943 | The master never moves | Authorship, Immutability, Music Provenance, Music Rights | Authorship, Music Rights, Provenance |
| 1848 | The label comes last | AI Disclosure, Content Labeling, Music Metadata | AI Disclosure, Music Metadata |
| 1743 | Trust doesn't disappear, it relocates | Artist Verification, Cryptographic Signatures, Music Metadata, Provenance | Artist Verification, Cryptographic Signatures, Music Metadata, Provenance |
| 1716 | The pen is not the notary | C2PA, Content Authenticity, Cryptography, Digital Signatures, Provenance | C2PA, Content Authenticity, Cryptographic Signatures, Provenance |
| 2076 | Better models erase the evidence | AI Training, Copyright Litigation, Evidence, Memorization, Music Provenance | AI Training, Music Rights, Provenance |
| 1721 | Provenance signs the claim, not the truth | Content Authenticity, Cryptography, Digital Signatures, Provenance | Content Authenticity, Cryptographic Signatures, Provenance |
| 1675 | The gate is not the signature | Artist Verification, Cryptographic Signatures, Independent Artists, Music Provenance | Artist Verification, Cryptographic Signatures, Independent Artists, Provenance |
| 1661 | Two kinds of provenance | C2PA, Digital Identity, Music Rights, Provenance | C2PA, Digital Identity, Music Rights, Provenance |
| 1589 | Provenance as a CFO problem | AI Music, Music Rights, Provenance, Royalty Disputes | AI Music, Music Rights, Music Royalties, Provenance |
| 1835 | Where provenance has to live | Authorship, Content Attribution, Digital Authorship, Generative Music, Provenance | AI Music, Authorship, Provenance |
| 1593 | Why platforms wait on provenance | AI-Generated Music, Music Industry, Music Provenance, Streaming Platforms | AI Music, Music Distribution, Music Industry, Provenance |
| 1858 | The unlabeled majority | AI Labeling, AI Music, Generative AI, IFPI, Music Industry | AI Disclosure, AI Music, Music Industry |
| 1572 | How a music file gets corrected | Cryptographic Provenance, Digital Signatures, Music Metadata | Cryptographic Signatures, Music Metadata, Provenance |
| 1833 | The court found the floor | Black Box Royalties, Court Of Appeal, Music Provenance, Music Rights | Black Box Royalties, Music Rights, Provenance |
| 1591 | Open standards or no standards | Music Distribution, Music Metadata, Provenance, Standards | Music Distribution, Music Metadata, Provenance, Standards |
| 1681 | The seat was never given | Artist Verification, Music Industry, Provenance, Royalties | Artist Verification, Music Industry, Music Royalties, Provenance |
| 1746 | Start here | Content Authenticity, Cryptographic Provenance, Digital Identity, Music Rights, Provenance | Content Authenticity, Digital Identity, Music Rights, Provenance |
| 1523 | The music industry talks to itself in code | Insider Jargon, Music Industry, Plain Language, Writing | Music Industry, Writing |
| 1570 | Who vouches for the independent artist? | Authorship Verification, Cryptographic Signatures, Digital Identity, Independent Artists, Music Provenance | Authorship, Cryptographic Signatures, Digital Identity, Independent Artists, Provenance |
| 1568 | What happens to old music? | Backward Compatibility, Cryptographic Identifiers, Legacy Catalog, Music Identification, Music Metadata | Legacy Catalog, Music Metadata, Standards |
| 1581 | Signing the inputs at the source | Cryptographic Signing, Digital Audio Workstation, Music Metadata, Music Provenance, Sample Marketplace | Cryptographic Signatures, Music Metadata, Music Production, Provenance |
| 1531 | Falsifiability is the line | AI Detection, Cryptographic Signatures, Falsifiability, Music Provenance, Royalty Disputes | AI Detection, Cryptographic Signatures, Music Royalties, Provenance |
| 1566 | Fingerprints, not name tags | Audio Fingerprinting, Cryptography, Digital Identity, Music Industry, Royalties | Cryptographic Signatures, Digital Identity, Music Industry, Music Metadata, Music Royalties |
| 1587 | Detection scales the wrong way | AI Detection, AI Music, Generative AI, Provenance | AI Detection, AI Music, Provenance |
| 1518 | Five layers, one system | Digital Audio Workstation, Music Distribution, Music Provenance, Royalty Statements, Streaming Platforms | Music Distribution, Music Production, Music Royalties, Provenance |
| 1516 | Five years of remote freelance work | Cross-Cultural Work, Freelance, Remote Freelance, Remote Work, Scope Management | Freelance Business ⚠️ |
| 1504 | Music's billion-dollar metadata problem | Black Box, Metadata, Music Rights, Music Royalties, Provenance | Black Box Royalties, Music Metadata, Music Rights, Music Royalties, Provenance |
| 1549 | Verifying the artist isn't enough | AI-Generated Music, Artist Verification, Music Authentication, Music Distribution, Spotify | AI Music, Artist Verification, Content Authenticity, Music Distribution |
| 1514 | Where AI actually saves time in record production | AI Tools, Artificial Intelligence, Audio Engineering, Music Production, Stem Separation | AI Music, Music Production |
| 1498 | Provenance is for humans, not against AI | AI Detection, Artist Credit, Human Attribution, Music Provenance, Music Rights | AI Detection, Authorship, Music Rights, Provenance |
| 1551 | Where artist signatures live | Artist Verification, Digital Signatures, Independent Artists, Music Authenticity, Music Distribution | Artist Verification, Content Authenticity, Cryptographic Signatures, Independent Artists, Music Distribution |
| 1512 | Pricing in dollars from Argentina | Argentina, Currency Controls, Freelance Business, Pricing Strategy, Recording Studio | Freelance Business ⚠️ |
| 1495 | Why C2PA isn't enough for music | C2PA, Content Credentials, Metadata, Music Distribution, Music Provenance | C2PA, Content Authenticity, Music Distribution, Music Metadata, Provenance |

---

## 4. The ⚠️ rows — three decisions already made for you

Three notes collapse to a thin tag set because their entire tag list was singletons. These are
resolved, not left open:

- **2071 "The rights files nobody reads"** — all five tags collapse into `AI Training` alone.
  **`Music Rights` has been added** (already in the After column): the note is about rights
  declaration files and opt-out, so the term is accurate, not padding.
- **1516 "Five years of remote freelance work"** and **1512 "Pricing in dollars from Argentina"** —
  both land on `Freelance Business` alone, and that is **correct as-is**. These two are a
  deliberate island: the only notes outside the research corpus. One shared tag connects them to
  each other and to nothing else, which is the right result. Do not invent a second term.

## 5. Execution notes for the WP admin pass

1. Do the **renames** first, not deletions — renaming `Music Provenance` to `Provenance` in the
   term editor will prompt to merge if `Provenance` already exists. That is the cheap path for
   the 53 merges and preserves post associations automatically.
2. Where a post carries **both** the source and target of a merge (e.g. 1746 has both
   `Cryptographic Provenance` and `Provenance`), WordPress dedupes on merge. No manual step.
3. Run the 7 **DELETEs** last, once nothing depends on them.
4. After the pass, `prune-unused-tags` becomes safe and useful — any term left at zero posts can
   be swept. It only removes empty terms, so it cannot damage the result.
5. Expected end state: **23 terms, 0 singletons except `Writing`** (1 post, deliberately kept).

Tag edits are taxonomy-only and change no rendered prose, so under the post-publish edit policy
they are free edits requiring no on-page correction, and they mint no provenance version.
