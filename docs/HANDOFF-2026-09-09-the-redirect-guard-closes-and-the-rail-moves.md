# Handoff — 2026-09-08..09: the redirect guard closes, and the pillar rail moves into the notes hero

Picks up where `HANDOFF-2026-09-08` stops (plugin **v13.107.5**). Two days,
**plugin v13.107.6 → v13.109.0** (4 releases), **theme v12.18.10 → v12.20.4**
(7 releases), five worker CI gates. All merged and tagged; both `origin/main`s
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

### v12.20.0 — the three corrections that came from LOOKING at it

Everything after v12.19.1 came from the owner opening the live page, and none
of it from a test. Worth recording as a pattern: the suite was green through
all three.

**The rail had no room under it.** It ends on a hairline and sat 14px above the
subscribe text — less than one line-height, so the two read as one block. Both
that `0.85rem` and the meta's `0.7rem` were pixels I had borrowed to hold the
notes at +0, which was the wrong thing to optimise. **28px** (~1.5x the 19.2px
line-height) is where they separate; the notes moved 803 → 822 and the hero
stopped looking cramped. Chosen by measuring five candidates on the live page,
not by picking a number.

**The row underlined itself on hover.** Compact rows are `<a>`, so WordPress's
own theme.json rule — `:root :where(a:where(:not(.wp-element-button)):hover)` —
drew one line across the number, the title AND the reading time. The full card
never showed it because it is an `<article>` whose only link is the CTA.
**Turning a container into a link inherits every global anchor rule the site
has**, and that is worth checking deliberately the next time a block becomes
clickable. The override is one class deep, so no `!important`; `:focus-visible`
got a real outline, because removing a decoration must not strand the keyboard
user relying on it.

**Sub-pillars rendered as peers.** The designation already says otherwise —
major is the pillar, minor an essay under it. Now derived from the number, so
`X.00` is never demoted, an orphan `2.01` is still subordinated, and an
undesignated essay stays top-level. Zero sub-pillars, one, or nine all render
correctly without reopening the file. The owner's framing is the right one to
keep: *"maybe there are no more subpillars and it was a one-off, but things
must be ready for anything."*

**Growth, measured on live by cloning rows.** The left column is FIXED at 282px
at every width and every count (h1 112px, dek capped 48ch, one CTA row). At 41px
a row plus 66px of chrome that is a hard budget of **five rows** before the rail
drives the layout instead of filling it. Three today; four balances the
masthead; **six is where the composition turns** — the rail runs 145px past the
left column and `NOTES.` floats in a half-empty column. The fix then is not a
tweak: the rail leaves the hero and becomes a full-width band above the index.
Not worth building until it happens.

### v12.20.1 — the header counts what the rows show

v12.20.0 taught the rows to distinguish pillar from sub-pillar and left the
header saying "3 essays", which undid the distinction one line above it. Now
"2 pillars · 1 sub-pillar", second half omitted when there are none.

**One classifier, shared by the header and the row class.** The first draft
parsed the designation twice — a pre-pass for the count, again in the loop for
the class. That is how a header ends up disagreeing with the rows it heads.

### Verified live after the owner installed v12.20.1

`/notes`: header `2 pillars · 1 sub-pillar`; 1.01 indents 803 → 827 with a grey
number and hairline; 28px under the rail; privacy sentence is a SPAN inside the
paragraph at 518px (72ch); hover gives no underline on row or reading time and
a blood title; first note 822. Mobile 375: rows 45–46px (44px floor holds),
indent 16px, no overflow. `/provenance`: 1.01 card steps in 40px, still article
cards with 3 deks and 3 CTAs, 912px, not compact.

**A stale browser cache nearly became a bug report.** My first load of
`/provenance` after the install showed the OLD "3 essays" with no subordination.
A `no-store` fetch of the same URL returned the new markup immediately, and
`cf-cache-status: DYNAMIC` ruled out the edge — it was this browser's own copy
from earlier in the session. **Before reporting a deploy as incomplete, refetch
with cache disabled and read the cache headers.**

### v12.20.2 — a WCAG failure the crowding work walked past

The owner asked whether the hero was too crowded. I diagnosed spacing, proposed
two moves, and was about to ship. He then told me frontend-design is a PLUGIN,
not a skill — I had searched skills and the claude.ai plugin catalogue, both
empty, and concluded none existed. It is CLI-installed at
`~/.claude/plugins/cache/awesome-claude-plugins/frontend-design`, invoked as
`frontend-design:frontend-design`. **`ListPlugins` reads the claude.ai catalogue
and returns empty even unfiltered; it cannot see CLI plugins. Check the cache
directory before concluding a plugin is absent.**

