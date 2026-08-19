<?php
/**
 * Tests: the ops wall's panel builder.
 *
 * "Everything without bloating" (owner, 2026-08-19) has a consequence the rest
 * of this codebase already believes: a source that is ABSENT still gets its
 * panel, saying it is not measured. Omitting it would make the wall silently
 * smaller on the exact day something stopped reporting — the failure mode is
 * a page that looks complete while a fact has gone missing.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $f, $t = 0 ) { return '19 minutes'; } }
require __DIR__ . '/../inc/dash-ops-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function titles( $panels ) { return array_map( function( $p ) { return $p['title']; }, $panels ); }
function by_title( $panels, $t ) {
	foreach ( $panels as $p ) { if ( $p['title'] === $t ) { return $p; } }
	return null;
}
echo "ops wall panel builder\n\n";

$all = sn_dash_ops_panels( array(
	'deploys' => array(
		array( 'repo' => 'juanlentino/signal-and-noise-tools', 'ref' => 'main', 'conclusion' => 'success', 'created_at' => '2026-08-19T15:00:00Z' ),
		array( 'repo' => 'juanlentino/sn-theme', 'ref' => 'main', 'conclusion' => 'failure', 'created_at' => '2026-08-19T13:00:00Z' ),
	),
	'pages'   => array( array( 'path' => '/notes/two-kinds', 'views' => 41 ) ),
	'sources' => array( array( 'label' => 'google.com', 'visits' => 12 ) ),
	'queries' => array( array( 'query' => 'provenance over detection', 'clicks' => 4 ) ),
	'api'     => array( 'github' => array( 'remaining' => 4200, 'limit' => 5000, 'kind' => 'ok' ) ),
) );

// ── every source present ────────────────────────────────────────────────────
ok( 5 === count( $all ), 'FIVE PANELS FOR FIVE SOURCES — the wall is however many the call site can source' );
ok( in_array( 'Recent deploys', titles( $all ), true ), 'recent deploys' );
ok( in_array( 'Top pages', titles( $all ), true ),      'top pages' );
ok( in_array( 'Top sources', titles( $all ), true ),    'top sources' );
ok( in_array( 'Top queries', titles( $all ), true ),    'top queries' );
ok( in_array( 'API limits', titles( $all ), true ),     'api limits' );

$dep = by_title( $all, 'Recent deploys' );
ok( 2 === count( $dep['rows'] ), 'a deploy row per run' );
// v11.30.0, MONOCHROME FIRST (Few): a healthy row carries NO dot. Painting
// green on every successful deploy and every healthy API host put a field of
// colour on a screen whose whole job is to make one amber cell obvious.
ok( '' === $dep['rows'][0]['dot'],   'A SUCCESSFUL RUN PAINTS NOTHING — healthy is the absence of a state, not a green one' );
ok( 'err' === $dep['rows'][1]['dot'], 'A FAILED RUN PAINTS AN ERR DOT — the wall is where you would see it' );
ok( false !== strpos( $dep['rows'][0]['label'], 'plugin' ), 'the repo is shortened, not printed whole' );

$pg = by_title( $all, 'Top pages' );
ok( '/notes/two-kinds' === $pg['rows'][0]['label'] && '41' === $pg['rows'][0]['value'], 'a page row is path + views' );

// ── ABSENT IS NOT OMITTED, and not zero either ──────────────────────────────
$none = sn_dash_ops_panels( array() );
ok( 5 === count( $none ), 'AN ABSENT SOURCE STILL GETS ITS PANEL — a wall that silently shrinks hides the fact that went missing' );
foreach ( $none as $p ) {
	ok( null === $p['rows'], $p['title'] . ': rows are NULL (never fetched), not an empty list' );
	ok( '' !== (string) $p['unmeasured'], $p['title'] . ': carries its own not-measured wording' );
}

// ── measured-and-empty is a THIRD state ─────────────────────────────────────
$empty = sn_dash_ops_panels( array( 'pages' => array() ) );
$ep    = by_title( $empty, 'Top pages' );
ok( is_array( $ep['rows'] ) && 0 === count( $ep['rows'] ), 'a fetched-but-empty source yields an EMPTY ARRAY, not null' );
ok( '' !== (string) $ep['empty'], 'and its own measured-empty wording, distinct from the not-measured one' );
ok( $ep['empty'] !== $ep['unmeasured'], 'THE TWO STRINGS DIFFER — one for both would state a zero while meaning silence' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
