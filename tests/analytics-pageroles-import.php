<?php
/**
 * Tests for the entry_pages / exit_pages import extension in inc/analytics-import.php.
 * Run: php tests/analytics-pageroles-import.php
 * @since plugin v6.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Stub the page-roles upsert — capture rows for assertion without DB access.
$GLOBALS['_pr_upsert_calls'] = array();
function sn_analytics_pageroles_upsert( $rows ) {
	$GLOBALS['_pr_upsert_calls'][] = $rows;
	return count( $rows );
}
// Stub the other upserts so import_run doesn't fatal on its type dispatch.
function sn_analytics_rollup_upsert( $rows ) { return count( $rows ); }
function sn_analytics_dims_upsert( $rows ) { return count( $rows ); }
function sn_analytics_events_upsert( $rows ) { return count( $rows ); }
function sn_analytics_event_props_upsert( $rows ) { return count( $rows ); }

require_once __DIR__ . '/../inc/analytics-import.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "analytics-pageroles-import: entry_pages + exit_pages\n\n";

// ── import_types ───────────────────────────────────────────────────────────────
echo "Group: sn_analytics_import_types\n";
$types = sn_analytics_import_types();
ok( array_key_exists( 'entry_pages', $types ), 'import_types: entry_pages key present' );
ok( array_key_exists( 'exit_pages', $types ), 'import_types: exit_pages key present' );
ok( is_string( $types['entry_pages'] ) && strlen( $types['entry_pages'] ) > 0, 'import_types: entry_pages has label' );
ok( is_string( $types['exit_pages'] ) && strlen( $types['exit_pages'] ) > 0, 'import_types: exit_pages has label' );
ok( array_key_exists( 'pages', $types ), 'import_types: existing pages type still present' );

// ── entry_pages mapping ────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_import_map entry_pages\n";
$raw_entry = array(
	array( 'date' => '2026-05-11', 'entry_page' => '/',      'visitors' => '40', 'entrances' => '42', 'visit_duration' => '0', 'bounces' => '38', 'pageviews' => '50' ),
	array( 'date' => '2026-05-12', 'entry_page' => '/about', 'visitors' => '9',  'entrances' => '9',  'visit_duration' => '0', 'bounces' => '9',  'pageviews' => '11' ),
);
$mapped = sn_analytics_import_map( 'entry_pages', $raw_entry );
ok( $mapped['table'] === 'pageroles', 'map entry_pages: table=pageroles' );
ok( is_array( $mapped['rows'] ) && count( $mapped['rows'] ) === 2, 'map entry_pages: 2 rows' );
$r0 = $mapped['rows'][0];
ok( $r0['role'] === 'entry', 'map entry_pages: role=entry' );
ok( $r0['day'] === '2026-05-11' && $r0['path'] === '/', 'map entry_pages: day + path' );
ok( (int) $r0['views'] === 50, 'map entry_pages: views from pageviews (50)' );
ok( (int) $r0['visits'] === 40, 'map entry_pages: visits from visitors (40)' );
$r1 = $mapped['rows'][1];
ok( (int) $r1['views'] === 11, 'map entry_pages: views from pageviews (11)' );
ok( (int) $r1['visits'] === 9, 'map entry_pages: visits from visitors (9)' );

// ── exit_pages mapping ─────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_import_map exit_pages\n";
$raw_exit = array(
	array( 'date' => '2026-05-11', 'exit_page' => '/contact', 'visitors' => '8', 'visit_duration' => '0', 'exits' => '8', 'bounces' => '7', 'pageviews' => '9' ),
);
$mapped_x = sn_analytics_import_map( 'exit_pages', $raw_exit );
ok( $mapped_x['table'] === 'pageroles', 'map exit_pages: table=pageroles' );
ok( count( $mapped_x['rows'] ) === 1 && $mapped_x['rows'][0]['role'] === 'exit', 'map exit_pages: role=exit' );
ok( $mapped_x['rows'][0]['path'] === '/contact', 'map exit_pages: path from exit_page column' );
ok( (int) $mapped_x['rows'][0]['views'] === 9, 'map exit_pages: views from pageviews (9)' );

// ── skip rules ─────────────────────────────────────────────────────────────────
echo "\nGroup: skip rules\n";
$with_bad = array(
	array( 'date' => '',            'entry_page' => '/x',        'visitors' => '1', 'pageviews' => '1' ), // bad date
	array( 'date' => '2026-05-11',  'entry_page' => '',          'visitors' => '1', 'pageviews' => '1' ), // blank path
	array( 'date' => '2026-05-11',  'entry_page' => '/wp-admin', 'visitors' => '1', 'pageviews' => '1' ), // admin skip
	array( 'date' => '2026-05-11',  'entry_page' => '/wp-login.php', 'visitors' => '1', 'pageviews' => '1' ), // login skip
	array( 'date' => '2026-05-11',  'entry_page' => '/keep',     'visitors' => '2', 'pageviews' => '2' ), // kept
);
$mapped_s = sn_analytics_import_map( 'entry_pages', $with_bad );
ok( count( $mapped_s['rows'] ) === 1 && $mapped_s['rows'][0]['path'] === '/keep', 'skip: bad date / blank path / wp-admin / wp-login all dropped, /keep kept' );

// ── import_run dispatch ────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_import_run dispatch\n";
$entry_csv = "date,entry_page,visitors,entrances,visit_duration,bounces,pageviews\n2026-05-11,/,40,42,0,38,50\n2026-05-12,/about,9,9,0,9,11\n";
$exit_csv  = "date,exit_page,visitors,visit_duration,exits,bounces,pageviews\n2026-05-11,/contact,8,0,8,7,9\n";
$tmp_e = tempnam( sys_get_temp_dir(), 'snt_entry_' );
$tmp_x = tempnam( sys_get_temp_dir(), 'snt_exit_' );
file_put_contents( $tmp_e, $entry_csv );
file_put_contents( $tmp_x, $exit_csv );

$GLOBALS['_pr_upsert_calls'] = array();
$report = sn_analytics_import_run( array( 'entry_pages' => $tmp_e, 'exit_pages' => $tmp_x ) );
unlink( $tmp_e );
unlink( $tmp_x );

ok( array_key_exists( 'pageroles', $report ), 'import_run: report has pageroles key' );
ok( (int) $report['pageroles'] > 0, 'import_run: pageroles count > 0' );
ok( count( $GLOBALS['_pr_upsert_calls'] ) >= 1, 'import_run: sn_analytics_pageroles_upsert was called' );
$all_rows = array();
foreach ( $GLOBALS['_pr_upsert_calls'] as $batch ) { $all_rows = array_merge( $all_rows, $batch ); }
$roles = array_unique( array_map( function ( $r ) { return $r['role']; }, $all_rows ) );
sort( $roles );
ok( $roles === array( 'entry', 'exit' ), 'import_run: both entry + exit rows reached the upsert' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