Its colour principle — *dominant colours with sharp accents beat timid,
evenly-distributed palettes* — sent me to count red marks. **Twelve, across six
unrelated jobs**, one of them a separator glyph. And the audit turned up a real
standards failure: `RSS` and `JSON Feed` were distinguished from their paragraph
by **colour alone at 1.23:1**, where the technique wants 3:1. The underline
already existed and was spent entirely on `:hover` — a state touch and keyboard
users may never enter. Two in-prose links on the page, both these; not systemic.

The crowding fix that shipped alongside it is the one worth remembering: the
right column carried **three unrelated jobs** at the same visual weight. That is
not density but *undifferentiated* density. Removing a job (the corpus stamp,
which restated the count 200px below) bought 35px and cost nothing; every
earlier attempt compressed the survivors and bought 6–25px while costing rhythm.

### v12.20.3 — the rail's heading level

Full-density card titles were always `<h2>`, correct while the rail IS the page
and wrong the moment that page grows `<h2>` sections. A `headingLevel` attribute,
enum 2–4, default 2 so nothing existing moves.

### The plugin side: the coverage sweep could not see two thirds of the site

`snt_gsc_coverage_targets()` walked `post_type => 'post'`. Every Page — the
provenance hub, its three essays, the maturity pages — and every tag archive had
**never been inspected**. That map is the ONLY discriminator between "not
indexed" and "indexed with no query demand"; excluding them made the question
unanswerable and the silence read like a finding.

Surfaced when `/provenance` showed **zero impressions over 28 days** and I could
not say which it was. Discovery was ruled out first: the sitemap carries all 28
pages. Tag archives are NOT in the sitemap and six of them still earn
impressions — Google reached them by following links.

Targets are now keyed `post:<id>` / `term:<id>`: term and post ids are
independent sequences that will collide, and under int keys one silently
overwrites the other with nothing looking wrong.

**NOT RUN.** The weekly cron fires **2026-09-15 21:39 UTC** and will spend quota
on the ~51 newly-covered URLs alone (the resume rule carries fresh entries
forward). The 09-14 crawl-delta reads against the saved baseline first, with two
days' margin.

### /provenance — analysed, scaffolded, WRITTEN, verified clean

The owner noticed `/notes` and `/provenance` are "not dupe, but almost". **This
session created that overlap** — the rail left `/notes` in v10.47.0 to become
`/provenance`'s content, and today it came back.

The analysis that mattered: `/start-here` is **922 words and already states the
claim**; `/provenance` is **46 words stating a compressed version of the same
claim**. So the near-duplication was never `/notes` vs `/provenance` — it was
`/start-here` vs `/provenance`, and writing "the thesis, at length" on the hub
would have built a real duplicate of the site's best page.

The division that came out of it, by JOB rather than by audience (the owner was
explicit: everyone, peers and public, same as the notes):

| surface | job |
|---|---|
| `/start-here` | why detection fails and what replaces it — the problem |
| `/provenance` | what the three papers establish and what is still open — the body of work |
| the three essays | each move, in full |
| the notes | the working-out |

A scaffold was written to scratchpad (`provenance-scaffold.html`): eight prose
blocks, every block in the curated inserter, rail underneath at
`headingLevel: 3`.

**WRITTEN AND PUBLISHED by the owner at 2026-09-09 20:58 UTC.** Post 1490 went
46 words → **741** (863 rendered). Verified live: zero placeholders left, outline
correct (H1 → H2 → H3), rail present at full density with H3 titles and the
header reading `2 pillars · 1 sub-pillar`, the sub-pillar card indented.

**One live defect, found and FIXED by the owner within the hour:** the page
linked `https://juanlentino.com/start-here/` **twice** and that is a **404**.
Start Here is a CHILD of the Notes page, so its permalink is
`/notes/start-here/` — which is what `/notes` itself links and what the sitemap
carries. Both were in the opening and in "Where to start", i.e. exactly the
links routing a first-time reader to the page that already argues the case.

Re-verified after the fix against a cache-busted fetch: **all 9 unique internal
links return 200**, zero occurrences of the broken path remain, no external
links.

**The broken-link health check would catch this and has not looked.** It scans
post_content across posts and pages for same-site links that 404 — precisely
this shape — but last ran **08:00 UTC, thirteen hours before the page was
published**. Its `finding_total: 0` is a stale snapshot with a plausible
`elapsed_ms: 33085`. Read `scanned_at` against the change you care about; the
verdict alone is not an answer. (Recorded trap, hit again.)

