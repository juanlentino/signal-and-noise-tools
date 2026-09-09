# Handoff — 2026-09-08..09: the redirect guard closes, and the pillar rail moves into the notes hero

Picks up where `HANDOFF-2026-09-08` stops (plugin **v13.107.5**). Two days,
**plugin v13.107.6 → v13.108.0** (3 releases), **theme v12.18.10 → v12.19.1**
(2 releases), five worker CI gates. All merged and tagged; both `origin/main`s
are clean.

**Provenance of this document:** unlike the 09-08 handoff, I did this work. Every
claim below is from a commit, a test run, or a browser measurement I took in this
session. Where something is unverified — chiefly what the owner has installed —
it says so.

## What shipped (do not redo)

### The outbound-redirect sweep closed (plugin v13.107.6, v13.107.7)

`WP_Http::handle_redirects()` re-issues a request with `$args` **wholesale**:
headers included, no `Authorization` stripping, no same-host check. Any
credentialed `wp_remote_*` call without `'redirection' => 0` will replay its
credentials at whatever host the response points to.

`v13.107.6` added the guard to the four calls that still lacked it —
`inc/ml-embeddings.php` and the three Search Console helpers in
`inc/search-console-client.php`, which are that surface's entire outbound
footprint. `tests/outbound-credential-redirect-guard.php` derives the census
from source rather than asserting per feature, with floors (50 calls / floor 40,
14 credentialed / floor 10) so a collapsed regex reads as a failure and not as
a clean sweep.

`v13.107.7` made it **fail closed**: a call whose options are an opaque `$var`,
or whose headers come from a variable, now counts as credentialed unless the
enclosing scope pins `'redirection' => 0` or carries a `// redirect-ok:` note.

**The lesson, and it recurred all session:** a convention asserted per-feature
drifts. Eleven suites already pinned `redirection => 0` and four credentialed
calls still had none. Derive the population from source, put a floor under it,
and negative-control against the **real pre-fix commit** — not a hand-made
mutation, which is shaped the way you expect and passes for the wrong reason.

### Five worker CI gates

`scripts/outbound-redirect-gate.mjs` now ships in **all five** workers
(sn-provenance, analytics, rights-signals, remote-mcp, login-guard) as a CI
**step**, plain Node, AST-parsing via `vite`'s `parseAst`. Nineteen
`// redirect-ok: <reason>` annotations across the five.

Two traps worth keeping:

- **Regex missed `fetchImpl(` twice.** First attempt required a `wp_` prefix;
  the second's prefix group required ≥1 char before `fetch`. Grep is the wrong
  instrument here — parse.
- **It cannot be a vitest suite.** `@cloudflare/vitest-pool-workers` runs inside
  workerd, and `parseAst` loads a native Rust binding workerd cannot `require`.
  It broke two repos before moving to `scripts/`.

`sn-provenance-worker` **v1.18.3** is live: `signAndPost()` was sending
`X-SN-Ed25519` on a `redirect: "follow"` request. Now `manual`, with the body
cancelled.

### The roadmap board, re-ranked and refilled (#1128–#1132)

One duplicate struck, three demoted, every future column filled to ≥2, and
signature verification graduated into Analytics-done. Board sits at 85 rows.

**Two near-misses.** A duplicate scan compared 90 characters and reported 0 on a
board holding 2 — comparing the title clause instead found a third, mine. And I
recommended retiring the wrong row: "Which machines send a reader back" carries
two DR-floor pins guarding a **declined** design, and my justification was a
misread tombstone comment. The suite caught it. "Search-side metrics" (0 test
refs) went instead.

### Door verdicts made total, and note-dossier absorbed (v13.108.0)

`tests/mcp-capabilities.php` now derives the ability population from source, so
an unclassified ability is **named** rather than silently uncounted. Matching
both quote styles mattered: the first totality control passed only because the
probe happened to be single-quoted.

`sn-posts` gained `include_dossier` + `dossier_days` (7/30/90, cap
`SNT_SN_POSTS_MAX_DOSSIER_IDS = 5`, 422 above the cap), retiring `note-dossier`
as a separate door tool.

**Reverted in full:** I re-added `get-insights` and `get-narration` as "drift".
They are a v13.0.0 wave-2 retirement, spec'd "retired, not absorbed" since day
one. Nothing shipped.

## The theme arc — /research became a rail in the /notes hero

The owner opened with "change Notes for a Research page where the pillars +
notes are all visible." **That is not what shipped, and the reasoning is the
useful part of this document.**

I built five mockups and the owner rejected all five. What eventually landed was
the opposite of a rename:

**Do not move `/notes`.** It is mid position-drift watch (6.3 → 11.5 on 11
impressions, due 2026-09-11) and the 09-08 coverage run found 34/40 indexed with
5 "Discovered — currently not indexed". A rename trades a measured problem for
an unmeasurable one. The actual complaint — "the pillars are hidden on
/provenance" — needed no URL at all.

### v12.19.0 — the hero's right column becomes an owner slot

