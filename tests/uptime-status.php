<?php
/**
 * Standalone fixture tests for inc/uptime-status.php (v8.2.0) — the
 * in-admin Better Stack status panel's data layer:
 *
 *   - token resolution: SN_BETTERSTACK_API_TOKEN constant wins over the
 *     non-autoloaded sn_betterstack_api_token option
 *   - sn_uptime_status_fetch(): Bearer-authed GETs to the monitors +
 *     heartbeats endpoints, normalized rows, 90s transient cache,
 *     force_refresh bypass, NO cache write on failure
 *   - level mapping: up → ok, down → alert, everything else → warn
 *   - the signal-noise/uptime-status ability (readonly, diagnostics,
 *     manage_options) and its three execute states (unconfigured /
 *     ok / unreachable)
 *   - mount + token-field HTML helpers (raw token NEVER in markup)
 *
 * Run: php tests/uptime-status.php
 *
 * @since plugin v8.2.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }

$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }

$GLOBALS['__transients'] = array();
$GLOBALS['__transient_ttls'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; $GLOBALS['__transient_ttls'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// HTTP boundary: capture every request; serve queued canned responses.
$GLOBALS['__http_requests'] = array();
$GLOBALS['__http_queue']    = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__http_requests'][] = array( 'url' => $url, 'args' => $args );
	$resp = array_shift( $GLOBALS['__http_queue'] );
	return null === $resp ? new WP_Error( 'http_request_failed', 'no queued response' ) : $resp;
}
function wp_remote_retrieve_response_code( $resp ) { return is_wp_error( $resp ) ? 0 : (int) ( $resp['code'] ?? 0 ); }
function wp_remote_retrieve_body( $resp ) { return is_wp_error( $resp ) ? '' : (string) ( $resp['body'] ?? '' ); }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }

// sn_mask_secret lives in inc/settings.php; mirror its contract here so the
// module under test stays the only real require.
function sn_mask_secret( $value ) {
	$value = (string) $value;
	if ( '' === $value ) { return ''; }
	return strlen( $value ) <= 8 ? '••••••••' : '••••' . substr( $value, -4 );
}

require_once __DIR__ . '/../inc/uptime-status.php';

$pass = 0;
$fail = 0;
function us_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function us_ok( $c, $msg ) { us_eq( true, (bool) $c, $msg ); }

function us_monitors_body() {
	return wp_json_encode_stub( array( 'data' => array(
		array( 'id' => '1', 'type' => 'monitor', 'attributes' => array( 'pronounceable_name' => 'Home', 'url' => 'https://juanlentino.com/', 'status' => 'up', 'last_checked_at' => '2026-07-02T22:00:00.000Z' ) ),
		array( 'id' => '2', 'type' => 'monitor', 'attributes' => array( 'pronounceable_name' => 'Notes', 'url' => 'https://juanlentino.com/notes/', 'status' => 'down', 'last_checked_at' => '2026-07-02T22:01:00.000Z' ) ),
	) ) );
}
function us_heartbeats_body() {
	return wp_json_encode_stub( array( 'data' => array(
		array( 'id' => '9', 'type' => 'heartbeat', 'attributes' => array( 'name' => 'WP-Cron heartbeat', 'status' => 'up' ) ),
	) ) );
}
// SLA/availability summary (v8.3.0): monitors use /sla, heartbeats use
// /availability — same data.attributes shape (verified against the Better
// Stack docs 2026-07-02). data is an OBJECT here, not a list.
function us_sla_body( $availability, $incidents ) {
	return wp_json_encode_stub( array( 'data' => array(
		'id'         => 'x',
		'type'       => 'monitor_sla',
		'attributes' => array(
			'availability'        => $availability,
			'total_downtime'      => 335,
			'number_of_incidents' => $incidents,
			'longest_incident'    => 194,
			'average_incident'    => 67,
		),
	) ) );
}
function wp_json_encode_stub( $v ) { return json_encode( $v ); }
function us_window_from() { return gmdate( 'Y-m-d', time() - 30 * 86400 ); }
function us_window_to() { return gmdate( 'Y-m-d' ); }

// ─── Test 1: unconfigured — no token anywhere ────────────────────────
echo "\nTest 1: unconfigured state\n";
us_eq( '', sn_uptime_status_token(), 'token empty with no constant and no option' );
us_eq( false, sn_uptime_status_configured(), 'configured() false' );
$r = sn_uptime_status_fetch();
us_ok( is_wp_error( $r ), 'fetch returns WP_Error when unconfigured' );
us_eq( 'not_configured', is_wp_error( $r ) ? $r->get_error_code() : '', 'error code not_configured' );
us_eq( 0, count( $GLOBALS['__http_requests'] ), 'no HTTP when unconfigured' );

// ─── Test 2: option-stored token + happy-path fetch ──────────────────
echo "\nTest 2: fetch normalizes monitors + heartbeats\n";
update_option( 'sn_betterstack_api_token', 'secret-token-abcd1234', false );
us_eq( 'secret-token-abcd1234', sn_uptime_status_token(), 'option token resolves' );
us_eq( true, sn_uptime_status_configured(), 'configured() true' );

$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => us_monitors_body() ),
	array( 'code' => 200, 'body' => us_heartbeats_body() ),
	array( 'code' => 200, 'body' => us_sla_body( 99.98, 0 ) ),
	array( 'code' => 200, 'body' => us_sla_body( 98.5, 3 ) ),
	array( 'code' => 200, 'body' => us_sla_body( 100, 0 ) ),
);
$snap = sn_uptime_status_fetch();
us_ok( is_array( $snap ), 'fetch returns a snapshot array' );
us_eq( 5, count( $GLOBALS['__http_requests'] ), 'five HTTP GETs (monitors + heartbeats + 3 availability)' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors', $GLOBALS['__http_requests'][0]['url'], 'monitors endpoint' );
us_eq( 'https://uptime.betterstack.com/api/v2/heartbeats', $GLOBALS['__http_requests'][1]['url'], 'heartbeats endpoint' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors/1/sla?from=' . us_window_from() . '&to=' . us_window_to(),
	$GLOBALS['__http_requests'][2]['url'], 'monitor SLA endpoint with 30d window' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors/2/sla?from=' . us_window_from() . '&to=' . us_window_to(),
	$GLOBALS['__http_requests'][3]['url'], 'second monitor SLA endpoint' );
us_eq( 'https://uptime.betterstack.com/api/v2/heartbeats/9/availability?from=' . us_window_from() . '&to=' . us_window_to(),
	$GLOBALS['__http_requests'][4]['url'], 'heartbeat availability endpoint (different suffix than monitors)' );
us_eq( 'Bearer secret-token-abcd1234', $GLOBALS['__http_requests'][0]['args']['headers']['Authorization'] ?? '', 'Bearer auth on monitors call' );
us_eq( 'Bearer secret-token-abcd1234', $GLOBALS['__http_requests'][4]['args']['headers']['Authorization'] ?? '', 'Bearer auth on availability call' );
us_eq( 3, count( $snap['rows'] ), 'three normalized rows' );
us_eq( array( 'monitor', '1', 'Home', 'up', 'ok', '2026-07-02T22:00:00.000Z' ),
	array( $snap['rows'][0]['kind'], $snap['rows'][0]['id'], $snap['rows'][0]['name'], $snap['rows'][0]['status'], $snap['rows'][0]['level'], $snap['rows'][0]['checked_at'] ),
	'monitor row normalized (up → ok, carries id)' );
us_eq( 'alert', $snap['rows'][1]['level'], 'down → alert' );
us_eq( array( 'heartbeat', 'WP-Cron heartbeat', 'up', 'ok', null ),
	array( $snap['rows'][2]['kind'], $snap['rows'][2]['name'], $snap['rows'][2]['status'], $snap['rows'][2]['level'], $snap['rows'][2]['checked_at'] ),
	'heartbeat row normalized (no checked_at)' );
us_eq( array( 99.98, 0 ), array( $snap['rows'][0]['availability'], $snap['rows'][0]['incidents_30d'] ), 'row 0 availability merged' );
us_eq( array( 98.5, 3 ), array( $snap['rows'][1]['availability'], $snap['rows'][1]['incidents_30d'] ), 'row 1 availability merged' );
us_eq( array( 100.0, 0 ), array( (float) $snap['rows'][2]['availability'], $snap['rows'][2]['incidents_30d'] ), 'heartbeat availability merged' );
us_ok( ! empty( $snap['fetched_at'] ), 'snapshot carries fetched_at' );
us_eq( 90, $GLOBALS['__transient_ttls']['sn_uptime_status_snapshot'] ?? 0, 'cached in a 90s transient' );
us_eq( 3600, $GLOBALS['__transient_ttls']['sn_uptime_availability'] ?? 0, 'availability map cached for an hour' );

// ─── Test 3: transient cache short-circuits HTTP ─────────────────────
echo "\nTest 3: cache behavior\n";
$before = count( $GLOBALS['__http_requests'] );
$snap2  = sn_uptime_status_fetch();
us_eq( $before, count( $GLOBALS['__http_requests'] ), 'cached fetch makes no HTTP calls' );
us_eq( $snap['fetched_at'], $snap2['fetched_at'], 'same snapshot returned' );

$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => us_monitors_body() ),
	array( 'code' => 200, 'body' => us_heartbeats_body() ),
);
sn_uptime_status_fetch( true );
us_eq( $before + 2, count( $GLOBALS['__http_requests'] ), 'force refresh bypasses the snapshot but rides the warm availability map (no SLA re-fetch)' );

// ─── Test 4: API failure — WP_Error, nothing cached ──────────────────
echo "\nTest 4: failure handling\n";
delete_transient( 'sn_uptime_status_snapshot' );
$GLOBALS['__http_queue'] = array( array( 'code' => 500, 'body' => 'oops' ) );
$r = sn_uptime_status_fetch();
us_ok( is_wp_error( $r ), 'non-200 yields WP_Error' );
us_eq( 'unreachable', is_wp_error( $r ) ? $r->get_error_code() : '', 'error code unreachable' );
us_eq( false, get_transient( 'sn_uptime_status_snapshot' ), 'failures are NOT cached' );

delete_transient( 'sn_uptime_status_snapshot' );
$GLOBALS['__http_queue'] = array(); // transport-level WP_Error
$r = sn_uptime_status_fetch();
us_ok( is_wp_error( $r ), 'transport WP_Error yields WP_Error' );

// ─── Test 4b: availability failure is SOFT + circuit-broken ──────────
echo "\nTest 4b: availability circuit break\n";
delete_transient( 'sn_uptime_status_snapshot' );
delete_transient( 'sn_uptime_availability' );
$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => us_monitors_body() ),
	array( 'code' => 200, 'body' => us_heartbeats_body() ),
	array( 'code' => 500, 'body' => 'sla down' ), // first SLA call fails
);
$before = count( $GLOBALS['__http_requests'] );
$snap = sn_uptime_status_fetch();
us_ok( is_array( $snap ), 'snapshot still returned when availability fails' );
us_eq( 3, count( $snap['rows'] ), 'statuses intact without availability' );
us_eq( $before + 3, count( $GLOBALS['__http_requests'] ), 'circuit break: remaining SLA calls skipped after the first failure' );
us_eq( array( null, null, null ), array( $snap['rows'][0]['availability'], $snap['rows'][1]['availability'], $snap['rows'][2]['availability'] ), 'availability null across rows' );
us_eq( 600, $GLOBALS['__transient_ttls']['sn_uptime_availability'] ?? 0, 'failed map short-cached (10 min) so a down SLA API is not hammered' );

// ─── Test 5: level map covers every documented status ────────────────
echo "\nTest 5: level mapping\n";
us_eq( 'ok', sn_uptime_status_level( 'up' ), 'up → ok' );
us_eq( 'alert', sn_uptime_status_level( 'down' ), 'down → alert' );
foreach ( array( 'paused', 'pending', 'maintenance', 'validating' ) as $s ) {
	us_eq( 'warn', sn_uptime_status_level( $s ), "$s → warn" );
}

// ─── Test 6: the ability — registration + execute states ─────────────
echo "\nTest 6: signal-noise/uptime-status ability\n";
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$ab = $GLOBALS['__abilities']['signal-noise/uptime-status'] ?? null;
us_ok( is_array( $ab ), 'ability registered on wp_abilities_api_init' );
us_eq( 'diagnostics', $ab['category'] ?? '', 'category diagnostics' );
us_eq( 'snt_ability_perm_manage_options', $ab['permission_callback'] ?? '', 'manage_options permission' );
us_eq( true, $ab['meta']['annotations']['readonly'] ?? false, 'annotated readonly (GET verb)' );
us_eq( true, $ab['meta']['annotations']['idempotent'] ?? false, 'annotated idempotent' );

$exec = $ab['execute_callback'] ?? '';
delete_transient( 'sn_uptime_status_snapshot' );
delete_transient( 'sn_uptime_availability' );
$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => us_monitors_body() ),
	array( 'code' => 200, 'body' => us_heartbeats_body() ),
	array( 'code' => 200, 'body' => us_sla_body( 99.98, 0 ) ),
	array( 'code' => 200, 'body' => us_sla_body( 98.5, 3 ) ),
	array( 'code' => 200, 'body' => us_sla_body( 100, 0 ) ),
);
$out = call_user_func( $exec, null );
us_eq( true, $out['configured'], 'execute: configured true' );
us_eq( 3, count( $out['rows'] ), 'execute: rows present' );
us_eq( 99.98, $out['rows'][0]['availability'], 'execute: availability rides the rows' );
us_eq( '', $out['error'], 'execute: no error on success' );

delete_transient( 'sn_uptime_status_snapshot' );
$GLOBALS['__http_queue'] = array( array( 'code' => 500, 'body' => '' ) );
$out = call_user_func( $exec, null );
us_eq( true, $out['configured'], 'execute (unreachable): still configured' );
us_eq( array(), $out['rows'], 'execute (unreachable): empty rows' );
us_ok( '' !== $out['error'], 'execute (unreachable): error message set' );

// ─── Test 7: HTML helpers — mount + token field (option path) ────────
echo "\nTest 7: HTML helpers\n";
$mount = sn_uptime_status_mount_html();
us_ok( false !== strpos( $mount, 'data-sn-uptime-status' ), 'mount carries the JS hook attribute' );
us_ok( false === strpos( $mount, 'sn-status-box' ), 'mount is its own container (never a child of .sn-status-box)' );

$field = sn_uptime_status_token_field_html();
us_ok( false !== strpos( $field, 'name="sn_betterstack_token"' ), 'field posts as sn_betterstack_token' );
us_ok( false === strpos( $field, 'secret-token-abcd1234' ), 'raw token NEVER rendered' );
us_ok( false !== strpos( $field, '••••1234' ), 'masked token shown (last 4)' );

// ─── Test 8: unconfigured execute + empty-token field ────────────────
echo "\nTest 8: unconfigured ability + field\n";
delete_option( 'sn_betterstack_api_token' );
delete_transient( 'sn_uptime_status_snapshot' );
$out = call_user_func( $exec, null );
us_eq( false, $out['configured'], 'execute: configured false without token' );
us_eq( array(), $out['rows'], 'execute: no rows without token' );
us_eq( '', $out['error'], 'execute: unconfigured is not an error state' );
$field = sn_uptime_status_token_field_html();
us_ok( false !== strpos( $field, 'name="sn_betterstack_token"' ), 'empty field still posts as sn_betterstack_token' );

// ─── Test 9: constant wins (defined last — constants are sticky) ─────
echo "\nTest 9: SN_BETTERSTACK_API_TOKEN constant\n";
define( 'SN_BETTERSTACK_API_TOKEN', 'const-token-wxyz9876' );
update_option( 'sn_betterstack_api_token', 'option-should-lose', false );
us_eq( 'const-token-wxyz9876', sn_uptime_status_token(), 'constant wins over option' );
$field = sn_uptime_status_token_field_html();
us_ok( false !== strpos( $field, 'disabled' ), 'constant-locked field is disabled' );
us_ok( false !== strpos( $field, 'SN_BETTERSTACK_API_TOKEN' ), 'locked helper names the constant' );
us_ok( false === strpos( $field, 'const-token-wxyz9876' ), 'raw constant token never rendered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
