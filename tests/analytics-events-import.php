<?php
/**
 * Tests for the custom_events / custom_props import extension in
 * inc/analytics-import.php (Task A2).
 * Run: php tests/analytics-events-import.php
 * @since plugin v6.2.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Stub upserts — capture rows for assertion without DB access.
$GLOBALS['_ev_upsert_calls']    = array();
$GLOBALS['_props_upsert_calls'] = array();

function sn_analytics_events_upsert( $rows ) {
	$GLOBALS['_ev_upsert_calls'][] = $rows;
	return count( $rows );
}
function sn_analytics_event_props_upsert( $rows ) {
	$GLOBALS['_props_upsert_calls'][] = $rows;
	return count( $rows );
}

// Stub rollup / dims upserts so import_run doesn't fatal on unknown type checks.
function sn_analytics_rollup_upsert( $rows ) { return count( $rows ); }
function sn_analytics_dims_upsert( $rows ) { return count( $rows ); }

require_once __DIR__ . '/../inc/analytics-import.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "analytics-events-import: custom_events + custom_props\n\n";

// ── import_types ───────────────────────────────────────────────────────────────
echo "Group: sn_analytics_import_types\n";
$types = sn_analytics_import_types();
ok( array_key_exists( 'custom_events', $types ), 'import_types: custom_events key present' );
ok( array_key_exists( 'custom_props', $types ), 'import_types: custom_props key present' );
ok( is_string( $types['custom_events'] ) && strlen( $types['custom_events'] ) > 0, 'import_types: custom_events has non-empty label' );
ok( is_string( $types['custom_props'] ) && strlen( $types['custom_props'] ) > 0, 'import_types: custom_props has non-empty label' );
// Existing types still present.
ok( array_key_exists( 'pages', $types ), 'import_types: existing pages type still present' );
ok( array_key_exists( 'sources', $types ), 'import_types: existing sources type still present' );

// ── custom_events mapping ──────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_import_map custom_events\n";

// Two rows with same (date, name) but different link_url/path — should aggregate.
$raw_events = array(
	array( 'date' => '2026-05-11', 'name' => 'engagement', 'link_url' => '',           'path' => '/a',   'visitors' => '9', 'events' => '12' ),
	array( 'date' => '2026-05-11', 'name' => 'engagement', 'link_url' => 'https://x.com', 'path' => '/b', 'visitors' => '1', 'events' => '3'  ),
	array( 'date' => '2026-05-12', 'name' => 'click',      'link_url' => '',           'path' => '/c',   'visitors' => '5', 'events' => '7'  ),
);

$mapped = sn_analytics_import_map( 'custom_events', $raw_events );
ok( isset( $mapped['table'] ) && $mapped['table'] === 'events', 'map custom_events: table=events' );
ok( isset( $mapped['rows'] ) && is_array( $mapped['rows'] ), 'map custom_events: rows is array' );

// Aggregation: two rows for (2026-05-11, engagement) should collapse into one.
$rows = $mapped['rows'];
$eng_rows = array_values( array_filter( $rows, function ( $r ) { return $r['day'] === '2026-05-11' && $r['name'] === 'engagement'; } ) );
ok( count( $eng_rows ) === 1, 'map custom_events: (day,name) aggregation — engagement collapsed to 1 row' );
ok( (int) $eng_rows[0]['events'] === 15, 'map custom_events: engagement events summed (12+3=15)' );
ok( (int) $eng_rows[0]['visitors'] === 10, 'map custom_events: engagement visitors summed (9+1=10)' );
ok( $eng_rows[0]['day'] === '2026-05-11', 'map custom_events: day preserved as string' );

// The click row should also be present.
$click_rows = array_values( array_filter( $rows, function ( $r ) { return $r['name'] === 'click'; } ) );
ok( count( $click_rows ) === 1, 'map custom_events: click row present' );
ok( (int) $click_rows[0]['events'] === 7, 'map custom_events: click events=7' );

// Row with blank name is skipped.
$with_blank = array_merge( $raw_events, array(
	array( 'date' => '2026-05-11', 'name' => '', 'link_url' => '', 'path' => '/', 'visitors' => '3', 'events' => '5' ),
) );
$mapped_blank = sn_analytics_import_map( 'custom_events', $with_blank );
$blank_rows = array_values( array_filter( $mapped_blank['rows'], function ( $r ) { return $r['name'] === ''; } ) );
ok( count( $blank_rows ) === 0, 'map custom_events: row with blank name is skipped' );

// Row with blank date is skipped.
$with_bad_date = array_merge( $raw_events, array(
	array( 'date' => '', 'name' => 'ghost', 'link_url' => '', 'path' => '/', 'visitors' => '1', 'events' => '1' ),
) );
$mapped_bad = sn_analytics_import_map( 'custom_events', $with_bad_date );
$ghost_rows = array_values( array_filter( $mapped_bad['rows'], function ( $r ) { return $r['name'] === 'ghost'; } ) );
ok( count( $ghost_rows ) === 0, 'map custom_events: row with blank date is skipped' );

// Output shape: must have day, name, visitors, events keys.
ok( array_key_exists( 'day', $eng_rows[0] ), 'map custom_events: row has day key' );
ok( array_key_exists( 'name', $eng_rows[0] ), 'map custom_events: row has name key' );
ok( array_key_exists( 'visitors', $eng_rows[0] ), 'map custom_events: row has visitors key' );
ok( array_key_exists( 'events', $eng_rows[0] ), 'map custom_events: row has events key' );
ok( is_int( $eng_rows[0]['events'] ), 'map custom_events: events is int' );
ok( is_int( $eng_rows[0]['visitors'] ), 'map custom_events: visitors is int' );

// ── custom_props mapping ───────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_import_map custom_props\n";

$raw_props = array(
	array( 'date' => '2026-05-10', 'property' => 'plan', 'value' => 'pro',  'visitors' => '2', 'events' => '4'  ),
	array( 'date' => '2026-05-10', 'property' => 'plan', 'value' => 'free', 'visitors' => '8', 'events' => '20' ),
	array( 'date' => '2026-05-11', 'property' => 'os',   'value' => 'Mac',  'visitors' => '3', 'events' => '6'  ),
);

$mapped_p = sn_analytics_import_map( 'custom_props', $raw_props );
ok( isset( $mapped_p['table'] ) && $mapped_p['table'] === 'event_props', 'map custom_props: table=event_props' );
ok( isset( $mapped_p['rows'] ) && is_array( $mapped_p['rows'] ), 'map custom_props: rows is array' );
ok( count( $mapped_p['rows'] ) === 3, 'map custom_props: 3 distinct (day,property,value) rows' );

$pro_rows = array_values( array_filter( $mapped_p['rows'], function ( $r ) { return $r['property'] === 'plan' && $r['value'] === 'pro'; } ) );
ok( count( $pro_rows ) === 1, 'map custom_props: plan/pro row present' );
ok( (int) $pro_rows[0]['events'] === 4, 'map custom_props: plan/pro events=4' );
ok( array_key_exists( 'day', $pro_rows[0] ), 'map custom_props: row has day key' );
ok( array_key_exists( 'property', $pro_rows[0] ), 'map custom_props: row has property key' );
ok( array_key_exists( 'value', $pro_rows[0] ), 'map custom_props: row has value key' );
ok( array_key_exists( 'events', $pro_rows[0] ), 'map custom_props: row has events key' );
ok( is_int( $pro_rows[0]['events'] ), 'map custom_props: events is int' );

// Blank property is skipped.
$with_blank_prop = array_merge( $raw_props, array(
	array( 'date' => '2026-05-10', 'property' => '', 'value' => 'x', 'visitors' => '1', 'events' => '1' ),
) );
$mapped_bp = sn_analytics_import_map( 'custom_props', $with_blank_prop );
$blank_prop_rows = array_values( array_filter( $mapped_bp['rows'], function ( $r ) { return $r['property'] === ''; } ) );
ok( count( $blank_prop_rows ) === 0, 'map custom_props: blank property row is skipped' );

// Blank date is skipped.
$with_bad_date_p = array_merge( $raw_props, array(
	array( 'date' => 'not-a-date', 'property' => 'p', 'value' => 'v', 'visitors' => '1', 'events' => '1' ),
) );
$mapped_bd = sn_analytics_import_map( 'custom_props', $with_bad_date_p );
$bad_date_rows = array_values( array_filter( $mapped_bd['rows'], function ( $r ) { return $r['property'] === 'p'; } ) );
ok( count( $bad_date_rows ) === 0, 'map custom_props: bad date row is skipped' );

// ── import_run dispatch ────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_import_run dispatch\n";

// Write a temp CSV files for events + props.
$events_csv = "date,name,link_url,path,visitors,events\n2026-05-11,engagement,,/a,9,12\n2026-05-11,engagement,https://x.com,/b,1,3\n2026-05-12,click,,/c,5,7\n";
$props_csv  = "date,property,value,visitors,events\n2026-05-10,plan,pro,2,4\n2026-05-10,plan,free,8,20\n";

$tmp_ev = tempnam( sys_get_temp_dir(), 'snt_ev_' );
$tmp_pr = tempnam( sys_get_temp_dir(), 'snt_pr_' );
file_put_contents( $tmp_ev, $events_csv );
file_put_contents( $tmp_pr, $props_csv );

$GLOBALS['_ev_upsert_calls']    = array();
$GLOBALS['_props_upsert_calls'] = array();

$report = sn_analytics_import_run( array(
	'custom_events' => $tmp_ev,
	'custom_props'  => $tmp_pr,
) );

unlink( $tmp_ev );
unlink( $tmp_pr );

ok( array_key_exists( 'events', $report ), 'import_run: report has events key' );
ok( array_key_exists( 'event_props', $report ), 'import_run: report has event_props key' );
ok( (int) $report['events'] > 0, 'import_run: events count > 0' );
ok( (int) $report['event_props'] > 0, 'import_run: event_props count > 0' );
ok( count( $GLOBALS['_ev_upsert_calls'] ) >= 1, 'import_run: sn_analytics_events_upsert was called' );
ok( count( $GLOBALS['_props_upsert_calls'] ) >= 1, 'import_run: sn_analytics_event_props_upsert was called' );

// Verify the aggregated rows reached the upsert (engagement collapsed to 1 row with events=15).
$ev_rows_passed = $GLOBALS['_ev_upsert_calls'][0] ?? array();
$eng = array_values( array_filter( $ev_rows_passed, function ( $r ) { return $r['name'] === 'engagement'; } ) );
ok( count( $eng ) === 1, 'import_run: engagement row aggregated before upsert' );
ok( (int) $eng[0]['events'] === 15, 'import_run: engagement events = 15 (12+3)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
