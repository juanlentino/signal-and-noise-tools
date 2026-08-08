<?php
/**
 * Standalone fixture tests for inc/abilities-collector-status.php (v9.81.0):
 * the signal-noise/get-collector-status readonly ability — named invariants
 * over the analytics worker's /_sn/version payload.
 *
 * Run: php tests/abilities-collector-status.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_VERSION', 'test' );

// ── WP stubs ────────────────────────────────────────────────────────────────
$GLOBALS['__cs_actions'] = array();
function add_action( $tag, $cb = null ) { $GLOBALS['__cs_actions'][ $tag ][] = $cb; return true; }
$GLOBALS['__cs_abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__cs_abilities'][ $slug ] = $args; return true; }

$GLOBALS['__cs_http'] = array( 'code' => 200, 'body' => '{}' );
$GLOBALS['__cs_http_log'] = array();
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['__cs_http_log'][] = array( 'url' => $url, 'args' => $args );
	if ( 0 === (int) $GLOBALS['__cs_http']['code'] ) { return new WP_Error(); }
	return $GLOBALS['__cs_http'];
}
class WP_Error {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function sn_worker_version_endpoint_url() { return $GLOBALS['__cs_endpoint'] ?? 'https://collector.test/_sn/version'; }

require __DIR__ . '/../inc/abilities-collector-status.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function inv( $res, $name ) {
	foreach ( $res['invariants'] as $i ) { if ( $name === $i['name'] ) { return $i; } }
	return null;
}

$NOW = time();
$GOOD = array(
	'worker'  => 'sn-analytics',
	'version' => '1.14.0',
	'config'  => array( 'ae_bound' => true, 'px_token_set' => true, 'kv_bound' => true ),
	'salt'    => array( 'today_present' => true, 'prev_present' => true ),
	'cron'    => array( 'refresh_status' => 'ok', 'at' => gmdate( 'Y-m-d\TH:i:s\Z', $NOW - 600 ) ),
);

echo "abilities-collector-status — v9.81.0\n\nGroup: pure invariant evaluator\n";
$r = sn_collector_status_invariants( $GOOD, $NOW );
ok( true === $r['healthy'], 'a fully sane payload is healthy' );
ok( 4 === count( $r['invariants'] ), 'exactly the four named invariants are evaluated' );
$names = array_map( static function ( $i ) { return $i['name']; }, $r['invariants'] );
ok( array( 'config_bindings', 'salt_window', 'version_present', 'cron_fresh' ) === $names,
	'invariants carry their contract names in order' );
foreach ( $r['invariants'] as $i ) { ok( true === $i['ok'] && '' !== $i['detail'], "invariant {$i['name']} ok with a detail sentence" ); }

// config_bindings: any false binding fails and is NAMED.
$bad = $GOOD; $bad['config']['px_token_set'] = false;
$r = sn_collector_status_invariants( $bad, $NOW );
ok( false === $r['healthy'], 'a false binding makes the whole status unhealthy' );
$i = inv( $r, 'config_bindings' );
ok( false === $i['ok'] && false !== strpos( $i['detail'], 'px_token_set' ), 'the false binding is named in the detail' );

// salt_window: today_present must be true.
$bad = $GOOD; $bad['salt']['today_present'] = false;
$i = inv( sn_collector_status_invariants( $bad, $NOW ), 'salt_window' );
ok( false === $i['ok'] && false !== strpos( $i['detail'], 'MISSING' ), 'a missing today-salt fails salt_window' );
$bad = $GOOD; unset( $bad['salt'] );
$i = inv( sn_collector_status_invariants( $bad, $NOW ), 'salt_window' );
ok( false === $i['ok'], 'an absent salt block fails salt_window (older worker / KV failure — unknown is not sane)' );

// version_present.
$bad = $GOOD; $bad['version'] = '';
$i = inv( sn_collector_status_invariants( $bad, $NOW ), 'version_present' );
ok( false === $i['ok'], 'an empty version fails version_present' );

// cron_fresh: block present + status ok + at within the ~2h ceiling.
ok( 2 * 3600 + 900 === SN_COLLECTOR_STATUS_CRON_FRESH_SECS, 'the freshness ceiling is a named constant (~2h + slack)' );
$bad = $GOOD; unset( $bad['cron'] );
$i = inv( sn_collector_status_invariants( $bad, $NOW ), 'cron_fresh' );
ok( false === $i['ok'], 'an absent cron block fails cron_fresh' );
$bad = $GOOD; $bad['cron']['refresh_status'] = 'error';
$i = inv( sn_collector_status_invariants( $bad, $NOW ), 'cron_fresh' );
ok( false === $i['ok'] && false !== strpos( $i['detail'], '"error"' ), 'a non-ok refresh_status fails and is quoted' );
$bad = $GOOD; $bad['cron']['at'] = gmdate( 'Y-m-d\TH:i:s\Z', $NOW - 3 * 3600 );
$i = inv( sn_collector_status_invariants( $bad, $NOW ), 'cron_fresh' );
ok( false === $i['ok'] && false !== strpos( $i['detail'], 'stalled' ), 'a 3h-old at stamp is stale — the schedule stalled' );
$edge = $GOOD; $edge['cron']['at'] = gmdate( 'Y-m-d\TH:i:s\Z', $NOW - SN_COLLECTOR_STATUS_CRON_FRESH_SECS );
ok( true === inv( sn_collector_status_invariants( $edge, $NOW ), 'cron_fresh' )['ok'], 'an at stamp exactly on the ceiling still passes' );
$bad = $GOOD; $bad['cron']['at'] = 'not-a-time';
ok( false === inv( sn_collector_status_invariants( $bad, $NOW ), 'cron_fresh' )['ok'], 'an unparseable at stamp fails, no notice' );

ok( false === sn_collector_status_invariants( array(), $NOW )['healthy'], 'an empty payload is wholly unhealthy, no throw' );

echo "\nGroup: ability registration\n";
ok( isset( $GLOBALS['__cs_actions']['wp_abilities_api_init'] ), 'registration rides the canonical wp_abilities_api_init hook' );
foreach ( $GLOBALS['__cs_actions']['wp_abilities_api_init'] as $cb ) { call_user_func( $cb ); }
$ab = $GLOBALS['__cs_abilities']['signal-noise/get-collector-status'] ?? null;
ok( is_array( $ab ), 'signal-noise/get-collector-status registered (no bare REST route)' );
ok( array( 'object', 'null' ) === ( $ab['input_schema']['type'] ?? null ), 'input schema types the [object,null] union' );
ok( array( 'analytics' ) === ( $ab['input_schema']['properties']['worker']['enum'] ?? null ),
	'the optional worker input is a typed enum (siblings ride later without a schema break)' );
ok( 'analytics' === ( $ab['input_schema']['properties']['worker']['default'] ?? null ), 'worker defaults to analytics' );
ok( true === ( $ab['meta']['annotations']['readonly'] ?? null ), 'annotated readonly' );
ok( 'snt_ability_perm_manage_options' === ( $ab['permission_callback'] ?? '' ), 'manage_options-gated like its diagnostics siblings' );
ok( 'diagnostics' === ( $ab['category'] ?? '' ), 'category diagnostics' );

echo "\nGroup: execute callback\n";
$GLOBALS['__cs_http'] = array( 'code' => 200, 'body' => json_encode( $GOOD ) );
$res = snt_ability_get_collector_status( null );
ok( true === $res['healthy'] && 'analytics' === $res['worker'], 'null input (bodyless GET) evaluates the default analytics worker' );
ok( 4 === count( $res['invariants'] ), 'the four invariants ride the response' );
$call = end( $GLOBALS['__cs_http_log'] );
ok( 'https://collector.test/_sn/version' === $call['url'], 'fetches the derived /_sn/version endpoint (wp_safe_remote_get)' );
ok( SN_COLLECTOR_STATUS_TIMEOUT === ( $call['args']['timeout'] ?? 0 ) && SN_COLLECTOR_STATUS_TIMEOUT <= 5, 'short timeout — an agent call never hangs on a cold edge' );

$GLOBALS['__cs_http'] = array( 'code' => 0, 'body' => '' );
$res = snt_ability_get_collector_status( array( 'worker' => 'analytics' ) );
ok( false === $res['healthy'] && 'unreachable' === ( $res['error'] ?? '' ), 'a network failure returns healthy=false with a named error' );
$GLOBALS['__cs_http'] = array( 'code' => 200, 'body' => 'not json' );
ok( 'bad-response' === ( snt_ability_get_collector_status( null )['error'] ?? '' ), 'a non-JSON 200 is bad-response, never a fake evaluation' );
$GLOBALS['__cs_http'] = array( 'code' => 500, 'body' => '{}' );
ok( 'unreachable' === ( snt_ability_get_collector_status( null )['error'] ?? '' ), 'a 5xx is unreachable' );
$GLOBALS['__cs_endpoint'] = '';
ok( 'no-endpoint' === ( snt_ability_get_collector_status( null )['error'] ?? '' ), 'no derivable endpoint fails closed with no-endpoint' );

echo "\nGroup: wiring pins\n";
$loader = (string) file_get_contents( dirname( __DIR__ ) . '/signal-and-noise-tools.php' );
ok( false !== strpos( $loader, 'inc/abilities-collector-status.php' ), 'the plugin loader requires the module' );
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/abilities-collector-status.php' );
ok( false === strpos( $src, 'register_rest_route' ), 'no new bare REST route' );
ok( false !== strpos( $src, 'wp_safe_remote_get' ), 'outbound goes through wp_safe_remote_get' );


/* v10.62.0 — rejects passthrough (worker v1.19.0+), informational only. */
echo "\nGroup: rejects passthrough\n";

