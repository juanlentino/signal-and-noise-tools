#!/usr/bin/env bash
#
# The standalone test sweep. ONE implementation, used by CI and by hand.
#
# WHY THIS IS A COMMITTED SCRIPT AND NOT A LOOP IN ci.yml. The gate below used
# to live inline in the workflow, which meant anyone sweeping locally hand-rolled
# their own. On 2026-08-11 a session did exactly that and got it subtly wrong:
#
#   summary=$(php "$f" | grep -oE '[0-9]+ passed, [0-9]+ failed')
#   fails=$(echo "$summary" | sed -E 's/.*passed, ([0-9]+) failed/\1/')
#   if [ -n "$fails" ] && [ "$fails" != "0" ]; then flag; fi
#
# A suite that DIES mid-run prints no summary at all. So `$summary` is empty,
# `$fails` is empty, the `-n` guard is false, and the crashed suite is counted
# as GREEN. That runner reported "ALL 415 SUITES GREEN" over two suites that
# were fatally broken, and the breakage reached a pushed commit.
#
# THE GATE IS THE PRESENCE OF THE SUMMARY LINE, not its failure count. A suite
# that asserts nothing is not a passing suite — it is a suite that did not run,
# and those are indistinguishable from the outside unless you check for the line
# itself. That is the whole reason this file exists; do not "simplify" the
# -z check away.
#
# Usage:  bash tests/run.sh            # sweep everything
#         bash tests/run.sh foo.php    # sweep one suite by basename
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

# Suites that cannot run standalone. Each entry needs a reason, because an
# unexplained skip is how a suite quietly stops being run at all.
#   contracts-smoke.php — needs a live WP bootstrap; standalone it no-ops with
#     "ABSPATH not set" and asserts nothing, which the zero-assertion gate below
#     would (correctly) call a false green. Run it with `wp eval-file`.
SKIP="contracts-smoke.php"

only="${1:-}"
# A COUNT, not a flag. This was `fail=1` set in three places and printed as
# "$fail SUITE(S) FAILED", so three broken suites reported as ONE. The exit code
# was right; the number in the sentence was not — the same class of defect the
# line below was added to fix, surviving inside the fix itself.
fail=0
total=0
skipped=0
passed=0

# ::error annotations are Actions-only; locally they would just be noise.
annotate() {
	if [ -n "${GITHUB_ACTIONS:-}" ]; then
		echo "::error file=$1::$2"
	else
		echo "ERROR  $1: $2"
	fi
}

for f in tests/*.php; do
	base=$(basename "$f")

	if [ -n "$only" ] && [ "$base" != "$only" ]; then
		continue
	fi

	case " $SKIP " in
		*" $base "*)
			echo "SKIP (cannot run standalone): $base"
			skipped=$((skipped + 1))
			continue
			;;
	esac

	total=$((total + 1))
	out=$(php "$f" 2>&1) || true

	# The pipeline ends in `tail`, so this is status 0 even when grep matches
	# nothing — which is what keeps the -z branch below reachable under set -e.
	summary=$(echo "$out" | grep -ioE "[0-9]+ passed, [0-9]+ failed" | tail -1)

	if [ -z "$summary" ]; then
		annotate "$f" "no test summary line — the suite did not assert (crash, fatal, or silent skip). NOT a pass."
		echo "$out" | tail -3
		fail=$((fail + 1))
		continue
	fi

	f_count=$(echo "$summary" | grep -oE "[0-9]+ failed" | grep -oE "[0-9]+")
	p_count=$(echo "$summary" | grep -oE "[0-9]+ passed" | grep -oE "[0-9]+")
	passed=$((passed + ${p_count:-0}))

	# The subtler half of the same hole. A suite that prints "0 passed,
	# 0 failed" DID run and DID emit the line the gate above looks for — it
	# just asserted nothing, which is the false green wearing the summary's
	# clothes. Every real suite here asserts something; a genuinely empty one
	# should be deleted rather than swept.
	if [ "${p_count:-0}" -eq 0 ] && [ "${f_count:-0}" -eq 0 ]; then
		annotate "$f" "summary present but ZERO assertions — the suite ran and tested nothing. NOT a pass."
		fail=$((fail + 1))
		continue
	fi

	if [ "${f_count:-0}" -gt 0 ]; then
		annotate "$f" "$summary"
		fail=$((fail + 1))
	else
		echo "OK ($summary): $base"
	fi
done

if [ -n "$only" ] && [ "$total" -eq 0 ] && [ "$skipped" -eq 0 ]; then
	echo "ERROR: no suite matched '$only'"
	exit 2
fi

# The summary line reports FAILURES. It used to print only passed + skipped,
# so a suite failing two assertions still contributed its passing ones and the
# tail read healthy while the script exited 1. That is a real trap: the theme
# repo's sibling runner has the same shape, and a session there quoted
# "2,349 assertions, 0 failed" into a CHANGELOG, a commit message and a PR body
# from a run that was failing. The exit code was always right; the sentence a
# human reads was not, and the sentence is what gets quoted.
if [ "$fail" -gt 0 ]; then
	echo "-- swept $total suites, $passed assertions passed, $fail SUITE(S) FAILED, $skipped skipped --"
	# Exit 1, not $fail: now that it is a count, exiting with it would report 3
	# broken suites as status 3 — and anything above 125 wraps into the shell's
	# signal range. The count belongs in the sentence, not the exit code.
	exit 1
fi

echo "-- swept $total suites, $passed assertions passed, 0 failed, $skipped skipped --"
exit 0
