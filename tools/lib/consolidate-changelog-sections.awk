# Consolidate duplicate "### X" headings within one changelog section body.
#
# WHY (#1007). Rule 2 has every pull request add a bullet under
# "## [Unreleased]". Each PR does that in its own branch, prepending its own
# "### Fixed" or "### Added" — and at PR time an author cannot see the sections
# another open branch is about to add. Nothing merges them, so every release
# landing more than one PR carried duplicate headings into CHANGELOG.md, into
# docs/changelog/ when archived, and into the GitHub release notes, which are
# extracted from exactly that block.
#
# Reads a section BODY on stdin (no "## [...]" line) and writes it back with
# same-named headings merged.
#
#   - headings keep FIRST-APPEARANCE order: the changelog should read in the
#     order sections were introduced, not alphabetically.
#   - bullets keep their order WITHIN a heading.
#   - unknown heading names pass through unchanged. Keep-a-Changelog's set is a
#     convention here, and failing closed on a legitimate new one at release
#     time would be worse than a duplicate.
#   - text before the first heading is preserved.
#
# No variable here is named `next`, `length`, `split` or `index`. `next` is an
# awk STATEMENT, and passing -v next=... silently broke both write paths of
# tools/cut-release.sh once already — invisible to --dry-run, which exercises
# neither.

/^### / {
	heading = $0
	if ( !( heading in body ) ) {
		body[ heading ] = ""
		order[ ++count ] = heading
	}
	current = heading
	next
}

{
	if ( current == "" ) { preamble = preamble $0 "\n" }
	else if ( $0 ~ /^[ \t]*$/ ) {
		# Blank lines INSIDE a section are dropped. Merging two "### Fixed"
		# blocks otherwise leaves the first block's trailing blank sitting
		# between two bullets, which reads as a paragraph break that was never
		# written. Bullets in this changelog are never blank-separated; a
		# wrapped bullet continues on the next line.
	}
	else { body[ current ] = body[ current ] $0 "\n" }
}

END {
	if ( preamble != "" ) {
		sub( /\n+$/, "", preamble )
		if ( preamble != "" ) { printf "%s\n\n", preamble }
	}
	for ( i = 1; i <= count; i++ ) {
		heading = order[ i ]
		text = body[ heading ]
		gsub( /^\n+/, "", text )
		sub( /\n+$/, "", text )
		if ( text == "" ) { continue }   # a heading with no bullets is not a section
		printf "%s\n%s\n", heading, text
		if ( i < count ) { printf "\n" }
	}
}
