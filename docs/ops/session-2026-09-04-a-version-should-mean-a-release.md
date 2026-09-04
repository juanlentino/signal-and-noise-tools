# Session — 2026-09-04: a version should mean a release

Third arc of the day, and the only one that shipped no features. The first two
are `session-2026-09-04-the-things-that-come-due.md` (the watch system) and
`session-2026-09-04-found-by-using-it.md` (the audit and seven releases). This
one is about the habit those seven releases exposed.

The brief was explicit: a process and packaging arc, four phases, stop after
each. Six PRs across two repos, seven issues, no version bump anywhere, and
v13.96.0 deliberately not shipped.

## What was actually wrong

The plugin's `CHANGELOG.md` was **26,309 lines and 898 version headings**. The
theme's was 8,191 and 424. And `docs/VERSIONING.md` — the document that produced
the habit — instructed a session to bump `Version:` in `style.css` as step 2 of
every change, said mid-session per-commit bumps were fine, and closed with
**"Bump it. The cost of an extra version is essentially zero."**

That cost is not zero. It is a number that marks sittings rather than shipments,
and a file nobody can read.

The fix is not smaller changelogs. It is that a PR closes an issue and adds an
`## [Unreleased]` bullet, and a release is a separate deliberate act:
`tools/cut-release.sh`, which stamps the version, promotes Unreleased, archives
the previous cut, and PRINTS the tag command rather than running it.

## Phase 0 — the plan

Labels (12 per repo), an `Unreleased` milestone, seven issues, three issue
templates per repo, and the VERSIONING rewrite.

The Project board was blocked at first: `gh` lacked the `read:project` scope, so
I could neither create a Project nor **list** existing ones — and listing is what
would have proved I was not about to make a second board. I created nothing and
said so. After the owner refreshed the scope it took one call to confirm zero
existing projects, then Status became Proposed / In progress / In review / Done
via GraphQL, since the CLI cannot edit a single-select field's options.

One judgement call beyond the brief: `VERSIONING.md`'s "What does and does NOT
bump version" table answered *"Bump? Yes"* for any code change. Under the new
rule that question no longer arises in a PR, so the table would have contradicted
the workflow section two screens later. I reframed it as "What is shippable" —
same rows, same verdicts, different question — and flagged it for review rather
than performing it quietly.

## Phase 1 — the archives, and three of my own bugs

`git mv` moved both changelogs under `docs/changelog/`, bodies byte-identical
under a frozen header. Root files became 84 and 77 lines.

Then `tools/cut-release.sh`, and this is where the session earned its title.

**`--dry-run` passing proved nothing.** The first real run left the version at
13.95.1 and Unreleased intact while printing every success line. The cause: I
named an awk variable `next`, which is an awk **statement**. It silently broke
both the version substitution and the changelog rewrite — and `--dry-run`
exercises neither, so it was green over two dead code paths.

**A hardcoded line count that I then invalidated myself.**
`ARCHIVE_HEADER_LINES=11` was correct when written; I later edited the header to
14 lines, which would have spliced a release section into the middle of a
sentence. The splice point is now *found* with `grep -n -m1 '^## \['`.

**The spec's own instruction duplicates a section.** It says to *copy* the latest
cut into the root. Taken literally the section then lives in root AND archive,
and the first `cut-release` appends a second copy — which the first real run
produced: 899 headings, `13.95.1` twice. Root now holds the current cut
exclusively, and a guard refuses to archive a heading the archive already carries.

**And my test harness ate three fixes.** I reverted each trial with
`git reset --hard $SAFE && git reset --hard HEAD~1` — the second of which walks
back *past* the safe point. Three fixes were destroyed in sequence before I read
`git log` instead of trusting my own commands. Every "fix" I had confidently
applied was gone, and the same bug kept reappearing looking like a new one.

The theme's variant has one extra job: the version lives in `style.css` **and**
`readme.txt`, which CI already fails on when they disagree (it did at v10.28.1
and again at v10.29.0). The script edits both and refuses to cut if they have
already drifted, because cutting on top of a drift buries it inside the release
diff.

## Phase 1 — the workers, skipped with numbers

Five Worker repos, and the honest answer was **skip all five**. The largest
worker changelog is 6% of the plugin's; the busiest worker day carries 5 tags
against the plugin's 30 on 2026-08-27. A worker tag is a `wrangler deploy` — a
shipment, not a sitting.

Getting to that table took two wrong instruments, and both would have produced a
confident false answer:

