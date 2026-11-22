"""Shared ground for the factory's state keeper: configuration, the gh
wrapper, the job folder and the worktree's HEAD."""
import json, os, subprocess, sys, time

HERE = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
ROOT = os.environ.get("FACTORY_ROOT") or os.path.dirname(os.path.dirname(HERE))
JOBS = os.environ.get("FACTORY_JOBS") or os.path.join(HERE, "jobs")
CFG = json.load(open(os.path.join(HERE, "config.json")))
DRY = os.environ.get("FACTORY_DRY") == "1"


def die(msg):
    sys.exit("factory: " + msg)


def gh(*args, capture=False):
    if DRY:
        print("gh " + " ".join(args))
        return ""
    r = subprocess.run(["gh", *args, "-R", CFG["repo"]], capture_output=capture, text=True)
    if r.returncode != 0:
        die("gh " + args[0] + " " + args[1] + " failed: " + (r.stderr or "").strip())
    return r.stdout if capture else ""


def path(job, *parts):
    return os.path.join(JOBS, str(job), *parts)


def load(job):
    try:
        return json.load(open(path(job, "job.json")))
    except FileNotFoundError:
        die("no job " + str(job))


def save(j):
    j["updated"] = int(time.time())
    json.dump(j, open(path(j["id"], "job.json"), "w"), indent=1)


def first_line(job, *parts):
    try:
        return open(path(job, *parts)).readline().strip()
    except FileNotFoundError:
        return ""


def head(j):
    wt = j.get("worktree") or ""
    if not os.path.isdir(wt):
        return ""
    return subprocess.run(["git", "-C", wt, "rev-parse", "HEAD"], capture_output=True, text=True).stdout.strip()
