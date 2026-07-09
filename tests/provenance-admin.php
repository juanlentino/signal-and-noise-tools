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
		return true; }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() {
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