1. **Release `published_at` returned nothing** for two repos — because most
   releases here are DRAFTS, and a draft has no publication date. That is this
   estate's own "drafts stay drafts" rule, biting the measurement built on top
   of it. Switched to tag commit dates via GraphQL.
2. **Entry counts all read 0** on the first pass, because my pattern assumed
   `## [1.2.3]`. These repos write `## 1.2.3 - date`, without brackets. A repo
   with 28 tags reporting zero changelog entries is a claim about the regex.

## Phase 2 — the envelope helper

`tests/lib/assert-envelope.php`, the check that was missing when v13.95.1
shipped. Not swept (`run.sh` globs `tests/*.php` non-recursively), borrows each
suite's own `ok`/`eq`.

The contract was **derived from `inc/abilities-sn-apply.php`**, not assumed, and
the reading changed the design: the ability has TWO refusal paths. A single
target's refusal becomes a `WP_Error` whose message is the JSON envelope; a batch
target's stays a plain array inside `results[]` so one failure cannot abort the
loop. I pinned the array path in the only suite that exercises it — without that
the helper would have been proven against one shape and assumed correct for the
other.

Success is split too. A dry run and an applied write are different shapes, and a
dry run is not a refusal: both carry `applied:false`, only one carries `error`.

Two citation errors in the issue text, both worth checking rather than trusting:
BATCH.12-22 live in `tests/abilities-sn-apply-block-edit.php`, not
`tests/sn-apply-batch-edits.php` — both files exist, so it looked right. And
`sn-apply-batch-edits.php` is deliberately NOT wired: it tests the planner, whose
`WP_Error` carries a plain string rather than an envelope.

## Phase 3 — the package, and a bug in my own guard

Thirteen files into `inc/sn-apply/` by `git mv`. `inc/abilities-sn-apply.php`
stays at its old public path as the loader. Bootstrap requires for the family:
13 to 0.

The surface-coverage guard was written BEFORE the first `git mv` and made
layout-agnostic — it detects the arrangement rather than assuming one. The
identical guard ran green on the flat layout and then on the packaged one, so
the move had to prove it changed nothing.

It caught a bug in itself on the first packaged run: `revision.php (x2)`. Not a
duplicate require — `revision.php` matches as a **substring** of
`restore-revision.php`. The basename match now requires a preceding `/` or quote.
Same shape as the `.sn-notes-wayfinding` trap this codebase has hit before, and a
guard reporting a phantom failure is one step from one reporting a phantom pass.

## Merging — a MERGEABLE label is not a working merge

Both conflicts I predicted in the PR bodies materialised. #980 hit the
`CHANGELOG.md` collision with #978 and needed a real rebase: I took #978's
archived file and moved the bullet under ITS `## [Unreleased]` heading, so the
entry sits where the new rule 2 actually looks.

The second was subtler. After #979 merged, GitHub reported #980 **MERGEABLE** —
but #979 had inserted `require_once .../lib/assert-envelope.php` beside the exact
SUT require lines #980 rewrote for the new paths. A clean textual merge there
could still produce a broken require. I trial-merged locally: 13 requires
resolved, 3 envelope requires survived, and the sweep came out at **22,463 =
22,437 + 26**, which is the arithmetic that had to hold.

## Where it landed

Six PRs merged, seven issues closed, board all Done. Plugin **13.95.1**, theme
**12.18.4 / 12.18.4**, zero `v13.96*` tags, nothing deployed.

Final sweep on merged main: **556 suites, 22,463 assertions, 0 failed, 1
skipped**. Surface guard 8/8. Envelope self-test 5/5. Zero stale
`inc/sn-apply-*.php` paths in live code.

## Left undone, deliberately

- v1-v12 are not split out of `docs/changelog/v13.md`, nor the theme's earlier
  majors out of `v12.md`.
- `CHANGELOG.md` and three `docs/` files keep the old `inc/sn-apply-*.php`
  paths. They are records of what was true when written; rewriting them is the
  same mistake as rewriting a changelog entry.
- Pre-existing non-ASCII in `run:` block comments, both repos.
- All five Workers, with the numbers on #975 rather than an impression.

## The through-line, again

Every phase produced at least one instrument that lied: a dry run that passed
over dead code, a `published_at` that was empty because the releases were drafts,
a regex that assumed the wrong heading style, a substring match without a
boundary, a revert sequence that walked past its own safe point, a `MERGEABLE`
label that had not been asked the right question. Not one was a bug in the thing
being measured.

That is the same pattern the earlier arc today wrote down as
`a-readout-that-cannot-separate-two-states`. This session adds the tooling
corollary: **the check that passes is the one to distrust when it never had a way
to fail.**
