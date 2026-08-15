<?php
/**
 * Standalone tests for the MCP Layer B telemetry module (inc/mcp/mcp-telemetry.php)
 * AND its wiring into inc/mcp/mcp-tools.php's sn_mcp_call_tool(). Sub-project B.
 *
 * Run: php tests/mcp-telemetry.php
 *
 * @since plugin v10.25.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

if ( ! class_exists( 'WP_Error' ) ) {
	// Mirrors real WP_Error's multi-code storage + get_error_data() default
	// (no $code arg -> the FIRST registered code's data) closely enough for
	// this file's fixtures, which (like every real ability) only ever
	// construct with a single code.
	class WP_Error {
		public $errors     = array();
		public $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code || null === $code ) { return; }
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) { $this->error_data[ $code ] = $data; }
		}
		public function get_error_message() {
			$codes = array_keys( $this->errors );
			return $codes ? $this->errors[ $codes[0] ][0] : '';
		}
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) { $code = $this->get_error_code(); }
			return $this->error_data[ $code ] ?? null;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) {
	$GLOBALS['__filters'] = array();
	function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['__filters'] ) ? call_user_func( $GLOBALS['__filters'][ $hook ], $value ) : $value; }
}
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $hook, $cb ) { $GLOBALS['__filters'][ $hook ] = $cb; return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }

// --- option store (dbDelta version guard) ---
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }

// --- dbDelta stub (mirrors tests/analytics-buckets.php) ---
$GLOBALS['__dbdelta_calls'] = array();
if ( ! function_exists( 'dbDelta' ) ) { function dbDelta( $sql ) { $GLOBALS['__dbdelta_calls'][] = $sql; return array(); } }

// --- rw-door stubs mcp-tools.php / mcp-rw-guard.php / mcp-rw-audit.php need ---
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
$GLOBALS['__app_pw_uuid'] = null;
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) { function rest_get_authenticated_app_password() { return $GLOBALS['__app_pw_uuid']; } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return false !== strpos( (string) $e, '@' ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k ) { return 'Test Site'; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $subject, $body ) { return true; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $v ) ); } }
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache() { return false; } }
$GLOBALS['__transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = 'auth' ) { return 'test-salt'; } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min, $max ) { return mt_rand( $min, $max ); } }
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

// --- ability stand-in (same shape as tests/mcp-tools.php's SN_Test_Ability) ---
class SN_Test_Ability {
	private $n, $label, $desc, $in, $out, $perm, $result, $meta;
	public function __construct( $n, $args ) {
		$this->n = $n; $this->label = $args['label'] ?? ''; $this->desc = $args['description'] ?? '';
		$this->in = $args['input_schema'] ?? array(); $this->out = $args['output_schema'] ?? array();
		$this->perm = $args['perm'] ?? true; $this->result = $args['result'] ?? null;
		$this->meta = $args['meta'] ?? array();
	}
	public function get_name() { return $this->n; }
	public function get_label() { return $this->label; }
	public function get_description() { return $this->desc; }
	public function get_input_schema() { return $this->in; }
	public function get_output_schema() { return $this->out; }
	public function get_meta() { return $this->meta; }
	public function check_permissions( $i = null ) { return $this->perm; }
	public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

// --- a $wpdb stand-in that models BOTH the success shape and the FAILURE
//     shape (insert()/query() returning false, per project memory: a failed
//     wpdb call returns false/[], never null, and a stub must model that). ---
class SN_Test_Wpdb {
	public $prefix       = 'wp_';
	public $insert_calls = array();
	public $queries      = array();
	public $fail_insert  = false;
	public $last_error   = '';
	public function get_charset_collate() { return 'utf8mb4'; }
	public function insert( $table, $data, $format = null ) {
		if ( $this->fail_insert ) { $this->last_error = 'forced failure: no such table'; return false; }
		$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%s|%d/', function( $m ) use ( &$i, $args ) {
			$v = $args[ $i ] ?? ''; $i++;
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';
require __DIR__ . '/../inc/mcp/mcp-telemetry.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-rw-audit.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function sn_test_reset_telemetry() {
	global $wpdb;
	$wpdb->insert_calls = array();
	$wpdb->queries      = array();
	$wpdb->fail_insert  = false;
	$GLOBALS['__opts']  = array();
	$GLOBALS['__filters'] = array();
	$GLOBALS['__app_pw_uuid'] = null;
}

echo "MCP telemetry — plugin v10.25.0\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * Pure helpers
 * ════════════════════════════════════════════════════════════════════════ */

