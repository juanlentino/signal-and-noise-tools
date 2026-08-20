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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
