# Notes corpus structural sweep — 2026-08-15

Structural only. I changed no prose, no number, no citation, no argument. Every write was markup
or taxonomy. Corpus: 42 notes, 33 published, 9 scheduled through 2026-09-19.

---

## What I found before I wrote anything

**The 1681 fix from the previous pass never actually cleared the rule.** The opening
`anchor_violations` scan returned **9** candidates, not the 8 I was briefed on — 1681 was still
flagged.

Reading it live showed the earlier fix *had* been applied:
`<a …>Origination is not leverage</a>. It never has been.` The period had been pulled outside the
link exactly as intended. But `anchor_equals_sentence` compares **with terminal punctuation
ignored** — that is in the scan's own contract. So moving the period is invisible to the
detector. The anchor still equals the sentence under normalization.

The other three from that pass (1587, 1572, 1531) were absent from the scan, because those were
substring **shrinks**. That was the hypothesis going in, and the verification scan proved it: my
eight shrinks/unlinks all cleared, and the one punctuation-only fix did not.

**A punctuation pull is never a fix for this rule. Only a genuine shrink is.**

1681 was on the explicit do-not-redo list and had no prescribed `new_anchor`, so I left it
alone. It is the only reason the corpus does not read zero. See "Decisions I need from you".

---

## Phase 1 — anchor violations: 8 of 8 applied, verified

Seven posts, eight writes (1591 took two). Every one ran `sn-apply dry_run:true` first, passed a
freshly-read `content_hash` as `change.fingerprint`, and carried an idempotency key.

| Post | Type | Change | Result |
|---|---|---|---|
| 1589 Provenance as a CFO problem | `link_reshape` | `This is a CFO problem.` → `a CFO problem` | applied |
| 1581 Signing the inputs at the source | `link_reshape` | `The DAW signs the assembly` → `signs the assembly` | applied |
| 1593 Why platforms wait on provenance | `link_reshape` | `Spam tracks…until they're removed` → `Spam tracks contribute to catalog` | applied |
| 1746 Start here | `link_reshape` | `The formal versions…live as papers` → `The formal versions of this argument` | applied |
| 1591 Open standards or no standards | `link_reshape` | `Every party in that chain is a verification surface` → `a verification surface` | applied |
| 1591 Open standards or no standards | `link_reshape` | `We sign tracks at upload` → `sign tracks at upload` | applied |
| 1721 Provenance signs the claim, not the truth | `unlink` | h2 `Assertion and observation` | applied |
| 1570 Who vouches for the independent artist? | `unlink` | h2 `The institutional shortcut` | applied |

**Verification: re-scan went 9 → 1.** Only 1681 remained.

**1681 closed (owner call, same session).** Juan chose `not leverage`. Applied as
`Origination is not leverage` → `not leverage`, giving
`Origination is <a …>not leverage</a>.` — a genuine shrink, so it clears the rule where the
earlier punctuation pull did not. **Final scan: `total_candidates: 0`. The corpus is clean.**

That makes nine Phase 1 writes across eight posts.

Every diff showed rendered prose byte-identical — tags moved, words did not.

### Mechanics worth recording

- **`sn-validate` has no `dry_run` parameter and no `link_reshape`/`unlink` surface.** It
  validates body/tags/excerpt/meta. The real pre-write gate for these change types is
  `sn-apply` with `dry_run: true`, which reports all four gates (fingerprint, validation,
  capability, idempotency). That is what I used, and I am flagging the substitution rather than
  implying I ran the tool the brief named.
- **`blocks_touched` reports `0` for `link_reshape` even on a successful write.** The diff is the
  reliable signal, not that counter.
- Reusing one idempotency key across the dry run and the real write is safe — dry runs do not
  record the key (`first_seen` stayed null and the real write applied).
- The fingerprint re-read between 1591's two writes earned its keep: `42d57a3b…` → `88bb45aa…`.

---

## Phase 2 — internal links: 3 applied, and one part of the brief is blocked

### The hub-inbound priority cannot be executed through this path

Priority 1 was inbound links to **start-here (1746)**, marked non-negotiable. I assembled the
full set from the scan — **21** candidate pairs, not 17 (the extra four are the off-topic
personal notes at the bottom of the confidence ranking).

I tested the four highest-confidence sources. All four returned **`skip`**, with the same reason
each time:

- 1661 → *"'Start here' is a generic site map/about page with no substantive connection…"*
- 1504 → *"…generic site map/about page with no substantive connection to the metadata topic…"*
- 1587 → *"…a meta/index page about the site's note format, not a subject the source discusses."*
- 1498 → *"…a meta index about the site's note format, not a subject the source discusses."*

This is systematic, not noise. `ai-pair-suggest` answers a **topical** question — does the source
genuinely discuss the target's subject? Routing a reader back to a hub is a **navigational**
intent. The tool's rubric structurally cannot approve a hub link, and start-here is by design a
meta page about the notes rather than a note making an argument.