ok( '' === sn_mcp_telemetry_args_shape( array() ), 'args_shape: empty args → empty string' );
ok( 'a,b' === sn_mcp_telemetry_args_shape( array( 'b' => 1, 'a' => 2 ) ), 'args_shape: sorted, comma-joined keys' );
ok( strlen( sn_mcp_telemetry_args_shape( array_fill_keys( range( 1, 100 ), 1 ) ) ) <= 255, 'args_shape: truncated to 255 chars' );

$hash_a = sn_mcp_telemetry_args_hash( array( 'post_id' => 42 ) );
$hash_b = sn_mcp_telemetry_args_hash( array( 'post_id' => 43 ) );
ok( 64 === strlen( $hash_a ), 'args_hash: sha256 hex is 64 chars' );
ok( $hash_a !== $hash_b, 'args_hash: different arg values hash differently' );
ok( $hash_a === sn_mcp_telemetry_args_hash( array( 'post_id' => 42 ) ), 'args_hash: deterministic for identical args' );
ok( false === strpos( $hash_a, '42' ), 'args_hash: the raw argument VALUE never appears in the hash string itself' );

ok( 3 === sn_mcp_telemetry_result_count( array( 'a', 'b', 'c' ) ), 'result_count: list-shaped array counts' );
ok( null === sn_mcp_telemetry_result_count( array( 'k' => 'v' ) ), 'result_count: assoc array → null (never introspected)' );
ok( null === sn_mcp_telemetry_result_count( 'scalar' ), 'result_count: scalar → null' );
ok( null === sn_mcp_telemetry_result_count( null ), 'result_count: null → null' );

// Every fixture below reproduces the REAL error's exact (code, status) shape
// as constructed at its real call site (read directly, not assumed — see
// inc/mcp/mcp-telemetry.php's sn_mcp_telemetry_classify_wp_error() docblock
// for the file:line citations), per the repo's #1 stub-drift trap.

// --- status-first: the 4xx band (schema_error), grounded on real constructions ---
$c1 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_invalid_hook', 'Missing or empty hook name.', array( 'status' => 422 ) ) );
ok( 'schema_error' === $c1['outcome'], 'classify: snt_invalid_hook (status 422, inc/abilities-cron.php) → schema_error' );
$c2 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_block_migration_invalid_input', 'bad input', array( 'status' => 422 ) ) );
ok( 'schema_error' === $c2['outcome'], 'classify: snt_block_migration_invalid_input (status 422) → schema_error' );
$c3 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_dismiss_unknown_surface', 'Unknown dismiss surface.', array( 'status' => 422 ) ) );
ok( 'schema_error' === $c3['outcome'], 'classify: snt_dismiss_unknown_surface (status 422) → schema_error' );
$c4 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_post_not_found', 'Post 99 not found.', array( 'status' => 404 ) ) );
ok( 'schema_error' === $c4['outcome'], 'classify: snt_post_not_found (status 404, a bad post_id argument, not an unknown TOOL) → schema_error' );
$c_400 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_tag_suggest_unavailable', 'Tag suggestion is unavailable.', array( 'status' => 400 ) ) );
ok( 'schema_error' === $c_400['outcome'], 'classify: a real status-400 error (snt_tag_suggest_unavailable, inc/abilities-content.php) → schema_error' );

// --- status-first: the 5xx band (server_error) ---
$c5 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_helper_unavailable', 'dependency missing', array( 'status' => 500 ) ) );
ok( 'server_error' === $c5['outcome'], 'classify: snt_helper_unavailable (status 500) → server_error' );
$c6 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_og_failed', 'render failed', array( 'status' => 500 ) ) );
ok( 'server_error' === $c6['outcome'], 'classify: snt_og_failed (status 500) → server_error' );
// snt_impl_missing (inc/abilities-cron.php:337: new WP_Error('snt_impl_missing','Cron runner not available.', array('status'=>500))):
// the OLD regex classifier caught 'missing' as a substring and wrongly scored
// this schema_error, contradicting its own docstring (a genuine 500 dependency
// failure) — the exact HIGH finding this fix closes.
$c_impl_missing = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_impl_missing', 'Cron runner not available.', array( 'status' => 500 ) ) );
ok( 'server_error' === $c_impl_missing['outcome'], 'classify: snt_impl_missing (real status 500 shape) → server_error, NOT schema_error (the regression this fix closes)' );

