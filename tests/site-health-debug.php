<?php
/**
 * Standalone fixture tests for the Site Health > Info debug panel in
 * inc/admin-tab-dashboard.php (v4.9.0, Task 3).
 *
 * Covers snt_dashboard_debug_information():
 *   - returns the incoming $info array with a 'signal-noise-tools' panel
 *   - panel has label / description / fields
 *   - a Plugin-version field === SNT_VERSION
 *   - cron field reflects the scheduled/last-fired fixtures
 *   - at least one field is private => true (excluded from copy-export)
 *   - the webhooks count reflects the fixture (total + enabled)
 *
 * Run: php tests/site-health-debug.php
 *
 * @since plugin v4.9.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SNT_VERSION', '4.9.0' );
define( 'SNT_CRON_HISTORY_DB_VERSION_OPT', 'snt_cron_history_db_version' );
define( 'SNT_CRON_HISTORY_CRON_HOOK',      'snt_cron_history_prune' );
define( 'SN_RSS_TRACKER_CRON_HOOK',           'sn_rss_tracker_daily_prune' );

// Avoid loading the heavy renderer file: it pulls many module helpers. We
// only need snt_dashboard_debug_information + its delegates, so we declare
// the constants the file needs and stub WP, then require it.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return array_key_exists( $hook, $GLOBALS['__test_filters'] ) ? $GLOBALS['__test_filters'][ $hook ] : $value;
	}
}
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return $u; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p ) { return 'https://x/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field() {} }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u ) { return $u; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'version_compare' ) ) {} // built-in

if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) {
		$to = $to ? $to : time();
		return abs( $to - $from ) . ' seconds';
	}
}

// wp_get_theme stub → Version getter.
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $slug = null ) {
		return new class {
			public function get( $field ) { return '9.9.0'; }
		};
	}
}

// get_posts stub for override count → fields=ids returns an array of ids.
$GLOBALS['__test_override_ids'] = array( 11, 22, 33 );
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return $GLOBALS['__test_override_ids'];
	}
}

// Option store (last-fired + cron-history version + webhooks).
$GLOBALS['__test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

// Transient store (cache_state field reads the health-scan transient).
$GLOBALS['__test_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__test_transients'] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}

// wp_next_scheduled + last-fired tracking.
$GLOBALS['__test_next_scheduled'] = array();
function wp_next_scheduled( $hook, $args = array() ) {
	return isset( $GLOBALS['__test_next_scheduled'][ $hook ] ) ? $GLOBALS['__test_next_scheduled'][ $hook ] : false;
}

// cron-dashboard helpers the panel delegates to.
if ( ! function_exists( 'snt_cron_sn_owned_hooks' ) ) {
	function snt_cron_sn_owned_hooks() {
		return array( SN_RSS_TRACKER_CRON_HOOK ); // v6.0.0: RSS-only after Plausible retirement.
	}
}
if ( ! function_exists( 'snt_cron_last_fired_for' ) ) {
	function snt_cron_last_fired_for( $hook ) {
		$v = get_option( 'snt_cron_last_fired_' . md5( $hook ), null );
		return ( null === $v || '' === $v ) ? null : (int) $v;
	}
}

// Optional integration getters.
$GLOBALS['__test_ai_available'] = true;
if ( ! function_exists( 'snt_ai_is_available' ) ) {
	function snt_ai_is_available() { return ! empty( $GLOBALS['__test_ai_available'] ); }
}
$GLOBALS['__test_rate_statuses'] = array(
	'github' => array( 'label' => 'GitHub', 'snapshot' => array( 'limit' => 5000, 'remaining' => 4000 ) ),
);
if ( ! function_exists( 'snt_rate_limit_all_statuses' ) ) {
	function snt_rate_limit_all_statuses() { return $GLOBALS['__test_rate_statuses']; }
}
$GLOBALS['__test_webhooks'] = array(
	array( 'id' => 'wh_a', 'name' => 'A', 'enabled' => true ),
	array( 'id' => 'wh_b', 'name' => 'B', 'enabled' => false ),
	array( 'id' => 'wh_c', 'name' => 'C', 'enabled' => true ),
);
if ( ! function_exists( 'sn_webhooks_all' ) ) {
	function sn_webhooks_all() { return $GLOBALS['__test_webhooks']; }
}

// Deploy status stub (panel reads plugin update state).
if ( ! function_exists( 'sn_gh_latest_plugin_tag' ) ) {
	function sn_gh_latest_plugin_tag() { return null; }
}

// Action Scheduler backlog stubs (v9.48.0) — the panel row delegates to the
// scheduled-actions-health module; canned snapshot + the REAL summary shape.
$GLOBALS['__test_asb_snapshot'] = array(
	'counts'          => array( 'pending' => 5 ),
	'total'           => 5,
	'overdue_pending' => 2,
);
if ( ! function_exists( 'snt_asb_snapshot' ) ) {
	function snt_asb_snapshot( $db = null, $now = null ) { return $GLOBALS['__test_asb_snapshot']; }
}
if ( ! function_exists( 'snt_asb_summary_line' ) ) {
	function snt_asb_summary_line( $snapshot ) {
		return null === $snapshot ? 'Action Scheduler not installed' : 'pending 5 (2 overdue) | total 5';
	}
}

// v11.28.0: split out of admin-tab-dashboard.php.
require_once __DIR__ . '/../inc/dash-debug-info.php';
require_once __DIR__ . '/../inc/admin-tab-dashboard.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function sh_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function sh_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// Helper: find a field whose label or value contains a needle.
function sh_field_value( $fields, $key ) {
	return isset( $fields[ $key ] ) ? $fields[ $key ]['value'] : null;
}

// Prime cron fixtures: the sole SN hook (RSS) is intentionally NOT scheduled,
// so the panel reflects an unscheduled SN-owned hook. All fired 1h ago.
foreach ( snt_cron_sn_owned_hooks() as $h ) {
	$GLOBALS['__test_options'][ 'snt_cron_last_fired_' . md5( $h ) ] = time() - HOUR_IN_SECONDS;
}
$GLOBALS['__test_options'][ SNT_CRON_HISTORY_DB_VERSION_OPT ] = '1';

// ─── Test 1: panel present + envelope shape ──────────────────────────
echo "\nTest 1: panel present + envelope shape\n";
$info = array( 'wp-core' => array( 'label' => 'WP', 'fields' => array() ) );
$out  = snt_dashboard_debug_information( $info );
sh_true( is_array( $out ), 'returns an array' );
sh_true( array_key_exists( 'signal-noise-tools', $out ), "has 'signal-noise-tools' key" );
sh_true( array_key_exists( 'wp-core', $out ), 'preserves the incoming panels' );
$panel = $out['signal-noise-tools'];
sh_true( isset( $panel['label'] ), 'panel has label' );
sh_true( isset( $panel['description'] ), 'panel has description' );
sh_true( isset( $panel['fields'] ) && is_array( $panel['fields'] ), 'panel has fields array' );

// ─── Test 2: plugin version field === SNT_VERSION ────────────────────
echo "\nTest 2: plugin version field\n";
$fields = $panel['fields'];
sh_eq( SNT_VERSION, sh_field_value( $fields, 'plugin_version' ), 'plugin_version field === SNT_VERSION' );
sh_eq( '9.9.0', sh_field_value( $fields, 'theme_version' ), 'theme_version field from wp_get_theme stub' );

// ─── Test 3: override count reflects fixture ─────────────────────────
echo "\nTest 3: override count\n";
sh_eq( 3, sh_field_value( $fields, 'db_overrides' ), 'db_overrides === fixture count (3)' );

// ─── Test 4: cron field reflects fixtures ────────────────────────────
echo "\nTest 4: cron pipeline field\n";
$cron_val = sh_field_value( $fields, 'cron_pipeline' );
sh_true( is_string( $cron_val ), 'cron_pipeline value is a string' );
sh_true( false !== strpos( $cron_val, SN_RSS_TRACKER_CRON_HOOK ), 'mentions the RSS tracker hook' );
sh_true( false !== stripos( $cron_val, 'not scheduled' ) || false !== stripos( $cron_val, 'unscheduled' ), 'reflects the unscheduled RSS hook' );

// ─── Test 5: at least one private field ──────────────────────────────
echo "\nTest 5: private fields exist\n";
$private_count = 0;
foreach ( $fields as $f ) {
	if ( ! empty( $f['private'] ) ) { $private_count++; }
}
sh_true( $private_count >= 1, "at least one field is private => true ($private_count found)" );

// ─── Test 6: webhooks count reflects fixture ─────────────────────────
echo "\nTest 6: webhooks count\n";
$wh_val = sh_field_value( $fields, 'webhooks' );
sh_true( is_string( $wh_val ), 'webhooks value is a string' );
sh_true( false !== strpos( $wh_val, '3' ), 'total of 3 webhooks reflected' );
sh_true( false !== strpos( $wh_val, '2' ), '2 enabled reflected' );

// ─── Test 7: cron-history table presence field ───────────────────────
echo "\nTest 7: cron-history table presence\n";
$hist_val = sh_field_value( $fields, 'cron_history_table' );
sh_true( null !== $hist_val, 'cron_history_table field present' );

// ─── Test 8: Action Scheduler backlog row (v9.48.0) ──────────────────
// The panel row is guarded on function_exists('snt_asb_snapshot'); the
// stubs above stand in for the module so the row's delegation is pinned
// without loading its wpdb dependencies.
echo "\nTest 8: Action Scheduler backlog row\n";
$asb_val = sh_field_value( $fields, 'as_backlog' );
sh_eq( 'pending 5 (2 overdue) | total 5', $asb_val, 'as_backlog value comes straight from snt_asb_summary_line(snapshot)' );
sh_true( ! empty( $panel['fields']['as_backlog']['private'] ), 'as_backlog row is private (internal ops detail)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
