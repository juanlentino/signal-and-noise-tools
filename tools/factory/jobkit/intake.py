"""What intake.md must say before a job may be specified or built."""
import re

from .base import first_line, path

SURFACES = ("public", "admin", "security", "data", "none")
MARKS = " \t*_`"


def surfaces(job):
    """The surfaces intake names, as (set, ''), or (None, the problem).
    An allowlist that fails closed: exactly one line that starts with
    SURFACES, the whole list on that line, every word a known surface, and
    nothing after it that reads as more of the list."""
    try:
        rows = open(path(job, "intake.md")).read().splitlines()
    except (OSError, ValueError):
        return None, "intake.md is missing or unreadable"
    at = [i for i, l in enumerate(rows) if re.match(r"\W*surfaces\b", l, re.I) or re.search(r"\bsurfaces\b[^\w\n]*:", l, re.I)]
    if len(at) != 1:
        return None, "intake.md must have exactly one SURFACES line (%d lines start with SURFACES or say SURFACES:)" % len(at)
    m = re.match(r"\W*SURFACES\W*:(.*)$", rows[at[0]], re.I)
    if not m:
        return None, "the SURFACES line must start with SURFACES: (it says %r)" % rows[at[0]]
    value = m.group(1).strip(MARKS)
    if not value:
        return None, "the SURFACES line is empty: name the surfaces on that line, separated by commas"
    items = [i.strip(MARKS) for i in value.split(",")]
    if "" in items:
        return None, "the SURFACES line has an empty item (a trailing or doubled comma): the whole list goes on that one line"
    words = [w.strip(MARKS) for i in items for w in i.lower().split()]
    unknown = [w for w in words if w not in SURFACES]
    if unknown:
        return None, "SURFACES names %s: only %s are allowed" % (", ".join(map(repr, unknown)), ", ".join(SURFACES))
    if "none" in words and len(set(words)) > 1:
        return None, "SURFACES says none together with a surface: none stands alone"
    after = next((l.strip() for l in rows[at[0] + 1:] if l.strip()), "")
    item = re.match(r"([-*+•]|\d+[.)])\s", after)
    bare = [w.strip(MARKS + ",;.") for w in after.lower().split()]
    if item or (bare and all(w in SURFACES for w in bare)):
        return None, "the line after SURFACES reads as more of the list (%r): put every surface on the SURFACES line, then a heading or prose" % after
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
