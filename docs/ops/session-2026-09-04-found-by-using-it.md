# Session — 2026-09-04: the audit, and the defects only using it revealed

Second arc of the day. The first is in
`session-2026-09-04-the-things-that-come-due.md` — that one built the watch
system's newest entry. This one starts when a three-part audit arrived: §1
urgent with a September 10 deadline, §2 two confirmed gaps to build, §3 four
things to investigate and report without fixing.

Seven releases came out of it. The two defects that mattered most were not in
the audit, and I found neither by reading code. I found both by using what I
had just shipped.

## §1 was a read, not a build — and two of its premises did not hold

The question was whether `sn-normalize-v2` is the active algorithm for new
records, because a scheduled note carries a `signal-noise/sidenote` whose text
v1 would strip out of the signed payload. The instruction was explicit: if v2
is not active, do not attempt a plugin fix under time pressure; revert the
note's sidenote instead.

It is active, on five independent lines: the constant is v2, the bearing-fields
builder calls `sn_prov_normalize_v2()` with no branch, every record written that
day carries `algo: sn-normalize-v2`, the v1 branch only decides *coalescing* and
its second condition exists precisely to stop a sidenote addition from
coalescing, and a live five-leg from-page verify passes including served-page
byte-equality. Later I also ran the ledger's own parity suite, which executes
the PHP as an oracle against the JS mirror — 48 tests, agreeing.

Two premises in the brief were wrong, and one of them I got wrong too:

- The note named as "already published and already carrying a sidenote" 404s.
  I reported it as not existing. It exists — post 2535, status `future`,
  publishing **September 6**. I concluded absence from a scoped read instead of
  checking the scheduled queue. That moves the first live sidenote test four
  days earlier than the brief assumed.
- `pending.json` was described as holding six entries with one unexplained. It
  holds five, and the sixth had drained because it anchored: `52781acb…` is
  `nobody-can-sign-an-absence`, a note, per-note anchor, OTS confirmed, Bitcoin
  block 965180. Not an orphan.

## The correction notice: right colours, foreign shape

`.sn-correction` shipped as v12.18.3. Every token was correct, the contrast
cleared AA in all three palettes the theme serves — including High Contrast,
which is the one the live site actually runs and which a root+dark check would
have missed entirely.

Then the owner asked whether the lighter inset block was a detour from the
brutalist style. It was, and the audit found exactly which part. Every
asphalt-filled block *inside prose* breaks the measure and carries hard rules
top and bottom — pull-quote, compare-columns, steps-enumerated, all
`left: -1rem; width: calc(100% + 2rem)`. Mine was the only element in the
corpus that was inset with a left rail. That shape is the docs-admonition
idiom, imported from generic web design without my noticing I had imported
anything.

Four guards had passed it — contrast, inverts, dark-mode, design-tokens —
because every one checks what a rule is PAINTED with. Nothing checked what it
is SHAPED like, which is where a house style actually lives. v12.18.4 reshaped
it and added `tests/prose-slab-idiom.php`, negative-controlled with a
correctly-coloured, wrongly-shaped panel, because a colour-wrong control would
prove nothing about a shape rule.

The brief had pointed straight at this and I missed it: it called
`.sn-correction` the sidenote's cousin, and the sidenote has no fill at all. I
read that as a statement about tokens.

## §2.1 — the exclusion comment was right, and narrower than it sounded

`payload.edits` already batched N edits since v10.66.0, but only within one
type; `block_insert` was refused by name because "block edits interact through
tag structure in ways the prose batch's byte-range overlap check cannot see."

True, and narrow. Nesting is covered for free — a prose span inside a replaced
block starts within that block's range. The real gap is **zero-width claims**:
two inserts at one point never overlap and their order is undefined, and an
insert at the leading or trailing edge of a replaced span passes a range test
while its anchor is being destroyed.

Four conflict rules, each mutation-proven. Two only became rules that way:

- My first mutation batch **never applied** — I wrote `$prev[ 'start' ]`, the
  source has `$prev['start']` — and every test still printed PASS. That reads
  exactly like "rules verified". Only the assert caught it.
- With mutations actually landing, rule (b) turned out **not load-bearing**:
  rule (c) caught my test's ordering. It is the only rule that fires when the
  insert is listed BEFORE the replace, where `cur.start < prev.start + 0` is
  false. Without that added case it could have been deleted on a green suite.

The claim I asserted is the write count, not the content: three changes cost
one `wp_update_post()`, against one write each unbatched. Asserting the content
came out right would have passed on three writes, which is the bug.

The scheduled-post guarantee mattered more than the batching. Rather than copy
it — the way safety code drifts — I extracted it so both paths share one
implementation, with the two large existing suites as the net. One caught a
missing dependency immediately. `tests/batch-schedule.php` went red on a pure
move, because it read `sn-apply-block-edit.php` as TEXT to assert the rule lived
there: a guard naming a FILE for what is a property of the LAYER. It now pins
the rule where it lives AND that block-edit still calls it.

