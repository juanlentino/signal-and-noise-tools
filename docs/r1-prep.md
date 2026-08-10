# R1 prep — the alt-text arc, key history, draft-time echoes

Build sessions start **here**, not by re-deriving. Written 2026-08-10 while the
context was warm; recon is current against `origin/main` @ `3d0f734` (v10.76.0).

Sequence and economics: [roadmap-release-sequence.md](roadmap-release-sequence.md).

---

## Session split

R1 is five board rows. That is **not one session** — the guardrail is ~80–100 turns
and ≤10M effective. Split:

| Session | Rows | Notes |
|---|---|---|
| **1A** | Alt-text coverage (inline SVG) + alt-text quality | **DONE** — landed un-versioned, see below |
| **1B** | Key history with a future + draft-time echoes | Independent of each other and of 1A |
| **—** | Third-party embeds | **BLOCKED — owner design decision, see below** |

### What 1A left for 1B

- **The version bump is 1B's job.** 1A landed with the plugin header still at
  `10.76.0` and its CHANGELOG entry under `## [Unreleased]`. 1B renames that
  heading to `## [10.77.0] - <date>`, appends its own sections to it, and bumps
  `signal-and-noise-tools.php:6`. One tag for the whole of R1.
- **Board graduation is still pending** and is the *third* step the maturity
  pattern keeps losing: badge flip (code) → board row through the door (data) →
  resync the static DR floor (code). Do all three when R1 ships, and check the
  `done` ceiling (5) before adding rows — AI sits at 4.
- **New in 1A, reusable:** `inc/health-alt-quality.php` holds pure
  string→verdict helpers with no WP or DB dependency. `sn_health_normalise_alt_text()`
  (lowercase, punctuation folded to single spaces) is the comparison primitive if
  1B's draft-time echoes need text equivalence.
- **A trap 1A hit that 1B should expect:** `sn_health_render_suggest_cell()` in
  `inc/health-checks-admin.php` routes on `$check_key` with **no default guard** —
  an unhandled key still emits a button, just without a `data-check`. Any new
  finding type needs an explicit branch there or it degrades silently.

Routing: **Opus** orchestrates and reviews; **Sonnet** implements from the briefs
below; **Haiku** for mechanical sweeps (corpus counts, fixture tallies). **Fable off.**

Before committing anything: AI's `done` column is at **4 of a ceiling of 5**. The
canary reds CI at 5. R1 adds no `done` rows until its own graduation, but if a row
graduates during R1, graduate something off first.

---

## 1A — Alt-text coverage for inline SVG — **SHIPPED**

> Landed as described. Two things the recon below did not predict: there was **no**
> `tests/health-check-missing-alt.php` (so the suite was written from scratch), and
> the gap was **triple**, not double — the third hidden layer was the Suggest-button
> `subject_type` binary in `inc/health-checks-admin.php`, which would have routed
> every new finding type to the attachment suggester with a post id. Kept below as
> the record of what the reasoning was.

**Where:** `inc/health-check-missing-alt.php` (115 lines). Tests: check for
`tests/health-check-missing-alt.php` first; the sibling fixtures are
`tests/ai-alt-prompt-shared.php` and `tests/ai-alt-vision-context.php`.

**What exists:** the check covers (a) attachments with no alt, and (b) inline `<img>`
without an `alt=` attribute, via `preg_match_all( '/<img\b([^>]*)>/i', … )`.

**The gap is real and doubly hidden:**

1. The SQL pre-filter is `post_content LIKE '%<img%'` — a post whose only graphic is
   an inline `<svg>` is **never selected**, so it cannot be reported even if the
   parser were extended. Fix the query before the parser or the parser is dead code.
2. `<svg>` spans lines and carries children. The single-tag `<img>` regex does not
   transfer. Per the project's own rule, exhaustiveness here needs a `perl -0777`
   style multi-line sweep, not a line-wise one.

**THE TRAP — SVG has no `alt` attribute.** An inline `<svg>` gets its accessible name
from a child `<title>`, or `aria-label` / `aria-labelledby`, usually paired with
`role="img"`. A decorative one wants `aria-hidden="true"`. Any implementation that
looks for `alt=` on `<svg>` will report 100% failure and any "fix" that adds `alt`
to an `<svg>` is invalid markup that changes nothing for a screen reader.

