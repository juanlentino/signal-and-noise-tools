<?php
/**
 * Tests: cut-release.sh consolidates duplicate "### X" headings (issue #1007).
 *
 * Rule 2 has every PR add a bullet under `## [Unreleased]`, in its own branch.
 * At PR time an author cannot see what another open branch will add, so nothing
 * merges the sections — and a release landing more than one PR carried
 * "### Fixed" twice into CHANGELOG.md, into docs/changelog/ when archived, and
 * into the GitHub release notes, which are extracted from exactly that block.
 * Three PRs produced it on the first release after the doctrine landed.
 *
 * The consolidation is a standalone awk program so a fixture can drive it. The
 * last inline-awk bug in cut-release.sh (`next` used as a variable name, which
 * is an awk STATEMENT) was invisible to --dry-run because --dry-run exercises
 * neither write path.
 *
 * Run: php tests/changelog-section-consolidation.php
 * @since 13.96.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$awk  = $root . '/tools/lib/consolidate-changelog-sections.awk';

/** Run the consolidator over a body. Fixed command, escapeshellarg'd path. */
function consolidate( $body, $awk ) {
	$cmd   = 'awk -f ' . escapeshellarg( $awk );
	$pipes = array();
	$proc  = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { return null; }
	fwrite( $pipes[0], $body );
	fclose( $pipes[0] );
	$out = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	return $out;
}

echo "changelog-section-consolidation — plugin v13.96.2\n\nGroup 1: the defect this closes\n";

ok( file_exists( $awk ), 'the consolidator exists as its own file, drivable without running a release' );

$dupe = "### Fixed\n- alpha\n\n### Added\n- beta\n\n### Fixed\n- gamma\n";
$out  = consolidate( $dupe, $awk );
ok( 1 === substr_count( $out, '### Fixed' ), '"### Fixed" appears ONCE after consolidation' );
ok( 1 === substr_count( $out, '### Added' ), '"### Added" still appears once' );
foreach ( array( 'alpha', 'beta', 'gamma' ) as $b ) {
	ok( false !== strpos( $out, "- $b" ), "the '$b' bullet survives — consolidation must never drop content" );
}

echo "\nGroup 2: order, which is the part a diff would not show\n";
ok( strpos( $out, '### Fixed' ) < strpos( $out, '### Added' ),
	'headings keep FIRST-APPEARANCE order — the changelog reads in the order sections were introduced, not alphabetically' );
ok( strpos( $out, '- alpha' ) < strpos( $out, '- gamma' ),
	'bullets keep their order within a heading' );
ok( false === strpos( $out, "- alpha\n\n- gamma" ),
	'merged bullets are NOT separated by a blank line — the first block\'s trailing blank would otherwise read as a paragraph break nobody wrote' );

echo "\nGroup 3: it does not damage the ordinary cases\n";
$single = "### Fixed\n- only one\n";
ok( trim( consolidate( $single, $awk ) ) === trim( $single ), 'a body with no duplicates is returned unchanged' );

$wrapped = "### Fixed\n- a bullet that\n  wraps onto a second line\n";
ok( false !== strpos( consolidate( $wrapped, $awk ), "  wraps onto a second line" ), 'a wrapped bullet keeps its continuation line' );

$unknown = "### Fixed\n- a\n\n### Provenance\n- b\n\n### Provenance\n- c\n";
$uout = consolidate( $unknown, $awk );
ok( 1 === substr_count( $uout, '### Provenance' ) && false !== strpos( $uout, '- c' ),
	'an UNKNOWN heading name consolidates too — Keep-a-Changelog\'s set is a convention here, and failing closed on a new one at release time would be worse than a duplicate' );

$preamble = "Some note before any heading.\n\n### Fixed\n- a\n";
ok( false !== strpos( consolidate( $preamble, $awk ), 'Some note before any heading.' ), 'text before the first heading is preserved' );

$empty_sec = "### Fixed\n- a\n\n### Added\n\n";
ok( false === strpos( consolidate( $empty_sec, $awk ), '### Added' ), 'a heading with no bullets is dropped, not emitted as an empty section' );

echo "\nGroup 4: the release script actually uses it\n";
$sh = (string) file_get_contents( $root . '/tools/cut-release.sh' );
ok( false !== strpos( $sh, 'tools/lib/consolidate-changelog-sections.awk' ),
	'cut-release.sh invokes the consolidator — a consolidator nothing calls is decoration' );
ok( false !== strpos( $sh, 'inside_unreleased { next }' ),
	'the ORIGINAL body lines are dropped, or the promoted section would carry the block twice' );

// The reason this file exists as a separate program at all. Comments stripped
// first: the awk file's own header explains why `-v next=` is avoided, and the
// first version of this assertion went red on that sentence. Fourth instance
// today of prose about a rule reading as the rule.
$awk_code = preg_replace( '/^\s*#.*$/m', '', (string) file_get_contents( $awk ) );
ok( false === strpos( $awk_code, '-v next=' ) && false === strpos( $awk_code, 'next =' ),
	'no awk variable is named `next` — it is a STATEMENT, and that bug silently broke both write paths of this script once already' );
ok( false !== strpos( $awk_code, 'next' ),
	'CONTROL: the stripper did not delete the whole file — `next` still appears, as the statement it is' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
