<?php
/**
 * Tests for inc/analytics-import.php — the one-time Plausible-CSV → first-party
 * rollup importer (v6.0.0). Pure parse/map/normalize functions + an orchestrator
 * that feeds the EXISTING idempotent upserts. No live Plausible dependency.
 * Run: php tests/analytics-import.php
 * @since plugin v6.0.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

// Capture what the importer hands to the (real, elsewhere) upserts.
$GLOBALS['__imp_daily'] = array();
$GLOBALS['__imp_dims']  = array();
function sn_analytics_rollup_upsert( $rows ) { $GLOBALS['__imp_daily'][] = $rows; return count( $rows ); }
function sn_analytics_dims_upsert( $rows ) { $GLOBALS['__imp_dims'][] = $rows; return count( $rows ); }

require_once __DIR__ . '/../inc/analytics-import.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function imp_reset() { $GLOBALS['__imp_daily'] = array(); $GLOBALS['__imp_dims'] = array(); }

echo "Analytics import (Plausible CSV → first-party rollup)\n\n";

echo "Group: CSV parse\n";
$csv = "\"date\",\"page\",\"visitors\",\"pageviews\"\n\"2026-05-11\",\"/notes/x\",3,5\n\"2026-05-11\",\"/a,b\",1,2\n";
$rows = sn_analytics_import_parse_csv( $csv );
ok( count( $rows ) === 2, 'parse: row count (header excluded)' );
ok( $rows[0]['date'] === '2026-05-11' && $rows[0]['page'] === '/notes/x' && $rows[0]['pageviews'] === '5', 'parse: header-keyed values' );
ok( $rows[1]['page'] === '/a,b', 'parse: quoted comma inside a field preserved' );
ok( sn_analytics_import_parse_csv( '' ) === array(), 'parse: empty content → empty array' );

echo "\nGroup: normalizers (merge historical labels into the first-party vocab)\n";
ok( sn_analytics_import_norm_device( 'Desktop' ) === 'desktop', 'device: Desktop → desktop' );
ok( sn_analytics_import_norm_device( 'Mobile' ) === 'mobile', 'device: Mobile → mobile' );
ok( sn_analytics_import_norm_device( '' ) === '', 'device: empty passes through (upsert maps to (unknown))' );
ok( sn_analytics_import_norm_browser( 'Microsoft Edge' ) === 'Edge', 'browser: Microsoft Edge → Edge' );
ok( sn_analytics_import_norm_browser( 'Samsung Browser' ) === 'Samsung Internet', 'browser: Samsung Browser → Samsung Internet' );
ok( sn_analytics_import_norm_browser( 'Chrome' ) === 'Chrome', 'browser: Chrome passthrough' );
ok( sn_analytics_import_norm_os( 'Mac' ) === 'macOS', 'os: Mac → macOS' );
ok( sn_analytics_import_norm_os( 'GNU/Linux' ) === 'Linux', 'os: GNU/Linux → Linux' );
ok( sn_analytics_import_norm_os( 'Ubuntu' ) === 'Linux', 'os: Ubuntu → Linux' );
ok( sn_analytics_import_norm_os( 'Windows' ) === 'Windows', 'os: Windows passthrough' );
ok( sn_analytics_import_norm_referrer( 'coccoc.com/search' ) === 'coccoc.com', 'referrer: strips path → host' );
ok( sn_analytics_import_norm_referrer( 'android-app://com.linkedin.android' ) === 'com.linkedin.android', 'referrer: strips scheme → host' );
ok( sn_analytics_import_norm_referrer( 'linkedin.com' ) === 'linkedin.com', 'referrer: bare host passthrough' );
ok( sn_analytics_import_norm_referrer( '' ) === '', 'referrer: empty passes through (upsert maps to (direct))' );

echo "\nGroup: map pages → daily rollup\n";
$pages = array(
	array( 'date' => '2026-05-11', 'page' => '/notes/x', 'visits' => '4', 'visitors' => '3', 'pageviews' => '5', 'total_scroll_depth' => '120', 'total_scroll_depth_visits' => '2', 'total_time_on_page' => '30', 'total_time_on_page_visits' => '3' ),
	array( 'date' => '2026-05-11', 'page' => '/wp-admin/', 'visits' => '1', 'visitors' => '1', 'pageviews' => '6', 'total_scroll_depth' => '100', 'total_scroll_depth_visits' => '1', 'total_time_on_page' => '29', 'total_time_on_page_visits' => '1' ),
	array( 'date' => '2026-05-11', 'page' => '/wp-login.php', 'visits' => '1', 'visitors' => '1', 'pageviews' => '1', 'total_scroll_depth' => '0', 'total_scroll_depth_visits' => '0', 'total_time_on_page' => '0', 'total_time_on_page_visits' => '0' ),
);
$mapped = sn_analytics_import_map( 'pages', $pages );
ok( $mapped['table'] === 'daily', 'pages: maps to the daily table' );
ok( count( $mapped['rows'] ) === 1, 'pages: /wp-admin + /wp-login.php skipped (not beacon-tracked)' );
$r = $mapped['rows'][0];
ok( $r['day'] === '2026-05-11' && $r['path'] === '/notes/x' && $r['class'] === 'human', 'pages: day/path/class' );
ok( (int) $r['views'] === 5 && (int) $r['visits'] === 3, 'pages: views=pageviews, visits=visitors' );
ok( (int) round( (float) $r['scroll_avg'] ) === 60, 'pages: scroll_avg = total_scroll/scroll_visits (120/2)' );
ok( (int) round( (float) $r['time_avg'] ) === 10000, 'pages: time_avg ms = (total_time/time_visits)*1000 (30/3*1000)' );

echo "\nGroup: map dims (sources/locations/devices/browsers/os)\n";
$sources = array(
	array( 'date' => '2026-05-13', 'source' => 'Google', 'referrer' => 'google.com', 'pageviews' => '2', 'visitors' => '1', 'visits' => '1' ),
	array( 'date' => '2026-05-13', 'source' => '', 'referrer' => '', 'pageviews' => '6', 'visitors' => '11', 'visits' => '33' ),
);
$ms = sn_analytics_import_map( 'sources', $sources );
ok( $ms['table'] === 'dims' && $ms['dim'] === 'referrer', 'sources → referrer dim' );
ok( $ms['rows'][0]['value'] === 'google.com' && (int) $ms['rows'][0]['views'] === 2 && (int) $ms['rows'][0]['visits'] === 1, 'sources: host + views=pageviews + visits=visitors' );
ok( $ms['rows'][1]['value'] === '', 'sources: empty referrer stays empty (upsert → (direct))' );

$devices = array( array( 'date' => '2026-05-13', 'device' => 'Desktop', 'pageviews' => '7', 'visitors' => '6' ) );
$md = sn_analytics_import_map( 'devices', $devices );
ok( $md['dim'] === 'device' && $md['rows'][0]['value'] === 'desktop', 'devices → device dim, normalized lowercase' );

$browsers = array( array( 'date' => '2026-05-13', 'browser' => 'Microsoft Edge', 'pageviews' => '3', 'visitors' => '1' ) );
ok( sn_analytics_import_map( 'browsers', $browsers )['rows'][0]['value'] === 'Edge', 'browsers → browser dim, normalized' );

$os = array( array( 'date' => '2026-05-13', 'operating_system' => 'Mac', 'pageviews' => '3', 'visitors' => '1' ) );
ok( sn_analytics_import_map( 'operating_systems', $os )['rows'][0]['value'] === 'macOS', 'operating_systems → os dim, normalized' );

$locs = array( array( 'date' => '2026-05-13', 'country' => 'US', 'region' => '', 'city' => '0', 'pageviews' => '9', 'visitors' => '7' ) );
$ml = sn_analytics_import_map( 'locations', $locs );
ok( $ml['dim'] === 'country' && $ml['rows'][0]['value'] === 'US', 'locations → country dim' );

ok( sn_analytics_import_map( 'martian', array() )['rows'] === array(), 'unknown type → empty (no rows)' );

echo "\nGroup: orchestrator run (reads files, feeds the existing upserts)\n";
imp_reset();
$tmp_pages = tempnam( sys_get_temp_dir(), 'imp' );
file_put_contents( $tmp_pages, "\"date\",\"page\",\"visits\",\"visitors\",\"pageviews\",\"total_scroll_depth\",\"total_scroll_depth_visits\",\"total_time_on_page\",\"total_time_on_page_visits\"\n\"2026-05-11\",\"/notes/x\",4,3,5,120,2,30,3\n" );
$tmp_dev = tempnam( sys_get_temp_dir(), 'imp' );
file_put_contents( $tmp_dev, "\"date\",\"device\",\"pageviews\",\"visitors\"\n\"2026-05-11\",\"Mobile\",7,6\n" );
$report = sn_analytics_import_run( array( 'pages' => $tmp_pages, 'devices' => $tmp_dev, 'browsers' => '/nonexistent/file.csv' ) );
ok( $report['daily'] === 1, 'run: daily rows imported (1)' );
ok( ( $report['dims']['device'] ?? 0 ) === 1, 'run: device dim rows imported (1)' );
ok( isset( $report['skipped']['browsers'] ), 'run: unreadable file reported as skipped, not fatal' );
ok( count( $GLOBALS['__imp_daily'] ) === 1 && count( $GLOBALS['__imp_dims'] ) === 1, 'run: called the daily + dims upserts once each' );
ok( $GLOBALS['__imp_daily'][0][0]['path'] === '/notes/x', 'run: daily upsert received mapped rows' );
ok( $GLOBALS['__imp_dims'][0][0]['dim'] === 'device' && $GLOBALS['__imp_dims'][0][0]['value'] === 'mobile', 'run: dims upsert received mapped+normalized rows' );
@unlink( $tmp_pages ); @unlink( $tmp_dev );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
