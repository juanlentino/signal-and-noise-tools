# R2 prep — signing beyond notes, the AI channel, contrast, the deterministic layer

Build sessions start **here**, not by re-deriving. Written 2026-08-10 while the
context was warm, immediately after R1 shipped; recon is current against
`origin/main` @ `878d780` (v10.77.0).

Sequence and economics: [roadmap-release-sequence.md](roadmap-release-sequence.md).
Previous release: [r1-prep.md](r1-prep.md).

**R2's gate is already cleared.** "Extend signing beyond notes" was gated on R1's
key history with a future — that shipped in v10.77.0, so nothing here is waiting
on anything.

---

## Session split

R2 is five rows across four families. That is **not one session** — the guardrail
is ~80–100 turns and ≤10M effective.

| Session | Rows | Why grouped |
|---|---|---|
| **2A** | Extend signing + anchoring beyond notes | Largest by far; touches the ledger and the Worker. Own session. |
| **2B** | AI-attention digest section + AI-referred humans as a channel | **One arc in practice.** Both need the same answer to "what host counts as an AI assistant" — building that vocabulary twice guarantees they drift. |
| **2C** | Contrast at the token level (report only) + extend the deterministic layer | Two small, independent rows. |

Routing: **Opus** orchestrates and reviews; **Sonnet** implements from the briefs
below; **Haiku** for mechanical sweeps. **Fable off.**

**Before committing:** AI's `done` column is at **4 of a ceiling of 5**, and the
canary reds CI at 5. R1's graduation is *still outstanding* — if it happens
before or during R2, something graduates off the AI column first.

---

## 2A — Extend signing and anchoring beyond notes

**Where:** `inc/provenance-webhook.php` (the dispatcher), `inc/provenance-did.php`
(keys — just rebuilt in R1), `inc/provenance-integrity.php` (the ledger
cross-exam), `inc/provenance-render.php` (the panel).

**What exists:** signing is wired to `sn_prov_committed` →
`sn_prov_enqueue_dispatch` → async reconcile. The corpus is **hardcoded to
`'post'` in at least two places**: `sn_prov_post_by_uid()` (line ~295) and the
reconcile sweep's batch walk (line ~418). Both are `post_type => 'post'`,
`post_status => 'publish'`.

**THE TRAP — the hardcodes are a scope DEFINITION, not an oversight.** Widening
them is a one-line change each and is the wrong first move. `sn_prov_post_by_uid()`
resolves a UID *back* to a post; if two post types can hold the same UID meta the
resolver becomes ambiguous, and the reconcile sweep's pagination assumes a single
homogeneous corpus. Decide the identity model before touching either line.

**Second trap — "media" is not "pages with a different post_type".** A page has
`post_content` the signature covers; an attachment's substance is *bytes on disk*,
not prose. The existing signature covers **normalized prose**
([[prov-signature-covers-normalized-prose]] — markup-only edits coalesce to no
commit). That machinery has nothing to say about a JPEG. Signing media needs a
content hash over the file, which is a different pipeline, not a wider query.
**Strong recommendation: split this row — pages in R2, media deferred with a
named gate.** Do not let a `post_type` array make it look like one job.

**Also verify before building:** does the ledger's record path
(`notes/<uid>/v<n>.json`) still make sense for a page? The Worker and the ledger
repo both encode `notes/` in the path, and `prov-verify-core.js` builds it
client-side (`ledgerRecordUrl`). A page signed into `notes/` is a lie in a URL.

**Tests must pin:** a page is signed and reconciles; a post still signs
identically (no regression); a UID resolves to exactly one subject across both
types; the verify page builds the right ledger path per subject type; a media
attachment is *refused* with a named reason rather than silently signed as empty
prose.

## 2B — The AI channel arc (two rows, one vocabulary)

### Row: AI-referred humans as a channel

**Where:** `inc/analytics-sources.php`. `sn_analytics_source_rules()` (line ~86)
holds the label→category vocabulary; `sn_analytics_canonical_source()` (~137)
applies it; categories today are Search / Social / Direct / Other. Reads are
already split by `$class` (`'human'` vs bot).

**The row:** a human arriving from an AI assistant (chatgpt.com, claude.ai,
perplexity.ai, gemini.google.com, copilot.microsoft.com …) is currently folded
into Social or Other. It needs to be its **own category**.

**THE TRAP — this is the exact shape of [[exclusion-lists-invert-under-reuse]].**
The rules table is an allowlist of known hosts; anything unmatched falls to
Other. Adding AI hosts is easy. What is *not* easy: the same host list will be
tempting to reuse as "is this an AI request?" elsewhere — and as a predicate it
inverts, because an AI *crawler* is a different thing from a human *referred by*
an AI. Two questions, two lists, and R3's give-back ratio depends on this
segment being the human one.

**Tests must pin:** an AI-assistant referrer on a human-class visit lands in the
new category, not Social or Other; the same host on a bot-class visit does NOT;
an unknown host still falls to Other; the existing Search/Social/Direct
classifications are unchanged (regression).

