"""The commands of tools/factory/job that move a job between stages."""
import inspect, os, sys, time

from .base import CFG, DRY, JOBS, die, gh, gh_json, path
from .evidence import evidence
from .intake import surfaces
from .issue import cmd_new, cmd_refresh
from .ready import cmd_ready
from .state import MOVES, TERMINAL, WAITING, load, read, save
from .worktree import cmd_where, cmd_worktree


def leave_ready(j, to):
    """The GitHub side of leaving `ready`. Rework takes the pull request back
    to a draft; merged is only recorded once GitHub says it happened."""
    if to == "build":
        gh("pr", "ready", j["branch"], "--undo")
        gh("issue", "edit", j["id"], "--remove-label", "factory:ready")
    if to == "merged" and not DRY:
        state = gh_json("pr", "view", j["branch"], "--json", "state").get("state")
        if state != "MERGED":
            die("the pull request for %s is %s on GitHub, not MERGED" % (j["branch"], state))


def cmd_advance(job, to):
    j = load(job)
    job = j["id"]
    if j["stage"] in TERMINAL:
        die("job %s is %s" % (job, j["stage"]))
    if to == "needs-human":
        die("use `job escalate %s <reason>`: it tells the owner and records when the job stopped" % job)
    if to not in MOVES.get(j["stage"], set()):
        die("%s -> %s is not a legal move (from %s: %s)" % (j["stage"], to, j["stage"], ", ".join(sorted(MOVES[j["stage"]]))))
    why = evidence(j, to)
    if why:
        die(why)
    if to == "build" and j["stage"] in ("gate", "review"):  # a failed round costs one
        if j["round"] >= CFG["max_rounds"]:
            die("round limit (%d) used up: escalate" % CFG["max_rounds"])
        j["round"] += 1
    if j["stage"] == "ready":
        leave_ready(j, to)
        j.pop("announced", None)  # whatever comes next is announced afresh
    if to in ("build", "spec") and j["stage"] in WAITING:
        # The owner's answer grants a fresh set of rounds. The old rounds are
        # archived, so no earlier gate or review can stand in for a new one.
        old = [d for d in os.listdir(path(job)) if d.startswith("round-")]
        if old:
            arc = path(job, "archive-%d" % int(time.time()))
            os.makedirs(arc)
            for d in old:
                os.rename(path(job, d), os.path.join(arc, d))
        j["round"] = 1
        if "public" in (surfaces(job)[0] or ()):
            # Their answer was to a job that named a public surface: that yes
            # is kept, and an answer about anything else is not one.
            j["public_ok"] = int(time.time())
    os.makedirs(path(job, "round-%d" % j["round"]), exist_ok=True)
    j["stage"] = to
    if to == "ready":
        j["stopped"] = int(time.time())  # every way into a waiting state records when
    save(j)
    print("job %s: %s (round %d)" % (job, to, j["round"]))


def cmd_escalate(job, reason):
    j = load(job)
    if "needs-human" not in MOVES.get(j["stage"], set()):
        die("cannot escalate from " + j["stage"])
    gh("issue", "edit", j["id"], "--add-label", "factory:needs-human")
    gh("issue", "comment", j["id"], "--body", "Factory stopped and needs you: " + reason)
    j["stage"], j["stopped"] = "needs-human", int(time.time())
    save(j)
    print("job %s: needs-human" % j["id"])


def cmd_status(job=None):
    if job:  # one job: the same checked read as every other command
        j = load(job)
        return print("%s\t%s\tround %d\t%s" % (j["id"], j["stage"], j["round"], j["worktree"]))
    for d in sorted(os.listdir(JOBS)) if os.path.isdir(JOBS) else []:
        if not os.path.isfile(path(d, "job.json")):
            continue
        j, why = read(d)
        if j is None:
            print("factory: skipping job %s: %s" % (d, why), file=sys.stderr)
        else:
            print("%s\t%s\tround %d\t%s%s" % (j["id"], j["stage"], j["round"], j["worktree"], "\tdry" if j.get("dry") else ""))


def main(argv, usage):
    cmds = {"new": cmd_new, "refresh": cmd_refresh, "advance": cmd_advance, "escalate": cmd_escalate, "ready": cmd_ready,
            "worktree": cmd_worktree, "where": cmd_where, "status": cmd_status}
    if not argv or argv[0] not in cmds:
        die(usage.split("Usage:")[1].strip())
    try:
        inspect.signature(cmds[argv[0]]).bind(*argv[1:])
    except TypeError:
        die("wrong arguments for " + argv[0])
    try:
        cmds[argv[0]](*argv[1:])
    except Exception as e:  # a refusal is one line, whatever went wrong
        die("%s stopped on something unexpected (%s: %s); nothing more was done" % (argv[0], type(e).__name__, e))
