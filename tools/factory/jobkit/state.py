"""job.json: the stage table, and reading a job so that nothing in the file
is trusted for who the job is or where its code lives."""
import json, os, re, time

from .base import DRY, ROOT, die, path, write_atomic

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


def job_id(raw):
    """An issue number, as its plain decimal string. Nothing else names a job."""
    if not re.fullmatch(r"[1-9][0-9]{0,11}", str(raw)):
        die("a job id is a positive whole number (got %r)" % str(raw))
    return str(raw)


def shape_problem(j, job):
    """Why this parsed job.json is not a job, or ''."""
    if not isinstance(j, dict):
        return "it is not an object"
    if j.get("id") != job:
        return "its id is not %s" % job
    if j.get("stage") not in set(MOVES) | TERMINAL:
        return "its stage is not a stage"
    if type(j.get("round")) is not int or j["round"] < 1:
        return "its round is not a positive number"
    if type(j.get("stopped", 0)) is not int or type(j.get("dry", False)) is not bool:
        return "stopped or dry has the wrong type"
    return ""


def read(job):
    """(job dict, '') or (None, the problem). Worktree and branch are set from
    the id on every read and never taken from the file, so an edited job.json
    cannot point a job at another checkout or another branch."""
    try:
        j = json.load(open(path(job, "job.json")))
    except FileNotFoundError:
        return None, "no job " + job
    except (OSError, ValueError):
        return None, "job %s has an unreadable job.json" % job
    why = shape_problem(j, job)
    if why:
        return None, "job %s has a job.json of the wrong shape: %s" % (job, why)
    j["worktree"], j["branch"] = os.path.join(ROOT, ".claude", "worktrees", "factory-" + job), "factory/" + job
    return j, ""


def load(raw):
    job = job_id(raw)
    if os.path.islink(path(job)) or os.path.islink(path(job, "job.json")):
        die("job %s is reached through a symlink: refused" % job)
    j, why = read(job)
    if j is None:
        die(why)
    # Dry and live state never mix: FACTORY_OWNER_OK answers for the owner in
    # a dry run, so a dry run may only ever touch a job that was born in one.
    if j.get("dry", False) != DRY:
        die("job %s %s: refused" % (job, "is a live job and this is a dry run (FACTORY_DRY=1)" if DRY
                                    else "was made by a dry run (a test) and this is a live run"))
    return j


def save(j, folder=None):
    j["updated"] = int(time.time())
    write_atomic(os.path.join(folder or path(j["id"]), "job.json"), json.dumps(j, indent=1))
