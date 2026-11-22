"""`job worktree`: the one folder a job's builder may change."""
import os, subprocess

from .base import CFG, ROOT, die, is_worktree_of, load, save


def cmd_worktree(job):
    j = load(job)
    wt, br = os.path.join(ROOT, ".claude", "worktrees", "factory-" + j["id"]), j["branch"]

    def run(*args):
        r = subprocess.run(["git", "-C", ROOT, *args], capture_output=True, text=True)
        if r.returncode != 0:
            die("git %s failed: %s" % (" ".join(args[:2]), r.stderr.strip()))

    if os.path.lexists(wt):
        # Never adopt what is merely there: a plain folder inside this
        # checkout would make every later git call answer for the checkout.
        if not is_worktree_of(wt, br):
            die("%s exists but is not a git worktree on %s: move it away, then run this again" % (wt, br))
    else:
        run("fetch", "-q", "origin")
        run("worktree", "prune")  # forget a worktree whose folder was deleted by hand
        known = subprocess.run(["git", "-C", ROOT, "rev-parse", "-q", "--verify", "refs/heads/" + br], capture_output=True)
        if known.returncode == 0:  # the branch outlived its folder: keep its commits
            run("worktree", "add", "-q", wt, br)
        else:
            run("worktree", "add", "-q", "-b", br, wt, "origin/" + CFG["base"])
    j["worktree"] = wt
    save(j)
    print(wt)