// --- status-first: 429 (refused), including the write-throttle special case ---
// snt_surfaces_throttled (inc/abilities-update-post-surfaces.php:162) is the
// ONLY status-429 construction the sweep found — it must map to its own
// refusal_gate, not be lumped in with the rw door's rate-limit gate.
$c_throttle = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_surfaces_throttled', 'This post has reached its surface-write limit.', array( 'status' => 429 ) ) );
ok( 'refused' === $c_throttle['outcome'] && 'write_throttle' === $c_throttle['refusal_gate'], 'classify: snt_surfaces_throttled (status 429) → refused/write_throttle' );
// A hypothetical/synthetic status-429 code NOT in the throttle list, proving the
// fallback path (any other 429 → refusal_gate 'rate_limit') independently of
// snt_surfaces_throttled's special case.
$c_429_generic = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_some_future_cap', 'capped', array( 'status' => 429 ) ) );
ok( 'refused' === $c_429_generic['outcome'] && 'rate_limit' === $c_429_generic['refusal_gate'], 'classify: any OTHER status-429 code → refused/rate_limit (the default, not write_throttle)' );

// --- status-less fallback: the one real reachable code, and the honest default ---
// sn_tag_not_unused (inc/tag-consolidation.php:376) carries NO status data at
// all, yet IS reachable raw via signal-noise/prune-unused-tags's execute()
// callback — the one status-less code the sweep proved reachable.
$c_tag = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'sn_tag_not_unused', 'A selected tag is not an empty tag.' ) );
ok( 'schema_error' === $c_tag['outcome'], 'classify: sn_tag_not_unused (status-less, but reachable + caller-argument-shaped) → schema_error' );
// A status-less, unrecognized code — the honest default for an unexplained
// failure never proven to be the caller's fault.
$c_unknown = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'some_totally_unmapped_code', 'no idea what happened' ) );
ok( 'server_error' === $c_unknown['outcome'], 'classify: status-less + unrecognized code → server_error (honest default, not guessed)' );

$row = sn_mcp_telemetry_build_row( '2026-07-31 12:00:00.123', 'rw', 'human', 'signal-noise__get-insights', 'post_id', 'deadbeef', 'ok', null, 12, 3 );
ok( 'server' === $row['layer'], 'build_row: layer is always "server" this session' );
ok( null === $row['candidate_id'], 'build_row: candidate_id is always null this session' );
ok( 3 === $row['result_count'] && 12 === $row['latency_ms'], 'build_row: numeric fields pass through as ints' );
ok( array_key_exists( 'refusal_gate', $row ) && null === $row['refusal_gate'], 'build_row: refusal_gate null when not supplied' );

/* ════════════════════════════════════════════════════════════════════════
 * Kill switch
 * ════════════════════════════════════════════════════════════════════════ */

ok( true === sn_mcp_telemetry_enabled(), 'kill switch: default is enabled' );
add_filter( 'sn_mcp_telemetry_enabled', function() { return false; } );
ok( false === sn_mcp_telemetry_enabled(), 'kill switch: the filter can disable telemetry with no code change' );
$GLOBALS['__filters'] = array();

