<?php
/**
 * Search Console's native painter keeps Google's metric contract intact.
 *
 * Run: php tests/openstation-analytics-search-painter.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

require_once __DIR__ . '/lib/os-leaf-harness.php';

$GLOBALS['__gsc_data'] = array(
	'property'  => 'https://example.test/',
	'synced_at' => time() - HOUR_IN_SECONDS,
	'window'    => array( 'start' => '2026-08-07', 'end' => '2026-09-03' ),
	'queries'   => array(
		array( 'key' => 'crypto music error', 'clicks' => 3, 'impressions' => 97, 'ctr' => 3 / 97, 'position' => 11.24 ),
		array( 'key' => '', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ),
	),
	'pages'     => array(
		'/notes/provenance-is-the-wrong-half' => array( 'clicks' => 2, 'impressions' => 41, 'ctr' => 2 / 41, 'position' => 7.85 ),
	),
);

function snt_gsc_credential_is_configured() { return true; }
function sn_setting( $path, $default = '' ) { return 'search_console.property' === $path ? 'https://example.test/' : $default; }
function snt_gsc_data() { return $GLOBALS['__gsc_data']; }
function snt_gsc_window_totals() { return array( 'clicks' => 5, 'impressions' => 138, 'days' => 28, 'capped' => false ); }
function snt_analytics_settings_url() { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=measurement'; }

require_once SNT_PATH . 'apps/sn-analytics/parts/paint-kit.php';
require_once SNT_PATH . 'apps/sn-analytics/parts/painters/view-search.php';
// Coverage history: the summary seam is stubbed; the history module is real.
function snt_gsc_coverage_summary( $d, $inbound = null ) { return array( 'inspected' => 0, 'indexed' => 0, 'not_indexed' => 0, 'unknown' => 0, 'errors' => 0 ); }
require_once SNT_PATH . 'inc/search-console-coverage-history.php';
$GLOBALS['__options']['snt_gsc_coverage_history'] = array();
$empty_history_html = \SignalNoise\OpenStationHost\Analytics\Painters\paint_view_search( array() );
$GLOBALS['__options']['snt_gsc_coverage_history'] = array(
	array( 'synced_at' => 1_790_000_000, 'inspected' => 70, 'indexed' => 50, 'not_indexed' => 20, 'crawled_not_indexed' => 6, 'unknown' => 0, 'errors' => 0, 'median_crawl_age_days' => 9.0, 'crawl_times' => 70 ),
	array( 'synced_at' => 1_790_604_800, 'inspected' => 71, 'indexed' => 53, 'not_indexed' => 18, 'crawled_not_indexed' => 5, 'unknown' => 0, 'errors' => 0, 'median_crawl_age_days' => 12.5, 'crawl_times' => 71 ),
);

$html = \SignalNoise\OpenStationHost\Analytics\Painters\paint_view_search( array() );
$pass = 0;
$fail = 0;
function search_ok( $condition, $message ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "PASS: $message\n";
		return;
	}
	++$fail;
	echo "FAIL: $message\n";
}

search_ok( false !== strpos( $html, 'crypto music error' ), 'query keys remain visible labels' );
search_ok( false !== strpos( $html, '/notes/provenance-is-the-wrong-half' ), 'associative page keys remain visible labels' );
search_ok( false !== strpos( $html, 'Clicks' ) && false !== strpos( $html, 'Impressions' ) && false !== strpos( $html, 'Avg position' ), 'tables expose Search Console measures rather than Views and Visits' );
search_ok( false !== strpos( $html, '3.1%' ) && false !== strpos( $html, '11.2' ), 'CTR and average position retain their real units' );
search_ok( false === strpos( $html, '&quot;value&quot;:&quot;&quot;' ), 'blank source keys are dropped instead of becoming zero-labelled rows' );
search_ok( false !== strpos( $html, '138' ) && false !== strpos( $html, '3.6% CTR' ), 'window KPIs are derived from the stored Search Console totals' );
search_ok( false !== strpos( $empty_history_html, 'Index coverage history' ) && false !== strpos( $empty_history_html, 'History starts with the next weekly run.' ), 'an empty coverage history paints the honest line, not a table' );
search_ok( false !== strpos( $html, 'indexed +3' ) && false !== strpos( $html, 'not indexed -2' ) && false !== strpos( $html, 'median days since last crawl +3.5' ), 'two readings paint what moved since the previous run' );
search_ok( false !== strpos( $html, 'Median days since last crawl' ) && false !== strpos( $html, '12.5' ), 'the history table carries the median crawl age' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
