<?php
/**
 * Standalone fixture tests for inc/cron-dashboard.php.
 *
 * Matches the bot-detection.php precedent: bare-PHP, no PHPUnit, no
 * composer. Runnable as:
 *
 *     php tests/cron-dashboard.php
 *
 * Exits 0 on all-pass, 1 on any failure.
 *
 * Stubs only the WP functions the impl module actually calls. Tests
 * pure logic — REST + abilities + admin render layers get exercised
 * by the manual smoke test on live (per spec § 10.2).
 *
 * @since plugin v3.0.0
 */

// ─── WP stubs ─────────────────────────────────────────────────────────
define( 'ABSPATH', '/' );

// In-memory option store the stubs read/write.
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_actions'] = array(); // hook => bool (has_action)
$GLOBALS['__test_cron_array'] = array();
$GLOBALS['__test_current_user_can'] = true;
$GLOBALS['__test_current_action'] = '';
$GLOBALS['__test_action_callbacks'] = array();

function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {
	// No-op for module load; specific tests can override via globals.
}

function _get_cron_array() {
	return $GLOBALS['__test_cron_array'];
}

function has_action( $hook ) {
	return isset( $GLOBALS['__test_actions'][ $hook ] ) && $GLOBALS['__test_actions'][ $hook ];
}

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__test_options'][ $key ] ) ? $GLOBALS['__test_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__test_options'][ $key ] = $value;
	return true;
}

function current_user_can( $cap ) {
	return $GLOBALS['__test_current_user_can'];
}

function current_action() {
	return $GLOBALS['__test_current_action'];
}

function do_action_ref_array( $hook, $args ) {
	// Test stub: invoke a registered callable if present.
	if ( isset( $GLOBALS['__test_action_callbacks'][ $hook ] ) ) {
		call_user_func_array( $GLOBALS['__test_action_callbacks'][ $hook ], $args );
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_message() { return $this->message; }
}

require_once __DIR__ . '/../inc/cron-dashboard.php';

// ─── Test harness ─────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function assert_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
		echo "    Expected: " . var_export( $expected, true ) . "\n";
		echo "    Actual:   " . var_export( $actual, true ) . "\n";
	}
}

function assert_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

// Tests will be appended in Task 8.
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
