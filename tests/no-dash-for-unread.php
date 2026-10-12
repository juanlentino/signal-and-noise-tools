<?php
/**
 * No em dash stands in for a value (2026-10-12 audit, cross-cutting #7).
 *
 * "—" painted where a figure was not read says neither that it was not read
 * nor why, and the house rule bans em dashes in UI text. A value that was not
 * read says so (snt_kit_unread(), "not read"); a value known to be empty says
 * its own word ("none", "not recorded"). This reads every PHP string literal
 * and every JS string under apps/, inc/ and assets/ (comments excluded) and
 * fails on one that is ONLY an em dash, the shape a placeholder takes.
 *
 * Run: php tests/no-dash-for-unread.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
require_once __DIR__ . '/lib/inc-population.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
// Left alone on purpose, each with its reason:
$skip = array(
	// Public pages: a change there needs the owner's yes (listed in the PR).
	'inc/public-stats.php'     => 'the public /stats page',
	'inc/public-stats-live.php' => 'the public /stats page',
	'inc/maturity-roadmap-shortcode.php' => 'the public /maturity page',
	'assets/live-now.js'       => 'shared with the public /stats page',
	// Not a painted value: the palette's entity-decoding table.
	'assets/command-palette.js' => 'maps &#8212; to the character it decodes to',
);
$dash = '/^([\'"])(—|&mdash;|\\\\u2014|&#8212;)\1$/u';
$bad  = array();
$files = array_merge(
	glob( $root . '/apps/*/*.php' ), glob( $root . '/apps/*/*/*.php' ), glob( $root . '/apps/*/*/*/*.php' ),
	snt_test_inc_files( '*.php' ),
	glob( $root . '/apps/*/*.js' ), glob( $root . '/assets/*.js' ), glob( $root . '/assets/*/*.js' )
);
foreach ( $files as $file ) {
	$rel = substr( $file, strlen( $root ) + 1 );
	if ( isset( $skip[ $rel ] ) || false !== strpos( $rel, '.min.' ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );
	if ( '.php' === substr( $file, -4 ) ) {
		foreach ( token_get_all( $src ) as $tok ) {
			// A literal that is only a dash, or markup whose element holds only one.
			if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] && ( preg_match( $dash, $tok[1] ) || preg_match( '/>(—|&mdash;|&#8212;)</u', $tok[1] ) ) ) {
				$bad[] = "$rel:{$tok[2]}";
			}
		}
		continue;
	}
	// JS: drop comments, then look for a string that is only a dash.
	$code = preg_replace_callback( '#/\*.*?\*/#s', static fn( $m ) => str_repeat( "\n", substr_count( $m[0], "\n" ) ), $src );
	foreach ( explode( "\n", $code ) as $i => $line ) {
		$line = preg_replace( '#(^|[^:\'"\\\\])//.*$#', '$1', $line );
		if ( preg_match( '/([\'"])(—|\\\\u2014)\1/u', $line ) ) {
			$bad[] = "$rel:" . ( $i + 1 );
		}
	}
}
ok( array() === $bad, 'no string that is only an em dash is painted as a value' . ( $bad ? ":\n    " . implode( "\n    ", $bad ) : '' ) );
ok( count( $files ) > 300, 'the scan read the tree (' . count( $files ) . ' files), not an empty glob' );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
require_once $root . '/inc/openstation-kit.php';
ok( function_exists( 'snt_kit_unread' ) && 'not read' === snt_kit_unread(), 'the kit says an unread value in words: "not read"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
