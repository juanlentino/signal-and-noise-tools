<?php
/**
 * Standalone fixture tests for inc/abilities-rate-gate.php (v10.34.0).
 *
 * The real module is require'd, not reimplemented. WordPress's transient API is
 * stubbed with an in-memory store so the count/window behavior is driven for
 * real. Covers:
 *   - allows up to $max, denies the ($max + 1)th with a 429 WP_Error
 *   - the counter is per-user (different get_current_user_id → separate budget)
 *   - filters can raise/zero the cap and window (zero disables the gate)
 *   - fail-OPEN when the transient API is unavailable (asserted first, before
 *     the stubs are defined)
 *   - REGISTRATION GUARD: the module is actually require'd from the bootstrap
 *
 * Run: php tests/abilities-rate-gate.php
 *
 * @since plugin v10.34.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Label.
 * @return void
 */
function rg_check( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $msg\n";
	} else {
		$fail++;
		echo "FAIL: $msg\n";
	}
}

require_once __DIR__ . '/../inc/abilities-rate-gate.php';

// ---- 1. Fail-OPEN before any WP transient stub exists ----------------------
// get_transient/set_transient are undefined at this point, so the gate must
// allow unconditionally rather than fatal.
$open = snt_ability_rate_gate( 'x', 1, 60 );
rg_check( true === $open, 'fail-open: no transient API ⇒ allow (never fatal, never block)' );

// ---- WordPress stubs -------------------------------------------------------
$GLOBALS['__rg_store'] = array();
$GLOBALS['__rg_uid']   = 7;

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['__rg_store'] ) ? $GLOBALS['__rg_store'][ $k ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__rg_store'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['__rg_uid'];
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return array_key_exists( $hook, $GLOBALS['__rg_filters'] ?? array() )
			? $GLOBALS['__rg_filters'][ $hook ]
			: $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = 'default' ) {
		return $s;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return $t instanceof WP_Error; }
}

// ---- 2. Allows up to $max, denies the next -------------------------------
$GLOBALS['__rg_store'] = array();
$r1 = snt_ability_rate_gate( 'scan', 3, 60 );
$r2 = snt_ability_rate_gate( 'scan', 3, 60 );
$r3 = snt_ability_rate_gate( 'scan', 3, 60 );
$r4 = snt_ability_rate_gate( 'scan', 3, 60 );
rg_check( true === $r1 && true === $r2 && true === $r3, 'allows the first 3 of a cap-3 window' );
rg_check( is_wp_error( $r4 ) && 'snt_rate_limited' === $r4->get_error_code(), 'denies the 4th with snt_rate_limited' );
rg_check( is_wp_error( $r4 ) && 429 === ( $r4->data['status'] ?? 0 ), 'denial carries HTTP 429' );
rg_check( is_wp_error( $r4 ) && 60 === ( $r4->data['retry_after'] ?? 0 ), 'denial carries retry_after = window' );

// ---- 3. Per-user budget ----------------------------------------------------
// User 7 is now exhausted; a different user starts fresh.
$GLOBALS['__rg_uid'] = 99;
$other = snt_ability_rate_gate( 'scan', 3, 60 );
rg_check( true === $other, 'a different user has an independent budget' );
$GLOBALS['__rg_uid'] = 7;

// ---- 4. Filters ------------------------------------------------------------
$GLOBALS['__rg_store']   = array();
$GLOBALS['__rg_filters'] = array( 'snt_ability_rate_gate_max' => 0 );
rg_check( true === snt_ability_rate_gate( 'scan', 3, 60 ), 'a filter zeroing max disables the gate for this key' );
$GLOBALS['__rg_filters'] = array();

$GLOBALS['__rg_store'] = array();
$hi1 = snt_ability_rate_gate( 'scan', 1, 60 );
$hi2 = snt_ability_rate_gate( 'scan', 1, 60 );
rg_check( true === $hi1 && is_wp_error( $hi2 ), 'cap of 1 allows exactly one call per window' );

// ---- 5. Registration guard -------------------------------------------------
$boot = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
rg_check( false !== strpos( $boot, 'inc/abilities-rate-gate.php' ), 'bootstrap: require_once inc/abilities-rate-gate.php' );

// The two intended call sites actually invoke the gate.
$corpus   = (string) file_get_contents( __DIR__ . '/../inc/abilities-corpus.php' );
$insights = (string) file_get_contents( __DIR__ . '/../inc/abilities-insights.php' );
rg_check( false !== strpos( $corpus, "snt_ability_rate_gate( 'near_duplicate_scan'" ), 'wired: near-duplicate-scan gates on near_duplicate_scan' );
rg_check( false !== strpos( $insights, "snt_ability_rate_gate( 'run_insights_scan'" ), 'wired: run-insights-scan gates on run_insights_scan' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
