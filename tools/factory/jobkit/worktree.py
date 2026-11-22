"""`job worktree`: the one folder a job's builder may change."""
import os

from .base import BASE_REF, ROOT, die, git_run, is_worktree_of, path
from .state import load, save


def cmd_worktree(job):
    j = load(job)
    wt, br = j["worktree"], j["branch"]

    def run(*args):
        r = git_run(ROOT, *args)
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
        if git_run(ROOT, "rev-parse", "-q", "--verify", "refs/heads/" + br).returncode == 0:
            run("worktree", "add", "-q", wt, br)  # the branch outlived its folder: keep its commits
        else:
            run("worktree", "add", "-q", "-b", br, wt, BASE_REF)
    save(j)
    print(wt)


def cmd_where(job):
    """For tools/factory/gate: the job's worktree, round and folder, one per
    line, from the same checked read every command uses."""
    j = load(job)
    print("%s\n%d\n%s" % (j["worktree"], j["round"], path(j["id"])))
