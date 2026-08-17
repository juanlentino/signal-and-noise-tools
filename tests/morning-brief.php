<?php
/** Standalone fixture tests for the R6a Operations morning brief. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_DEPLOY_REPOS', array( 'theme' => 'owner/theme', 'plugin' => 'owner/plugin' ) );

function add_action() {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function checked() {}
function wp_nonce_field() {}
function get_bloginfo( $key = '' ) { return 'Test Site'; }
function wp_specialchars_decode( $value, $flags = 0 ) { return $value; }
function is_email( $email ) { return false !== strpos( $email, '@' ); }
function human_time_diff( $from, $to = 0 ) { return '2 hours'; }
function current_datetime() { return new DateTimeImmutable( '2026-08-17 06:30:00', new DateTimeZone( 'America/New_York' ) ); }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }

$GLOBALS['__settings'] = array();
function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
$GLOBALS['__options'] = array( 'admin_email' => 'owner@example.com' );
function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }

$GLOBALS['__cron'] = array();
function wp_next_scheduled( $hook ) { return $GLOBALS['__cron'][ $hook ]['timestamp'] ?? false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['__cron'][ $hook ] = compact( 'timestamp', 'recurrence' ); return true; }
function wp_unschedule_event( $timestamp, $hook ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }

$GLOBALS['__mail'] = array();
function wp_mail( $to, $subject, $body ) { $GLOBALS['__mail'][] = compact( 'to', 'subject', 'body' ); return true; }

function sn_health_last_scan() { return array( 'scanned_at' => 100, 'checks' => array( 'links' => array(), 'seo' => array() ) ); }
function sn_health_finding_total( $scan ) { return 2; }
function sn_health_advisory_total( $scan ) { return 1; }
function snt_cron_get_events_impl() {
	return array(
		array( 'hook' => 'sn_one', 'is_sn_owned' => true, 'has_handler' => true ),
		array( 'hook' => 'orphan', 'is_sn_owned' => false, 'has_handler' => false ),
	);
}
function snt_cron_history_for_hook( $hook, $limit = 10 ) { return array( array( 'success' => true ), array( 'success' => false ) ); }
function sn_uptime_status_configured() { return true; }
function sn_uptime_status_fetch( $force = false ) { return array( 'rows' => array( array( 'status' => 'up' ), array( 'status' => 'down' ) ) ); }
function is_wp_error( $value ) { return false; }
function snt_deploy_status_for( $package ) { return array( 'state' => 'theme' === $package ? 'ok' : 'available' ); }
function snt_deploy_history_merged( $repos, $limit ) { return array( array( 'created_at' => '2026-08-17T05:00:00Z' ) ); }
function snt_deploy_runs_age_label( $runs ) { return '2 hours ago'; }
$GLOBALS['__drift'] = array( 'has_drift' => false, 'count' => 0, 'changed' => array(), 'added' => array(), 'removed' => array() );
function snt_config_drift_status() { return $GLOBALS['__drift']; }

require __DIR__ . '/../inc/morning-brief.php';

$pass = 0; $fail = 0;
function ok( $condition, $message ) { global $pass, $fail; if ( $condition ) { $pass++; echo "  PASS: $message\n"; } else { $fail++; echo "  FAIL: $message\n"; } }

echo "\nTest: default and collector\n";
ok( false === snt_morning_brief_enabled(), 'new mail surface defaults off' );
$data = snt_morning_brief_collect();
ok( 2 === $data['health']['findings'] && 1 === $data['health']['advisories'], 'collector reads cached health facts' );
ok( 2 === $data['cron']['total'] && 1 === $data['cron']['failed'], 'collector reads cron events and real history shape' );
ok( 1 === $data['uptime']['up'] && 1 === $data['uptime']['attention'], 'collector summarizes uptime rows' );
ok( 'available' === $data['deploy']['plugin']['state'], 'collector reads deploy status helper' );

echo "\nTest: prose composer\n";
$body = snt_morning_brief_compose( $data );
ok( false !== strpos( $body, '2 fault-tier issues across 2 checks' ), 'compose narrates health facts as prose' );
ok( false !== strpos( $body, '2 scheduled events' ) && false !== strpos( $body, '1 of the 2 recent owned firings' ), 'compose narrates cron facts' );
ok( false !== strpos( $body, '1 of 2 monitors and heartbeats up' ), 'compose narrates uptime facts' );
ok( false !== strpos( $body, '1 update available' ), 'compose narrates deploy facts' );
ok( false === strpos( $body, 'Configuration drift' ), 'compose is silent when drift does not exist' );
$data['drift'] = array( 'has_drift' => true, 'count' => 3, 'changed' => array( 'a' ), 'added' => array( 'b' ), 'removed' => array( 'c' ) );
$body = snt_morning_brief_compose( $data );
ok( 1 === substr_count( $body, 'Configuration drift' ), 'compose adds exactly one drift sentence' );
ok( false !== strpos( $body, '1 changed, 1 added, and 1 removed' ), 'drift sentence states the diff shape' );

echo "\nTest: subject, send, and cron\n";
ok( false !== strpos( snt_morning_brief_subject( $data ), '[Test Site] Morning operations brief' ), 'subject names the site and brief' );
$GLOBALS['__mail'] = array();
ok( true === snt_morning_brief_send( true ) && 1 === count( $GLOBALS['__mail'] ), 'test sender dispatches one email' );
ok( 0 === strpos( $GLOBALS['__mail'][0]['subject'], '[TEST] ' ), 'test sender prefixes the subject' );
ok( '2026-08-17 07:00' === ( new DateTimeImmutable( '@' . snt_morning_brief_next_run() ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d H:i' ), 'next run is 7:00 a.m. site time' );
$GLOBALS['__settings']['operations.morning_brief_enabled'] = true;
snt_morning_brief_maybe_schedule_cron();
ok( 'daily' === $GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ]['recurrence'], 'cron schedules daily when enabled' );
snt_morning_brief_maybe_schedule_cron();
ok( 1 === count( $GLOBALS['__cron'] ), 'cron scheduling is idempotent' );
// DST drift: wp_schedule_event repeats at fixed +86400s, so after a
// transition the pending firing lands off 7:00 site time. The init hook
// must re-anchor it rather than no-op on "already scheduled".
$drifted = ( new DateTimeImmutable( '2026-11-02 06:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
$GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ] = array( 'timestamp' => $drifted, 'recurrence' => 'daily' );
snt_morning_brief_maybe_schedule_cron();
$re_anchored = ( new DateTimeImmutable( '@' . $GLOBALS['__cron'][ SNT_MORNING_BRIEF_CRON_HOOK ]['timestamp'] ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'H:i' );
ok( '07:00' === $re_anchored, 'a DST-drifted firing (6:00 site time) is re-anchored to 7:00' );
$GLOBALS['__settings']['operations.morning_brief_enabled'] = false;
snt_morning_brief_maybe_schedule_cron();
ok( false === wp_next_scheduled( SNT_MORNING_BRIEF_CRON_HOOK ), 'cron unschedules when disabled' );

echo "\nTest: settings shape\n";
$GLOBALS['__drift'] = array( 'has_drift' => true, 'count' => 2, 'changed' => array( 'a' ), 'added' => array( 'b' ), 'removed' => array() );
ob_start();
snt_morning_brief_render_settings();
$rendered = ob_get_clean();
ok( false !== strpos( $rendered, 'class="sn-fieldset"' ), 'settings render uses the digest fieldset shape' );
ok( false !== strpos( $rendered, 'name="snt_morning_brief_enabled"' ), 'settings render includes the opt-in toggle' );
ok( false !== strpos( $rendered, 'name="snt_morning_brief_test"' ), 'settings render includes a test-send action' );
ok( false !== strpos( $rendered, 'name="snt_config_drift_acknowledge"' ), 'settings render exposes explicit drift acknowledgement only when needed' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
