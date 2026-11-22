# The plugin factory (pilot)

A maintainer's tool, not part of the plugin: `tools/` is export-ignored, so
nothing here ships. Modeled on OpenStation's software factory (GPL-2.0),
cut down for one maintainer.

It turns a GitHub issue labeled `factory` into a reviewed pull request and
**stops at ready to merge**. It never merges and never cuts a release.

| File | What it is |
|---|---|
| `SKILL.md` | The orchestrator a Claude Code session follows (`/factory run`). |
| `job` | State and rules: one folder per job in `jobs/` (gitignored), a fixed table of legal stage moves, the evidence each move needs, and every GitHub side effect. |
| `gate` | The checks before review: clean tree, `php -l`, a CHANGELOG bullet, no em dash, a touched test, and `tests/run.sh` (the CI sweep). |
| `config.json` | Repository, owner, base branch, round limit. |

## Stages

```
intake      -> spec | needs-info | rejected | needs-human
needs-info  -> intake | abandoned
spec        -> build
build       -> gate | needs-human
gate        -> review | build | needs-human
review      -> ready | build | needs-human
needs-human -> spec | build | abandoned
ready       -> build | merged | abandoned
```

A failed gate or a review asking for changes costs a round. After
`max_rounds` the job must be escalated. An issue whose intake names a
`public` surface cannot reach `spec` without the owner: it is escalated.

A job at `needs-human` or `ready` restarts only when the owner has commented
on the issue since it stopped (the factory's own comments open with
"Factory" and do not count). The restart archives the earlier rounds, so no
old gate or review can stand in for a new one. The gate and the review are
both tied to a commit: `ready` needs both on the worktree's HEAD, and the
pushed branch must be that commit.

## Install the skill

`.claude/` is gitignored here, so the skill is linked from the user's skills:

```bash
mkdir -p ~/.claude/skills/factory && ln -sf "$PWD/tools/factory/SKILL.md" ~/.claude/skills/factory/SKILL.md
```

Then, in a session opened on this repository: `/factory run <issue>`.

Test: `php tests/factory-job.php`.
