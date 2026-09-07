<?php
/**
 * Empirical Stress Test Suite for OpenStation Preferences Toggles (Option B).
 *
 * Adversarial and stress testing covering:
 *  - Section 1: Boolean coercion stress testing (snt_os_sanitize_bool) with 35+ truthy/falsy/edge-case inputs.
 *  - Section 2: REST update handler (snt_os_preferences_rest_update) edge cases:
 *               nested structures, malicious keys, payload spoofing, null values, type confusion,
 *               malformed requests, non-array inputs.
 *  - Section 3: Multi-user isolation and meta persistence:
 *               cross-user independence, concurrent permutations, anti-tampering (user_id injection),
 *               unauthenticated user safety, corrupted user-meta recovery.
 *  - Section 4: Dynamic dock placement and desktop icon isolation across user sessions.
 *
 * Run: php tests/openstation-preferences-stress.php
 *
 * @package SignalNoiseTools
 * @since 13.106.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '13.106.4' );

// ── Test harness ───────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $condition, $message ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "PASS: $message\n";
	} else {
		$fail++;
		echo "FAIL: $message\n";
	}
}

// ── WordPress stubs ───────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();

function add_filter( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__filters'][ $hook ][ $priority ][] = array(
		'cb'   => $cb,
		'args' => $accepted_args,
	);
	return true;
}

function apply_filters( $hook, $value ) {
	$args        = func_get_args();
	$by_priority = $GLOBALS['__filters'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $entry ) {
			$cb      = $entry['cb'];
			$args[1] = $value;
			$value   = call_user_func_array( $cb, array_slice( $args, 1, $entry['args'] ) );
		}
	}
	return $value;
}

function has_filter( $hook, $callback = false ) {
	if ( ! isset( $GLOBALS['__filters'][ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $GLOBALS['__filters'][ $hook ] as $cbs ) {
		foreach ( $cbs as $entry ) {
			if ( $entry['cb'] === $callback ) {
				return true;
			}
		}
	}
	return false;
}

function add_action( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__actions'][ $hook ][ $priority ][] = array(
		'cb'   => $cb,
		'args' => $accepted_args,
	);
	return true;
}

function do_action( $hook ) {
	$args        = func_get_args();
	$by_priority = $GLOBALS['__actions'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $entry ) {
			call_user_func_array( $entry['cb'], array_slice( $args, 1, $entry['args'] ) );
		}
	}
}

function __( $text, $domain = 'default' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $text ) { return (string) $text; }

// ── User and user-meta stubs ──────────────────────────────────────────
$GLOBALS['__user_id']   = 1;
$GLOBALS['__user_meta'] = array();

function get_current_user_id() {
	return (int) $GLOBALS['__user_id'];
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$user_id = (int) $user_id;
	if ( '' === $key ) {
		return $GLOBALS['__user_meta'][ $user_id ] ?? array();
	}
	$val = $GLOBALS['__user_meta'][ $user_id ][ $key ] ?? null;
	if ( $single ) {
		return null !== $val ? $val : '';
	}
	return null !== $val ? array( $val ) : array();
}

function update_user_meta( $user_id, $key, $value ) {
	$user_id = (int) $user_id;
	$GLOBALS['__user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

// ── Capabilities stub ─────────────────────────────────────────────────
$GLOBALS['__caps'] = array( 'manage_options' => true );
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['__caps'][ $cap ] );
}

// ── REST API stubs ────────────────────────────────────────────────────
$GLOBALS['__rest_routes'] = array();

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = (int) $status;
	}
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

class WP_REST_Request {
	private $json_params;
	private $params;
	public function __construct( $json_params = array(), $params = array() ) {
		$this->json_params = $json_params;
		$this->params      = $params;
	}
	public function get_json_params() {
		return $this->json_params;
	}
	public function get_params() {
		return $this->params;
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function rest_ensure_response( $response ) {
	if ( $response instanceof WP_REST_Response || $response instanceof WP_Error ) {
		return $response;
	}
	return new WP_REST_Response( $response );
}

function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['__rest_routes'][ $namespace ][ $route ] = $args;
	return true;
}

// ── URL & Nonce stubs ─────────────────────────────────────────────────
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}
function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . $action;
}
function plugins_url( $path = '', $plugin = '' ) {
	return SNT_URL . ltrim( (string) $path, '/' );
}

// ── OpenStation stubs ─────────────────────────────────────────────────
$GLOBALS['__os_icons'] = array();
function openstation_register_icon( $id, $args ) {
	$GLOBALS['__os_icons'][ $id ] = $args;
	return 'os-icon';
}
function openstation_register_command( $args = array() ) { return 'os-command'; }
function openstation_register_widget( $id, $args = array() ) { return 'os-widget'; }
function openstation_is_enabled() { return true; }
function openstation_register_settings_tab( $args ) { return true; }
function openstation_is_shell_request() { return true; }
function wp_register_script() { return true; }
function wp_localize_script() { return true; }
function wp_enqueue_script() { return true; }

// ── Load target files ─────────────────────────────────────────────────
require_once SNT_PATH . 'inc/openstation-compat.php';
require_once SNT_PATH . 'inc/openstation-preferences.php';
require_once SNT_PATH . 'inc/desktop-mode-dock.php';

echo "openstation-preferences-stress — Empirical Challenger Stress Suite\n\n";

// ── Section 1: Boolean Coercion Stress Matrix ──────────────────────────
echo "Section 1: snt_os_sanitize_bool() stress matrix\n";

$truthy_cases = array(
	'bool true'             => true,
	'string "1"'            => '1',
	'string " 1 "'          => ' 1 ',
	'string "true"'         => 'true',
	'string "TRUE"'         => 'TRUE',
	'string "True"'         => 'True',
	'string " true "'       => ' true ',
	'string "yes"'          => 'yes',
	'string "YES"'          => 'YES',
	'string "Yes"'          => 'Yes',
	'string " yes "'        => ' yes ',
	'string "on"'           => 'on',
	'string "ON"'           => 'ON',
	'string "On"'           => 'On',
	'string " on "'         => ' on ',
	'integer 1'             => 1,
	'float 1.0'             => 1.0,
	'float 1.99'            => 1.99,
	'non-empty array'       => array( 'a' => 'b' ),
	'non-empty list'        => array( 1 ),
	'populated stdClass'    => (object) array( 'foo' => 'bar' ),
);

foreach ( $truthy_cases as $label => $val ) {
	$result = snt_os_sanitize_bool( $val );
	ok( true === $result, "truthy matrix: $label strictly coerces to bool(true)" );
}

$falsy_cases = array(
	'bool false'            => false,
	'string "0"'            => '0',
	'string " 0 "'          => ' 0 ',
	'string "false"'        => 'false',
	'string "FALSE"'        => 'FALSE',
	'string "False"'        => 'False',
	'string " false "'      => ' false ',
	'string "no"'           => 'no',
	'string "NO"'           => 'NO',
	'string "No"'           => 'No',
	'string " no "'         => ' no ',
	'string "off"'          => 'off',
	'string "OFF"'          => 'OFF',
	'string "Off"'          => 'Off',
	'string " off "'        => ' off ',
	'empty string'          => '',
	'whitespace string'     => '   ',
	'string "null"'         => 'null',
	'string "undefined"'    => 'undefined',
	'string "NaN"'          => 'NaN',
	'string "2"'            => '2',
	'string "-1"'           => '-1',
	'string arbitrary text' => 'random_string',
	'integer 0'             => 0,
	'integer 2'             => 2,
	'integer -1'            => -1,
	'integer PHP_INT_MAX'   => PHP_INT_MAX,
	'integer PHP_INT_MIN'   => PHP_INT_MIN,
	'float 0.0'             => 0.0,
	'float 0.5'             => 0.5,
	'float -1.0'            => -1.0,
	'null'                  => null,
	'empty array'           => array(),
);

foreach ( $falsy_cases as $label => $val ) {
	$result = snt_os_sanitize_bool( $val );
	ok( false === $result, "falsy matrix: $label strictly coerces to bool(false)" );
}

// ── Section 2: REST Update Handler Stress & Edge Cases ────────────────
echo "\nSection 2: snt_os_preferences_rest_update() payload stress testing\n";

$GLOBALS['__user_id'] = 100;
snt_os_save_native_window_preferences( array( 'dashboard' => true, 'analytics' => true, 'signal-noise' => true ), 100 );

// 2.1 Nested array value
$nested_payload = array(
	'dashboard' => array( 'nested' => 'exploit', 'deep' => array( 1, 2, 3 ) ),
);
$res_nested = snt_os_preferences_rest_update( $nested_payload );
ok( $res_nested instanceof WP_REST_Response, 'nested payload returns WP_REST_Response without error' );
ok( 200 === $res_nested->get_status(), 'nested payload returns HTTP 200' );
$nested_data = $res_nested->get_data();
ok( true === $nested_data['dashboard'], 'nested non-empty array coerces safely to boolean true' );
ok( is_bool( $nested_data['dashboard'] ), 'dashboard in response is strict boolean type' );

// 2.2 Nested empty array value
$nested_empty_payload = array( 'dashboard' => array() );
$res_nested_empty = snt_os_preferences_rest_update( $nested_empty_payload );
ok( false === $res_nested_empty->get_data()['dashboard'], 'nested empty array coerces safely to boolean false' );

// 2.3 Null value in payload
$null_payload = array( 'dashboard' => null );
$res_null = snt_os_preferences_rest_update( $null_payload );
ok( false === $res_null->get_data()['dashboard'], 'null value coerces to boolean false without notice' );

// 2.4 Malicious / Prototype Pollution / SQL Injection keys
$malicious_payload = array(
	'dashboard'                   => true,
	'__proto__'                   => true,
	'constructor'                 => 'payload',
	'user_id'                     => 9999,
	'role'                        => 'administrator',
	'SELECT * FROM wp_users;'     => true,
	'<script>alert(1)</script>'   => true,
	'../../etc/passwd'            => true,
);
$res_malicious = snt_os_preferences_rest_update( $malicious_payload );
$malicious_data = $res_malicious->get_data();
ok( array( 'signal-noise', 'dashboard', 'analytics' ) === array_keys( $malicious_data ), 'malicious keys stripped: response contains only signal-noise, dashboard, analytics' );
ok( ! isset( $malicious_data['user_id'] ), 'user_id key cannot be injected into preferences' );
ok( ! isset( $malicious_data['__proto__'] ), '__proto__ key stripped' );

// Verify user meta in storage was not polluted
$stored_meta = get_user_meta( 100, SNT_OS_PREFERENCES_META, true );
ok( array( 'signal-noise', 'dashboard', 'analytics' ) === array_keys( $stored_meta ), 'user meta in DB contains only allowed preference keys' );

// 2.5 Empty payload `{}`
$empty_req = new WP_REST_Request( array() );
$res_empty = snt_os_preferences_rest_update( $empty_req );
ok( $res_empty instanceof WP_REST_Response, 'empty JSON request returns WP_REST_Response' );
ok( 200 === $res_empty->get_status(), 'empty JSON request returns 200' );
ok( array( 'signal-noise' => true, 'dashboard' => true, 'analytics' => true ) === $res_empty->get_data(), 'empty JSON request returns current preferences unchanged' );

// 2.6 Sequential list payload (non-associative array)
$list_payload = array( 'dashboard', 'analytics', 'signal-noise' );
$res_list = snt_os_preferences_rest_update( $list_payload );
ok( $res_list instanceof WP_REST_Response, 'sequential indexed array does not crash handler' );
ok( array( 'signal-noise' => true, 'dashboard' => true, 'analytics' => true ) === $res_list->get_data(), 'sequential indexed array leaves preferences untouched' );

// 2.7 Form-encoded fallback via get_params()
$fallback_req = new WP_REST_Request( null, array( 'analytics' => '0' ) );
$res_fallback = snt_os_preferences_rest_update( $fallback_req );
ok( $res_fallback instanceof WP_REST_Response, 'form-encoded fallback returns WP_REST_Response' );
ok( false === $res_fallback->get_data()['analytics'], 'form-encoded "0" correctly coerced to boolean false' );

// 2.8 Invalid payload inputs returning 400
$invalid_inputs = array(
	'string primitive'       => '{"dashboard": true}',
	'integer primitive'      => 12345,
	'boolean primitive'      => false,
	'null primitive'         => null,
	'empty stdClass'         => new stdClass(),
	'unrelated object'       => (object) array( 'dashboard' => true ),
	'request with null json' => new WP_REST_Request( null, null ),
	'request with string'    => new WP_REST_Request( 'raw_string' ),
);

foreach ( $invalid_inputs as $label => $bad_input ) {
	$res_bad = snt_os_preferences_rest_update( $bad_input );
	ok( is_wp_error( $res_bad ), "invalid input [$label] returns WP_Error" );
	if ( is_wp_error( $res_bad ) ) {
		ok( 'rest_invalid_json' === $res_bad->get_error_code(), "invalid input [$label] error code is rest_invalid_json" );
		ok( 400 === ( $res_bad->get_error_data()['status'] ?? 0 ), "invalid input [$label] status is HTTP 400" );
	}
}

// ── Section 3: Multi-User Isolation & Anti-Tampering ──────────────────
echo "\nSection 3: Multi-user isolation, state permutations, and meta corruption\n";

// 3.1 Distinct users with orthogonal preferences
$user_configs = array(
	201 => array( 'signal-noise' => true,  'dashboard' => false, 'analytics' => false ),
	202 => array( 'signal-noise' => false, 'dashboard' => true,  'analytics' => false ),
	203 => array( 'signal-noise' => false, 'dashboard' => false, 'analytics' => true  ),
	204 => array( 'signal-noise' => false, 'dashboard' => false, 'analytics' => false ),
	205 => array( 'signal-noise' => true,  'dashboard' => true,  'analytics' => true  ),
);

foreach ( $user_configs as $uid => $conf ) {
	snt_os_save_native_window_preferences( $conf, $uid );
}

// Verify isolation: reading any user does not leak into other users
foreach ( $user_configs as $uid => $expected ) {
	$actual = snt_os_native_window_preferences( $uid );
	ok( $expected === $actual, "user $uid preferences match configured permutation exactly" );
	ok( $expected['signal-noise'] === snt_os_native_window_enabled( 'signal-noise', $uid ), "user $uid signal-noise helper matches" );
	ok( $expected['dashboard'] === snt_os_native_window_enabled( 'dashboard', $uid ), "user $uid dashboard helper matches" );
	ok( $expected['analytics'] === snt_os_native_window_enabled( 'analytics', $uid ), "user $uid analytics helper matches" );
}

// 3.2 Anti-tampering: User A logged in cannot overwrite User B via REST
$GLOBALS['__user_id'] = 201; // User 201 is logged in
$tamper_request = new WP_REST_Request( array(
	'user_id'   => 205, // Attempt to tamper with User 205
	'dashboard' => false,
) );
$tamper_res = snt_os_preferences_rest_update( $tamper_request );
ok( 200 === $tamper_res->get_status(), 'tamper request executes under current user context' );

// Check that User 205 was NOT altered
$user_205_prefs = snt_os_native_window_preferences( 205 );
ok( true === $user_205_prefs['dashboard'], 'anti-tampering: User 205 preferences unaffected by User 201 request' );

// Check that User 201 was updated instead
$user_201_prefs = snt_os_native_window_preferences( 201 );
ok( false === $user_201_prefs['dashboard'], 'anti-tampering: User 201 only modified their own preference' );

// 3.3 Unauthenticated user (user_id = 0)
$GLOBALS['__user_id'] = 0;
$unauth_prefs = snt_os_native_window_preferences( 0 );
ok( array( 'signal-noise' => true, 'dashboard' => true, 'analytics' => true ) === $unauth_prefs, 'user_id 0 receives default preferences' );

// Save attempt for user_id 0 does not store meta
snt_os_save_native_window_preferences( array( 'dashboard' => false ), 0 );
ok( ! isset( $GLOBALS['__user_meta'][0] ), 'saving for user_id 0 does not write to meta table' );

// 3.4 Corrupted user meta recovery
$corrupted_users = array(
	301 => 'corrupted_string_value',
	302 => 987654,
	303 => false,
	304 => null,
	305 => 0,
	306 => array( 'unexpected_key' => 'garbage', 'dashboard' => 'invalid_truthy_attempt' ),
);

foreach ( $corrupted_users as $c_uid => $corrupted_data ) {
	update_user_meta( $c_uid, SNT_OS_PREFERENCES_META, $corrupted_data );
	$recovered = snt_os_native_window_preferences( $c_uid );
	ok( is_array( $recovered ), "corrupted user $c_uid recovers to array" );
	ok( array( 'signal-noise', 'dashboard', 'analytics' ) === array_keys( $recovered ), "corrupted user $c_uid recovers exact key schema" );
	ok( is_bool( $recovered['signal-noise'] ) && is_bool( $recovered['dashboard'] ) && is_bool( $recovered['analytics'] ), "corrupted user $c_uid all values are strictly boolean" );
}

// For user 306 specifically, verify bogus string value coerced safely to false
$user_306_recovered = snt_os_native_window_preferences( 306 );
ok( false === $user_306_recovered['dashboard'], 'corrupted user 306 invalid string coerced to bool(false)' );
ok( true === $user_306_recovered['signal-noise'], 'corrupted user 306 missing keys fall back to defaults' );
ok( ! isset( $user_306_recovered['unexpected_key'] ), 'corrupted user 306 unexpected keys filtered out' );

// ── Section 4: Shell Routing & Dock Placement Under Multi-User Context ─
echo "\nSection 4: Shell routing and dock placement isolation across users\n";

// User A (401): native windows fully enabled
$GLOBALS['__user_id'] = 401;
snt_os_save_native_window_preferences( array( 'dashboard' => true, 'analytics' => true, 'signal-noise' => true ), 401 );

// User B (402): native windows fully disabled
$GLOBALS['__user_id'] = 402;
snt_os_save_native_window_preferences( array( 'dashboard' => false, 'analytics' => false, 'signal-noise' => false ), 402 );

// Switch session to User A
$GLOBALS['__user_id'] = 401;
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'sn-theme-options' ), 'User 401: classic theme-options hidden' );
ok( 'dock'   === apply_filters( 'openstation_dock_placement', 'dock', 'app:sn-dashboard' ), 'User 401: native dashboard tile visible' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'sn-analytics' ), 'User 401: classic analytics hidden' );
ok( 'dock'   === apply_filters( 'openstation_dock_placement', 'dock', 'app:sn-analytics' ), 'User 401: native analytics tile visible' );
ok( 'dock'   === apply_filters( 'openstation_dock_placement', 'dock', 'signal-noise' ), 'User 401: signal-noise tile visible' );

// Switch session to User B
$GLOBALS['__user_id'] = 402;
ok( 'dock'   === apply_filters( 'openstation_dock_placement', 'dock', 'sn-theme-options' ), 'User 402: classic theme-options appears on dock' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'app:sn-dashboard' ), 'User 402: native dashboard tile hidden' );
ok( 'dock'   === apply_filters( 'openstation_dock_placement', 'dock', 'sn-analytics' ), 'User 402: classic analytics appears on dock' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'app:sn-analytics' ), 'User 402: native analytics tile hidden' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'signal-noise' ), 'User 402: signal-noise tile hidden' );

// Desktop Icon under User A vs User B
$GLOBALS['__user_id'] = 401;
$GLOBALS['__os_icons'] = array();
do_action( 'init' );
$icon_user_a = $GLOBALS['__os_icons']['sn-icon-dashboard'];
ok( 'sn-dashboard' === ( $icon_user_a['window'] ?? '' ), 'User 401 desktop icon targets native window sn-dashboard' );
ok( ! isset( $icon_user_a['url'] ), 'User 401 desktop icon sets no URL' );

$GLOBALS['__user_id'] = 402;
$GLOBALS['__os_icons'] = array();
do_action( 'init' );
$icon_user_b = $GLOBALS['__os_icons']['sn-icon-dashboard'];
ok( ! isset( $icon_user_b['window'] ), 'User 402 desktop icon does not set window' );
ok( admin_url( 'admin.php?page=sn-theme-options' ) === ( $icon_user_b['url'] ?? '' ), 'User 402 desktop icon targets classic URL' );

// 4.3 Rapid toggling stress cycle
$GLOBALS['__user_id'] = 403;
$toggle_states = array( false, true, false, true, false );
foreach ( $toggle_states as $idx => $toggle_val ) {
	snt_os_save_native_window_preferences( array( 'dashboard' => $toggle_val ), 403 );
	$current_val = snt_os_native_window_enabled( 'dashboard', 403 );
	ok( $toggle_val === $current_val, "rapid toggle cycle step $idx matches expected $toggle_val" );
	$expected_dock = $toggle_val ? 'hidden' : 'dock';
	$actual_dock   = apply_filters( 'openstation_dock_placement', 'dock', 'sn-theme-options' );
	ok( $expected_dock === $actual_dock, "rapid toggle cycle step $idx dock placement matches expected $expected_dock" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