**Tests must pin:** an SVG with `<title>` passes; with `aria-label` passes; with
`aria-hidden="true"` passes as decorative; a bare `<svg>` fails; a post containing
only an SVG is **selected by the query** (this is the regression that the `LIKE
'%<img%'` filter would silently reintroduce); a multi-line SVG is parsed.

## 1A — Alt-text quality, not just coverage

Same arc, ships together. Targets the present-but-useless kind: filename echoes
(`hero-image-2.png`), caption duplicates, and single-word alt on a content image.

**Board copy commits to this:** "with every fix passing the same human acceptance as
the coverage sweep" — so quality findings route through the existing staged-revision
path, never a direct write.

**Tests must pin:** a filename-echo alt is flagged; a caption-duplicate alt is
flagged; a genuinely descriptive alt is not; the flag never auto-applies.

## 1B — Key history with a future

**Where:** `inc/provenance-did.php` (184 lines). Serves `/.well-known/did.json` with
one `verificationMethod` and an Ed25519 `publicKeyJwk`.

**Already scaffolded:** line ~126 has a path predicate for
`/.well-known/provenance-keys.json` — check what, if anything, currently serves it.
This row may be finishing an existing seam rather than opening one.

**The row's gate is in its own prose:** "the next key committed by hash **before it is
ever used**". So the endpoint publishes past keys with validity windows plus a
commitment (hash) to the next key. Rotation later reveals the pre-image; a verifier
checks the revealed key against the earlier commitment.

**Tests must pin:** the commitment is a hash, never a public key; a rotation whose
revealed key does not match the prior commitment is rejected; historical keys keep
their validity windows so old anchors still verify; the endpoint stays valid JSON
with no key material beyond public parts.

**Sequencing:** this row unblocks R2's "extend signing beyond notes". Do it in 1B, not
later.

## 1B — Draft-time echoes

**Where:** `inc/ml-cousins.php` — `snt_ml_cousin_pairs( $threshold )` and
`SNT_ML_COUSIN_THRESHOLD_DEFAULT` are the similarity core. Renderer:
`inc/ml-related-render.php`. Health surface: `inc/health-check-ml-cousins.php`.

**The row:** while writing, surface the most similar existing note so overlap is a
choice, not a surprise. This is an **editor-side** surface over an existing corpus
computation — no new model, and per the ML family's standing never, nothing in the
reader's browser.

**Watch:** cousins are computed corpus-wide; a draft is not in the corpus yet. Decide
whether the draft is scored against the corpus on demand or on save, and keep it off
the render path for readers.

**Tests must pin:** a draft similar to an existing note surfaces it; a novel draft
surfaces nothing rather than the least-bad match; the computation never runs for a
non-editing request.

---

## BLOCKED — third-party embeds (owner decision)

The tier list says "design decision first". The decision, stated so it can be answered
in one line:

> When a third-party embed (YouTube, X, etc.) cannot be made accessible from our side,
> do we **(a)** wrap it with our own accessible affordances — visible title, described
> link to the source, keyboard-reachable container; **(b)** replace it with a static
> accessible card that links out, loading the embed only on explicit request; or
> **(c)** allow it only where an accessible fallback is authored alongside it?

(b) is the strongest accessibility and performance answer and the biggest change to how
existing posts render. (a) is the smallest change and leaves the inaccessible iframe in
place. Not codeable until this is answered.

---

## Release loop (from the sequence doc)

1. Open here. 2. Opus reads only what this doc names, in ranges. 3. Sonnet subagent
implements against the pinned tests. 4. Opus reviews the diff; one Bash call for the
full suite printing `FAIL` lines only. 5. CHANGELOG + version. 6. PR. 7. CI poll in ONE
long call (two settled reads, empty-string sentinel). 8. Write the next prep doc while
warm. 9. End at ~80–100 turns regardless.

**Version:** R1 is user-visible new capability → **MINOR**, v10.77.0. Both 1A and 1B can
land in one release even across two sessions; bump once, on the last PR.