### Row: AI-attention section in the weekly digest

**Where:** `inc/insights-narration.php` — `snt_narration_collect_signals()`
(line ~106) assembles the signal groups; `snt_narration_system_instruction()`
(~237) is the prose contract; `inc/machine-readers-narration.php` is the closest
existing sibling and the shape to copy.

**Watch:** this is **AI-generated prose on an owner-facing surface**, so the R2
prose rule from the threat model applies — every narration boundary already runs
through `snt_ai_untrusted_display`. A new section is a new boundary. Do not add
one that skips it.

**Sequencing within 2B:** build the channel first, the digest section second. The
digest reports on the segment; a section written against a vocabulary that does
not exist yet gets pinned to a guess.

**Tests must pin:** the new section appears only when there is something to say
(no "no AI attention this week" filler); its input goes through the untrusted
display filter; the digest still renders when the signal group is empty.

## 2C — Contrast at the token level, **report only**

**Where:** the THEME (`~/Projects/signal-and-noise`, a separate repo —
[[concurrent-sessions-share-theme-checkout]], use an isolated worktree). Palette
tokens in `theme.json`: `void`, `asphalt`, `concrete`, `rust`, `bone`, `blood`,
`signal`. The report surface belongs in the plugin (a health check, alongside
`inc/health-color-drift.php`); there is no contrast helper anywhere today.

**THE TRAP, and it is the one that already bit this repo:**
[[template-inline-styles-defeat-theme-css]] — a rule's presence is not its
application. Block templates inline their own colours, so a table of "every token
pair and its contrast ratio" is a report about **arithmetic**, not about what any
reader sees. A pair that never co-occurs failing WCAG is noise; a pair that *is*
rendered and fails is the finding.

The row says **report only** for a reason — R3 owns the fixes. Resist producing
a number that looks like a verdict. The honest report separates:
1. token pairs that fail on arithmetic (cheap, complete, low signal), from
2. pairs actually rendered together (expensive, incomplete, high signal).

Computing (2) needs computed styles from a real render — the headless-Chrome
harness noted in [[desktop-mode-widget-mount-contract]] is the existing tool.
**If only (1) ships, say so in the report's own words**, or the next reader takes
a clean arithmetic sweep for a clean site.

**Tests must pin:** a known-failing pair is computed correctly against WCAG 2.2
AA thresholds (hand-derived ratio, never recomputed by the code under test);
large-text vs body-text thresholds differ and both are applied; the report states
its own coverage rather than implying completeness.

## 2C — Extend the deterministic layer, pipeline by pipeline

**Where:** `inc/ml-pipelines.php` — the registry is the single dispatch seam.
Seven pipelines as of v10.77.0: `related`, `near-duplicates`, `extract-keywords`,
`link-candidates`, `topic-clusters`, `cadence-flags`, `draft-echoes`.

**The row is deliberately open-ended**, which makes it the easiest one to do
badly. "Extend the deterministic layer" is a direction, not a spec. Pick **one**
pipeline whose absence is currently felt and add it; do not add three thin ones.

**Precedent worth copying:** `draft-echoes` (v10.77.0) added real value with
**zero new model** — it asked an existing computation a different question, from
the other direction, at a different moment. That is the cheapest kind of
extension and the pattern to look for first.

**Standing nevers** ([[ml-kernel-program]]): no provenance verdicts, no reader
profiling, nothing in the reader's browser. All three are live constraints here.

---

## Release loop (from the sequence doc)

1. Open here. 2. Opus reads only what this doc names, in ranges. 3. Sonnet
implements against the pinned tests. 4. Opus reviews the diff; one Bash call for
the full suite printing `FAIL` lines only. 5. CHANGELOG + version. 6. PR. 7. CI
poll in ONE long call (two settled reads, empty-string sentinel). 8. Write the
next prep doc while warm. 9. End at ~80–100 turns regardless.

**Version:** R2 is user-visible new capability → **MINOR**, v10.78.0.

**BUMP PER PR, NOT ONCE AT THE END.** R1 batched the bump onto its last PR, which
left the first half merged to `main` but **not installable** for the gap between
sessions — `SNT_VERSION` derives from the plugin header and the update checker
compares it against the highest tag, so an un-versioned merge is invisible to the
updater. If R2 spans three sessions, either bump each PR (v10.78.0, .1, .2 or
successive minors) or state plainly in the PR that the work is unshippable until
the last one lands. Do not repeat R1's silent version of this.

---

## Carried forward from R1 — still open

- **Board graduation for R1** — three steps: badge flip (code) → board row
  through the door (data; the write **replaces the entire board**) → static DR
  floor resync (code, next release; this is the step that has drifted three
  times). Check each family's `done` column against the ceiling of **5** first.
- **Third-party embeds** — now **DECIDED** as (b), the facade pattern
  ([r1-prep.md](r1-prep.md#decided--third-party-embeds-b-the-facade-pattern)).
  Unscheduled: it belongs to the Accessibility family and can ride R2 or R3.
  **Step one is a corpus count of existing embeds, not a component.**