`/notes` renders entirely from `inc/page-notes-render.php`
(`sn_notes_owns_request()` short-circuits `template_include`), so the Notes
Page's `post_content` was **inert** — measured empty, post 1489, `word_count 0`.
It now renders into the hero's right column, and the pillar rail is placeable
from Pages → Notes with no deploy.

**Placement was the whole question, and it is a grid fact.** Measured at
1440×900: left column 282px, right column 179px, right column's copy capped at
346px inside a 577px column. A block in the **shorter grid column** costs the
page nothing until it exceeds the taller one. A block **between hero and index**
adds its full height to every row below: 216px, measured.

The rail gained a `compact` density (161px in the hero vs the full card's 912px
on `/provenance`, 58% of that page). Default stays full, so `/provenance` is
byte-identical — asserted, with heading ids normalised out.

Cost to the notes, **measured on live after the owner placed the block**:
**+10px at 1440** (793 → 803) and **+199px at 375** (812 → 1011). A stacked hero
has no spare column, and no placement avoids the mobile figure.

Those are 4px worse and 25px better than I predicted from a local copy of the
page. The copy was byte-identical HTML with the shipped CSS, and it was still
off by ~25px in both directions. **A local reproduction predicts a layout to
about ±25px, not exactly** — good enough to choose between 216px and 6px, not
good enough to quote a number as if it were measured.

### v12.19.1 — the editor now says what the slot is

Shipping v12.19.0 left the Notes editor offering all **56** curated blocks for
what is really a 577px rail. Firewall 3 in `sn_theme_allowed_blocks()` narrows
that one Page to `signal-noise/pillar-essays`, resolved by ID through the same
`get_page_by_path('notes')` the slot uses. Four controls pin that it is a
firewall and not a lockout, including **fails open** when the page cannot be
resolved.

## Recurring failure modes from this session

- **Widening a container does nothing when the content is capped by measure.**
  The theme band is 1320px site-wide and the page still measured 1320 at a 1920
  viewport — but `.sn-notes-dek` and `.sn-notes-subscribe` are capped at `48ch`.
  Raising the band would have bought empty space. The cap was the constraint.
- **DOM surgery invalidates the measurements taken after it.** I moved elements
  around the live page to test layouts, which changed which CSS rules matched;
  a `max-width: none` reading was my own doing. A clean reload said `345.656px`.
  Structural conclusions from **source**; pixel counts only from a real render.
- **A breakpoint must watch the box that actually changes.** I gated the compact
  tightening at 821px; `.sn-notes-hero`'s grid query is **900px**. From 820–899
  the hero is stacked — hand-held — and rows would have dropped below the 44px
  touch target.
- **A test that goes red on a legitimate change may be testing the wrong thing.**
  Two did. `blocks-registry` exempted the rail for two reasons welded together,
  one of which expired; the exemption is now derived from whether `editor.js`
  previews through `ServerSideRender`. `notes-hero-structure` pinned
  `<p class="...-privacy">`, testing the tag rather than the intent. Both were
  rewritten to assert the intent, both negative-controlled.
- **The hidden browser pane throttles timers** and returns stale frames on
  scroll. Short evals, tool-side waits.

## State at handoff

| | version | where |
|---|---|---|
| plugin | v13.108.0 | tagged, merged |
| theme | v12.19.1 | tagged, merged |
| sn-provenance-worker | v1.18.3 | live |
| four other workers | — | census gate merged |

## Open, and NOT started

- ~~The owner has not installed v12.19.1 or placed the block.~~ **Done and
  verified live 2026-09-09.** The rail renders compact in the hero's right
  column; `/provenance` still renders full cards (912px, `<article>`, 3 deks,
  3 CTAs, 3 `<h2>`, `is-compact: false`); rows are 45–46px wherever the hero is
  stacked, so the 44px touch floor holds at 860px and 375px; no horizontal
  overflow.

  **`/notes` is the `page_for_posts` page, not an ordinary Page** — I did not
  know that while building the slot, and it is the kind of fact that silently
  breaks this class of change. `is_page('notes')` returns FALSE there. It works
  only because the slot resolves its target three ways: the `/notes` path check
  inside `sn_notes_is_index_request()`, `get_queried_object_id()` (which does
  return the page ID for `page_for_posts`), and a `get_page_by_path('notes')`
  fallback. **Belt-and-braces resolution is what saved it, not knowledge.** Any
  future change to that lookup needs a test pinning the posts-page case.
- **The +224px mobile cost is an owner decision.** It is content, not code: keep
  the block or remove it in Pages → Notes.
- **GSC crawl-delta on 2026-09-14.** Baseline at
  `~/.claude/session-data/2026-09-08-gsc-coverage-baseline.tsv`. Needs a LOCAL
  session — the remote door has no coverage twin. The coverage run **overwrites
  its own history**, so the baseline is the only copy.
- **`snt_ml_embed` has no production evidence.** `snt_ml_rebuild` is the last
  redirect-guarded path never exercised live.
- Roadmap "Ready to build" stays empty by design.