### v12.20.4 — a block that was invisible on Pages

The owner: *"Shouldn't I use any of the WP blocks we created in /provenance? It's
a wall of text until the end where the pillars are."* He was right — the page is
eight sections, each exactly one paragraph of 82–125 words.

`signal-noise/sidenote` had **two** CSS rules and **both** were
`.single-post .sn-sidenote`. On a Page it rendered a bare `<p>` in body type:
no float, no margin escape, no hairline, no mono. **Not a degraded sidenote, an
invisible one** — nothing errors, and the editor shows it correctly because the
editor loads its own styles.

Counting rules ranked all three blocks immediately: sidenote 2 rules / 2 scoped;
pull-quote 6 / 0; pillar-essays 42 / 0. Only one was stuck, so "extend all the
blocks" turned out to be one block and two rules.

Scoped now to `.wp-block-post-content` — what the device needs is a constrained
prose column with room beside it, not a post type. **That was already the
pull-quote's scope**, which is exactly why that block worked everywhere and this
one did not: the correct answer was in the same stylesheet the whole time.

Verified before widening: the wrapper exists on four templates checked and is
the `.is-layout-constrained` element the `!important` escape was written to
beat; clearance measured at the breakpoint where the float turns on (260px
available against 200px needed at 1280 on `/provenance`, 340px at 1440);
specificity held at two classes.

Negative-controlled three ways, and the third is the point: **unscoping
entirely** — a bare `.sn-sidenote` — passes a naive "is it still `.single-post`?"
check while leaking the float into headers and widgets. The assertion is
"reachable from a prose column", not "not template-scoped".

**Also found:** the block's docblock pointed at `critical.css`. The rules are in
`article.css` and always were. Caught only because the comment contradicted a
grep I had just run.

Delivered alongside: `/provenance` block markup with the owner's prose verbatim
plus two sidenotes and one pull-quote — the §Two sidenote defining ISRC/ISWC is
the structural answer to "for everyone", since a peer's eye skips the margin
while a general reader gets the definition, without writing two registers.

### Breaking the wall: the cheapest fix was the last one found

The owner, after the blocks shipped: *"Still a great wall of text, IMHO."* He was
right, and the measurement said why.

**A sidenote floats BESIDE the column. It never touches the measure.** So
v12.20.4 added texture to the right-hand gutter and left the wall exactly as it
was — eight paragraphs, 60 lines, one unbroken run per section, two structural
breaks in the whole page. I had reached for *the blocks that existed* instead of
asking what a wall of text is. A wall is an unbroken MEASURE; the fix has to
interrupt the column.

Then the owner: *"There's a block that's a list, why don't we use that instead?"*
There is — `patterns/steps-enumerated.php`, shipped v9.2.0, and the important
part is not the 01/02/03 numerals but its wrapper: `.sn-pattern-steps-enumerated`
carries a **bleed panel** (asphalt, `left:-1rem`, `width:calc(100% + 2rem)`,
2rem padding) that breaks out of the column on both sides. That is the
structural interruption. **Two of his sections were already lists** — three
parallel `If…` conditionals each — rendering as prose.

Measured on the live page, start to finish:

| | first published | after |
|---|---|---|
| prose paragraphs | 8 | 12 |
| longest unbroken run | **11 lines** | **7 lines** |
| total prose lines | 60 | 50 |
| paragraphs >= 9 lines | 3 | 0 |
| structural breaks | 2 | 4 |

**Ranked by what each contributed:** three paragraph splits did the heavy lifting
(11 lines to 7) and cost three Enter keys; the two steps panels moved 176 words
out of the column entirely; the sidenotes and pull-quote — the things a whole
release was built for — contributed least.

**Where to split a paragraph: at the TURN, not at a word count.** All three
breaks landed on a discourse marker the owner had already written — "The paper
*also* names…", "Provenance as Substrate *extends*…", and a hard four-word stop
("Absence is not signable.") before the sentence that turns from what a chain
cannot do to what it can. A split at a word count lands mid-argument and reads
worse than the wall.

**Semantic note left open:** "Three ways in" is an `<ol>` with 01/02/03 numerals
over three ALTERNATIVES, not steps. The label reconciles it for a sighted
reader; the `<ol>` still announces a sequence to a screen reader.
`{"ordered":false}` drops the numerals if that matters.

## The /provenance tail — the page's own surfaces (plugin v13.109.1 → v13.109.3)

Written after the section above closed. The hub Page existed and read well; what
was wrong was everything *about* it that a machine reads.

### The OG card printed the title twice (v13.109.2)

