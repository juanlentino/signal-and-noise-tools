"""Shared ground for the factory's state keeper: where things are, the gh
wrapper, and git asked in a way the caller's environment cannot redirect."""
import json, os, subprocess, sys

HERE = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
DRY = os.environ.get("FACTORY_DRY") == "1"
# FACTORY_ROOT is a test seam like FACTORY_DRY: a live run's root is where this script sits.
ROOT = os.path.realpath((DRY and os.environ.get("FACTORY_ROOT")) or os.path.dirname(os.path.dirname(HERE)))
REAL_JOBS = os.path.realpath(os.path.join(HERE, "jobs"))
JOBS = os.path.realpath(os.environ.get("FACTORY_JOBS") or REAL_JOBS)
CFG = json.load(open(os.path.join(HERE, "config.json")))
BASE_REF = "refs/remotes/origin/" + CFG["base"]  # never "origin/<base>": a local branch can carry that name
# What `git rev-parse --local-env-vars` lists: the variables that choose the repository.
GIT_LOCAL = ("GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_CONFIG GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_DIR"
             " GIT_WORK_TREE GIT_IMPLICIT_WORK_TREE GIT_GRAFT_FILE GIT_INDEX_FILE GIT_NO_REPLACE_OBJECTS GIT_REPLACE_REF_BASE"
             " GIT_PREFIX GIT_SHALLOW_FILE GIT_COMMON_DIR").split()


def die(msg):
    sys.exit("factory: " + msg)


# FACTORY_DRY skips GitHub and takes FACTORY_OWNER_OK for the owner's answer,
# so it must never be able to write the real jobs folder, however it is spelled.
if DRY and JOBS == REAL_JOBS:
    die("FACTORY_DRY=1 is for tests: set FACTORY_JOBS to a folder that is not the real jobs folder")


def gh_try(*args):
    """Run gh on the configured repository. Returns (exit code, stdout, stderr).
    A dry run prints the command instead and reports success."""
    if DRY:
        print("gh " + " ".join(args))
        return 0, "", ""
    try:
        r = subprocess.run(["gh", *args, "-R", CFG["repo"]], capture_output=True, text=True)
    except OSError as e:
        die("could not run gh: %s" % e)
    return r.returncode, r.stdout, r.stderr


def gh(*args):
    code, out, err = gh_try(*args)
    if code != 0:
        die("gh " + " ".join(args[:2]) + " failed: " + err.strip())
    return out


def gh_json(*args):
    """A gh answer as a dict. A dry run has no answer: it returns None."""
    out = gh(*args)
    if DRY:
        return None
    try:
        data = json.loads(out)
    except ValueError:
        data = None
    if not isinstance(data, dict):
        die("gh " + " ".join(args[:2]) + " did not answer with a JSON object")
    return data


def path(job, *parts):
    return os.path.join(JOBS, str(job), *parts)


def write_atomic(target, text):
    """Write beside the target, then rename over it: a reader sees the old
    file or the new one, never half of either."""
    tmp = os.path.join(os.path.dirname(target), "." + os.path.basename(target) + ".tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(text)
    os.replace(tmp, target)


def lines(job, *parts):
    """A job file's lines, stripped. Empty when the file is missing."""
    try:
        return [l.strip() for l in open(path(job, *parts)).read().splitlines()]
    except (OSError, ValueError):
        return []


def first_line(job, *parts):
    return (lines(job, *parts) or [""])[0]


def git_run(where, *args):
    env = {k: v for k, v in os.environ.items() if k not in GIT_LOCAL}
    return subprocess.run(["git", "-C", where, *args], capture_output=True, text=True, env=env)


def git(where, *args):
    """A git command's output, or '' when it fails."""
    r = git_run(where, *args)
    return r.stdout.strip() if r.returncode == 0 else ""


def is_worktree_of(wt, branch):
    """True only when `wt` is itself the top of a git worktree checked out on
    `branch`. A plain folder inside another checkout answers git with that
    checkout's HEAD, so the top-level path is compared, not assumed."""
    if not wt or not os.path.isdir(wt):
        return False
    top = git(wt, "rev-parse", "--show-toplevel")
    return bool(top) and os.path.realpath(top) == os.path.realpath(wt) and git(wt, "symbolic-ref", "-q", "--short", "HEAD") == branch


def head(j):
    return git(j["worktree"], "rev-parse", "HEAD") if is_worktree_of(j["worktree"], j["branch"]) else ""
