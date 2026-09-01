<?php
/**
 * Standalone fixture tests for inc/security-digest.php (weekly security email).
 *
 * Covers the collector's graceful degradation, the pure composer (active week /
 * zero-week heartbeat / guard-absent / audit-absent), the subject line, the
 * sender's last-sent/last-error recording, and the self-healing cron sync.
 *
 * Run: php tests/security-digest.php
 * @since plugin v7.2.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'checked' ) ) { function checked() {} }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field() {} }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'Test Site'; } }
if ( ! function_exists( 'wp_specialchars_decode' ) ) { function wp_specialchars_decode( $s, $q = 0 ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '1 hour'; } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return false !== strpos( (string) $e, '@' ); } }

// ── settings store ──
$GLOBALS['__settings'] = array();
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
}
if ( ! function_exists( 'sn_setting_update' ) ) {
	function sn_setting_update( $key, $value ) { $GLOBALS['__settings'][ $key ] = $value; return true; }
}

// ── options store (durable last-sent / last-error) ──
$GLOBALS['__options'] = array( 'admin_email' => 'owner@example.com' );
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }

// ── cron store ──
$GLOBALS['__cron'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h ) { return $GLOBALS['__cron'][ $h ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['__cron'][ $h ] = $ts; return true; } }
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $ts, $h ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }

// ── mail stub ──
$GLOBALS['__mail']    = array();
$GLOBALS['__mail_ok'] = true;
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body ) {
		$GLOBALS['__mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
		return $GLOBALS['__mail_ok'];
	}
}

// ── data-source stubs (value-toggled) ──
$GLOBALS['__audit_summary'] = array();
$GLOBALS['__audit_days']    = array();
if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
	function snt_audit_get_summary_impl() { return $GLOBALS['__audit_summary']; }
}
if ( ! function_exists( 'snt_audit_get_counters_impl' ) ) {
	function snt_audit_get_counters_impl( $days ) { return $GLOBALS['__audit_days']; }
}
$GLOBALS['__lg_headline'] = array( 'configured' => false, 'checked' => 0, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '' );
if ( ! function_exists( 'sn_login_defense_headline' ) ) {
	function sn_login_defense_headline() { return $GLOBALS['__lg_headline']; }
}
$GLOBALS['__lg_top_country'] = array();
if ( ! function_exists( 'sn_login_defense_top_country_sql' ) ) { function sn_login_defense_top_country_sql( $d = 30, $l = 10 ) { return 'SQL'; } }
if ( ! function_exists( 'sn_analytics_query' ) ) { function sn_analytics_query( $sql ) { return $GLOBALS['__lg_top_country']; } }
$GLOBALS['__lg_status'] = null; // null ⇒ worker unreachable
if ( ! function_exists( 'sn_login_defense_status' ) ) { function sn_login_defense_status() { return $GLOBALS['__lg_status']; } }

require __DIR__ . '/../inc/security-digest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── collector: full data ──
echo "\nTest: collector\n";
$GLOBALS['__audit_summary'] = array( 'last_7d_vs_prior' => array( 'current' => 37, 'prior' => 20, 'pct_delta' => 85 ) );
$GLOBALS['__audit_days']    = array(
	array( 'login_failed' => 5, 'wp_login_404' => 2, 'wp_admin_unauth_404' => 1, 'lockout_triggered' => 1 ),
	array( 'login_failed' => 3, 'wp_login_404' => 0, 'wp_admin_unauth_404' => 0, 'lockout_triggered' => 0 ),
);
$GLOBALS['__lg_headline']    = array( 'configured' => true, 'checked' => 500, 'blocked' => 412, 'block_rate' => 82, 'top_network' => 'EvilNet' );
$GLOBALS['__lg_top_country'] = array( array( 'country' => 'CN', 'hits' => 300 ) );
$GLOBALS['__lg_status']      = array( 'denylistCount' => 4200, 'ageHours' => 6, 'stale' => false, 'version' => '1.2.0', 'compiledAt' => '2026-07-01T04:07:00Z' );

$data = snt_security_digest_collect();
ok( 37 === ( $data['audit']['events_7d'] ?? -1 ), 'collect: audit events_7d' );
ok( 8 === ( $data['audit']['failed_7d'] ?? -1 ), 'collect: failed_7d summed across days' );
ok( 3 === ( $data['audit']['recon_7d'] ?? -1 ), 'collect: recon_7d summed across days' );
ok( 1 === ( $data['audit']['lockouts_7d'] ?? -1 ), 'collect: lockouts_7d summed' );
ok( 412 === ( $data['guard']['blocked'] ?? -1 ), 'collect: guard blocked' );
ok( 'CN' === ( $data['guard']['top_country'] ?? '' ), 'collect: guard top country' );
ok( false === ( $data['status']['stale'] ?? true ), 'collect: status stale flag' );

// ── collector: everything absent degrades to nulls ──
$GLOBALS['__audit_summary'] = array();
$GLOBALS['__audit_days']    = array();
$GLOBALS['__lg_headline']   = array( 'configured' => false, 'checked' => 0, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '' );
$GLOBALS['__lg_status']     = null;
$data = snt_security_digest_collect();
ok( null === $data['guard'], 'collect: unconfigured guard → null' );
ok( null === $data['status'], 'collect: unreachable status → null' );

// ── composer: active week ──
echo "\nTest: composer\n";
$active = array(
	'window' => array( 'days' => 7 ),
	'audit'  => array( 'events_7d' => 37, 'prior_7d' => 20, 'pct_delta' => 85, 'failed_7d' => 8, 'recon_7d' => 3, 'lockouts_7d' => 1 ),
	'guard'  => array( 'checked' => 500, 'blocked' => 412, 'block_rate' => 82, 'top_network' => 'EvilNet', 'top_country' => 'CN' ),
	'status' => array( 'denylist_count' => 4200, 'age_hours' => 6, 'stale' => false, 'version' => '1.2.0' ),
);
$body = snt_security_digest_compose( $active );
ok( false !== strpos( $body, 'Failed logins: 8' ), 'compose: failed logins line' );
ok( false !== strpos( $body, 'Blocked: 412' ), 'compose: guard blocked line' );
ok( false !== strpos( $body, 'CN' ), 'compose: top country present' );
ok( false !== strpos( $body, '4,200' ), 'compose: denylist freshness' );
ok( false !== strpos( $body, '+85%' ), 'compose: trend delta' );
ok( false === strpos( $body, 'Quiet week' ), 'compose: no heartbeat wording on active week' );

// ── composer: zero week (heartbeat) ──
$zero = array(
	'window' => array( 'days' => 7 ),
	'audit'  => array( 'events_7d' => 0, 'prior_7d' => 0, 'pct_delta' => 0, 'failed_7d' => 0, 'recon_7d' => 0, 'lockouts_7d' => 0 ),
	'guard'  => array( 'checked' => 0, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '', 'top_country' => '' ),
	'status' => array( 'denylist_count' => 4200, 'age_hours' => 6, 'stale' => false, 'version' => '1.2.0' ),
);
$body = snt_security_digest_compose( $zero );
ok( false !== strpos( $body, 'Quiet week' ), 'compose: zero week says quiet' );
ok( false !== strpos( $body, '4,200' ), 'compose: zero week still shows guard alive' );

// ── composer: sections degrade to unavailable lines ──
$bare = array( 'window' => array( 'days' => 7 ), 'audit' => null, 'guard' => null, 'status' => null );
$body = snt_security_digest_compose( $bare );
ok( false !== strpos( $body, 'Audit log: unavailable' ), 'compose: audit unavailable line' );
ok( false !== strpos( $body, 'Login guard: not configured' ), 'compose: guard unavailable line' );
ok( false !== strpos( $body, 'status unavailable' ), 'compose: status unavailable line' );

// ── composer: stale guard warns ──
$staleData = $active;
$staleData['status']['stale'] = true;
$staleData['status']['age_hours'] = 90;
$body = snt_security_digest_compose( $staleData );
ok( false !== strpos( $body, 'STALE' ), 'compose: stale denylist warns' );

// ── subject ──
echo "\nTest: subject\n";
ok( '[Test Site] Weekly security digest: 412 blocked, 8 failed logins' === snt_security_digest_subject( $active ), 'subject: counts embedded' );
ok( 0 === strpos( snt_security_digest_subject( $active, true ), '[TEST] ' ), 'subject: test prefix' );
ok( '[Test Site] Weekly security digest: 0 blocked, 0 failed logins' === snt_security_digest_subject( $bare ), 'subject: null sections read as zero' );

// ── sender: success records last-sent, clears last-error ──
echo "\nTest: sender\n";
$GLOBALS['__mail'] = array();
$GLOBALS['__mail_ok'] = true;
$GLOBALS['__options'][ SN_SECURITY_DIGEST_LAST_ERROR ] = array( 'message' => 'old', 'at' => 1 );
$ok = snt_security_digest_send();
ok( true === $ok, 'send: returns true on success' );
ok( 1 === count( $GLOBALS['__mail'] ), 'send: one mail dispatched' );
ok( 'owner@example.com' === $GLOBALS['__mail'][0]['to'], 'send: to admin_email' );
ok( is_int( get_option( SN_SECURITY_DIGEST_LAST_SENT ) ), 'send: last-sent recorded' );
ok( false === get_option( SN_SECURITY_DIGEST_LAST_ERROR ), 'send: last-error cleared' );

// ── sender: failure records last-error ──
$GLOBALS['__mail_ok'] = false;
$ok = snt_security_digest_send();
ok( false === $ok, 'send: returns false on failure' );
$err = get_option( SN_SECURITY_DIGEST_LAST_ERROR );
ok( is_array( $err ) && isset( $err['at'] ), 'send: last-error recorded' );
$GLOBALS['__mail_ok'] = true;

// ── sender: test mode prefixes subject ──
$GLOBALS['__mail'] = array();
snt_security_digest_send( true );
ok( 0 === strpos( $GLOBALS['__mail'][0]['subject'], '[TEST] ' ), 'send: test subject prefixed' );

// ── cron self-healing sync ──
echo "\nTest: cron sync\n";
$GLOBALS['__settings']['audit.digest_email_enabled'] = true;
$GLOBALS['__cron'] = array();
snt_security_digest_maybe_schedule_cron();
ok( false !== wp_next_scheduled( SN_SECURITY_DIGEST_CRON_HOOK ), 'cron: schedules when enabled+unscheduled' );
snt_security_digest_maybe_schedule_cron();
ok( 1 === count( $GLOBALS['__cron'] ), 'cron: idempotent when already scheduled' );
$GLOBALS['__settings']['audit.digest_email_enabled'] = false;
snt_security_digest_maybe_schedule_cron();
ok( false === wp_next_scheduled( SN_SECURITY_DIGEST_CRON_HOOK ), 'cron: unschedules when disabled' );

// ── admin-post handler (plain function returning a flash slug, per dispatcher contract) ──
echo "\nTest: admin-post handler\n";
require __DIR__ . '/../inc/admin-post-actions.php';
$GLOBALS['__settings']['audit.digest_email_enabled'] = false;
$slug = sn_handle_security_digest_save( array( 'sn_digest_enabled' => '1' ) );
ok( 'digest_saved' === $slug && true === $GLOBALS['__settings']['audit.digest_email_enabled'], 'handler: enables and returns saved slug' );
ok( false !== wp_next_scheduled( SN_SECURITY_DIGEST_CRON_HOOK ), 'handler: enabling syncs the cron on' );
$slug = sn_handle_security_digest_save( array() );
ok( 'digest_saved' === $slug && false === $GLOBALS['__settings']['audit.digest_email_enabled'], 'handler: absent checkbox disables' );
ok( false === wp_next_scheduled( SN_SECURITY_DIGEST_CRON_HOOK ), 'handler: disabling syncs the cron off' );
$GLOBALS['__mail'] = array();
$slug = sn_handle_security_digest_save( array( 'sn_digest_test' => '1' ) );
ok( 'digest_test_sent' === $slug && 1 === count( $GLOBALS['__mail'] ), 'handler: test-send dispatches mail' );
$GLOBALS['__mail_ok'] = false;
$slug = sn_handle_security_digest_save( array( 'sn_digest_test' => '1' ) );
ok( 'digest_test_failed' === $slug, 'handler: failed test-send returns error slug' );
$GLOBALS['__mail_ok'] = true;

// ── settings render: .sn-fieldset card structure (v7.2.1 — the status box is a
// flex row; the digest section must be its own fieldset card, never a flex child) ──
echo "\nTest: settings render structure\n";
ob_start();
snt_security_digest_render_settings();
$out = ob_get_clean();
ok( false !== strpos( $out, '<form method="post" class="sn-fieldset">' ), 'render: form IS the fieldset card' );
ok( false !== strpos( $out, 'class="sn-fieldset-h"' ), 'render: fieldset heading class' );
ok( false !== strpos( $out, 'class="sn-field-helper"' ), 'render: helper copy uses field-helper' );
$actions = strpos( $out, 'class="sn-fieldset-actions"' );
ok( false !== $actions, 'render: actions row present' );
ok( false !== strpos( $out, 'button-primary', (int) $actions ), 'render: Save inside actions row' );
ok( false !== strpos( $out, 'sn_digest_test', (int) $actions ), 'render: test-send inside actions row' );
ok( false !== strpos( $out, 'name="sn_digest_enabled"' ), 'render: opt-in checkbox present' );


// ── v13.60.0: the breached-password section ──
echo "\nTest: breached-password section\n";
$d0 = snt_security_digest_collect();
ok( array_key_exists( 'breach', $d0 ) && null === $d0['breach'], 'collect: module absent → breach is null' );
ok( false !== strpos( snt_security_digest_compose( $d0 ), 'Breached-password check: unavailable (module not loaded).' ), 'compose: null → one truthful unavailable line, never zeros' );
if ( ! function_exists( 'sn_hibp_surface_data' ) ) { function sn_hibp_surface_data() { return $GLOBALS['__hibp_data']; } }
if ( ! function_exists( 'sn_hibp_health' ) ) { function sn_hibp_health( $d, $now ) { return $GLOBALS['__hibp_health']; } }
$GLOBALS['__hibp_data']   = array( 'set' => array( 'breached_count' => 3, 'unavailable_count' => 2 ), 'login' => array() );
$GLOBALS['__hibp_health'] = array( 'status' => 'recommended', 'summary' => 'THE SUMMARY', 'flagged' => 1, 'checked' => 2, 'unavailable_recent' => true );
$d1 = snt_security_digest_collect();
ok( 1 === ( $d1['breach']['flagged'] ?? -1 ) && 3 === ( $d1['breach']['set_breached'] ?? -1 ) && true === ( $d1['breach']['unavailable_recent'] ?? false ), 'collect: breach carries flagged/checked/set counts and the recent flag' );
$b1 = snt_security_digest_compose( $d1 );
ok( false !== strpos( $b1, 'Breached-password check (ATTENTION)' ) && false !== strpos( $b1, 'Accounts flagged at login: 1 of 2 checked' ) && false !== strpos( $b1, 'refused at set-time: 3' ), 'compose: ATTENTION header + the three count lines' );
ok( false !== strpos( $b1, 'Fail-closed rejections (API unreachable): 2 — RECENT: the API may be degrading' ) && false !== strpos( $b1, '  THE SUMMARY' ), 'compose: recent fail-closed is called out, and a non-good verdict prints its summary' );
$GLOBALS['__hibp_health'] = array( 'status' => 'good', 'summary' => 'FINE', 'flagged' => 0, 'checked' => 2, 'unavailable_recent' => false );
$b2 = snt_security_digest_compose( snt_security_digest_collect() );
ok( false !== strpos( $b2, 'Breached-password check (ok)' ) && false === strpos( $b2, 'RECENT' ) && false === strpos( $b2, 'FINE' ), 'compose: good → ok header, no RECENT tag, no summary line' );
ok( false === strpos( $b2, 'unavailable (module not loaded)' ), 'compose: the loaded module never prints the unavailable line' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
