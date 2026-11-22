# The plugin factory (pilot)

A maintainer's tool, not part of the plugin: `tools/` is export-ignored, so
nothing here ships. Modeled on OpenStation's software factory (GPL-2.0),
cut down for one maintainer.

It turns a GitHub issue labeled `factory` into a reviewed pull request and
**stops at ready to merge**. It never merges and never cuts a release.

| File | What it is |
|---|---|
| `SKILL.md` | The orchestrator a Claude Code session follows (`/factory run`). |
| `job` | State and rules: one folder per job in `jobs/` (gitignored), a fixed table of legal stage moves, the evidence each move needs, and every GitHub side effect. The entry point; its code is the `jobkit/` package beside it. |
| `gate` | The checks before review: clean tree, `php -l` on every changed PHP file, a bullet the diff adds inside CHANGELOG's one `## [Unreleased]` section, no em dash on an added line, a test added or modified, `tests/run.sh` untouched and no test deleted or renamed away, no change to any `.gitattributes`, and the sweep itself, run with the base branch's copy of `tests/run.sh`. The base is always `refs/remotes/origin/<base>`. |
| `config.json` | Repository, owner, base branch, round limit. |

## Stages

```
intake      -> spec | needs-info | rejected | needs-human
needs-info  -> intake | abandoned
spec        -> build | needs-human | abandoned
build       -> gate | needs-human
gate        -> review | build | needs-human
review      -> ready | build | needs-human
needs-human -> spec | build | abandoned
ready       -> build | merged | abandoned
```

A failed gate or a review asking for changes costs a round. After
`max_rounds` the job must be escalated. `needs-human` is entered only through
`job escalate`, which labels the issue, tells the owner and records the stop.

What `job` checks before a move:

- **to spec or build, from any stage:** `intake.md` opens with
  `DECISION: ACCEPT` and exactly one line starts with SURFACES or contains
  `SURFACES:`. It reads
  `SURFACES: a, b`, with every word from `public`, `admin`, `security`,
  `data`, `none` (`none` alone), no empty item, and no list item or bare
  surface word on the next non-empty line. Anything else is refused.
  `public` is refused unless the job is restarting from a
  stop with the owner's answer, or did so earlier while intake named `public`.
  A move to build also needs `spec.md`.
- **to gate:** the round's `build.md` opens with `STATUS: READY`.
- **the gate itself:** it runs only at stage `gate`, and once this round's
  `checks.md` opens with `FAIL` it refuses to run again: a failed gate goes
  through `advance <id> build`, which costs a round. A `PASS` may be rerun,
  and a `PASS` for an older HEAD is run afresh. A missing base ref writes
  `ERROR`, which is no verdict and costs nothing. An added line with an em
  dash, as the character or as its HTML entity (named or numeric), fails.
- **to review:** the round's `checks.md` opens with `PASS` and the worktree's
  HEAD. The worktree must be a git worktree on the job's branch.
- **to ready:** the same, plus the round's `review.md` opens with
  `VERDICT: PASS` and that HEAD, and, when intake names `security` or `data`,
  its second line is `SECURITY: PASS` and that HEAD.
- **`job ready`:** the to-ready check again, then GitHub: the worktree's
  origin is this repository, the pull request is open, its head on GitHub is
  that commit, and `gh pr checks` is all green. Only then is the pull request
  marked ready and the owner told, once per commit: a second run says there
  is nothing to do and posts nothing.
- **ready to build (rework):** the owner's answer; the pull request goes back
  to draft and the `factory:ready` label comes off.
- **ready to merged:** GitHub says the pull request is merged.
- **`job new`:** the issue is open, labeled `factory`, and not already labeled
  `factory:needs-human` or `factory:ready`.

Two stops, by design. Stage `ready` is the evidence on disk: it can be
reached unpushed or with red CI, because `advance` never asks GitHub.
`job ready` is the evidence on GitHub, and nothing is announced without it.

Every command reads a job the same way: the id is a positive whole number,
the folder is not a symlink, `job.json` has the right shape (or the command
refuses in one line), and the job's worktree and branch are worked out from
the id (`<root>/.claude/worktrees/factory-<id>`, `factory/<id>`), never read
from the file.

A job at `needs-human` or `ready` restarts only when the owner has commented
on the issue since it stopped (the factory's own comments open with
"Factory" and do not count). The restart archives the earlier rounds, so no
old gate or review can stand in for a new one. `job refresh <id>` rewrites
`issue.md` with the owner's comments, quoted as data, for the builder to read.

## Known limits

- **Any comment from the owner after a stop counts as the go-ahead.** The
  tool does not judge what the comment means. The orchestrator reads the
  answer (from the refreshed `issue.md`) and must not restart on a no.
- **Job state is local to one checkout.** `jobs/` is gitignored. Another
  checkout cannot see a job, which is why `job new` refuses an issue that
  already carries a stop label.
- **The tool cannot know who wrote a file.** `intake.md`, `checks.md` and
  `review.md` are trusted as written; `job` only ties them to a commit.
- **A stage set by hand in `job.json` is not detected.** The file's shape is
  checked, its history is not.
- **An edited Unreleased bullet satisfies the changelog check.** The gate
  looks for a bullet line the diff adds inside the section; rewording an
  existing bullet adds such a line.
- **The gate does not fetch.** It compares against the local copy of
  `origin/<base>`, which may be behind. `job ready`'s CI check on GitHub is
  the backstop.
- **A gutted test that still prints its summary passes the gate.** The gate
  runs the branch's own suites and helpers (with the base branch's runner),
  and, when the shared `.wp-core` copy is missing, the branch's own
  `tools/fetch-wp-test-core.sh`. A reviewer reads the test changes.
- `FACTORY_DRY=1`, `FACTORY_OWNER_OK=1` and `FACTORY_ROOT` are test seams.
  `FACTORY_DRY` is exactly `1` or unset; any other value is refused.
  A dry run refuses the real jobs folder; a job made in a dry run is marked
  `"dry": true` and a live run refuses it, as a dry run refuses a live job;
  `FACTORY_OWNER_OK` and `FACTORY_ROOT` are read only in a dry run.

## Install the skill

`.claude/` is gitignored here, so the skill is linked from the user's skills:

```bash
mkdir -p ~/.claude/skills/factory && ln -sf "$PWD/tools/factory/SKILL.md" ~/.claude/skills/factory/SKILL.md
```

Then, in a session opened on this repository: `/factory run <issue>`.

Test: `php tests/factory-job.php`.