`/provenance` had no excerpt, so `sn_og_card_dek_source()` fell back to the first
36 words of content. `templates/page-provenance.html` renders `post-content`
alone, so the `<h1>` lives *inside* that content — the card showed "ON PROVENANCE"
in 88px Bebas and then "On Provenance Two papers, three long-form essays…"
underneath it, spending the words that then fell into the ellipsis. A **leading**
heading is now dropped before the words are counted; only the leading one, since
a heading further down is a section title inside the prose.

### The card title guidance advised a length that truncates (v13.109.1)

The field said 60–90 characters. Measured with `imagettfbbox` against the real
font at the real sizes: one line holds about **24** characters, and an 83-character
title truncated even at the smallest step. I had guessed "28" and "~55" first;
both were wrong, and the measurement is the only reason the helper is now right.

### The excerpt (content, no release)

`/provenance` had none, so the validator had nothing to grade and the card was
living off the content fallback. Written to spec (50–75 words, 2–3 sentences,
≤35 words/sentence, no em dashes, no tricolon, no repeated openers, title not
restated), checker-run before it was applied, 55 words:

> Detection is a race that gets harder as generative models improve. A signature
> captured at the moment of creation does not degrade, and that asymmetry is the
> ground the two papers stand on. What neither of them settles is who vouches for
> an independent musician's identity, because nobody in the current arrangement
> is obliged to.

**The excerpt outranks the content fallback in `sn_og_card_dek_source()`, so
writing one obsoletes the existing card.** I regenerated it in the same breath.
Measured, because I had estimated wrongly twice already: the card dek wraps at
**3 lines × ~50 characters ≈ 150 characters ≈ 25 words** before the ellipsis, so
sentence one is deliberately 11 words and survives the cut whole.

### sn_validate graded a string that never ships (v13.109.3)

`/provenance` is a **route-served** page: `inc/seo.php` takes its description
from `seo_copy.provenance_description`, never from `_sn_meta_description`. The
same is true of the front page and `/notes`. `sn_validate` read the post meta on
every post regardless, so it reported **175 characters against a page serving
83** — a length appearing nowhere in the HTML, and a `char_range` warning no edit
to either value alone could satisfy.

The route table lived inside `sn_seo_description_for_post()`, where nothing else
could ask it a question. It is now `sn_seo_description_setting_key()`, read by
both the description resolver and the validator. An empty route setting **skips**
the surface rather than falling back to the meta row: "which store" and "is it
filled" are different questions.

Negative-controlled — the four new cases go red against the pre-fix resolver
(58/5) and green with it (63/0). The regression case (a generic page still reads
post meta) stays green in both, correctly.

### Release drafts backfilled

v13.109.0, .1 and .2 had tags but **no draft releases** — the newest was
v13.108.0. Backfilled all three from their archived CHANGELOG sections, bodies
diffed against the archive rather than trusted. Every one of the last 25 tags now
has a release; all are Drafts, none published. The updater reads `/tags`, not
`/releases` (`inc/wp-update-integration.php:317`), so the gap never blocked an
install — it was the human-readable record that had the hole.

## The memory repo — it is a git repo, and I did not know

Not plugin work, but it happened this session and the next session needs it.

**`~/.claude/projects/<project>/memory` is a symlink** into
`~/Projects/signal-and-noise-memory` — a real repo with `plugin/` (this project),
`theme/`, `provenance-worker/` and `archive/`. Both signal-and-noise projects
symlink into it.

I did not know that. I wrote two memory files, updated the index, **reported the
session as saved, and left all three untracked.** Nothing warns you: the symlink
is transparent, `Write` succeeds, and a memory dir looks identical whether or not
it is version-controlled. The rule now lives in three places — a memory
(`memory-is-a-git-repo-commit-and-push`), a banner at the top of both
`MEMORY.md` files, and the owner's global `CLAUDE.md` — because the banner only
fires if memory loads at all.

### The index was over its own limit

`MEMORY.md` is read into context every session and truncates silently past
~24.4KB. It was at **25.4KB**, so its tail was already being dropped.

Compacted to **22.5KB** with every pointer kept (target set diffed byte-identical
before and after). 40% of the file was link labels, one of them 147 characters —
the index had drifted from pointers into lessons, while each topic file's
frontmatter `description` is what actually drives recall.

Then archived the two closed-arc sections to
`archive/closed-arcs-2026-09-10/`. **The index sections moved; the files did
not** — fifteen of the seventeen carry inbound `[[wikilinks]]` from live
memories (`mcp-consolidation-program` from six), so moving the files would have
shredded the graph.