/* ════════════════════════════════════════════════════════════════════════
 * Schema + lazy install (mirrors tests/analytics-buckets.php)
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_reset_telemetry();
$schema = sn_mcp_telemetry_schema_sql();
ok( strpos( $schema, 'wp_sn_tool_call' ) !== false, 'schema: table name is prefixed' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: dbDelta two-space PRIMARY KEY form' );
ok( strpos( $schema, 'ts DATETIME(3)' ) !== false, 'schema: ts is DATETIME(3)' );

$GLOBALS['__dbdelta_calls'] = array();
sn_mcp_telemetry_maybe_install();
ok( 1 === count( $GLOBALS['__dbdelta_calls'] ), 'maybe_install: missing version runs dbDelta' );
$GLOBALS['__dbdelta_calls'] = array();
sn_mcp_telemetry_maybe_install();
ok( 0 === count( $GLOBALS['__dbdelta_calls'] ), 'maybe_install: current version → no dbDelta (not on the hot path every request)' );

/* ════════════════════════════════════════════════════════════════════════
 * sn_mcp_telemetry_record() — the live wrapper
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_reset_telemetry();
sn_mcp_telemetry_record( 'signal-noise__get-insights', array( 'post_id' => 7 ), 'read', 'ok', null, 4, 2 );
ok( 1 === count( $wpdb->insert_calls ), 'record(): exactly one row inserted for one call' );
$inserted = $wpdb->insert_calls[0]['data'];
ok( 'wp_sn_tool_call' === $wpdb->insert_calls[0]['table'], 'record(): inserts into the prefixed table' );
ok( 'ok' === $inserted['outcome'] && 'read' === $inserted['door'], 'record(): outcome + door pinned in the inserted row' );
ok( 'post_id' === $inserted['args_shape'], 'record(): args_shape carries only the KEY, never the value' );
ok( ! in_array( 7, $inserted, true ), 'record(): the raw arg VALUE (7) is not stored verbatim in any column' );

function sn_test_return_false() { return false; }
sn_test_reset_telemetry();
add_filter( 'sn_mcp_telemetry_enabled', 'sn_test_return_false' );
sn_mcp_telemetry_record( 'signal-noise__get-insights', array(), 'read', 'ok', null, 1 );
ok( 0 === count( $wpdb->insert_calls ), 'record(): disabled kill switch → no insert at all' );
$GLOBALS['__filters'] = array();

// --- fail-open: a wpdb insert failure must not throw / must not be surfaced ---
sn_test_reset_telemetry();
$wpdb->fail_insert = true;
$threw = false;
try {
	sn_mcp_telemetry_record( 'signal-noise__get-insights', array( 'post_id' => 1 ), 'read', 'ok', null, 1 );
} catch ( \Throwable $e ) {
	$threw = true;
}
ok( false === $threw, 'record(): a failing wpdb->insert() never throws (fail-open)' );
ok( 0 === count( $wpdb->insert_calls ), 'record(): the forced failure really did fail (sanity: the stub models the FAILURE shape, not a silent success)' );
$wpdb->fail_insert = false;

// --- pruning: the ~1-in-50 gate uses real wp_rand(), so drive it by calling
//     repeatedly (bounded) until it fires at least once, then assert the
//     exact DELETE shape the fired call produced. ---
sn_test_reset_telemetry();
$fired = false;
for ( $i = 0; $i < 2000 && ! $fired; $i++ ) {
	sn_mcp_telemetry_maybe_prune();
	if ( count( $wpdb->queries ) > 0 ) { $fired = true; }
}
ok( $fired, 'maybe_prune(): the ~1-in-50 gate fires within a bounded number of attempts' );
if ( $fired ) {
	$sql = end( $wpdb->queries );
	ok( strpos( $sql, 'DELETE FROM wp_sn_tool_call' ) !== false, 'maybe_prune(): the fired query deletes from the prefixed table' );
	ok( strpos( $sql, 'WHERE ts <' ) !== false, 'maybe_prune(): the fired query filters on ts <' );
	ok( strpos( $sql, 'LIMIT 500' ) !== false, 'maybe_prune(): the fired query caps at 500 rows' );
}

/* ════════════════════════════════════════════════════════════════════════
 * actor resolution
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_reset_telemetry();
ok( 'human' === sn_mcp_telemetry_actor(), 'actor: no authenticated app password → human' );
$GLOBALS['__app_pw_uuid'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
ok( 'app-pw:aaaaaaaa' === sn_mcp_telemetry_actor(), 'actor: authenticated app password → app-pw:<first 8 chars>' );
$GLOBALS['__app_pw_uuid'] = null;

/* ════════════════════════════════════════════════════════════════════════
 * WIRING: sn_mcp_call_tool() records telemetry at every outcome, both doors,
 * without changing the tool response it already returns byte-for-byte.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nWiring into sn_mcp_call_tool()\n\n";

// --- success, read door ---
sn_test_reset_telemetry();
$GLOBALS['__abilities']['signal-noise/get-health-scan'] = new SN_Test_Ability( 'signal-noise/get-health-scan', array(
	'output_schema' => array( 'type' => 'object' ),
	'result'        => array( 'status' => 'green' ),
) );
$before = sn_mcp_call_tool( 'signal-noise__get-health-scan', array() );
sn_test_reset_telemetry();
$after = sn_mcp_call_tool( 'signal-noise__get-health-scan', array() );
ok( $before == $after, 'wiring: the tool response is BYTE-IDENTICAL with telemetry recording on' ); // phpcs:ignore -- deep value compare intentional
ok( 1 === count( $wpdb->insert_calls ), 'wiring: success records exactly one telemetry row' );
ok( 'ok' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: success outcome is "ok"' );
ok( 'read' === $wpdb->insert_calls[0]['data']['door'], 'wiring: read-door call records door=read' );
ok( $wpdb->insert_calls[0]['data']['latency_ms'] >= 0, 'wiring: latency_ms is a non-negative measured value' );

// --- unknown tool → not_found ---
sn_test_reset_telemetry();
sn_mcp_call_tool( 'signal-noise__totally-unknown-tool', array() );
ok( 1 === count( $wpdb->insert_calls ), 'wiring: unknown tool still records a row (refusals are logged, not just success)' );
ok( 'not_found' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: unknown/blocked tool → not_found' );

// --- un-allowlisted (blocked) tool named directly → not_found ---
sn_test_reset_telemetry();
$GLOBALS['__abilities']['signal-noise/purge-all-caches'] = new SN_Test_Ability( 'signal-noise/purge-all-caches', array( 'result' => 'purged' ) );
sn_mcp_call_tool( 'signal-noise__purge-all-caches', array() ); // read door — not on the read allowlist
ok( 'not_found' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: un-allowlisted-for-this-door tool → not_found' );

// --- invalid (non-string) tool name → schema_error ---
sn_test_reset_telemetry();
sn_mcp_call_tool( array( 'not', 'a', 'string' ), array() );
ok( 'schema_error' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: non-string tool name → schema_error' );

// --- permission denied → refused ---
sn_test_reset_telemetry();
$GLOBALS['__abilities']['signal-noise/get-insights'] = new SN_Test_Ability( 'signal-noise/get-insights', array( 'perm' => false, 'result' => array( 'x' => 1 ) ) );
sn_mcp_call_tool( 'signal-noise__get-insights', array() );
ok( 'refused' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: permission denial → refused' );
ok( 'permission' === $wpdb->insert_calls[0]['data']['refusal_gate'], 'wiring: permission denial names refusal_gate=permission' );

// --- execute() WP_Error, status-422 → schema_error (real shape: inc/abilities-cron.php) ---
sn_test_reset_telemetry();
$GLOBALS['__abilities']['signal-noise/get-cron-history'] = new SN_Test_Ability( 'signal-noise/get-cron-history', array(
	'result' => new WP_Error( 'snt_invalid_hook', 'no such hook', array( 'status' => 422 ) ),
) );
sn_mcp_call_tool( 'signal-noise__get-cron-history', array( 'hook' => 'bogus' ) );
ok( 'schema_error' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: execute() WP_Error with a status-422 code → schema_error' );
ok( false === strpos( wp_json_encode( $wpdb->insert_calls[0]['data'] ), 'bogus' ), 'wiring: the argument VALUE ("bogus") never reaches the inserted row' );

// --- execute() WP_Error, status-500 → server_error (real shape: inc/abilities-content.php) ---
sn_test_reset_telemetry();
$GLOBALS['__abilities']['signal-noise/get-rss-stats'] = new SN_Test_Ability( 'signal-noise/get-rss-stats', array(
	'result' => new WP_Error( 'snt_helper_unavailable', 'feed unavailable', array( 'status' => 500 ) ),
) );
sn_mcp_call_tool( 'signal-noise__get-rss-stats', array() );
ok( 'server_error' === $wpdb->insert_calls[0]['data']['outcome'], 'wiring: execute() WP_Error with a status-500 code → server_error' );

// --- execute() WP_Error, status-429 write-throttle → refused/write_throttle (real shape: inc/abilities-update-post-surfaces.php) ---
sn_test_reset_telemetry();
$GLOBALS['__abilities']['signal-noise/update-post-surfaces'] = new SN_Test_Ability( 'signal-noise/update-post-surfaces', array(
	'result' => new WP_Error( 'snt_surfaces_throttled', 'write cap reached', array( 'status' => 429 ) ),
) );
sn_mcp_call_tool( 'signal-noise__update-post-surfaces', array( 'post_id' => 1 ), SN_MCP_DOOR_RW );
ok( 'refused' === $wpdb->insert_calls[0]['data']['outcome'] && 'write_throttle' === $wpdb->insert_calls[0]['data']['refusal_gate'], 'wiring: execute() WP_Error snt_surfaces_throttled → refused/write_throttle end-to-end' );

// --- rate-limit refusal on the rw door → refused + refusal_gate=rate_limit ---
sn_test_reset_telemetry();
$GLOBALS['__opts']['sn_mcp_rw_enabled']             = true;
$GLOBALS['__opts']['sn_mcp_rw_app_password_uuid']   = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['__app_pw_uuid']                            = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['__abilities']['signal-noise/prune-unused-tags'] = new SN_Test_Ability( 'signal-noise/prune-unused-tags', array( 'result' => array( 'pruned' => 0 ) ) );
for ( $i = 0; $i < SN_MCP_RW_RATE_LIMIT_PER_MINUTE; $i++ ) {
	sn_mcp_call_tool( 'signal-noise__prune-unused-tags', array(), SN_MCP_DOOR_RW );
}
sn_test_reset_telemetry_rate_only(); // keep rate-limit + option state, only reset the wpdb capture
function sn_test_reset_telemetry_rate_only() {
	global $wpdb;
	$wpdb->insert_calls = array();
}
$over = sn_mcp_call_tool( 'signal-noise__prune-unused-tags', array(), SN_MCP_DOOR_RW );
ok( isset( $over['error'] ) && -32000 === $over['error']['code'], 'sanity: the rate limit really did trip after the cap' );
ok( 1 === count( $wpdb->insert_calls ), 'wiring: a rate-limited call still records exactly one telemetry row' );
ok( 'refused' === $wpdb->insert_calls[0]['data']['outcome'] && 'rate_limit' === $wpdb->insert_calls[0]['data']['refusal_gate'], 'wiring: rate-limit denial → refused + refusal_gate=rate_limit' );
ok( 'rw' === $wpdb->insert_calls[0]['data']['door'], 'wiring: the rate-limited row is attributed to the rw door' );

// --- actor attribution on a real rw call ---
sn_test_reset_telemetry();
// sn_test_reset_telemetry() clears __app_pw_uuid; use a DIFFERENT uuid than the
// rate-limit block above so this call gets a fresh rate-limit bucket too.
$GLOBALS['__app_pw_uuid'] = 'ffffffff-1234-5678-9abc-ffffffffffff';
$call = sn_mcp_call_tool( 'signal-noise__prune-unused-tags', array(), SN_MCP_DOOR_RW );
ok( isset( $call['result'] ) && false === $call['result']['isError'], 'sanity: the rw call itself still succeeds' );
ok( 'app-pw:ffffffff' === $wpdb->insert_calls[0]['data']['actor'], 'wiring: actor resolves to app-pw:<uuid prefix> on an authenticated rw call' );

/* ════════════════════════════════════════════════════════════════════════
 * v11.8.0 — the `conflict` outcome and the `change_type` dimension.
 * ════════════════════════════════════════════════════════════════════════ */