## §2.2 — the harness defects mattered more than the feature

`create_draft` now takes `meta_description` and `og_card_title`, validated in
the same gate-2 pass by the identical validators `surfaces` runs.

Reading the validator corrected the premise: the 60–90 range is a **warning**;
only empty-or-over-cap refuses. So the failure that cost a third call was the
hard cap, not the guideline.

Two harness defects surfaced. `get_post_meta`/`update_post_meta` in that suite
were **count-only** — the writer stored nothing, the reader always answered
`''`. Adequate while `create_draft` wrote no meta; the moment it does, that
stub lets "wrote the wrong key" pass. My first run failed on exactly that: two
meta writes counted, both reads empty. And the `$wpdb` stub the collision check
needs was lifted byte-identically from the delegation sweep rather than
re-written, so the two suites cannot disagree about the same SQL.

## The one I would not have found by reading

After the app restart I drove the batch door live, to confirm the new
`change.type` was reachable through a fresh schema. It was. Then I drove the
conflict path, and the refusal envelope was wrong twice:

- `gates.fingerprint.passed: false` with `expected` and `observed`
  **identical**. Gate 1 does double duty for the batching types — it proves the
  hash and runs the planner — so a planner refusal came back through the
  fingerprint channel. A contradiction that sends the caller to re-fetch a hash
  nothing was wrong with. This predates my work: `payload.edits` has had the
  same shape since v10.66.0.
- The diff reported `changes_applied: 2` and `ledger_impact: "coalesces"` on a
  batch that applied zero. The dry-run diff resolved `after` as
  `new_content ?? before`, so a refused plan diffed the post against itself.

Nothing was ever written — the all-or-nothing property held. The READOUT could
not be told apart from a benign restructure. v13.95.1 fixed both, and
`BATCH.12`–`BATCH.22` pin the envelope. Every prior assertion had checked the
refusal CODE and none the shape around it, which is why a correct refusal
wearing a successful-looking readout passed cleanly.

## Four times my instrument was the artifact

Worth listing, because it kept happening:

1. `pending.json` read as **empty** — the ledger clone was 39 commits and five
   days stale. The Worker owns the remote.
2. A `path=$(...)` assignment silently wiped `PATH`; zsh ties `path` to it. The
   loop then reported "record not in tree" for every record.
3. The correction paragraph read as **absent** from the live page — WordPress
   emits `href='…'` with single quotes and I searched for `href="`.
4. A release verification read `full-bleed=0` — my `grep -A6` window was
   smaller than the rule's own comment block.

Each looked like a finding. None was.

## The pattern, now written down

Five defects this session, one shape: a readout that cannot distinguish two
states, one benign and one not, and reports the benign one. The cache verdict
("not yet verified" from "stale"), the integrity watch ("clean" from "never
sampled"), the refusal envelope twice, the meta stubs. Every fix was the same
move — add the field that separates the cases, never change the machinery.

Saved as `a-readout-that-cannot-separate-two-states`, indexed at the top of the
recurring-traps section.

## Where it stands

Theme 12.18.2 → **12.18.4**. Plugin 13.92.0 → **13.95.1**. Seven releases, each
verified on six points: marker line, annotated tag equal to `origin/main`,
version files, draft release bound, parity `code=tagged`, change present in the
tagged tree. Theme suite 115/3055, plugin 555/22429, zero failures.

No worker was touched, and none needed to be — verified across all four repos.
The remote door is read-only by construction and exposes neither `sn_apply` nor
`watches`, so nothing shipped here reaches it.

Due, none of it needing action now:

- **September 6** — post 2535 publishes. If it carries the sidenote, it is the
  first live one, and `verify.mjs --from-page` on it closes §1 for good.
- **September 10** — post 2631 publishes.
- **OpenStation** — PR #717 is an ancestor of trunk, so any tag carries it.
  v1.1.5 remains the latest release; the fix missed it by ten hours. The owner
  has a direct line to the maintainer and asked for no local mitigation. The
  acceptance test is windows revealing WITHOUT the console paste.
- The integrity watch surfaces itself in `sn-status{watches}` once the sweep
  re-covers the fleet. It is still correctly refusing to report: the last sweep
  ran 19:33 UTC, three and a half hours before the silent DB write it exists to
  check.

## What I could not do

The browser pane's content reads — screenshot, `read_page`, `get_page_text`,
`find`, JS — were gated the entire session with `Policy check temporarily
unavailable`, while `navigate`, `tabs_context` and `resize_window` worked. The
Chrome extension was not connected. So every visual check fell to the owner. I
verified the CSS byte-for-byte from the served stylesheet and measured contrast
against the palette the site actually serves, and said plainly that this is not
the same as having looked at it.