**The structural number, so nobody re-derives it:** ~37 bytes per memory in
filename alone, so at ~280 entries roughly 10.2KB of the index is link targets
and cannot be compressed. Label trimming buys about a dozen entries, which is
why the repo log carries three separate "trim one index label" commits.

### The wikilink graph was rotting, mostly for one reason

Swept both corpora. `plugin/` had 12 unresolved, `theme/` had **84**.

The cause in theme was not rot at all: **`plugin/` is kebab-case (288 of 288)
and `theme/` is snake_case (162 of 163)** — opposite conventions in one repo.
36 of theme's 43 broken slugs were links written in the wrong convention,
pointing at files that existed the whole time. 28 normalised cleanly.

Also backfilled `feedback_falsification_test_before_trusting_clean`, which
**eleven** theme memories cited and nobody had ever written. Its content was not
invented: all eleven citations assert the same thing, and it matches the owner's
standing rule that a guard must be shown able to fail before it is trusted.

Final: plugin 841 links / 0 unresolved, theme 462 / 16, provenance-worker 2 / 0.
The 16 are forward references to memories never written — git shows 162 adds and
zero deletions, so they were never lost. They name project decisions
(activitypub declined, betterstack migration, social syndication) I have no
basis to author. The format sanctions those; they mark work, not rot.

## The tail: /provenance verified, the pull quote, the webmention pin

### The description landed (content, no release)

The owner pasted the drafted `seo_copy.provenance_description`. All three
surfaces serve it verbatim at **154 chars**, in the 140-160 window, and
`sn_validate`'s `char_range` warning is **gone** — which is the end-to-end proof
that v13.109.3 reads the shipped settings string rather than the 175-char post
meta it used to grade. All 9 in-content links return 200.

The owner also edited the body in the same window: **746 -> 766 words**, two
sentences added to the incentive paragraph, a duplicate SSRN mention dropped
(6402298 is still cited once), whitespace-before-punctuation cleaned, and the
pull-quote attribution changed from "Provenance Over Detection" to "From the
argument above". Link count unchanged at 32.

### The pull quote ran off the screen (theme v12.20.6)

`width: calc(100% + 2rem)` sizes the CONTENT box, so under `content-box` the
`1.5rem` horizontal padding is added ON TOP of the intended 1rem bleed. At 375px
the quote rendered **423px against a 343px column, 48px past the viewport**, and
`document.scrollWidth` stayed 375 — clipped, not scrollable, slicing the last
character off six lines.

The three `.sn-pattern-*` siblings use the identical idiom and were fine: they
are core `wp:group` blocks, which core gives `border-box`. Only `.sn-pull-quote`
is a custom `<aside>`, so only it inherited nothing. `box-sizing` now sits on all
four so the idiom stops depending on who renders the element.

**The test gap underneath it.** `tests/prose-slab-idiom.php` exists because a
slab shipped correctly COLOURED and wrongly SHAPED. One level down, the same
gap: it pinned the shape and not the SIZE. Now pinned.

**And that scan was reading its own comments.** It stripped comment blocks from a
rule's selector but not its body, and a comment between `;` and a declaration
blocks any predicate anchored on `;`. `.sn-correction` documents its padding
inline, so its `padding: 1.5rem` was invisible and the element this file was
written for would have skipped the new check in silence. Caught only because a
guard-the-guard floor of 3 failed at 2 and I measured instead of lowering the
floor to match.

### The webmention route's registration (plugin v13.109.4)

`tests/citations-endpoint.php` had 26 assertions on handler behaviour but had
never called `sn_cit_register_route()` — `register_rest_route` was not even
stubbed. Namespace, path, the POST-only method list and the deliberately public
`permission_callback` were free to move while every assertion stayed green.

The discovery assertions also matched a hardcoded path literal, which cannot
catch drift. The `<link rel="webmention">` href is now compared against
`rest_url()` computed from the emitter's own constants AND against the
namespace + route as registered.

**Handler deliberately unchanged.** Its 400 returns
`WP_REST_Response( array( 'error' => '<free text>' ), 400 )` — no
machine-readable code a sender could branch on. W3C Webmention REC 3.2 requires
only the 400 status, so this is not a spec violation; it is pinned with a comment
saying "recorded as current behaviour, not endorsed". Note that because `args`
declares both params `required`, a real HTTP POST with no params never reaches
the handler at all — core answers first with `rest_missing_callback_param`. Both
are 400; only one carries a code.

### CLOSED: the card and the page never disagreed about reading time

**Resolved 2026-09-10. There was no discrepancy; the measurement was mine.**

