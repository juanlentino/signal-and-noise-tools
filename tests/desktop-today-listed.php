<?php
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '0.0.0-test' ); }
/**
 * SN Traffic's "Listed in Sessions (rest sampled)" row: the stored-row figure
 * is compared with the exact count only when both describe the same moment.
 * A same-day last-good from hours before the exact read is not such a figure.
 * Run: php tests/desktop-today-listed.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_REALTIME_KEY', 'sn_rt' );
define( 'SN_ANALYTICS_REALTIME_RETENTION', 300 );
define( 'SN_ANALYTICS_VIEWS_TODAY_LASTGOOD', 'sn_lg' );
$GLOBALS['t'] = array(); $GLOBALS['o'] = array();
function add_action() {}
function snt_os_compat_add_filter() {}
function __( $s ) { return $s; }
function sn_setting( $k, $d = null ) { return $d; }
function apply_filters( $h, $v ) { return $v; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['t'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function current_time() { return '2026-09-27 14:00:00'; }
function sn_analytics_local_day() { return '2026-09-27'; }
function sn_analytics_daily_series() { return array( array( 'day' => '2026-09-27', 'views' => 23 ) ); }
function sn_analytics_range_totals() { return array( 'views' => 20, 'visits' => 0, 'unique_visitor_days' => 0 ); }
function sn_analytics_exact_today() { return array( 'views' => 20, 'read_at' => $GLOBALS['read_at'] ); }
class WP_REST_Response { public $data; public function __construct( $d ) { $this->data = $d; } }
require __DIR__ . '/../inc/north-star.php';
require __DIR__ . '/../inc/analytics-today-estimate.php';
require __DIR__ . '/../inc/desktop-mode-payloads.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function listed() {
	unset( $GLOBALS['t'][ 'sn_desktop_site_views_2026-09-27_' . SNT_VERSION ] );
	$d = snt_desktop_site_views_payload()->data;
	return array( $d['today'] ?? null, $d['today_listed'] ?? null );
}

$GLOBALS['t'][ SNT_NSM_CACHE_KEY ] = array( 'configured' => false ); // the north star is not under test
$now                 = time();
$GLOBALS['read_at']  = $now; // the exact count, read at 2 pm
// The audit's case: the last-good was written at 9 am with 12 stored rows.
$GLOBALS['o']['sn_lg'] = array( 'day' => '2026-09-27', 'views' => 12, 'stored' => 12, 'at' => $now - 5 * 3600 );
ok( array( 20, null ) === listed(), 'a last-good from hours before the exact read claims no sampling' );
unset( $GLOBALS['o']['sn_lg']['at'] );
ok( array( 20, null ) === listed(), 'a last-good with no read time (written before this change) claims none either' );
$GLOBALS['o']['sn_lg']['at'] = $now - 5 * 3600;
ok( null === sn_analytics_today_stored_beside( $now ), 'the helper: stale stored rows are not comparable' );

// Stored rows read with the exact count: the comparison is made.
$GLOBALS['t']['sn_rt'] = array( 'views_today' => 23, 'views_today_stored' => 12, 'fetched' => $now + 2 );
ok( array( 20, 12 ) === listed(), 'stored rows read after the exact count, and fewer: the rest were sampled' );
$GLOBALS['t']['sn_rt']['fetched'] = $now - SN_ANALYTICS_REALTIME_RETENTION;
ok( array( 20, 12 ) === listed(), 'stored rows read inside the realtime cache window before it still compare' );
$GLOBALS['t']['sn_rt']['fetched'] = $now - SN_ANALYTICS_REALTIME_RETENTION - 1;
ok( array( 20, null ) === listed(), 'one second outside the window they do not' );
$GLOBALS['t']['sn_rt'] = array( 'views_today' => 20, 'views_today_stored' => 20, 'fetched' => $now );
ok( array( 20, null ) === listed(), 'every row kept: no line' );
ok( array( 'views' => 20, 'stored' => 20 ) === sn_analytics_today_sampling(), 'sn_analytics_today_sampling() keeps its two keys' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
