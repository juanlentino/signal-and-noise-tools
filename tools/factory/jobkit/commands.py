"""The commands of tools/factory/job that move a job between stages."""
import inspect, json, os, subprocess, sys, time

from .base import CFG, DRY, JOBS, die, gh, gh_try, git, head, load, path, save
from .evidence import MOVES, TERMINAL, WAITING, evidence, ready_problem
from .intake import surfaces
from .issue import cmd_new, cmd_refresh
from .worktree import cmd_worktree


def leave_ready(j, to):
    """The GitHub side of leaving `ready`. Rework takes the pull request back
    to a draft; merged is only recorded once GitHub says it happened."""
    if to == "build":
        gh("pr", "ready", j["branch"], "--undo")
        gh("issue", "edit", j["id"], "--remove-label", "factory:ready")
    if to == "merged" and not DRY:
        try:
            state = json.loads(gh("pr", "view", j["branch"], "--json", "state")).get("state")
        except ValueError:
            state = "unknown"
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
        die("%s -> %s is not a legal move (from %s: %s)" % (j["stage"], to, j["stage"], ", ".join(sorted(MOVES.get(j["stage"], [])))))
    why = evidence(j, to)
    if why:
        die(why)
    if to == "build" and j["stage"] in ("gate", "review"):  # a failed round costs one
        if j["round"] >= CFG["max_rounds"]:
            die("round limit (%d) used up: escalate" % CFG["max_rounds"])
        j["round"] += 1
    if j["stage"] == "ready":
        leave_ready(j, to)
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


def cmd_ready(job):
    """Mark the pull request ready. Safe to run again: it re-checks everything
    and never moves the stop recorded when the stage became ready."""
    j = load(job)
    if j["stage"] != "ready":
        die("job %s is at %s, not ready" % (job, j["stage"]))
    why = ready_problem(j)  # a commit after `advance ready` was neither gated nor reviewed
    if why:
        die(why)
    if not DRY:  # the pull request's head must be that commit, and its CI green
        wt, br = j["worktree"], j["branch"]
        if subprocess.run(["git", "-C", wt, "fetch", "-q", "origin", br]).returncode != 0:
            die("could not fetch origin/%s, so the pushed head is unknown" % br)
        remote = git(wt, "rev-parse", "origin/" + br)
        if remote != head(j):
            die("the pushed branch (%s) is not the gated HEAD (%s): push, or run the gate again" % (remote[:8] or "unknown", head(j)[:8]))
        code = gh_try("pr", "checks", br)[0]
        if code != 0:
            die("the pull request's checks are not all green (`gh pr checks %s` exited %d): wait for CI, or send the job back to build" % (br, code))
    gh("pr", "ready", j["branch"])
    gh("issue", "edit", j["id"], "--add-label", "factory:ready", "--remove-label", "factory:needs-human")
    gh("issue", "comment", j["id"], "--body", "Factory: the pull request passed the gate and review on %s and is ready for you to merge. The factory does not merge or cut." % head(j)[:8])
    print("job %s: ready to merge (the owner merges)" % j["id"])


def cmd_status(job=None):
    for d in ([str(job)] if job else sorted(os.listdir(JOBS)) if os.path.isdir(JOBS) else []):
        if not os.path.isfile(path(d, "job.json")):
            continue
        try:
            j = json.load(open(path(d, "job.json")))
            print("%s\t%s\tround %d\t%s" % (j["id"], j["stage"], j["round"], j.get("worktree", "")))
        except (OSError, ValueError, KeyError, TypeError):
            print("factory: skipping job %s: its job.json is unreadable" % d, file=sys.stderr)


def main(argv, usage):
    cmds = {"new": cmd_new, "refresh": cmd_refresh, "advance": cmd_advance, "escalate": cmd_escalate,
            "ready": cmd_ready, "worktree": cmd_worktree, "status": cmd_status}
    if not argv or argv[0] not in cmds:
        die(usage.split("Usage:")[1].strip())
    try:
        inspect.signature(cmds[argv[0]]).bind(*argv[1:])
    except TypeError:
        die("wrong arguments for " + argv[0])
    cmds[argv[0]](*argv[1:])