// The change-type allowlist fixture is EXTRACTED from the real registration
// (inc/sn-apply-executors.php's SNT_SN_APPLY_CHANGE_TYPES) rather than copied,
// so it cannot drift from it — this repo's #1 recurring trap. A hand-copied
// list would still pass every assertion below on the day a type is added.
$sn_exec_src = file_get_contents( __DIR__ . '/../inc/sn-apply-executors.php' );
$sn_types    = array();
if ( is_string( $sn_exec_src ) && preg_match( '/const\s+SNT_SN_APPLY_CHANGE_TYPES\s*=\s*array\s*\((.*?)\);/s', $sn_exec_src, $sn_m ) ) {
	preg_match_all( '/\'([a-z_]+)\'/', $sn_m[1], $sn_hits );
	$sn_types = $sn_hits[1];
}
// Negative-control the extractor itself before trusting anything built on it:
// a silently-empty match would make every allowlist assertion below vacuous.
ok( count( $sn_types ) >= 16, 'fixture: extracted the REAL change-type list from inc/sn-apply-executors.php (' . count( $sn_types ) . ' types)' );
ok( in_array( 'link_reshape', $sn_types, true ) && in_array( 'unlink', $sn_types, true ), 'fixture: extraction found known members (link_reshape, unlink) — the regex really parsed the const' );
if ( ! defined( 'SNT_SN_APPLY_CHANGE_TYPES' ) ) {
	define( 'SNT_SN_APPLY_CHANGE_TYPES', $sn_types );
}