I reported the page showing "5 min read" against the card's "4 MIN READ" by
grepping the rendered HTML for `[0-9]+ min read` and taking `head -1`. The page
carries **three** such strings — 4, 5 and 7 — and all three live inside the
pillar-essays block, describing the three linked essays. `/provenance` does not
display its own reading time anywhere: occurrences before the pillar block begins
are **zero**. The "5 min" belongs to *Provenance Over Detection*.

What is actually true:

- 766 words at the plugin's 225 wpm is `ceil(3.4)` = **4 minutes**.
- The note dossier independently computes **4 min**, "at 225 words a minute".
- The OG card renders **4 MIN READ** via `sn_get_reading_time()`, which returns
  the cached `_sn_reading_time_minutes`. The card showing 4 is therefore also
  evidence the cached meta IS 4 — no door exposes arbitrary post meta, so that
  is an inference from the reader rather than a direct read, but the reader is
  the one that matters.
- The byte-identical regeneration was correct all along: unchanged inputs,
  deterministic output.

**Three wrong causes before the right one** — a stale `?v=` read as a failed
rebuild, then a "stale card", then a "second reading-time calculation" — and
every one of them was a reading of an instrument rather than of the world. The
`head -1` is the root: a grep over a whole page attributes whatever it finds
first to the page itself.

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
- **A scan that reads its own explanation.** My CSS check for "is this rule
  still scoped to the hero?" matched the COMMENT explaining why the scope was
  removed, and reported the bug as present in the fixed file. Comment-strip
  every source scan, and add a vacuity check that the stripping does work — the
  fifth instance of this shape in two days.
- **A class MODIFIER breaks an exact-attribute assertion.** An existing test
  pinned `<article class="sn-notes-pillar">` including the closing quote, so a
  row carrying the new `--sub` modifier counted 2 of 3 and read as a missing
  card. Match the prefix.
- **Borrowed pixels are not a design decision.** Two spacings shipped at values
  I chose only to keep a number at zero. Neither survived the owner looking at
  the page. If a value exists to protect a metric rather than to look right,
  say so in the comment or do not ship it.
- **THE PATTERN OF THE DAY: instruments that measured their own neighbourhood
  rather than the code.** Three separate instances, two of them already recorded
  as traps in memory and written anyway, hours apart:
  1. scans matching the COMMENT explaining the thing (×3 — a CSS scope check
     reading its own justification, a CHANGELOG insert matching the sentence
     that names `## [Unreleased]`, a count returning 3 for a string appearing
     once in markup);
  2. a **fixed 1400-byte window** standing in for a region — one added control
     pushed the sentinel past the cut-off and an exemption evaporated;
  3. a test helper that **never passed its arguments**, so a whole branch had
     never been exercised and new cases passed by rendering the default.

  The common error is measuring the neighbourhood of a thing instead of the
  thing. The tell: any scan whose correctness rests on a number picked by
  looking at today's file, or on a string that also appears in prose about the
  file. Both written up: [[strip-comments-before-scanning-source]] and the
  second instance appended to [[a-proximity-window-is-the-finding]].
- **Estimating over a measurement I could have taken.** I predicted the longest
  run would fall to 7 lines, then "corrected" myself to ~8 using a
  words-per-line divisor. The rendered page says **7**: the original estimate
  was right and the correction was wrong. Second-guessing a number with another
  estimate, in a more confident voice, is worse than the first guess — the page
  was live and one browser call away the whole time.

### Closed out at the end of the session

- **#288** (theme) — Dependabot bump of `anthropics/claude-code-action`
  1.0.210 → 1.0.216. Merged after verifying both SHA pins resolve to the tags
  Dependabot names and that the diff is one line in one workflow file, with no
  permission or trigger changes alongside. `security` and `CodeQL` show
  `skipping` because Dependabot PRs get no Actions secrets — expected, and no
  real coverage loss on a workflow pin. No release: nothing shipped changes.
- **#1137** (plugin) — the `/notes` drift watch records that its window is
  confounded. See below.
- **The design spec** was written to
  `docs/superpowers/specs/2026-09-09-notes-hero-pillar-rail-design.md` and
  deliberately **not committed**: `docs/superpowers/` is gitignored in the theme
  with the comment "Internal AI-assisted-development scaffolding … local only",
  and all 97 specs there are untracked. The brainstorming skill says to commit
  it; the repo says otherwise, and the repo wins. Not forced past the ignore.
- Cleanup: the scratch static server stopped and its `.claude/launch.json`
  entry removed; the brainstorm companion had already exited.

### The drift watch is reading a window this session contaminated

