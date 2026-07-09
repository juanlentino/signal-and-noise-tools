<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', 'test' );
}

$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_enq']     = array(); // captured wp_enqueue_* handles
$GLOBALS['__pv_routes']  = array(); // captured register_rest_route args
$GLOBALS['__pv_can']     = true;    // current_user_can( 'manage_options' ) toggle

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $k, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $k ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) {
		$GLOBALS['__pv_meta'][ $id ][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'get_posts' ) ) {
	// Return every seeded post id that carries the provenance UID meta —
	// mirrors the real meta_key-only query sn_prov_admin_status() runs.
	function get_posts( $args = array() ) {
		$ids = array();
		foreach ( $GLOBALS['__pv_meta'] as $pid => $meta ) {
			if ( isset( $meta[ SN_PROV_UID_META ] ) ) {
				$ids[] = (int) $pid;
			}
		}
		return $ids;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['__pv_can'] ); }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	// Record the route args so the auth gate + handler can be exercised.
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['__pv_routes'][ $namespace . $route ] = $args;
		return true; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $d = null, $s = 200 ) {
			$this->data = $d;
			$this->status = $s; }
	}
}
// Enqueue-gate stubs (Task 5). sn_admin_page_hooks() is NOT loaded in this
// standalone harness (inc/admin-menu.php is never required), so stub it to the
// canonical plugin top-level hook the gate keys off.
if ( ! function_exists( 'sn_admin_page_hooks' ) ) {
	function sn_admin_page_hooks( $set = null ) {
		return array( 'toplevel_page_sn-theme-options' ); }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['__pv_enq'][] = $handle;
		return true; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['__pv_enq'][] = $handle;
		return true; }
}

require_once SNT_PATH . 'inc/provenance-core.php';
require_once SNT_PATH . 'inc/provenance-admin.php';

$pass = 0;
$fail = 0;
function ad_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function ad_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance admin suite\n\nTask 4: status endpoint\n";
update_post_meta( 11, SN_PROV_UID_META, 'u11' );
update_post_meta( 11, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'pending', 'committed_at' => '2026-07-09T00:00:00Z' ) ) );
$GLOBALS['__pv_options']['sn_prov_genesis'] = array( 'status' => 'pending', 'date' => '2026-07-09' );

$data = sn_prov_admin_status();
ad_true( is_array( $data ) && isset( $data['pending'] ), 'status returns a pending list' );
ad_eq( 1, count( $data['pending'] ), 'one pending commit surfaced' );
ad_eq( 'u11', $data['pending'][0]['note_uid'], 'pending item carries note_uid' );
ad_eq( 'pending', $data['genesis']['status'], 'genesis status included' );

echo "\nTask 4b: REST auth gate + handler\n";
$GLOBALS['__pv_routes'] = array();
sn_prov_admin_register_status_route();
$route = $GLOBALS['__pv_routes']['sn-prov/v1/status'] ?? null;
ad_true( is_array( $route ), 'status route registered under sn-prov/v1/status' );
ad_eq( 'GET', $route['methods'] ?? null, 'route method is GET' );
ad_true( is_callable( $route['permission_callback'] ?? null ), 'permission_callback is callable' );
ad_true( is_callable( $route['callback'] ?? null ), 'callback is callable' );

$GLOBALS['__pv_can'] = true;
ad_eq( true, call_user_func( $route['permission_callback'] ), 'manage_options user passes the gate' );
$GLOBALS['__pv_can'] = false;
ad_eq( false, call_user_func( $route['permission_callback'] ), 'non-manage_options user rejected' );
$GLOBALS['__pv_can'] = true;

$resp = call_user_func( $route['callback'], null );
ad_true( $resp instanceof WP_REST_Response, 'handler returns a WP_REST_Response' );
ad_true( is_array( $resp->data ) && isset( $resp->data['pending'] ), 'handler response data carries a pending list' );

echo "\nTask 5: admin assets gate\n";
$GLOBALS['__pv_enq'] = array();
sn_prov_admin_enqueue( 'toplevel_page_sn-theme-options' );  // a plugin page hook
ad_true( in_array( 'sn-provenance-admin', $GLOBALS['__pv_enq'], true ), 'assets enqueued on the plugin screen' );
$GLOBALS['__pv_enq'] = array();
sn_prov_admin_enqueue( 'edit.php' );
ad_eq( 0, count( $GLOBALS['__pv_enq'] ), 'assets NOT enqueued on foreign screens' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
