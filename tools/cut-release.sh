#!/usr/bin/env bash
#
# Cut a release: stamp the version, promote Unreleased, archive the previous cut.
#
# A pull request does not bump Version and does not tag. This script is the
# separate, deliberate act that does — run by a human who has decided that what
# has accumulated under ## [Unreleased] is worth a number.
#
# It deliberately does NOT commit and does NOT tag. It edits files, prints what
# it touched, and prints the tag command for you to run once you have read the
# diff. --tag opts into tagging; nothing opts into committing.
#
# Usage:
#   tools/cut-release.sh patch|minor|major "one-line headline"
#   tools/cut-release.sh minor "the batch door" --dry-run
#   tools/cut-release.sh patch "envelope fix" --tag
#
# Refuses: a dirty worktree, an empty Unreleased, an unparseable Version.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLUGIN_FILE="signal-and-noise-tools.php"
CHANGELOG="CHANGELOG.md"
ARCHIVE="docs/changelog/v13.md"

die() { printf 'cut-release: %s\n' "$1" >&2; exit 1; }

# ── arguments ────────────────────────────────────────────────────────────
LEVEL="${1:-}"
HEADLINE="${2:-}"
DRY_RUN=0
DO_TAG=0
shift 2 2>/dev/null || true
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --tag)     DO_TAG=1 ;;
    *) die "unknown option: $arg" ;;
  esac
done

case "$LEVEL" in
  patch|minor|major) ;;
  *) die "first argument must be patch, minor or major (got '${LEVEL}')" ;;
esac
[ -n "$HEADLINE" ] || die "second argument must be a one-line headline"
case "$HEADLINE" in
  *$'\n'*) die "headline must be a single line" ;;
esac

# ── refuse a dirty worktree ──────────────────────────────────────────────
# A release edits tracked files. Doing that on top of unrelated uncommitted
# work makes the resulting diff unreviewable, and this script's whole promise
# is that you read the diff before tagging.
if [ -n "$(git status --porcelain)" ]; then
  die "worktree is dirty. Commit or stash first - a release must be reviewable as its own diff."
fi

# ── current version, read with CI's own regex ────────────────────────────
# Same pattern as .github/workflows/ci.yml so the two can never disagree about
# what the current version is.
CURRENT="$(grep -m1 -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
[ -n "$CURRENT" ] || die "could not parse Version: from ${PLUGIN_FILE}"

IFS=. read -r MAJ MIN PAT <<< "$CURRENT"
case "$LEVEL" in
  major) MAJ=$((MAJ + 1)); MIN=0; PAT=0 ;;
  minor) MIN=$((MIN + 1)); PAT=0 ;;
  patch) PAT=$((PAT + 1)) ;;
esac
NEXT="${MAJ}.${MIN}.${PAT}"
TODAY="$(date -u +%Y-%m-%d)"

# ── Unreleased must have something in it ─────────────────────────────────
# "Nothing to cut" is not a release. Heading plus whitespace does not count.
UNRELEASED_BODY="$(awk '
  /^## \[Unreleased\]/ { inside = 1; next }
  inside && /^## \[/   { exit }
  inside               { print }
' "$CHANGELOG")"

if [ -z "$(printf '%s' "$UNRELEASED_BODY" | tr -d '[:space:]')" ]; then
  die "## [Unreleased] is empty - nothing to cut. Add a bullet, or you are tagging a no-op."
fi

# ── the section currently sitting in the root file, which moves to archive ──
PREVIOUS_CUT="$(awk '
  /^## \[Unreleased\]/ { seen_unreleased = 1; next }
  seen_unreleased && /^## \[/ { inside = 1 }
  inside { print }
' "$CHANGELOG")"

echo "cut-release"
echo "  current : ${CURRENT}"
echo "  next    : ${NEXT}  (${LEVEL})"
echo "  headline: ${HEADLINE}"
echo "  date    : ${TODAY}"
echo

if [ "$DRY_RUN" -eq 1 ]; then
  echo "  --dry-run: nothing written. Would edit:"
  echo "    ${PLUGIN_FILE}       Version: ${CURRENT} -> ${NEXT}"
  echo "    ${CHANGELOG}         promote Unreleased to '## [${NEXT}] - ${TODAY} - ${HEADLINE}'"
  if [ -n "$PREVIOUS_CUT" ]; then
    echo "    ${ARCHIVE}           receive the previous cut ($(printf '%s' "$PREVIOUS_CUT" | grep -m1 -oE '^## \[[0-9.]+\]' || echo 'current section'))"
  else
    echo "    ${ARCHIVE}           (nothing to archive - root carries no previous cut)"
  fi
  echo
  echo "  Unreleased carries $(printf '%s\n' "$UNRELEASED_BODY" | grep -c '[^[:space:]]' || true) non-blank line(s)."
  exit 0