`notes_drift_reread` was set to re-read a position drift (6.3 → 11.5 over 11
impressions) on **2026-09-11**, assuming a stable page. The page then changed
materially — the rail arrived in the hero, the corpus stamp left it, the heading
outline changed, the first note moved twice. Its `why` now says the reading
**cannot** separate continued drift from the effect of those changes: treat a
worse position as unattributable and re-baseline from the first full week after
2026-09-10.

The watch system's own failure mode from an unexpected direction: the instrument
is fine, but its window was contaminated by work done in the same session that
will read it. Nothing in a watch's design catches that, because a watch measures
the world and assumes the world was left alone.

### From the /provenance tail

- **A write door's own report is not evidence.** `sn-apply` in `mode:publish`
  returns `diff.before: null` **structurally** — the executor builds it that way,
  so it says nothing about what was there. `applied: true` with all gates passed
  is a claim, not a verification. I confirmed the excerpt landed three
  independent ways: the regenerated PNG rendered the new text, `sn_validate` read
  the surface back as 11/22/22 words, and `sn-posts` returned it.
- **A cache-buster in a URL is not the artifact.** After one regeneration the
  `og:image` `?v=` was byte-identical to the previous card's, which looked like a
  failed rebuild. The **file** had changed (40,952 → 39,940 bytes). Compare
  bytes; the version stamp is a separate, laggier thing.
- **My own parse conflated "missing key" with "error response".** A one-liner
  printed `(EMPTY)` for both a blank excerpt and a REST error body, so a real
  answer and a failed request were indistinguishable. Print the raw response
  before concluding from a derived one. Same class as the hidden-pane
  `clientWidth: 0` earlier in this arc.
- **An empty REST `excerpt.rendered` is deliberate.** It is blanked for
  **anonymous** callers by `inc/rest-hardening.php:51`, site-wide on every post
  and page. It survives a full purge chain, which is what proves it is not a
  cache. I nearly filed it as a bug.
- **Flattening two directories into one scratch dir clobbers same-named files.**
  `inc/abilities-sn-validate.php` and `tests/abilities-sn-validate.php` share a
  basename; a `cp` loop into one folder silently kept only the last, and I
  restored the wrong file over the right one. Caught it on the file headers.
- **BSD `sed` failed loudly but produced empty output that read as success.**
  A blank-line squeeze died with "extra characters at the end of d command" and
  wrote three zero-byte notes files; the loop's own line count is what caught it,
  not the error text scrolling past.
- **Estimating instead of measuring, again.** I computed ~66 characters per card
  line from a nominal monospace advance; the rendered figure is **50**. Third
  time in this arc that an estimate stood in for a measurement, and the third
  time it was wrong. The card, the title helper and the line count all needed
  `imagettfbbox` or a real render.

### From the memory work

- **A scanner that does not understand its own syntax reports rot that is not
  there.** My first wikilink sweep flagged `[[migrations]]` (wrangler TOML inside
  backticks), `[[file|alias]]` (valid alias syntax) and a link ending `.md`. Three
  of the "dangling" links were the instrument's fault. Blank code spans, split on
  the pipe, strip the extension — then count.
- **A wrong repoint is worse than a dangling link.** It asserts a relationship
  nobody wrote and reads as correct forever. Where I could not resolve a target
  confidently I de-linked to plain text instead of guessing at a plausible
  neighbour, and only repointed the three whose target descriptions confirmed the
  relationship.
- **Char count is not byte count.** Python `len()` said the index was 24,980;
  `wc -c` said 25,352. The 372-byte gap is multibyte em dashes, and the loader's
  limit is in BYTES. I briefly attributed the discrepancy to a concurrent session
  writing to the file, which was wrong.
- **A basename collision silently keeps the last file.** `inc/` and `tests/`
  both hold `abilities-sn-validate.php`; copying both into one scratch directory
  left one copy, and I restored the wrong file over the right one.


### From the tail

- **A guard-the-guard floor is only useful if you MEASURE it.** I asserted the
  padded-bleed count was `>= 3` from memory; it came back 2. Lowering the floor to
  match would have shipped the coverage gap green. Measuring instead found that
  `.sn-correction`'s padding was invisible to the predicate — the one element the
  test file exists for. A floor written from memory ratifies whatever the scan
  currently sees.
- **A test can inherit the very blind spot it was written to close.** The prose-slab
  suite was created because token guards checked what a rule is PAINTED with and
  nothing checked its SHAPE. It then pinned the shape and not the SIZE, and a
  correctly-shaped slab shipped 48px off-screen. Ask what the new guard still
  cannot see.
