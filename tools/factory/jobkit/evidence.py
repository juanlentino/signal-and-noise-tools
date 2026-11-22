"""The legal stage moves and the evidence each one needs."""
import os

from .base import first_line, head, lines, path
from .intake import intake_problem, surfaces
from .issue import owner_answered

# The legal moves. `ready` is where the pilot stops: a person merges.
MOVES = {
    "intake": {"spec", "needs-info", "rejected", "needs-human"},
    "needs-info": {"intake", "abandoned"},
    "spec": {"build", "needs-human", "abandoned"},
    "build": {"gate", "needs-human"},
    "gate": {"review", "build", "needs-human"},
    "review": {"ready", "build", "needs-human"},
    "needs-human": {"spec", "build", "abandoned"},
    "ready": {"build", "merged", "abandoned"},
}
TERMINAL = {"merged", "rejected", "abandoned"}
WAITING = ("needs-human", "ready")


def gate_problem(j):
    rnd, sha = "round-%d" % j["round"], head(j)
    if not sha:
        return "the job has no worktree HEAD to check (run `job worktree`: the path must be a git worktree on %s)" % j["branch"]
    if first_line(j["id"], rnd, "checks.md") != "PASS " + sha:
        return rnd + "/checks.md must open with PASS and the worktree's HEAD (run tools/factory/gate)"
    return ""


def ready_problem(j):
    """Why the job's HEAD is not ready, or ''. `advance <id> ready` and
    `job ready` both ask here, so the two cannot drift apart."""
    why = gate_problem(j)
    if why:
        return why
    rnd, sha = "round-%d" % j["round"], head(j)
    review = lines(j["id"], rnd, "review.md")
    if review[:1] != ["VERDICT: PASS " + sha]:
        return rnd + "/review.md must open with VERDICT: PASS and the HEAD it reviewed"
    found, why = surfaces(j["id"])
    if found is None:
        return why
    if found & {"security", "data"} and review[1:2] != ["SECURITY: PASS " + sha]:
        return rnd + "/review.md needs the second line SECURITY: PASS and the same HEAD: intake names security or data, so a second reviewer must pass it"
    return ""


def evidence(j, to):
    """What must exist before a move. Returns a reason to refuse, or ''."""
    job, rnd = j["id"], "round-%d" % j["round"]
    waiting = j["stage"] in WAITING
    if waiting and to in ("spec", "build") and not owner_answered(j):
        return "the job is waiting for the owner: no owner comment on the issue since it stopped"
    if to in ("spec", "build"):  # no way to spec or build skips intake
        why = intake_problem(job, waiting or j.get("public_ok"))
        if why:
            return why
    if to == "build" and not os.path.isfile(path(job, "spec.md")):
        return "spec.md is missing"
    if to == "gate" and first_line(job, rnd, "build.md") != "STATUS: READY":
        return rnd + "/build.md must open with STATUS: READY"
    if to == "review":
        return gate_problem(j)
    if to == "ready":
        return ready_problem(j)
    return ""