// --- status 409 → conflict, grounded on REAL constructions ---
// All four cited below were found by a paren-balanced (perl -0777) sweep of
// every `new WP_Error(` under inc/ carrying array('status'=>409) — 24 sites.
// A single-line grep would have missed the multi-line ones, per the standing
// lesson recorded twice in docs/mcp-consolidation/FINDINGS.md.
$cf1 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_sn_apply_anchor_not_found', 'No <a> element with exactly this inner text exists.', array( 'status' => 409 ) ) );
ok( 'conflict' === $cf1['outcome'], 'classify: snt_sn_apply_anchor_not_found (409, inc/sn-apply-link-reshape.php:117) → conflict' );
$cf2 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_orphan_no_longer', 'Attachment is now referenced and was not deleted.', array( 'status' => 409 ) ) );
ok( 'conflict' === $cf2['outcome'], 'classify: snt_orphan_no_longer (409, inc/ai-orphan-suggest.php:191, a TOCTOU re-check) → conflict' );
$cf3 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_sn_apply_idempotency_target_mismatch', 'key was previously used against another target.', array( 'status' => 409 ) ) );
ok( 'conflict' === $cf3['outcome'], 'classify: snt_sn_apply_idempotency_target_mismatch (409, inc/abilities-sn-apply.php:251) → conflict' );
$cf4 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_sn_apply_batch_phrase_not_found', 'edit 2: phrase not present in post content.', array( 'status' => 409 ) ) );
ok( 'conflict' === $cf4['outcome'], 'classify: snt_sn_apply_batch_phrase_not_found (409, inc/sn-apply-batch-edits.php:200) → conflict' );
// The regression this closes: 409 used to fall in the 400-428 band.
ok( 'schema_error' !== $cf1['outcome'], 'classify: a 409 is NO LONGER schema_error — fingerprint contention is distinguishable from malformed input' );
ok( null === $cf1['refusal_gate'], 'classify: conflict carries no refusal_gate (it is not a gate refusal)' );