I negative-controlled the instrument before concluding: `1721 → 1661` returned `link` with
`can_apply: true`. The tool works. The hub verdicts are real.

Two instructions in the brief collide here — "non-negotiable" versus "apply only where verdict is
`link` and `can_apply` is true; do not argue with them." I followed the verdict rule, because it
is the explicit mechanical gate and the alternative is fabricating links the corpus does not
support. **No hub links were applied.** This needs your call.

### Applied (3)

| Source | → Target | Anchor | Note |
|---|---|---|---|
| 1721 Provenance signs the claim | 1661 Two kinds of provenance | `Signed claims do not all carry the same weight` | restores the edge Phase 1's unlink removed |
| 1495 Why C2PA isn't enough | 1531 Falsifiability is the line | `C2PA (the Coalition for Content Provenance and Authenticity)` | |
| 1675 The gate is not the signature | 1531 Falsifiability is the line | `provenance for music` | |

I held to **at most one new link per source note**, so no note can have gained more than one
version from Phase 2 regardless of how the ledger treats a splice.

### Skipped, with reasons

| Pair | Reason |
|---|---|
| 1661/1504/1587/1498 → 1746 | `skip` — hub-link rubric, above. 17 further hub pairs untested; same rubric applies. |
| 1570 → 1566 | `unsure`. **This one costs us an edge.** Phase 1's unlink on 1570 removed its link to fingerprints-not-name-tags, and the brief expected Phase 2 to re-add it in prose. The verdict blocks that, so this connection is a **net loss** from the sweep. |
| **1551 → 1591** | Verdict was `link`, `can_apply: true` — and the nominated anchor `Distribution is the natural place` **sits inside an h2**. Applying it would have re-created a `heading_contains_link` violation in the same session I cleared eight of them. Skipped per the brief's explicit rule. |
| 1858 → 1589 | `link` but `can_apply: false`, empty anchor — advice-only, no splice contract. |
| 1835 → 1661 | `skip`. |

**`ai-pair-suggest` does not enforce the house anchor rules.** It validates only that the anchor
string is locatable in prose. The heading case above proves it will hand you a heading anchor with
`can_apply: true`. The reliable tell without re-reading the body: in `context_snippet`, block
boundaries render as `\n\n\n\n`, so a heading is a **short line flanked on both sides**. A safe
anchor has prose running into it on at least one side. Anything reading that list needs this check
applied by hand.

**Link candidates: 252 → 249**, exactly −3. The brief projected ~225 on the assumption ~25 links
would land; 3 did, for the reasons above.

---

## Phase 3 — tag merge map (produced, nothing written)

`tag-merge-map.md` at the repo root. There is no MCP path to reassign tags on existing posts, so
this is a WP admin pass. I did not attempt a write.

The inventory reconciled exactly against the brief: **83 tags, 47 used once (57%)**.

Result: **83 → 23 terms** (53 merged, 7 deleted). The file carries the surviving vocabulary with
a one-line definition each, a from→to row for all 83 terms, and a before/after tag list for all
42 notes, plus execution ordering for the admin pass.

The largest single gain: `Provenance` (15) and `Music Provenance` (12) were splitting the primary
archive. Merged under `Provenance` — per the convention that tags follow the "provenance" side of
the provenance/proof-of-origin line — the main archive goes to **24 posts**.

Three notes would have collapsed to a single tag. I resolved all three in the file rather than
leaving judgment calls in it: 2071 gains `Music Rights` (accurate — it is about rights files and
opt-out); 1512 and 1516 keep `Freelance Business` alone, which is correct, as they are a
deliberate two-note island outside the research corpus.

---

## Provenance versions

**VERIFIED: zero new provenance versions from all 12 writes.** Not inferred — read off the
public ledger. Method and reasoning below.

## Verification: the public git ledger, not the Content-Health scan

The Content-Health scan turned out to be both unreachable *and* the weaker oracle — it samples
10 of 32. The ground truth for "did a version get minted" is the ledger itself:
**`github.com/juanlentino/signal-and-noise-provenance`**, public, "ed25519-signed,
OpenTimestamps-anchored commit records".

- Timezone confirmed first, so the comparison is apples to apples: cron ts `1786756503` renders as
  `2026-08-15 01:15:03 UTC`, exactly what the site reported. **The site reports UTC.**
- First write of this sweep: **2026-08-15 01:13:20 UTC**. Last: ~01:31 UTC.
- **Most recent ledger commit: `2026-08-14T22:00:24Z`** — 3h13m *before* the first write.
  **Nothing on 2026-08-15 at all.**

**Why that is conclusive rather than merely suggestive.** The obvious objection is that mints
might be batched into the hourly `sn_prov_reconcile` (fires at :15), in which case only the first
three writes would have been swept yet. The ledger's own history rules that out: the record
commits land at `18:55:06`, `19:40:17`, `19:45:46`, `22:00:19`, and the index sweeps at `18:00:28`,
`19:40:19`, `19:45:49`, `22:00:24`. **None aligns to :15.** Mints are event-driven — the worker's
own description is `HMAC webhook → ed25519 sign + OTS stamp → public git ledger` — so a minted
version produces an immediate `(pending)` commit, later upgraded to `(confirmed)` by the OTS
sweep. No `(pending)` commit appeared for any of the twelve writes.

