<?php
/**
 * Insights weave — Phase 2 (v13.57.0): the search section and ONE join key.
 *
 * Rule 1 of the weave: every per-path join goes through the shared key on
 * BOTH sides. Before this, views_map keyed on trim($path,'/') and permalinks
 * on trim(wp_make_link_relative(),'/'): agreed on the common case, diverged
 * on bare/empty/homepage inputs — dropped rows, silently. Pinned here as a
 * behaviour table over the shared key, a source-text pin that collect_signals
 * keys BOTH maps and the permalink through the same function, and the section
 * itself: never a zero row for a never-synced property, `capped` carried.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $s, $d = null ) { return $s; }
function apply_filters( $h, $v ) { return $v; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

require_once __DIR__ . '/../inc/path-join-key.php';
require_once __DIR__ . '/../inc/insights.php';

echo "insights search join — v13.57.0\n\n";

// ─── the shared key, on the inputs the three old spellings disagreed on ───
$table = array(
	'/notes/foo/'                          => '/notes/foo',
	'notes/foo'                            => '/notes/foo',
	'https://example.test/notes/foo/'      => '/notes/foo',
	'https://example.test/notes/foo?x=1#a' => '/notes/foo',
	'/notes//foo/'                         => '/notes/foo',
	'/'                                    => '/',
	''                                     => '',
);
foreach ( $table as $in => $want ) {
	ok( $want === snt_insights_join_key( $in ) && $want === sn_path_join_key( $in ), sprintf( 'join key %-40s → %s (identical to sn_path_join_key)', var_export( $in, true ), var_export( $want, true ) ) );
}

// ─── the section: never a zero row ───
$map = array( 'sentinel' );
$s0  = snt_insights_search_section( null, null, $map );
ok( array( 'synced' => false ) === $s0 && array() === $map, 'never synced: {synced:false} and an EMPTY map — nothing joins to a zero' );

$data = array(
	'window'  => array( 'start' => '2026-08-01', 'end' => '2026-08-28' ),
	'pages'   => array(
		'/notes/foo/' => array( 'clicks' => 3, 'impressions' => 120, 'ctr' => 0.025, 'position' => 7.44 ),
		''            => array( 'clicks' => 9, 'impressions' => 9 ), // an unjoinable key must NOT land on any page
	),
	'queries' => array_fill( 0, 14, array( 'key' => 'q', 'impressions' => 1 ) ),
);
$tot = array( 'clicks' => 3, 'impressions' => 120, 'days' => 28, 'capped' => true );
$s   = snt_insights_search_section( $data, $tot, $map );
ok( true === $s['synced'] && 10 === count( $s['top_queries'] ) && true === $s['totals']['capped'], 'synced: window, top-10 queries, and totals carrying `capped` (a floor when true)' );
ok( array( '/notes/foo' ) === array_keys( $map ), 'the map is keyed by the SHARED key ("/notes/foo/" → "/notes/foo"); the unjoinable row is dropped, not parked on "/"' );
ok( 7.4 === $map['/notes/foo']['position'] && 120 === $map['/notes/foo']['impressions'], 'per-path metrics rounded to the display grain' );

// ─── source pin: collect_signals keys BOTH sides through the shared key ───
$src = (string) file_get_contents( __DIR__ . '/../inc/insights.php' );
$fn  = substr( $src, strpos( $src, 'function snt_insights_collect_signals()' ) );
$fn  = substr( $fn, 0, strpos( $fn, "\n}\n" ) );
ok( 1 === preg_match( '/\$views_map\[\s*\$path\s*\]/', $fn ) && 1 === preg_match( '/\$path = isset\( \$row\[\'path\'\] \) \? snt_insights_join_key\(/', $fn ), 'views_map is keyed through snt_insights_join_key' );
ok( 1 === preg_match( '/\$permalink_path = snt_insights_join_key\(/', $fn ), 'permalink_path is computed through snt_insights_join_key — the same key as the maps' );
ok( 0 === preg_match( '/trim\(\s*(wp_make_link_relative\([^)]*\)|\(string\) \$permalink|\(string\) \$row\[\'path\'\]),\s*\'\/\'\s*\)/', $fn ), 'REGRESSION: no inline trim($x, "/") spelling survives in the join' );
ok( 1 === preg_match( '/\'search_28d\'\s*=>\s*isset\( \$search_map\[ \$permalink_path \] \) \? \$search_map\[ \$permalink_path \] : null/', $fn ), 'each post carries search_28d from the search map by the same key — null, not a zero row, when Google never showed it' );
ok( 1 === preg_match( '/\$out\[\'search\'\] = snt_insights_search_section\(/', $fn ), 'the search section is the sixth section of the payload' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