// --- the 4xx band around 409 must be UNCHANGED (boundary integrity) ---
$cb1 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'snt_sn_apply_bad_change_type', 'change.type must be one of: ...', array( 'status' => 422 ) ) );
ok( 'schema_error' === $cb1['outcome'], 'classify: 422 still → schema_error (inc/abilities-sn-apply.php:190, a REAL construction)' );
$cb2 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'x', '', array( 'status' => 408 ) ) );
ok( 'schema_error' === $cb2['outcome'], 'classify: 408 (just below 409) still → schema_error' );
$cb3 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'x', '', array( 'status' => 410 ) ) );
ok( 'schema_error' === $cb3['outcome'], 'classify: 410 (just above 409) still → schema_error' );
$cb4 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'x', '', array( 'status' => 429 ) ) );
ok( 'refused' === $cb4['outcome'], 'classify: 429 still → refused, not swallowed by the new branch' );
$cb5 = sn_mcp_telemetry_classify_wp_error( new WP_Error( 'x', '', array( 'status' => 500 ) ) );
ok( 'server_error' === $cb5['outcome'], 'classify: 500 still → server_error' );

// --- change_type extraction: ALLOWLIST, never passthrough ---
ok( 'link_reshape' === sn_mcp_telemetry_change_type( array( 'change' => array( 'type' => 'link_reshape' ) ) ), 'change_type: a real allowlisted type is recorded' );
ok( 'unlink' === sn_mcp_telemetry_change_type( array( 'target' => array( 'post_id' => 1 ), 'change' => array( 'type' => 'unlink' ), 'mode' => 'publish' ) ), 'change_type: extracted from a full sn-apply argument shape' );
ok( null === sn_mcp_telemetry_change_type( array( 'change' => array( 'type' => 'tag_merge' ) ) ), 'change_type: an UNREGISTERED type (tag_merge, ADR-0002, not yet in the enum) → null, not echoed' );
ok( null === sn_mcp_telemetry_change_type( array() ), 'change_type: absent change → null' );
ok( null === sn_mcp_telemetry_change_type( array( 'change' => 'link_reshape' ) ), 'change_type: non-array change → null' );
ok( null === sn_mcp_telemetry_change_type( array( 'change' => array( 'payload' => array() ) ) ), 'change_type: change without a type → null' );
ok( null === sn_mcp_telemetry_change_type( 'not-an-array' ), 'change_type: non-array args → null' );
ok( null === sn_mcp_telemetry_change_type( array( 'change' => array( 'type' => array( 'link_reshape' ) ) ) ), 'change_type: a non-string type → null (never stringified)' );
// PRIVACY: the carve-out is "a closed enum", so anything off-enum must not be
// stored. This is the assertion that keeps it from degrading into "log a value".
$leak = 'sk-secret-token-value-that-must-never-be-stored';
ok( null === sn_mcp_telemetry_change_type( array( 'change' => array( 'type' => $leak ) ) ), 'change_type PRIVACY: an arbitrary caller string is NEVER recorded — allowlist, not passthrough' );

