<?php
/**
 * Standalone fixture tests for inc/insights.php (v3.6.0).
 *
 * Matches the bot-detection/cron-dashboard pattern: bare PHP, no
 * PHPUnit. Runnable as `php tests/insights.php`. Exits 0 on pass.
 *
 * @since plugin v3.6.0
 */

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS',  86400 );
define( 'WEEK_IN_SECONDS', 604800 );

if ( ! defined( 'OBJECT' )   ) define( 'OBJECT',   'OBJECT' );
if ( ! defined( 'OBJECT_K' ) ) define( 'OBJECT_K', 'OBJECT_K' );
if ( ! defined( 'ARRAY_A' )  ) define( 'ARRAY_A',  'ARRAY_A' );
if ( ! defined( 'ARRAY_N' )  ) define( 'ARRAY_N',  'ARRAY_N' );

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}

$GLOBALS['__test_options']    = array();
$GLOBALS['__test_transients'] = array();

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__test_options'][ $key ] );
	return true;
}
function get_transient( $key ) {
	return isset( $GLOBALS['__test_transients'][ $key ] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__test_transients'][ $key ] );
	return true;
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled() { return false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event() {}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event() {}
}
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $path, $default = null ) {
		return isset( $GLOBALS['__test_sn_settings'][ $path ] )
			? $GLOBALS['__test_sn_settings'][ $path ]
			: $default;
	}
}

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// ─── wpdb stub for post queries ──────────────────────────────────────
class Stub_wpdb_insights {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $rows   = array();

	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$out = $query;
		foreach ( $args as $a ) {
			$rep = is_int( $a ) || is_float( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'";
			$out = preg_replace( '/%s|%d|%f/', $rep, $out, 1 );
		}
		return $out;
	}
	public function get_results( $query, $output = OBJECT_K ) {
		// Used only for the post-list query in Task 2.
		$rows = $this->rows;
		if ( preg_match( "/post_status\s*=\s*'publish'/", $query ) ) {
			$rows = array_values( array_filter( $rows, function( $r ) { return ! empty( $r['post_status'] ) && 'publish' === $r['post_status']; } ) );
		}
		if ( preg_match( '/LIMIT (\d+)/', $query, $lm ) ) {
			$rows = array_slice( $rows, 0, (int) $lm[1] );
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Stub_wpdb_insights();

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return "https://juanlentino.com/?p={$id}"; }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		return isset( $GLOBALS['__test_post_terms'][ $post_id ][ $taxonomy ] )
			? $GLOBALS['__test_post_terms'][ $post_id ][ $taxonomy ]
			: array();
	}
}
if ( ! function_exists( 'sn_plausible_dashboard_data' ) ) {
	function sn_plausible_dashboard_data() {
		return isset( $GLOBALS['__test_plausible'] ) ? $GLOBALS['__test_plausible'] : null;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show ) { return ''; }
}

require_once __DIR__ . '/../inc/insights.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ins_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ins_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: module loads + constants defined ────────────────────────
echo "\nTest 1: module bootstrap\n";
ins_true( defined( 'SN_INSIGHTS_CACHE_KEY' ), 'SN_INSIGHTS_CACHE_KEY defined' );
ins_true( defined( 'SN_INSIGHTS_STATE_OPT' ), 'SN_INSIGHTS_STATE_OPT defined' );
ins_true( defined( 'SN_INSIGHTS_CRON_HOOK' ), 'SN_INSIGHTS_CRON_HOOK defined' );
ins_eq( 7 * DAY_IN_SECONDS, SN_INSIGHTS_CACHE_TTL, 'cache TTL is 7 days' );

// ─── Test 2: collect_signals returns site identity ───────────────────
echo "\nTest 2: snt_insights_collect_signals() — site identity\n";
$GLOBALS['__test_sn_settings'] = array(
	'identity.site_name'        => 'Juan Lentino',
	'identity.site_description' => 'A music producer site',
	'identity.person_name'      => 'Juan Lentino',
	'identity.job_title'        => 'Music Producer',
);
$GLOBALS['wpdb']->rows  = array();
$GLOBALS['__test_plausible'] = array( 'aggregate' => array(), 'pages' => array(), 'sources' => array() );
$signals = snt_insights_collect_signals();
ins_true( is_array( $signals ), 'returns array' );
ins_eq( 'Juan Lentino', $signals['site']['name'], 'site.name' );
ins_eq( 'Music Producer', $signals['site']['job_title'], 'site.job_title' );
ins_eq( 'https://juanlentino.com/', $signals['site']['home_url'], 'site.home_url' );

// ─── Test 3: post list shape + sort by views_7d ──────────────────────
echo "\nTest 3: posts sorted by views_7d desc\n";
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Low traffic',  'post_name' => 'low',  'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
	array( 'ID' => 2, 'post_title' => 'High traffic', 'post_name' => 'high', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5  * DAY_IN_SECONDS ) ),
);
$GLOBALS['__test_plausible'] = array(
	'aggregate' => array( 'visitors' => array( 'value' => 1000 ) ),
	'pages'     => array(
		array( 'page' => '/high', 'visitors' => array( 'value' => 500 ) ),
		array( 'page' => '/low',  'visitors' => array( 'value' => 10 ) ),
	),
	'sources'   => array(),
);
$signals = snt_insights_collect_signals();
ins_eq( 2, count( $signals['posts'] ), 'two posts' );
ins_eq( 2, $signals['posts'][0]['id'], 'highest-traffic post first' );
ins_eq( 500, $signals['posts'][0]['views_7d'], 'views_7d matched from Plausible' );
ins_eq( 'post', $signals['posts'][0]['type'], 'post.type' );

// ─── Test 4: post age cap (2 years) ──────────────────────────────────
echo "\nTest 4: posts older than 2 years excluded\n";
$old_date = gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS );  // 800d > 730d cap
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Old', 'post_name' => 'old', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => $old_date, 'post_modified_gmt' => $old_date ),
	array( 'ID' => 2, 'post_title' => 'New', 'post_name' => 'new', 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ),
);
$signals = snt_insights_collect_signals();
ins_eq( 1, count( $signals['posts'] ), 'only the new post included' );
ins_eq( 2, $signals['posts'][0]['id'], 'new post (id=2) survived' );

// ─── Test 5: post count cap (100) ────────────────────────────────────
echo "\nTest 5: post list capped at 100\n";
$rows = array();
for ( $i = 1; $i <= 150; $i++ ) {
	$rows[] = array( 'ID' => $i, 'post_title' => "P{$i}", 'post_name' => "p{$i}", 'post_status' => 'publish', 'post_type' => 'post', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
}
$GLOBALS['wpdb']->rows = $rows;
$signals = snt_insights_collect_signals();
ins_eq( 100, count( $signals['posts'] ), 'capped at 100' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
