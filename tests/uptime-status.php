<?php
/**
 * Standalone fixture tests for inc/uptime-status.php — the Better Stack
 * status data layer (v8.2.0; detail tier v8.4.0):
 *
 *   - token resolution: SN_BETTERSTACK_API_TOKEN constant wins over the
 *     non-autoloaded sn_betterstack_api_token option
 *   - sn_uptime_status_fetch(): STATUSES ONLY since v8.4.0 (2 Bearer-authed
 *     GETs, 90s transient, failures never cached) — the stats moved to the
 *     detail tier when the display moved to the Analytics page
 *   - sn_uptime_status_detail(): availability 30d + 90d (per-window maps,
 *     1h / 6h TTLs), avg response times (24h endpoint, 15 min TTL),
 *     incidents log (v3 endpoint, 5 min TTL, sorted newest first) — every
 *     tier independent, soft-failing, and circuit-broken
 *   - the signal-noise/uptime-status ability: detail=false (light payload,
 *     null stats) vs detail=true (full payload + incidents)
 *   - mount + token-field HTML helpers (raw token NEVER in markup)
 *
 * Run: php tests/uptime-status.php
 *
 * @since plugin v8.2.0, reworked v8.4.0
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
function us_clear_all_transients() { $GLOBALS['__transients'] = array(); }

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

function wp_json_encode_stub( $v ) { return json_encode( $v ); }
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
// SLA/availability summary: monitors use /sla, heartbeats use /availability —
// same data.attributes shape; data is an OBJECT here, not a list.
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
// Response times (v2, last 24h): data.attributes.regions[] each carrying
// timestamped samples with response_time in SECONDS.
function us_response_body( $regions ) {
	$region_objs = array();
	foreach ( $regions as $name => $times ) {
		$samples = array();
		foreach ( $times as $t ) {
			$samples[] = array( 'at' => '2026-07-02T20:00:00.000Z', 'response_time' => $t );
		}
		$region_objs[] = array( 'region' => $name, 'response_times' => $samples );
	}
	return wp_json_encode_stub( array( 'data' => array(
		'id'         => 'x',
		'type'       => 'monitor_response_times',
		'attributes' => array( 'regions' => $region_objs ),
	) ) );
}
// Incidents (v3): list, resolved_at null when ongoing. Deliberately out of
// order (older first) to exercise the newest-first sort.
function us_incidents_body() {
	return wp_json_encode_stub( array( 'data' => array(
		array( 'id' => '77', 'type' => 'incident', 'attributes' => array(
			'name' => 'Home', 'url' => 'https://juanlentino.com/', 'cause' => 'Status 500',
			'started_at' => '2026-07-01T10:00:00.000Z', 'resolved_at' => '2026-07-01T10:12:00.000Z',
		) ),
		array( 'id' => '78', 'type' => 'incident', 'attributes' => array(
			'name' => 'Notes', 'url' => 'https://juanlentino.com/notes/', 'cause' => 'Timeout',
			'started_at' => '2026-07-02T21:00:00.000Z', 'resolved_at' => null,
		) ),
	) ) );
}
function us_win_from( $days ) { return gmdate( 'Y-m-d', time() - $days * 86400 ); }
function us_win_to() { return gmdate( 'Y-m-d' ); }
function us_queue_statuses() {
	$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_monitors_body() );
	$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_heartbeats_body() );
}
function us_queue_sla_trio() {
	$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_sla_body( 99.98, 0 ) );
	$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_sla_body( 98.5, 3 ) );
	$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_sla_body( 100, 0 ) );
}

// ─── Test 1: unconfigured — no token anywhere ────────────────────────
echo "\nTest 1: unconfigured state\n";
us_eq( '', sn_uptime_status_token(), 'token empty with no constant and no option' );
us_eq( false, sn_uptime_status_configured(), 'configured() false' );
$r = sn_uptime_status_fetch();
us_ok( is_wp_error( $r ), 'fetch returns WP_Error when unconfigured' );
us_eq( 'not_configured', is_wp_error( $r ) ? $r->get_error_code() : '', 'error code not_configured' );
$r = sn_uptime_status_detail();
us_ok( is_wp_error( $r ), 'detail returns WP_Error when unconfigured' );
us_eq( 0, count( $GLOBALS['__http_requests'] ), 'no HTTP when unconfigured' );

// ─── Test 2: base fetch — statuses ONLY since v8.4.0 ─────────────────
echo "\nTest 2: base fetch normalizes monitors + heartbeats (statuses only)\n";
update_option( 'sn_betterstack_api_token', 'secret-token-abcd1234', false );
us_eq( true, sn_uptime_status_configured(), 'configured() true' );

$GLOBALS['__http_queue'] = array();
us_queue_statuses();
$snap = sn_uptime_status_fetch();
us_ok( is_array( $snap ), 'fetch returns a snapshot array' );
us_eq( 2, count( $GLOBALS['__http_requests'] ), 'TWO HTTP GETs only — no SLA calls on the light path (the widget path stays cheap)' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors', $GLOBALS['__http_requests'][0]['url'], 'monitors endpoint' );
us_eq( 'https://uptime.betterstack.com/api/v2/heartbeats', $GLOBALS['__http_requests'][1]['url'], 'heartbeats endpoint' );
us_eq( 'Bearer secret-token-abcd1234', $GLOBALS['__http_requests'][0]['args']['headers']['Authorization'] ?? '', 'Bearer auth' );
us_eq( 3, count( $snap['rows'] ), 'three normalized rows' );
us_eq( array( 'monitor', '1', 'Home', 'up', 'ok', '2026-07-02T22:00:00.000Z' ),
	array( $snap['rows'][0]['kind'], $snap['rows'][0]['id'], $snap['rows'][0]['name'], $snap['rows'][0]['status'], $snap['rows'][0]['level'], $snap['rows'][0]['checked_at'] ),
	'monitor row normalized (up → ok, carries id)' );
us_eq( 'alert', $snap['rows'][1]['level'], 'down → alert' );
us_eq( array( 'heartbeat', 'WP-Cron heartbeat', 'up', 'ok', null ),
	array( $snap['rows'][2]['kind'], $snap['rows'][2]['name'], $snap['rows'][2]['status'], $snap['rows'][2]['level'], $snap['rows'][2]['checked_at'] ),
	'heartbeat row normalized (no checked_at)' );
us_ok( ! array_key_exists( 'availability', $snap['rows'][0] ), 'base rows carry NO stat keys (detail tier owns them)' );
us_eq( 90, $GLOBALS['__transient_ttls']['sn_uptime_status_snapshot'] ?? 0, 'cached in a 90s transient' );

// ─── Test 3: base cache behavior ─────────────────────────────────────
echo "\nTest 3: base cache behavior\n";
$before = count( $GLOBALS['__http_requests'] );
$snap2  = sn_uptime_status_fetch();
us_eq( $before, count( $GLOBALS['__http_requests'] ), 'cached fetch makes no HTTP calls' );
us_eq( $snap['fetched_at'], $snap2['fetched_at'], 'same snapshot returned' );
$GLOBALS['__http_queue'] = array();
us_queue_statuses();
sn_uptime_status_fetch( true );
us_eq( $before + 2, count( $GLOBALS['__http_requests'] ), 'force refresh bypasses the snapshot transient' );

// ─── Test 4: base failure — WP_Error, nothing cached ─────────────────
echo "\nTest 4: base failure handling\n";
delete_transient( 'sn_uptime_status_snapshot' );
$GLOBALS['__http_queue'] = array( array( 'code' => 500, 'body' => 'oops' ) );
$r = sn_uptime_status_fetch();
us_ok( is_wp_error( $r ), 'non-200 yields WP_Error' );
us_eq( 'unreachable', is_wp_error( $r ) ? $r->get_error_code() : '', 'error code unreachable' );
us_eq( false, get_transient( 'sn_uptime_status_snapshot' ), 'failures are NOT cached' );
delete_transient( 'sn_uptime_status_snapshot' );
$GLOBALS['__http_queue'] = array(); // transport-level WP_Error
us_ok( is_wp_error( sn_uptime_status_fetch() ), 'transport WP_Error yields WP_Error' );

// ─── Test 5: detail — full monitor payload (v8.4.0) ──────────────────
echo "\nTest 5: detail fetch — windows + response times + incidents\n";
us_clear_all_transients();
$GLOBALS['__http_requests'] = array();
$GLOBALS['__http_queue']    = array();
us_queue_statuses();
us_queue_sla_trio(); // 30d: m1, m2, hb9
us_queue_sla_trio(); // 90d: m1, m2, hb9 (same canned numbers, fine)
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_response_body( array( 'us' => array( 0.2, 0.4 ), 'eu' => array( 0.3 ) ) ) ); // m1 avg 0.3s
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_response_body( array( 'us' => array( 0.5 ) ) ) );                              // m2 avg 0.5s
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_incidents_body() );

$detail = sn_uptime_status_detail();
us_ok( is_array( $detail ), 'detail returns a payload array' );
us_eq( 11, count( $GLOBALS['__http_requests'] ), '11 calls cold: 2 statuses + 3 SLA(30d) + 3 SLA(90d) + 2 response-times + 1 incidents' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors/1/sla?from=' . us_win_from( 30 ) . '&to=' . us_win_to(),
	$GLOBALS['__http_requests'][2]['url'], '30d SLA window' );
us_eq( 'https://uptime.betterstack.com/api/v2/heartbeats/9/availability?from=' . us_win_from( 30 ) . '&to=' . us_win_to(),
	$GLOBALS['__http_requests'][4]['url'], 'heartbeat availability (different suffix)' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors/1/sla?from=' . us_win_from( 90 ) . '&to=' . us_win_to(),
	$GLOBALS['__http_requests'][5]['url'], '90d SLA window' );
us_eq( 'https://uptime.betterstack.com/api/v2/monitors/1/response-times',
	$GLOBALS['__http_requests'][8]['url'], 'response-times endpoint (monitors only, no heartbeats)' );
us_eq( 'https://uptime.betterstack.com/api/v3/incidents?per_page=25',
	$GLOBALS['__http_requests'][10]['url'], 'incidents endpoint is on the v3 API' );

us_eq( array( 99.98, 0, 99.98, 300 ),
	array( $detail['rows'][0]['availability'], $detail['rows'][0]['incidents_30d'], $detail['rows'][0]['availability_90d'], $detail['rows'][0]['response_ms'] ),
	'monitor row: 30d + 90d availability, incidents, avg response 300ms' );
us_eq( 500, $detail['rows'][1]['response_ms'], 'second monitor avg response 500ms' );
us_eq( null, $detail['rows'][2]['response_ms'], 'heartbeats have no response times' );
us_eq( 100.0, $detail['rows'][2]['availability'], 'heartbeat availability merged' );

us_eq( 2, count( $detail['incidents'] ), 'two incidents in the log' );
us_eq( array( 'Notes', 'Timeout', true, null ),
	array( $detail['incidents'][0]['name'], $detail['incidents'][0]['cause'], $detail['incidents'][0]['ongoing'], $detail['incidents'][0]['duration_s'] ),
	'newest (ongoing) incident sorted first, null duration' );
us_eq( array( 'Home', 'Status 500', false, 720 ),
	array( $detail['incidents'][1]['name'], $detail['incidents'][1]['cause'], $detail['incidents'][1]['ongoing'], $detail['incidents'][1]['duration_s'] ),
	'resolved incident carries its duration in seconds' );

us_eq( 3600, $GLOBALS['__transient_ttls']['sn_uptime_availability'] ?? 0, '30d map cached 1h' );
us_eq( 21600, $GLOBALS['__transient_ttls']['sn_uptime_availability_90d'] ?? 0, '90d map cached 6h' );
us_eq( 900, $GLOBALS['__transient_ttls']['sn_uptime_response_times'] ?? 0, 'response map cached 15 min' );
us_eq( 300, $GLOBALS['__transient_ttls']['sn_uptime_incidents'] ?? 0, 'incidents cached 5 min' );

$before = count( $GLOBALS['__http_requests'] );
$detail2 = sn_uptime_status_detail();
us_eq( $before, count( $GLOBALS['__http_requests'] ), 'warm detail makes ZERO HTTP calls (every tier cached)' );
us_eq( $detail['rows'][0]['availability'], $detail2['rows'][0]['availability'], 'same detail payload returned' );

// ─── Test 6: detail tiers fail SOFT, independently, circuit-broken ───
echo "\nTest 6: detail tier independence + circuit break\n";
us_clear_all_transients();
$GLOBALS['__http_requests'] = array();
$GLOBALS['__http_queue']    = array();
us_queue_statuses();
$GLOBALS['__http_queue'][] = array( 'code' => 500, 'body' => 'sla down' ); // first 30d call fails → circuit
us_queue_sla_trio(); // 90d proceeds
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_response_body( array( 'us' => array( 0.2 ) ) ) );
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_response_body( array( 'us' => array( 0.4 ) ) ) );
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_incidents_body() );

$detail = sn_uptime_status_detail();
us_ok( is_array( $detail ), 'detail still returned when a tier fails' );
us_eq( 9, count( $GLOBALS['__http_requests'] ), 'circuit break: remaining 30d SLA calls skipped; other tiers proceed (2+1+3+2+1)' );
us_eq( null, $detail['rows'][0]['availability'], '30d availability null after its tier broke' );
us_eq( 99.98, $detail['rows'][0]['availability_90d'], '90d tier unaffected by the 30d break' );
us_eq( 200, $detail['rows'][0]['response_ms'], 'response tier unaffected' );
us_eq( 2, count( $detail['incidents'] ), 'incidents tier unaffected' );
us_eq( 600, $GLOBALS['__transient_ttls']['sn_uptime_availability'] ?? 0, 'broken 30d map short-cached (10 min)' );

// Incidents-only failure: statuses + maps warm, incidents cold + failing.
delete_transient( 'sn_uptime_incidents' );
$GLOBALS['__http_queue'] = array( array( 'code' => 500, 'body' => '' ) );
$detail = sn_uptime_status_detail();
us_eq( null, $detail['incidents'], 'incidents failure yields null (renderers show unavailable), rows intact' );
us_eq( 3, count( $detail['rows'] ), 'rows survive an incidents failure' );
us_eq( false, get_transient( 'sn_uptime_incidents' ), 'failed incidents fetch is NOT cached' );

// ─── Test 7: level map covers every documented status ────────────────
echo "\nTest 7: level mapping\n";
us_eq( 'ok', sn_uptime_status_level( 'up' ), 'up → ok' );
us_eq( 'alert', sn_uptime_status_level( 'down' ), 'down → alert' );
foreach ( array( 'paused', 'pending', 'maintenance', 'validating' ) as $s ) {
	us_eq( 'warn', sn_uptime_status_level( $s ), "$s → warn" );
}

// ─── Test 8: the ability — registration + light/detail payloads ──────
echo "\nTest 8: signal-noise/uptime-status ability\n";
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$ab = $GLOBALS['__abilities']['signal-noise/uptime-status'] ?? null;
us_ok( is_array( $ab ), 'ability registered on wp_abilities_api_init' );
us_eq( 'diagnostics', $ab['category'] ?? '', 'category diagnostics' );
us_eq( 'snt_ability_perm_manage_options', $ab['permission_callback'] ?? '', 'manage_options permission' );
us_eq( true, $ab['meta']['annotations']['readonly'] ?? false, 'annotated readonly (GET verb)' );
us_ok( isset( $ab['input_schema']['properties']['detail'] ), 'input schema exposes the detail flag' );

$exec = $ab['execute_callback'] ?? '';
us_clear_all_transients();
$GLOBALS['__http_queue'] = array();
us_queue_statuses();
$out = call_user_func( $exec, null );
us_eq( true, $out['configured'], 'light execute: configured true' );
us_eq( 3, count( $out['rows'] ), 'light execute: rows present' );
us_eq( null, $out['rows'][0]['availability'], 'light execute: stat keys present but null (stable shape)' );
us_eq( null, $out['incidents'], 'light execute: no incidents payload' );
us_eq( '', $out['error'], 'light execute: no error' );

us_clear_all_transients();
$GLOBALS['__http_queue'] = array();
us_queue_statuses();
us_queue_sla_trio();
us_queue_sla_trio();
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_response_body( array( 'us' => array( 0.2 ) ) ) );
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_response_body( array( 'us' => array( 0.4 ) ) ) );
$GLOBALS['__http_queue'][] = array( 'code' => 200, 'body' => us_incidents_body() );
$out = call_user_func( $exec, array( 'detail' => true ) );
us_eq( 99.98, $out['rows'][0]['availability'], 'detail execute: stats populated' );
us_eq( 99.98, $out['rows'][0]['availability_90d'], 'detail execute: 90d populated' );
us_eq( 2, count( $out['incidents'] ), 'detail execute: incidents present' );

us_clear_all_transients();
$GLOBALS['__http_queue'] = array( array( 'code' => 500, 'body' => '' ) );
$out = call_user_func( $exec, null );
us_eq( true, $out['configured'], 'execute (unreachable): still configured' );
us_eq( array(), $out['rows'], 'execute (unreachable): empty rows' );
us_ok( '' !== $out['error'], 'execute (unreachable): error message set' );

// ─── Test 9: HTML helpers — mount + token field (option path) ────────
echo "\nTest 9: HTML helpers\n";
$mount = sn_uptime_status_mount_html();
us_ok( false !== strpos( $mount, 'data-sn-uptime-status' ), 'mount carries the JS hook attribute' );
us_ok( false === strpos( $mount, 'sn-status-box' ), 'mount is its own container (never a child of .sn-status-box)' );

$field = sn_uptime_status_token_field_html();
us_ok( false !== strpos( $field, 'name="sn_betterstack_token"' ), 'field posts as sn_betterstack_token' );
us_ok( false === strpos( $field, 'secret-token-abcd1234' ), 'raw token NEVER rendered' );
us_ok( false !== strpos( $field, '••••1234' ), 'masked token shown (last 4)' );

// ─── Test 10: unconfigured execute + empty-token field ───────────────
echo "\nTest 10: unconfigured ability + field\n";
delete_option( 'sn_betterstack_api_token' );
us_clear_all_transients();
$out = call_user_func( $exec, null );
us_eq( false, $out['configured'], 'execute: configured false without token' );
us_eq( array(), $out['rows'], 'execute: no rows without token' );
us_eq( '', $out['error'], 'execute: unconfigured is not an error state' );

// ─── Test 11: constant wins (defined last — constants are sticky) ────
echo "\nTest 11: SN_BETTERSTACK_API_TOKEN constant\n";
define( 'SN_BETTERSTACK_API_TOKEN', 'const-token-wxyz9876' );
update_option( 'sn_betterstack_api_token', 'option-should-lose', false );
us_eq( 'const-token-wxyz9876', sn_uptime_status_token(), 'constant wins over option' );
$field = sn_uptime_status_token_field_html();
us_ok( false !== strpos( $field, 'disabled' ), 'constant-locked field is disabled' );
us_ok( false !== strpos( $field, 'SN_BETTERSTACK_API_TOKEN' ), 'locked helper names the constant' );
us_ok( false === strpos( $field, 'const-token-wxyz9876' ), 'raw constant token never rendered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
