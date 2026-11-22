"""What intake.md must say before a job may be specified or built."""
import re

from .base import first_line, path

SURFACES = ("public", "admin", "security", "data", "none")


def surfaces(job):
    """The surfaces intake names, as (set, ''), or (None, the problem).
    An allowlist that fails closed: exactly one SURFACES line, a value on that
    line, and every word on it a known surface."""
    try:
        text = open(path(job, "intake.md")).read()
    except (OSError, ValueError):
        return None, "intake.md is missing or unreadable"
    found = [l for l in text.splitlines() if re.search(r"\bsurfaces\b[^\w\n]*:", l, re.I)]
    if len(found) != 1:
        return None, "intake.md must have exactly one SURFACES line (it has %d)" % len(found)
    m = re.match(r"^\W*SURFACES\W*:(.*)$", found[0], re.I)
    if not m:
        return None, "the SURFACES line must start with SURFACES: (it says %r)" % found[0]
    words = [w.strip("*_`") for w in re.split(r"[,\s]+", m.group(1).lower())]
    words = [w for w in words if w]
    if not words:
        return None, "the SURFACES line is empty: name the surfaces on that line, separated by commas"
    unknown = [w for w in words if w not in SURFACES]
    if unknown:
        return None, "SURFACES names %s: only %s are allowed" % (", ".join(map(repr, unknown)), ", ".join(SURFACES))
    if "none" in words and len(set(words)) > 1:
        return None, "SURFACES says none together with a surface: none stands alone"
    return set(words), ""


def intake_problem(job, owner_said_yes):
    """Why this intake cannot go on to spec or build, or ''."""
    line = first_line(job, "intake.md")
    if line != "DECISION: ACCEPT":
        return "intake.md must open with DECISION: ACCEPT (it says %r)" % line
    found, why = surfaces(job)
    if found is None:
        return why
    if "public" in found and not owner_said_yes:
        return "intake names a public surface: escalate for the owner's yes first"
    return ""