- **`head -1` of a page-wide grep attributes the first match to the page.** I
  read "5 min read" off `/provenance` and spent three wrong diagnoses on a
  card-vs-page mismatch that did not exist: the page carries three reading times,
  all belonging to the essays the pillar block lists, and none of them its own.
  Count the matches and locate them before attributing one.
- **Deterministic output means identical bytes are a PASS, not a failure.** I read
  a byte-identical regenerated card as a failed rebuild and "fixed" it by
  regenerating again. With unchanged inputs, identical output is exactly right.
  Establish what the inputs are before reading the output as evidence.
- **Two repos cannot share a pull request.** Asked to put a theme fix "in the same
  PR" as a plugin test, the honest move was to say why not and open two, rather
  than pick one repo and silently drop the other change.


## State at handoff

| | version | where |
|---|---|---|
| plugin | v13.108.0 | tagged, merged |
| theme | v12.20.4 | tagged, merged (owner installed through v12.20.3) |
| plugin | v13.109.0 | tagged, merged, INSTALLED |
| plugin | v13.109.1 → v13.109.3 | tagged, merged, **INSTALLED** (deploy-status 2026-09-10: current = latest = 13.109.3) |
| theme | v12.20.5 | current = latest, ok |
| /provenance | excerpt | 55 words, live; OG card regenerated against it |
| release drafts | v13.109.0–.3 | backfilled; last 25 tags all have one, all Drafts |
| five workers | — | all `ok`, live = latest |
| memory repo | — | 4 commits; plugin index 25.4KB -> 22.5KB, wikilinks 12 -> 0 (theme 84 -> 16) |
| plugin | v13.109.4 | tagged, merged, draft release — webmention registration pinned |
| theme | v12.20.6 | tagged, merged, draft release — pull quote box-sizing |
| /provenance | description | 154 chars live; validator char_range warning cleared |
| /provenance | 746 words | written, restructured, verified; longest run 11 lines -> 7 |
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
- **The mobile cost is an owner decision.** Measured +199px on live (812 →
  1011); it is content, not code — keep the block or remove it in Pages → Notes.
- ~~installation unverified~~ **v12.20.1 installed and verified live**
  (see above). Theme work for this arc is CLOSED.
- **OPEN QUESTION raised at the end of the session and NOT answered:** `/notes`
  and `/provenance` are now near-neighbours. `/provenance` is 46 words of intro
  plus the same three essays the `/notes` hero rail now lists. **This session
  created that overlap** — the rail left `/notes` in v10.47.0 specifically to
  become `/provenance`'s content, and today it came back. Nothing was decided;
  do not act on it without measuring both pages in GSC first, and note that
  `/provenance/<slug>` children depend on the hub Page existing and published
  (sn_theme_pillar_descriptor_from_page gates the hierarchical URI on exactly
  that).
- **GSC crawl-delta on 2026-09-14.** Baseline at
  `~/.claude/session-data/2026-09-08-gsc-coverage-baseline.tsv`. Needs a LOCAL
  session — the remote door has no coverage twin. The coverage run **overwrites
  its own history**, so the baseline is the only copy.
- **`snt_ml_embed` has no production evidence.** `snt_ml_rebuild` is the last
  redirect-guarded path never exercised live.
- Roadmap "Ready to build" stays empty by design.

### Opened by the /provenance tail

- **`/provenance` still advertises the wrong description.** The page serves
  `seo_copy.provenance_description`, currently *"A short read on why the industry
  needs to prove what's human, not chase what isn't."* — which is **pillar essay
  1's dek**, describing the very page `/provenance` says it is not ("This page is
  the body of work rather than the case for it"). Stale from when the slug meant
  something else. It is a **settings paste**, not code; the write door cannot
  reach `seo_copy.*`. Drafted replacement, checker-clean at 154 chars:
  > The provenance argument in music, worked out across two papers and the notes
  > that keep testing it, including the conditions under which it would be wrong.
- **The stored `_sn_focus_keyword` for post 1490 is unverified.** `sn-posts` does
  not return it and I did not read the meta directly. The drafted description
  contains "provenance" verbatim, which satisfies the voice spec **if** that is
  the keyword; a phrase like "music provenance" would not match and the sentence
  needs rewording. Check it on the same admin screen as the paste.
- **Two test suites are red in a linked worktree and green in CI.**
  `admin-class-orphans` (14 failures) and `direct-access-guard-window` (1) fail
  identically on pristine `origin/main`; both are VACUITY guards firing because
  their scan surface is empty here. CI's Test suite passed at 43s on the same
  commit. Do not "fix" them from a worktree reading.
