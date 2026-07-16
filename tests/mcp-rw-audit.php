<?php
/**
 * Standalone tests for the MCP rw-door audit log + owner notification
 * (v9.51.0, lane SEC-B): redaction (default-drop allowlist), the pure row
 * builder, the live recorder (cap + retention prune), and the notification
 * coalesce/send/failure paths. Exercises pure functions directly (no WP
 * bootstrap) and live wrappers via in-memory get_option()/update_option()/
 * wp_mail() stubs — the same "injectable" contract lane SEC-A established.
 *
 * @since plugin v9.51.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data()    { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }

// In-memory options store. $GLOBALS['__force_update_option_throw'] simulates
// a third-party filter/plugin blowing up inside update_option() — used only
// to pin sn_mcp_rw_audit_record()'s own try/catch belt (never touched by
// production code; this is a test-harness-only fault injection).
$GLOBALS['__opts'] = array();
$GLOBALS['__force_update_option_throw'] = false;
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) {
		if ( $GLOBALS['__force_update_option_throw'] ) {
			throw new \RuntimeException( 'a third-party filter blew up' );
		}
		$GLOBALS['__opts'][ $k ] = $v;
		return true;
	}
}

// current user + app-password auth stubs.
$GLOBALS['__current_user_id'] = 0;
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return $GLOBALS['__current_user_id']; } }
$GLOBALS['__app_pw_uuid'] = null; // null = "function doesn't exist"/no app-pw auth; '' or a uuid = its return value.
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) { function rest_get_authenticated_app_password() { return $GLOBALS['__app_pw_uuid']; } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return false !== strpos( (string) $e, '@' ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k ) { return 'Test Site'; } }

// wp_mail stub: records every call; a global toggle can force it to fail or throw.
$GLOBALS['__mails_sent'] = array();
$GLOBALS['__wp_mail_behavior'] = 'succeed'; // 'succeed' | 'fail' | 'throw'
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body ) {
		if ( 'throw' === $GLOBALS['__wp_mail_behavior'] ) {
			throw new \RuntimeException( 'mailer exploded' );
		}
		if ( 'fail' === $GLOBALS['__wp_mail_behavior'] ) {
			return false;
		}
		$GLOBALS['__mails_sent'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
		return true;
	}
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

require __DIR__ . '/../inc/mcp/mcp-rw-audit.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP rw-audit — plugin v9.51.0 (lane SEC-B)\n\n";

// ============================================================
// R4 — redaction: default-drop allowlist
// ============================================================
echo "-- sn_mcp_rw_audit_safe_args: default-drop allowlist --\n";

$safe_keys = sn_mcp_rw_audit_safe_arg_keys();
ok( in_array( 'post_id', $safe_keys, true ), 'post_id is on the safe-key allowlist' );
ok( in_array( 'view', $safe_keys, true ), 'view is on the safe-key allowlist' );

$redacted = sn_mcp_rw_audit_safe_args( 'signal-noise/ai-alt-apply', array( 'attachment_id' => 42, 'alt_text' => 'a cat sitting on a fence, this is real AI-authored content' ) );
ok( ( $redacted['attachment_id'] ?? null ) === 42, 'safe scalar key (attachment_id) survives redaction' );
ok( ! array_key_exists( 'alt_text', $redacted ), 'PROBE PIN: a real content-bearing arg (alt_text) is DROPPED, never logged' );

$redacted2 = sn_mcp_rw_audit_safe_args( 'signal-noise/draft-release-notes', array( 'changelog_delta' => 'v9.51.0: secret internal notes, API keys, everything' ) );
ok( array() === $redacted2, 'draft-release-notes\' raw changelog_delta arg is entirely dropped (no safe keys in that call)' );

$redacted3 = sn_mcp_rw_audit_safe_args( 'signal-noise/unschedule-cron-event', array( 'hook' => 'orphaned_hook', 'args' => array( 'secret' => 'token-abc' ) ) );
ok( ( $redacted3['hook'] ?? null ) === 'orphaned_hook', 'safe scalar key (hook) survives' );
ok( ! array_key_exists( 'args', $redacted3 ), 'a safe-LOOKING key holding a non-scalar (array) value is still dropped — shape gate, not just name gate' );

$redacted4 = sn_mcp_rw_audit_safe_args( 'anything', array( 'post_id' => 7, 'api_key' => 'sk-live-should-never-appear', 'password' => 'hunter2' ) );
ok( array( 'post_id' => 7 ) === $redacted4, 'an unknown/secret-shaped key (api_key, password) never survives even alongside a safe key' );

ok( array() === sn_mcp_rw_audit_safe_args( 'x', 'not-an-array' ), 'non-array args redacts to an empty array (never crashes)' );

$redacted5 = sn_mcp_rw_audit_safe_args( 'signal-noise/get-audit-log', array( 'view' => 'logins', 'days' => 7 ) );
ok( $redacted5 === array( 'view' => 'logins', 'days' => 7 ), 'a fully-safe args array passes through byte-identical' );

// ============================================================
// IP hashing
// ============================================================
echo "\n-- sn_mcp_rw_audit_hash_ip --\n";
$h1 = sn_mcp_rw_audit_hash_ip( '203.0.113.9' );
$h2 = sn_mcp_rw_audit_hash_ip( '203.0.113.9' );
ok( $h1 === $h2 && 16 === strlen( $h1 ), 'IP hash is stable and 16 chars (fallback path, snt_audit_hash_ip not loaded)' );
ok( sn_mcp_rw_audit_hash_ip( '198.51.100.1' ) !== $h1, 'different IPs hash differently' );
ok( sn_mcp_rw_audit_current_ip() === '203.0.113.9', 'current IP reads from $_SERVER[REMOTE_ADDR]' );

// ============================================================
// error_code resolution
// ============================================================
echo "\n-- sn_mcp_rw_audit_error_code_from --\n";
ok( null === sn_mcp_rw_audit_error_code_from( null ), 'ok outcome (null error source) -> no error_code' );
ok( 'permission_denied' === sn_mcp_rw_audit_error_code_from( false ), 'denied outcome (false perm, no WP_Error) -> permission_denied' );
ok( 'custom_denial' === sn_mcp_rw_audit_error_code_from( new WP_Error( 'custom_denial', 'nope' ) ), 'denied outcome (WP_Error perm) -> its own code' );
ok( 'boom' === sn_mcp_rw_audit_error_code_from( new WP_Error( 'boom', 'feed unavailable' ) ), 'error outcome (execute WP_Error) -> its own code' );

// ============================================================
// PURE row builder
// ============================================================
echo "\n-- sn_mcp_rw_audit_build_row (pure) --\n";
$row = sn_mcp_rw_audit_build_row( 'signal-noise/prune-unused-tags', array(), 'ok', null, 5, 'uuid-a', 'iphash1', 1000 );
ok( $row['ts'] === 1000 && $row['slug'] === 'signal-noise/prune-unused-tags' && $row['outcome'] === 'ok', 'pure row: ts/slug/outcome set from params' );
ok( $row['user_id'] === 5 && $row['app_pw_uuid'] === 'uuid-a' && $row['ip_hash'] === 'iphash1', 'pure row: user_id/app_pw_uuid/ip_hash set from params' );
ok( ! array_key_exists( 'error_code', $row ), 'pure row: ok outcome has no error_code key at all' );

$denied_row = sn_mcp_rw_audit_build_row( 'signal-noise/run-audit-prune', array( 'x' => 1 ), 'denied', false, 5, 'uuid-a', 'iphash1', 1000 );
ok( ( $denied_row['error_code'] ?? null ) === 'permission_denied', 'pure row: denied outcome carries error_code' );

$error_row = sn_mcp_rw_audit_build_row( 'signal-noise/run-narration', array(), 'error', new WP_Error( 'ai_failed', 'AI client unreachable' ), 5, 'uuid-a', 'iphash1', 1000 );
ok( ( $error_row['error_code'] ?? null ) === 'ai_failed', 'pure row: error outcome carries the WP_Error\'s own code' );

$content_row = sn_mcp_rw_audit_build_row( 'signal-noise/ai-alt-apply', array( 'attachment_id' => 9, 'alt_text' => 'secret ai text' ), 'ok', null, 5, 'uuid-a', 'iphash1', 1000 );
ok( ( $content_row['args_redacted']['attachment_id'] ?? null ) === 9 && ! array_key_exists( 'alt_text', $content_row['args_redacted'] ), 'pure row: args_redacted applies the same default-drop rule' );

// ============================================================
// LIVE recorder: append, prune-by-age, cap-by-count
// ============================================================
echo "\n-- sn_mcp_rw_audit_record (live) + prune/cap --\n";
$GLOBALS['__opts'] = array();
$GLOBALS['__current_user_id'] = 7;
$GLOBALS['__app_pw_uuid'] = 'uuid-live';

$blob0 = sn_mcp_rw_audit_get_blob();
ok( 1 === $blob0['schema_version'] && array() === $blob0['rows'], 'fresh blob lazy-inits to schema_version 1, empty rows' );

sn_mcp_rw_audit_record( 'signal-noise/prune-unused-tags', array(), 'ok' );
$blob1 = get_option( SN_MCP_RW_AUDIT_OPTION );
ok( 1 === count( $blob1['rows'] ), 'recording one call appends exactly one row' );
ok( $blob1['rows'][0]['user_id'] === 7 && $blob1['rows'][0]['app_pw_uuid'] === 'uuid-live', 'the live wrapper gathers real current-user + app-pw state' );

// Age-based prune: seed a row far in the past directly, then record a new one — the stale row must be dropped.
$blob = get_option( SN_MCP_RW_AUDIT_OPTION );
$blob['rows'] = array( array( 'ts' => time() - ( SN_MCP_RW_AUDIT_RETENTION_DAYS + 5 ) * DAY_IN_SECONDS, 'slug' => 'stale', 'outcome' => 'ok', 'args_redacted' => array(), 'user_id' => 1, 'app_pw_uuid' => '', 'ip_hash' => '' ) );
update_option( SN_MCP_RW_AUDIT_OPTION, $blob, false );
sn_mcp_rw_audit_record( 'signal-noise/run-audit-prune', array(), 'ok' );
$after_age_prune = get_option( SN_MCP_RW_AUDIT_OPTION );
ok( 1 === count( $after_age_prune['rows'] ) && 'stale' !== ( $after_age_prune['rows'][0]['slug'] ?? '' ), 'a row older than the retention window is pruned on the next record' );

// Count-based cap: seed CAP rows, record one more — total stays at CAP, oldest dropped.
$seed = array();
for ( $i = 0; $i < SN_MCP_RW_AUDIT_CAP; $i++ ) {
	$seed[] = array( 'ts' => time(), 'slug' => 'seed-' . $i, 'outcome' => 'ok', 'args_redacted' => array(), 'user_id' => 1, 'app_pw_uuid' => '', 'ip_hash' => '' );
}
update_option( SN_MCP_RW_AUDIT_OPTION, array( 'schema_version' => 1, 'created_at' => time(), 'rows' => $seed ), false );
sn_mcp_rw_audit_record( 'signal-noise/purge-all-caches', array(), 'ok' );
$capped = get_option( SN_MCP_RW_AUDIT_OPTION );
ok( SN_MCP_RW_AUDIT_CAP === count( $capped['rows'] ), 'row count never exceeds the cap after it is reached' );
ok( 'seed-0' !== ( $capped['rows'][0]['slug'] ?? '' ), 'the OLDEST row was dropped to make room (rolling cap, not a hard stop)' );
ok( 'signal-noise/purge-all-caches' === ( $capped['rows'][ count( $capped['rows'] ) - 1 ]['slug'] ?? '' ), 'the newest call is still the last row' );

// ============================================================
// R5 — notification: disabled by default
// ============================================================
echo "\n-- sn_mcp_rw_notify_enabled default + gating --\n";
$GLOBALS['__opts'] = array();
ok( false === sn_mcp_rw_notify_enabled(), 'notify option absent -> default false (opt-in)' );

$GLOBALS['__mails_sent'] = array();
sn_mcp_rw_notify_maybe_send( array( 'slug' => 'x', 'outcome' => 'ok' ) );
ok( array() === $GLOBALS['__mails_sent'], 'notify disabled -> wp_mail is never called' );

// ============================================================
// R5 — coalesce predicate (pure)
// ============================================================
echo "\n-- sn_mcp_rw_notify_coalesce_decision (pure) --\n";
ok( true === sn_mcp_rw_notify_coalesce_decision( 1000, 1010 ), 'within the coalesce window -> coalesce (true)' );
ok( false === sn_mcp_rw_notify_coalesce_decision( 1000, 1000 + SN_MCP_RW_NOTIFY_COALESCE_WINDOW_SECONDS ), 'exactly at the window boundary -> NOT coalesced (send)' );
ok( false === sn_mcp_rw_notify_coalesce_decision( 0, 1000000 ), 'never sent before (last_sent=0) and far past the window -> not coalesced' );

// ============================================================
// R5 — send / coalesce / overflow, live
// ============================================================
echo "\n-- sn_mcp_rw_notify_maybe_send: send, coalesce, overflow --\n";
$GLOBALS['__opts'] = array( 'sn_mcp_rw_notify' => true, 'admin_email' => 'owner@example.com' );
$GLOBALS['__mails_sent'] = array();
$GLOBALS['__wp_mail_behavior'] = 'succeed';

$row_a = array( 'slug' => 'signal-noise/purge-all-caches', 'outcome' => 'ok', 'user_id' => 1, 'app_pw_uuid' => 'u', 'args_redacted' => array() );
$sent1 = sn_mcp_rw_notify_maybe_send( $row_a );
ok( true === $sent1 && 1 === count( $GLOBALS['__mails_sent'] ), 'first call, enabled + valid admin_email -> sends immediately' );
ok( false !== strpos( $GLOBALS['__mails_sent'][0]['subject'], 'purge-all-caches' ), 'subject names the tool' );
ok( false !== strpos( $GLOBALS['__mails_sent'][0]['subject'], 'ok' ), 'subject names the outcome' );
ok( false !== strpos( $GLOBALS['__mails_sent'][0]['body'], "you're getting this" ) || false !== stripos( $GLOBALS['__mails_sent'][0]['body'], 'notification setting is on' ), 'body includes the "why you got this" line' );

$row_b = array( 'slug' => 'signal-noise/run-audit-prune', 'outcome' => 'ok', 'user_id' => 1, 'app_pw_uuid' => 'u', 'args_redacted' => array() );
$sent2 = sn_mcp_rw_notify_maybe_send( $row_b );
ok( false === $sent2 && 1 === count( $GLOBALS['__mails_sent'] ), 'a second call inside the coalesce window does NOT send (mailbomb guard)' );
ok( 1 === (int) get_option( SN_MCP_RW_NOTIFY_OVERFLOW_OPTION ), 'the coalesced call is stashed as an overflow count of 1' );

// Fast-forward past the coalesce window by backdating last_sent directly (deterministic, no real sleep).
update_option( SN_MCP_RW_NOTIFY_LAST_SENT_OPTION, time() - SN_MCP_RW_NOTIFY_COALESCE_WINDOW_SECONDS - 1, false );
$row_c = array( 'slug' => 'signal-noise/prune-unused-tags', 'outcome' => 'ok', 'user_id' => 1, 'app_pw_uuid' => 'u', 'args_redacted' => array() );
$sent3 = sn_mcp_rw_notify_maybe_send( $row_c );
ok( true === $sent3 && 2 === count( $GLOBALS['__mails_sent'] ), 'once the window has passed, the next call sends' );
ok( false !== strpos( $GLOBALS['__mails_sent'][1]['body'], '1 more write-door call' ), 'the overflow count from the coalesced call rides along in the next mail\'s body' );
ok( 0 === (int) get_option( SN_MCP_RW_NOTIFY_OVERFLOW_OPTION ), 'overflow count resets to 0 after a successful send folds it in' );

// ============================================================
// R5 — never blocks/errors the tool call: admin_email missing, wp_mail() false, wp_mail() throws
// ============================================================
echo "\n-- sn_mcp_rw_notify_maybe_send: failure paths never throw --\n";
update_option( SN_MCP_RW_NOTIFY_LAST_SENT_OPTION, 0, false );
$GLOBALS['__opts']['admin_email'] = '';
$GLOBALS['__mails_sent'] = array();
$r = sn_mcp_rw_notify_maybe_send( array( 'slug' => 'x', 'outcome' => 'ok', 'args_redacted' => array() ) );
ok( false === $r && array() === $GLOBALS['__mails_sent'], 'missing admin_email -> no send, no crash' );
$last_err = get_option( SN_MCP_RW_NOTIFY_LAST_ERROR_OPTION );
ok( is_array( $last_err ) && false !== strpos( $last_err['message'], 'admin_email' ), 'missing admin_email is recorded to the durable last-error option' );

update_option( SN_MCP_RW_NOTIFY_LAST_SENT_OPTION, 0, false );
$GLOBALS['__opts']['admin_email'] = 'owner@example.com';
$GLOBALS['__wp_mail_behavior'] = 'fail';
$r2 = sn_mcp_rw_notify_maybe_send( array( 'slug' => 'x', 'outcome' => 'ok', 'args_redacted' => array() ) );
ok( false === $r2, 'wp_mail() returning false -> maybe_send returns false, no exception propagates' );
$last_err2 = get_option( SN_MCP_RW_NOTIFY_LAST_ERROR_OPTION );
ok( is_array( $last_err2 ) && false !== strpos( $last_err2['message'], 'wp_mail' ), 'wp_mail()=false is recorded to the durable last-error option' );

update_option( SN_MCP_RW_NOTIFY_LAST_SENT_OPTION, 0, false );
$GLOBALS['__wp_mail_behavior'] = 'throw';
$threw = false;
try {
	$r3 = sn_mcp_rw_notify_maybe_send( array( 'slug' => 'x', 'outcome' => 'ok', 'args_redacted' => array() ) );
} catch ( \Throwable $e ) {
	$threw = true;
	$r3 = null;
}
ok( false === $threw, 'PROBE PIN: a wp_mail() that throws never propagates out of maybe_send (caught internally)' );
ok( false === $r3, 'a thrown mailer exception still resolves to a clean false return' );
$GLOBALS['__wp_mail_behavior'] = 'succeed';

// ============================================================
// Defense in depth: sn_mcp_rw_audit_record() must never throw, even if a
// third-party filter blows up inside update_option(). This is an
// observability side-channel, not the security gate (SEC-A's
// permission_callback is the gate, and it runs before this is ever reached).
// ============================================================
echo "\n-- sn_mcp_rw_audit_record: never throws even if update_option() explodes --\n";
$GLOBALS['__force_update_option_throw'] = true;
$threw_record = false;
$record_result = 'unset';
try {
	$record_result = sn_mcp_rw_audit_record( 'signal-noise/purge-all-caches', array(), 'ok' );
} catch ( \Throwable $e ) {
	$threw_record = true;
}
ok( false === $threw_record, 'PROBE PIN: a third-party update_option() failure never propagates out of sn_mcp_rw_audit_record()' );
ok( null === $record_result, 'a swallowed failure returns null (never a fatal, never a fabricated row)' );
$GLOBALS['__force_update_option_throw'] = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
