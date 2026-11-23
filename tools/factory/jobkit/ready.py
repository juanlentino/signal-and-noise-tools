"""`job ready`: the evidence on GitHub, checked before the owner is told.

The stage `ready` means the evidence on disk is complete (gate and review on
the worktree's HEAD). This command is the other half: GitHub's pull request
must be that same commit, open, with green checks. Only then is it announced.
"""
import re

from .base import CFG, DRY, die, gh, gh_json, gh_try, git, head
from .evidence import ready_problem
from .state import load, save


def origin_problem(wt):
    """Why the worktree's `origin` is not the configured repository, or ''."""
    url = git(wt, "remote", "get-url", "origin")  # resolves insteadOf, unlike the raw config value
    hosts = r"(https://([^@/]+@)?github\.com/|git@github\.com:|ssh://git@github\.com/)"
    if re.fullmatch(hosts + re.escape(CFG["repo"]) + r"(\.git)?/?", url, re.I):
        return ""
    return "the worktree's origin (%s) is not %s on GitHub" % (url or "none", CFG["repo"])


def cmd_ready(job):
    """Mark the pull request ready and tell the owner, once per commit."""
    j = load(job)
    if j["stage"] != "ready":
        die("job %s is at %s, not ready" % (j["id"], j["stage"]))
    why = ready_problem(j)  # a commit after `advance ready` was neither gated nor reviewed
    if why:
        die(why)
    sha, br = head(j), j["branch"]
    if j.get("announced") == sha:
        print("job %s: already announced as ready on %s; nothing to do" % (j["id"], sha[:8]))
        return
    pr = gh_json("pr", "view", br, "--json", "headRefOid,isDraft,state")  # a dry run prints this and accepts
    if not DRY:
        why = origin_problem(j["worktree"])
        if why:
            die(why)
        if pr.get("state") != "OPEN":
            die("the pull request for %s is %s on GitHub, not OPEN" % (br, pr.get("state")))
        if pr.get("headRefOid") != sha:  # GitHub's view, not what this worktree calls origin
            die("GitHub's head for the pull request (%s) is not the gated HEAD (%s): push, or run the gate again"
                % (str(pr.get("headRefOid"))[:8], sha[:8]))
        code = gh_try("pr", "checks", br)[0]
        if code != 0:
            die("the pull request's CI is not all green (`gh pr checks %s` exited %d): wait for it. If it stays red the job "
                "needs rework, and `job advance %s build` from ready needs the owner's answer on the issue first" % (br, code, j["id"]))
    if pr is None or pr.get("isDraft") is not False:
        gh("pr", "ready", br)
    gh("issue", "edit", j["id"], "--add-label", "factory:ready", "--remove-label", "factory:needs-human")
    gh("issue", "comment", j["id"], "--body", "Factory: the pull request passed the gate and review on %s "
       "and is ready for you to merge. The factory does not merge or cut." % sha[:8])
    j["announced"] = sha  # the stop recorded when the stage became ready is left alone
    save(j)
    print("job %s: ready to merge (the owner merges)" % j["id"])