$r = sn_collector_status_sanitize_rejects( null );
ok( null === $r, 'rejects: absent block (pre-v1.19.0 worker) -> null, key simply omitted' );

$r = sn_collector_status_sanitize_rejects( array( 'since' => '2026-08-08T00:00:00Z', 'total' => 7, 'by_reason' => array( 'token' => 5, 'ratelimit_error' => 2 ) ) );
ok( is_array( $r ) && 7 === $r['total'] && 5 === $r['by_reason']['token'] && 'isolate' === $r['scope'], 'rejects: well-formed block passes through with scope pinned to isolate' );

$r = sn_collector_status_sanitize_rejects( array( 'total' => '9', 'by_reason' => array( 'token' => '3', 'evil<script>' => 4, 'UPPER' => 1 ) ) );
ok( 9 === $r['total'] && array( 'token' => 3 ) === $r['by_reason'], 'rejects: counts int-cast, non-allowlisted reason names dropped' );

$r = sn_collector_status_sanitize_rejects( array( 'since' => str_repeat( 'x', 200 ) ) );
ok( 32 === strlen( $r['since'] ), 'rejects: since clamped to 32 chars' );

// Invariant surface is UNCHANGED by the block: the evaluator never reads it.
$verdict = sn_collector_status_invariants( array( 'rejects' => array( 'total' => 999999 ) ), time() );
$names = array_column( $verdict['invariants'], 'name' );
ok( ! in_array( 'rejects', $names, true ), 'rejects: never an invariant — informational passthrough only (isolate counts cannot carry health semantics)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
