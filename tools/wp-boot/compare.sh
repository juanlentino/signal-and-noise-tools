#!/usr/bin/env bash
#
# Fetch the same paths from both sites (old on 8071, new on 8072) and report
# status, whether the theme and plugin really rendered (a page that fell back
# to a default theme reports no difference, which proves nothing), and the
# lines that differ once ports and ?ver= strings are folded. Then each debug.log.
#
# Usage: bash tools/wp-boot/compare.sh <workdir> <old> <new> [paths...]
set -uo pipefail
W="${1:?workdir}"; OLD="${2:?old}"; NEW="${3:?new}"; shift 3
PATHS=( "${@:-/ /a-boot-note/ /cv/ /notes/ /contact/ /category/provenance/ /tag/metadata/ /author/boot/ /2026/11/ /feed/ /?s=boot /no-such-page/}" )
[ $# -gt 0 ] && PATHS=( "$@" ) || read -r -a PATHS <<< "${PATHS[0]}"
O="$W/compare"; mkdir -p "$O"
fold() { sed -E 's#127\.0\.0\.1(:|%3A)807[12]#HOST#g; s#localhost:807[12]#HOST#g; s#(datetime="[0-9T:-]+)[0-9]{2}:[0-9]{2}([+-])#\1MM:SS\2#g; s#([?&](ver|v)=)[^"&'"'"' ]+#\1V#g; s#Boot [0-9][^<"]*#Boot X#g' | tr '>' '\n'; }
fail=0
for p in "${PATHS[@]}"; do
	n=$(echo "$p" | tr -c 'a-zA-Z0-9' '_')
	rm -f "$O/old$n.html" "$O/new$n.html"   # a failed fetch must not reuse an earlier run's page
	a=$(curl -s -o "$O/old$n.html" -w '%{http_code}' "http://127.0.0.1:8071$p")
	b=$(curl -s -o "$O/new$n.html" -w '%{http_code}' "http://127.0.0.1:8072$p")
	if [ -f "$O/old$n.html" ] && [ -f "$O/new$n.html" ]; then
		t=$(grep -c "themes/signal-and-noise/" "$O/new$n.html"); s=$(grep -c 'name="twitter:card"' "$O/new$n.html")  # printed by this plugin (inc/seo-og-parts.php)
		d=$(diff <(fold < "$O/old$n.html") <(fold < "$O/new$n.html") | grep -c '^[<>]')
	else
		t=-; s=-; d=-
	fi
	[ "$a" = "$b" ] && [ "$a" != 000 ] || fail=1   # 000: no server answered, never a match
	printf '%-24s old %s new %s  theme %-3s plugin %-3s differing lines %s\n' "$p" "$a" "$b" "$t" "$s" "$d"
done
# The logs, timestamps and paths folded: only lines one side has count.
# (SQLite rejects some MySQL-only SQL, TRIM(TRAILING ...), on BOTH: a rig artifact.)
lf() { sed -E 's/^\[[^]]*\] //; s#'"$W"'/[^/]+/wordpress#WP#g; s/ on line [0-9]+//; s/&#8217;/’/g; s/\.php\([0-9]+\)/.php(N)/g' "$W/$1/wordpress/debug.log" 2>/dev/null | sort -u; }
echo "debug.log lines only on $OLD: $(comm -23 <(lf "$OLD") <(lf "$NEW") | grep -c .)"
echo "debug.log lines only on $NEW: $(comm -13 <(lf "$OLD") <(lf "$NEW") | grep -c .)"
comm -13 <(lf "$OLD") <(lf "$NEW") | head -20
exit $fail