// --- the row and the insert carry the new column ---
$row_ct = sn_mcp_telemetry_build_row( '2026-08-15 01:00:00.000', 'rw', 'human', 'signal-noise__sn-apply', 'change,mode,target', str_repeat( 'a', 64 ), 'conflict', null, 12, null, 'link_reshape' );
ok( array_key_exists( 'change_type', $row_ct ), 'build_row: emits a change_type key' );
ok( 'link_reshape' === $row_ct['change_type'], 'build_row: carries the resolved change_type' );
ok( 'conflict' === $row_ct['outcome'], 'build_row: accepts the new conflict outcome' );
$row_null = sn_mcp_telemetry_build_row( '2026-08-15 01:00:00.000', 'read', 'human', 'signal-noise__sn-posts', 'scope', str_repeat( 'b', 64 ), 'ok', null, 3, 5, null );
ok( null === $row_null['change_type'], 'build_row: change_type is NULL for a tool that has no change.type' );

// The insert uses an EXPLICIT format array; a column added without its %s
// silently misbinds every column after it.
$sn_tel_src = file_get_contents( __DIR__ . '/../inc/mcp/mcp-telemetry.php' );
// Scope to the FUNCTION BODY first. An earlier cut of this assertion anchored
// on /\$wpdb->insert\(/ against the whole file and matched the docblock mention
// at line 27, so the span ran down to the real call and swallowed build_row's
// keys — it reported 41 columns vs 13 formats and RED-ed on correct code. The
// instrument was wrong, not the subject; scoping it is the fix.
$ins_body = preg_match( '/function sn_mcp_telemetry_insert_row\s*\(.*?\n\}/s', (string) $sn_tel_src, $fn_m ) ? $fn_m[0] : '';
ok( '' !== $ins_body && false !== strpos( $ins_body, '$wpdb->insert(' ), 'fixture: isolated the insert_row() function body (not the docblock mention 440 lines above it)' );
$n_cols    = substr_count( $ins_body, '=>' );
$n_formats = preg_match_all( "/'%[sd]'/", $ins_body );
ok( $n_cols > 0 && $n_cols === $n_formats, "insert_row: column count ($n_cols) matches format count ($n_formats) — a new column without its %s would misbind every column after it" );
ok( false !== strpos( $ins_body, 'change_type' ), 'insert_row: change_type is actually inserted' );
ok( false !== strpos( (string) $sn_tel_src, 'change_type VARCHAR' ), 'schema: change_type column is in the CREATE TABLE' );
ok( '1' !== SN_MCP_TELEMETRY_DB_VERSION, 'schema: DB version was bumped, so dbDelta actually adds the column on an existing install' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
