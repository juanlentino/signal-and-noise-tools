"""The legal stage moves and the evidence each one needs."""
import json, os, re, time

from .base import CFG, DRY, first_line, gh, head, path

# The legal moves. `ready` is where the pilot stops: a person merges.
MOVES = {
    "intake": {"spec", "needs-info", "rejected", "needs-human"},
    "needs-info": {"intake", "abandoned"},
    "spec": {"build"},
    "build": {"gate", "needs-human"},
    "gate": {"review", "build", "needs-human"},
    "review": {"ready", "build", "needs-human"},
    "needs-human": {"spec", "build", "abandoned"},
    "ready": {"build", "merged", "abandoned"},
}
TERMINAL = {"merged", "rejected", "abandoned"}


def owner_answered(j):
    """True when the owner commented on the issue after the job stopped.
    The factory's own comments (same account) open with "Factory" and never count."""
    if DRY:
        return bool(j.get("stopped")) and os.environ.get("FACTORY_OWNER_OK") == "1"
    data = json.loads(gh("issue", "view", j["id"], "--json", "comments", capture=True))
    if not j.get("stopped"):
        return False  # no recorded stop: nothing can count as an answer to it
    since = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(j["stopped"]))
    return any(c["author"]["login"] == CFG["owner"] and c["createdAt"] > since and not c["body"].lstrip().startswith("Factory")
               for c in data["comments"])


def evidence(j, to):
    """What must exist before a move. Returns a reason to refuse, or ''."""
    job, rnd, sha = j["id"], "round-%d" % j["round"], head(j)
    stopped = j["stage"] in ("needs-human", "ready")
    if stopped and to in ("spec", "build") and not owner_answered(j):
        return "the job is waiting for the owner: no owner comment on the issue since it stopped"
    if to == "spec":
        line = first_line(job, "intake.md")
        if line != "DECISION: ACCEPT":
            return "intake.md must open with DECISION: ACCEPT (it says %r)" % line
        m = re.search(r"^\W*SURFACES\W*:(.*)$", open(path(job, "intake.md")).read(), re.M | re.I)
        if not m:
            return "intake.md has no SURFACES line"  # fail closed
        if "public" in m.group(1).lower() and not stopped:
            return "intake names a public surface: escalate for the owner's yes first"
    if to == "build" and not os.path.isfile(path(job, "spec.md")):
        return "spec.md is missing"
    if to == "gate" and first_line(job, rnd, "build.md") != "STATUS: READY":
        return rnd + "/build.md must open with STATUS: READY"
    if to in ("review", "ready"):
        if not sha:
            return "the job has no worktree HEAD to check"
        if first_line(job, rnd, "checks.md") != "PASS " + sha:
            return rnd + "/checks.md must open with PASS and the worktree's HEAD (run tools/factory/gate)"
    if to == "ready" and first_line(job, rnd, "review.md") != "VERDICT: PASS " + sha:
        return rnd + "/review.md must open with VERDICT: PASS and the HEAD it reviewed"
    return ""