That covers all 12: nine `sn-apply` writes across eight notes and three `ai-link-apply` links.

**Expected, and now confirmed: zero new versions corpus-wide.**

- All 8 Phase 1 writes were `link_reshape`/`unlink` — markup-only, rendered prose byte-identical
  in every diff. These coalesce to no new commit.
- All 3 Phase 2 links wrapped text already present in the prose. Normalized prose is unchanged, so
  these should coalesce too.
- One link per source note by design, so the ceiling holds even if a splice does mint.

**I could not verify this directly, and the Content-Health scan cannot be run from here.**

`provenance-integrity-status` is read-only and never triggers a sweep. The latest sweep predates
this session — `swept_at: 1786723412` = **2026-08-14 16:03:32**, about 9¼ hours before the first
write (10 of 32 checked, 10 clean, 0 failed, keys ok). It confirms no pre-existing corruption. It
cannot speak to my writes.

I went looking for the trigger and there isn't one:

- **Every health-scan surface exposed over MCP is read-only.** `get-health-scan`,
  `sn_remote_health_scan`, and the portal duplicate all state "never triggers a scan". The write
  namespace's `run-*` tools are different scans — `run-insights-scan` reads Plausible/publish
  history/cron freshness, `run-narration` and `run-audit-prune` are unrelated.
- **It is not on cron.** All 43 scheduled events were listed; no content-health hook exists.
  `wp_site_health_scheduled_check` is WordPress core's own Site Health, not this.

**So the Content-Health scan is on-demand only, from wp-admin.** `get-health-scan.scanned_at` and
`provenance-integrity-status.swept_at` are the same value (1786723412), confirming one run
populates both — so triggering it in admin refreshes the provenance answer too.

**Caveat that survives running it:** the sweep reports `fleet: 32, checked: 10`. It samples rather
than covering the fleet, so a single fresh run may still not cover all nine notes touched here.
Check `checked` and the `failing` list against the touched set before reading a clean result as
coverage — a healthy readout can measure the wrong posts.

**One thing I deliberately did not read as evidence:** `sn_prov_reconcile` runs hourly and fired
at 01:15:03, mid-Phase-1, in 172ms. That is tempting to read as "no extra work, so no new
commits". It is not evidence — the six most recent runs span 60–267ms with no writes at all, so
172ms sits well inside ordinary variance. The metric cannot answer the question either way.

**One anomaly, now partly explained — and it has a practical consequence.**
`anchor_violations` returned an identical `scan_run_id` and `corpus_fingerprint` before and after
Phase 2's three content writes, while `link_candidates` moved both.

The 1681 write resolved half of it: that write **did** move the anchor scan's fingerprint
(`7f3d6b01…` → `c666e8ef…`), so the scan is genuinely live, not frozen.

The distinguishing variable is **which tool did the writing**. Phase 1 and 1681 went through
`sn-apply` and moved the fingerprint. Phase 2's three writes went through **`ai-link-apply`** and
did not. Working hypothesis: `ai-link-apply` writes via `wp_update_post()` without busting
whatever the anchor scan's corpus fingerprint is derived from.

**Consequence: do not trust `anchor_violations` as a gate immediately after `ai-link-apply`
writes.** It may report pre-write state. This is unconfirmed — it is a hypothesis that fits four
observations, not a verified mechanism. It retroactively justifies hand-checking the three Phase 2
anchors, which I did: none is a full sentence, none sits in a heading.

---

## Out of scope, surfaced not fixed

- **1531 `drift_lexicon`: "currently"** — flagged in the brief. Claim-adjacent, your call. Untouched.
- **1581 `drift_lexicon`: "today" and "Today"** — two warnings, pre-existing, non-blocking, surfaced
  by the validation gate during my write. Not caused by my change.
- **1721 `drift_lexicon`: "today"** — same.
- **1876 /uses em-dash** — excluded by you. Untouched.

Nothing was touched in the ReverBeat or S&N Tools repos.

---

## Decisions I need from you

1. ~~**1681.**~~ **RESOLVED in session.** Juan chose `not leverage`; applied and verified.
   Corpus now scans **0** anchor violations.
2. **Hub links.** `ai-pair-suggest` will not approve inbound links to start-here, so the
   navigational repair you called the highest-value fix in the corpus cannot be done through that
   path. The options are to place them by hand outside the suggest/apply flow, or to accept that
   the hub is reached from the index rather than from within notes. Not my call to make.
3. **1570 → fingerprints-not-name-tags** is a net-lost edge: Phase 1 removed it from a heading and
   the verdict blocks re-adding it in prose. Restoring it needs a manual placement.
