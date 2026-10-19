<?php
/**
 * Tests: edge-errors-summary carries installs and the install verdict per day
 * (2026-10-17). The SN Systems card already counted a day's plugin and theme
 * installs and said whether they explain its 5xx; the ability did not, so an
 * investigation of a 503-heavy day had to rebuild install times from git tags.
 * Local door only: the remote twin's schema and payload do not move, so the
 * remote contract (and the worker) stay as they are.
 *
 * Run: php tests/edge-errors-installs-by-day.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__abilities'] = array();
function add_action( $h, $cb ) { $cb(); }
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }

// Two days: 10-15 has 2 installs and 5 dashboard 503s (explained); 10-16 has
// 1 install and a 522 on the home page (never explained); 10-17 is pending.
function sn_edge_errors_reading( $days ) {
	return array(
		'from' => '2026-10-15', 'to' => '2026-10-17', 'honest_from' => '2026-08-23', 'query' => null,
		'total' => 7, 'paths' => array(), 'paths_by_status' => array(), 'sources' => array(),
		'days' => array(
			array( 'day' => '2026-10-15', 'total' => 5, 'read' => 'read', 'visitor' => 5, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 ),
			array( 'day' => '2026-10-16', 'total' => 2, 'read' => 'read', 'visitor' => 2, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 ),
			array( 'day' => '2026-10-17', 'total' => 0, 'read' => 'pending', 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 ),
		),
		'asked_by' => array( 'visitor' => 7, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 ),
	);
}
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) {
	$rows = array(
		'2026-10-15' => array( array( 'value' => 'visitor 503 - dynamic /wp-json/signal-noise/v1/desktop/pulse', 'requests' => 5 ) ),
		'2026-10-16' => array( array( 'value' => 'visitor 522 - dynamic /', 'requests' => 2 ) ),
	);
	return 'err_path_asker' === $dim && $from === $to ? ( $rows[ $from ] ?? array() ) : array();
}
$GLOBALS['__history'] = array(
	array( 'repo' => 'p', 'ref' => 'a', 'created_at' => '2026-10-15T18:22:00Z' ),
	array( 'repo' => 't', 'ref' => 'b', 'created_at' => '2026-10-15T18:40:00Z' ),
	array( 'repo' => 'p', 'ref' => 'c', 'created_at' => '2026-10-16T03:45:00Z' ),
);
function snt_deploy_history_get( $limit = null ) { return $GLOBALS['__history']; }

require __DIR__ . '/../inc/abilities-edge-errors.php';

echo "Group: the per-day install verdict\n";
$out = snt_ability_edge_errors_summary_local();
$by  = array();
foreach ( (array) ( $out['installs_by_day'] ?? array() ) as $r ) { $by[ $r['day'] ] = $r; }
ok( 3 === count( $by ), 'one installs row per day of the window' );
ok( 2 === ( $by['2026-10-15']['installs'] ?? null ) && true === ( $by['2026-10-15']['explained'] ?? null ), '10-15: 2 installs explain 5 dashboard 503s' );
ok( 1 === ( $by['2026-10-16']['installs'] ?? null ) && false === ( $by['2026-10-16']['explained'] ?? null ), '10-16: a 522 on the home page is never explained by an install' );
ok( array_key_exists( 'explained', $by['2026-10-17'] ?? array() ) && null === $by['2026-10-17']['explained'], '10-17: a pending day has no verdict (null, not false)' );
ok( $out['days'] === sn_edge_errors_reading( 7 )['days'], 'days[] is unchanged; installs ride beside it' );

echo "\nGroup: no deploy history is unknown, never zero\n";
$GLOBALS['__history'] = null;
$out2 = snt_edge_errors_installs_by_day( sn_edge_errors_reading( 7 )['days'], null );
ok( null === $out2[0]['installs'] && null === $out2[0]['explained'], 'without a history, installs and explained are null' );

echo "\nGroup: a full deploy history does not reach back (2026-10-19)\n";
// The history keeps the newest SNT_DEPLOY_HISTORY_MAX_ROWS (20) installs. Live
// 2026-10-19: 1 + 13 + 6 rows filled it, and 10-16 (about 12 releases, and
// every edit.php 500 of the week) read "0 installs" as a fact.
$full = array();
foreach ( array_merge( array( '2026-10-17T23:00:00Z' ), array_fill( 0, 13, '2026-10-18T12:00:00Z' ), array_fill( 0, 6, '2026-10-19T01:00:00Z' ) ) as $at ) {
	$full[] = array( 'repo' => 'p', 'ref' => 'x', 'created_at' => $at );
}
ok( null === snt_desktop_edge_installs_on( '2026-10-16', $full ), 'a day before a full history\'s oldest row is unknown (null), not 0' );
ok( null === snt_desktop_edge_installs_on( '2026-10-17', $full ), 'the oldest row\'s own day may have lost rows to the cap: unknown' );
ok( 13 === snt_desktop_edge_installs_on( '2026-10-18', $full ), 'a day after the oldest row is whole: counted' );
ok( 0 === snt_desktop_edge_installs_on( '2026-10-16', array_slice( $full, 0, 19 ) ), 'a history under the cap is complete, so 0 stays a real 0' );
$rows = snt_edge_errors_installs_by_day( sn_edge_errors_reading( 7 )['days'], $full );
ok( null === $rows[1]['installs'] && null === $rows[1]['explained'], 'installs_by_day: a day past the history reads null, with no verdict' );

echo "\nGroup: local door only\n";
$base  = snt_edge_errors_output_schema();
$local = snt_edge_errors_local_output_schema();
ok( ! isset( $base['properties']['installs_by_day'] ), 'the shared (remote) schema does not carry installs_by_day' );
ok( isset( $local['properties']['installs_by_day'] ), 'the admin schema declares installs_by_day' );
$strip = $local;
unset( $strip['properties']['installs_by_day'] );
ok( $strip === $base, 'the admin schema is the shared one plus installs_by_day, nothing else' );
$reg = $GLOBALS['__abilities']['signal-noise/edge-errors-summary'] ?? array();
ok( 'snt_ability_edge_errors_summary_local' === ( $reg['execute_callback'] ?? '' ) && $local === ( $reg['output_schema'] ?? null ), 'the admin ability runs the local callback with the local schema' );
ok( ! array_key_exists( 'installs_by_day', snt_ability_edge_errors_summary() ), 'the shared callback (the remote twin runs it) returns no installs_by_day' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
