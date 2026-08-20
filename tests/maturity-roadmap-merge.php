<?php
/**
 * Tests: the roadmap board's three-way merge.
 *
 * Two writers contend for one board — code (sn_maturity_roadmap_static_board)
 * and MCP (sn_apply's roadmap_board option write). Before v12.6.0 the override
 * shadowed code totally, so the first MCP write silently retired the code path.
 * These pin the merge that lets both land.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
// merge.php deliberately does NOT define this (it must stay loadable without
// maturity-roadmap-shortcode.php, whose top-level `const` of the same name
// can't be re-declared behind a defined() guard) — so the suite defines its
// own copy, standing in for the shortcode file's const in a real load.
define( 'SN_MATURITY_ROADMAP_OPTION', 'snt_maturity_roadmap_board' );

$GLOBALS['snt_options'] = array();
function get_option( $k, $d = null ) { return $GLOBALS['snt_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['snt_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['snt_options'][ $k ] ); return true; }
function __( $s, $d = null ) { return $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }

require_once dirname( __DIR__ ) . '/inc/maturity-roadmap-merge.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "roadmap board three-way merge\n\n";

$board = array( 'F' => array( 'done' => array( 'a' ), 'planned' => array( 'b' ) ) );

echo "Group: the stored envelope\n";
$GLOBALS['snt_options'] = array();
ok( null === snt_roadmap_stored_envelope(), 'no option stored -> null envelope' );

snt_roadmap_store_envelope( $board, $board );
$env = snt_roadmap_stored_envelope();
ok( is_array( $env ) && 2 === $env['v'], 'a written envelope reports v=2' );
ok( $board === $env['board'], 'the envelope round-trips the board' );
ok( $board === $env['base'], 'and the base it was derived from' );

// v1 = a BARE board array, the shape shipped before v12.6.0.
$GLOBALS['snt_options'] = array( SN_MATURITY_ROADMAP_OPTION => $board );
$env = snt_roadmap_stored_envelope();
ok( $board === $env['board'], 'a v1 bare array is read as the board' );
ok( null === $env['base'], 'with a NULL base — unknown provenance, so no code edit may land' );

echo "\nGroup: malformed and edge-case shapes\n";

// base present but not an array — must normalise to null, same defence as
// a missing base key gets below.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 2, 'board' => $board, 'base' => 'oops' ),
);
$env = snt_roadmap_stored_envelope();
ok( null === $env['base'], 'a non-array base normalises to null' );

// v2 envelope with no base key at all.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 2, 'board' => $board ),
);
$env = snt_roadmap_stored_envelope();
ok( null === $env['base'], 'a v2 envelope missing the base key entirely normalises to null' );

// board present but not an array — must not be silently coerced (e.g. a
// string cast to [0 => 'x']); an envelope this malformed is unreadable.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 2, 'board' => 'not-an-array', 'base' => $board ),
);
ok( null === snt_roadmap_stored_envelope(), 'a non-array board is unreadable, not coerced into a bare array' );

// the option explicitly set to array() (not absent) — same "nothing stored"
// outcome as get_option() returning its default.
$GLOBALS['snt_options'] = array( SN_MATURITY_ROADMAP_OPTION => array() );
ok( null === snt_roadmap_stored_envelope(), 'an explicit empty-array option reads as null, same as absent' );

// version skew: a stored envelope from a FUTURE version this code doesn't
// understand. It must not be misread as a v1 bare board — that would hand
// callers the whole {v, board, base} wrapper as if 'v' and 'board' were
// roadmap family keys. Unreadable envelope version = no override, code wins.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 3, 'board' => $board ),
);
ok( null === snt_roadmap_stored_envelope(), 'an unrecognised envelope version reads as no override, not a bare board' );

// a v1 bare board that happens to have families literally named "v" and
// "board" must still be read as a bare board, never misread as an envelope.
// Safe today because a non-empty array cast to int is always 1, never 2 —
// but that's a cast quirk, not a design; is_int() is the deliberate guard.
$board_with_v_family = array(
	'v'     => array( 'done' => array( 'x' ), 'planned' => array() ),
	'board' => array( 'done' => array( 'y' ), 'planned' => array() ),
);
$GLOBALS['snt_options'] = array( SN_MATURITY_ROADMAP_OPTION => $board_with_v_family );
$env = snt_roadmap_stored_envelope();
ok(
	$board_with_v_family === $env['board'] && null === $env['base'],
	'a v1 board with families literally named "v" and "board" is read as a bare board, not an envelope'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