fi

# ── 1. plugin header (SNT_VERSION is derived from it; never hardcode) ────
tmp="$(mktemp)"
# `next` is an awk STATEMENT, not a usable variable name. Passing -v next=...
# breaks this substitution AND the CHANGELOG rewrite below, and --dry-run does
# not exercise either, so both looked fine until the real path ran.
awk -v cur="$CURRENT" -v nextver="$NEXT" '
  !done && /^[[:space:]]*\*[[:space:]]*Version:/ { sub(cur, nextver); done = 1 }
  { print }
' "$PLUGIN_FILE" > "$tmp" && mv "$tmp" "$PLUGIN_FILE"

# ── 2. archive receives the previous cut, newest-first under the header ──
if [ -n "$PREVIOUS_CUT" ]; then
  # Never archive a section the archive already carries. Root holds the current
  # cut, the archive holds everything older, so this should be impossible - but a
  # duplicated release section is corruption nobody notices until they are
  # reading history, so it is checked rather than assumed.
  prev_heading="$(printf '%s' "$PREVIOUS_CUT" | grep -m1 -oE '^## \[[0-9.]+\]' || true)"
  if [ -n "$prev_heading" ] && grep -qF "$prev_heading" "$ARCHIVE"; then
    die "archive already contains ${prev_heading} - refusing to duplicate it. Root and archive have drifted; fix that before cutting."
  fi
  # Insert directly above the archive's newest entry so the file stays
  # newest-first. The split point is FOUND, not a hardcoded line number: the
  # preamble's length changes whenever someone edits it, and a stale constant
  # would splice a release section into the middle of a sentence.
  first_heading_line="$(grep -n -m1 -E '^## \[' "$ARCHIVE" | cut -d: -f1)"
  [ -n "$first_heading_line" ] || die "no '## [' heading found in ${ARCHIVE}."
  tmp="$(mktemp)"
  head -n $((first_heading_line - 1)) "$ARCHIVE" > "$tmp"
  printf '%s\n\n' "$PREVIOUS_CUT" >> "$tmp"
  tail -n +"$first_heading_line" "$ARCHIVE" >> "$tmp"
  mv "$tmp" "$ARCHIVE"
fi

# ── 3. root: fresh empty Unreleased, old Unreleased becomes the new cut ──
# Consolidate duplicate "### X" headings before promoting (#1007). Rule 2 has
# every PR prepend its own section in its own branch, and at PR time an author
# cannot see what another open branch will add — so a release landing more than
# one PR carried "### Fixed" twice into the changelog, the archive, and the
# GitHub release notes, which are extracted from exactly this block.
body_tmp="$(mktemp)"
printf '%s\n' "$UNRELEASED_BODY" | awk -f "$ROOT/tools/lib/consolidate-changelog-sections.awk" > "$body_tmp"

tmp="$(mktemp)"
awk -v nextver="$NEXT" -v today="$TODAY" -v headline="$HEADLINE" -v bodyfile="$body_tmp" '
  /^## \[Unreleased\]/ {
    print "## [Unreleased]"
    print ""
    print "## [" nextver "] - " today " \xe2\x80\x94 " headline
    print ""
    while ( ( getline body_line < bodyfile ) > 0 ) { print body_line }
    close( bodyfile )
    print ""
    inside_unreleased = 1
    next
  }
  inside_unreleased && /^## \[/ { dropping_previous = 1; inside_unreleased = 0 }
  inside_unreleased { next }   # the ORIGINAL body lines: replaced by the consolidated copy above
  dropping_previous { next }
  { print }
' "$CHANGELOG" > "$tmp" && mv "$tmp" "$CHANGELOG"
rm -f "$body_tmp"

echo "  edited:"
echo "    ${PLUGIN_FILE}"
echo "    ${CHANGELOG}"
[ -n "$PREVIOUS_CUT" ] && echo "    ${ARCHIVE}"
echo
echo "  Nothing was committed. Read the diff, then:"
echo "    git add -A && git commit -m \"v${NEXT}: ${HEADLINE}\""

if [ "$DO_TAG" -eq 1 ]; then
  git tag -a "v${NEXT}" -m "v${NEXT} — ${HEADLINE}"
  echo "    tagged v${NEXT} (annotated, local). Push it with: git push origin v${NEXT}"
else
  echo "    git tag -a v${NEXT} -m \"v${NEXT} — ${HEADLINE}\" && git push origin v${NEXT}"
fi
