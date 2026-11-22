"""Shared ground for the factory's state keeper: configuration, the gh
wrapper, the job folder and the worktree's HEAD."""
import json, os, subprocess, sys, time

HERE = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
ROOT = os.environ.get("FACTORY_ROOT") or os.path.dirname(os.path.dirname(HERE))
REAL_JOBS = os.path.join(HERE, "jobs")
JOBS = os.environ.get("FACTORY_JOBS") or REAL_JOBS
CFG = json.load(open(os.path.join(HERE, "config.json")))
DRY = os.environ.get("FACTORY_DRY") == "1"


def die(msg):
    sys.exit("factory: " + msg)


# FACTORY_DRY is a test seam. It skips GitHub and takes FACTORY_OWNER_OK for the
# owner's answer, so it must never be able to write the real jobs folder.
if DRY and os.path.realpath(JOBS) == os.path.realpath(REAL_JOBS):
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


def path(job, *parts):
    return os.path.join(JOBS, str(job), *parts)


def load(job):
    try:
        return json.load(open(path(job, "job.json")))
    except FileNotFoundError:
        die("no job " + str(job))
    except (OSError, ValueError):
        die("job %s has an unreadable job.json" % job)


def write_atomic(target, text):
    """Write beside the target, then rename over it: a reader sees the old
    file or the new one, never half of either."""
    tmp = os.path.join(os.path.dirname(target), "." + os.path.basename(target) + ".tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(text)
    os.replace(tmp, target)


def save(j, folder=None):
    j["updated"] = int(time.time())
    write_atomic(os.path.join(folder or path(j["id"]), "job.json"), json.dumps(j, indent=1))


def lines(job, *parts):
    """A job file's lines, stripped. Empty when the file is missing."""
    try:
        return [l.strip() for l in open(path(job, *parts)).read().splitlines()]
    except (OSError, ValueError):
        return []


def first_line(job, *parts):
    return (lines(job, *parts) or [""])[0]


def git(where, *args):
    """A git command's output, or '' when it fails."""
    r = subprocess.run(["git", "-C", where, *args], capture_output=True, text=True)
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
    wt = j.get("worktree") or ""
    return git(wt, "rev-parse", "HEAD") if is_worktree_of(wt, j["branch"]) else ""
