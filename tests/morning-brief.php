<?php
/** Standalone fixture tests for the R6a Operations morning brief. */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_DEPLOY_REPOS', array( 'theme' => 'owner/theme', 'plugin' => 'owner/plugin' ) );
// WordPress core constants + i18n helper the composer relies on. WP always
// defines these; the harness did not, and the R6b search section was the first
// code path here to reach for them — it fataled the moment a fixture exercised it.
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
}

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

echo "\nTest: R6b search section\n";
// SILENT without data, on purpose: an operations brief that says "no search
// data" every morning for a site that never connected GSC is noise, and the
// setup nag belongs on the settings screen.
ok( false === strpos( $body, 'Google showed the site' ), 'compose is SILENT when nothing has synced' );
$data['search'] = array(
	'impressions' => 4200, 'clicks' => 61, 'zero_click' => 3,
	'top_query' => 'music provenance', 'synced_at' => time(),
	'window' => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
);
$body = snt_morning_brief_compose( $data );
ok( false !== strpos( $body, 'Google showed the site 4,200 times' ), 'search sentence reports impressions' );
ok( false !== strpos( $body, '61 clicks' ), 'and clicks' );
ok( false !== strpos( $body, '2026-07-01 to 2026-07-28' ), "and names GOOGLE's window, not the brief's day" );
ok( false !== strpos( $body, '3 pages drew meaningful impressions without a single click' ), 'the zero-click count is called out — it is the actionable one' );
ok( false !== strpos( $body, '"music provenance"' ), 'and the most-seen query is quoted' );
ok( false === strpos( $body, 'has not been re-synced' ), 'a FRESH window raises no staleness sentence' );

// A window nobody refreshed must read as stale, not as current.
$data['search']['synced_at'] = time() - ( 9 * DAY_IN_SECONDS );
$body = snt_morning_brief_compose( $data );
ok( false !== strpos( $body, 'search window is 9 days old' ), 'a STALE window says so — otherwise old numbers read as this morning\'s' );

$data['search']['zero_click'] = 0;
$body = snt_morning_brief_compose( $data );
ok( false === strpos( $body, 'without a single click' ), 'no zero-click sentence when there are none' );
ok( 1 === substr_count( $body, 'Google showed the site' ), 'exactly one search sentence' );

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


// ─── v13.90.0: watches that have come due ─────────────────────────────────
// Every other section speaks on every send, including to say "unavailable",
// because its subject always exists. A watch that is not due has nothing to
// report, and a daily line saying so would train the reader to skip the
// paragraph it sits in.
$base = array( 'health' => null, 'cron' => null, 'uptime' => null, 'deploy' => null, 'drift' => null, 'search' => null, 'watches' => array() );

$quiet = snt_morning_brief_compose( $base );
// Asserting the MARKER alone was too narrow: a mutation adding "No watches are
// due." passed, because that string does not contain "Watch due". The property
// is that the brief says nothing about watches at all.
ok( false === stripos( $quiet, 'watch' ),
	'NO ripe watches emits NOTHING about watches — silence is the signal, not a daily "nothing yet"' );

$loud = snt_morning_brief_compose( array_merge( $base, array( 'watches' => array(
	array( 'id' => 'x', 'label' => 'reader-anomalies remote twin', 'note' => 'unchanged across 26 readings over 7.4 days', 'read' => 'sn-status{shape_stability}' ),
) ) ) );
ok( false !== strpos( $loud, 'Watch due — reader-anomalies remote twin' ), 'a ripe watch is named' );
ok( false !== strpos( $loud, '26 readings' ), 'with the evidence that ripened it, not a restatement of the label' );
ok( false !== strpos( $loud, 'sn-status{shape_stability}' ), 'and WHERE to read it, so the mail is actionable without a lookup' );

// One sentence per watch, so two ripe watches cannot collapse into one line.
$two = snt_morning_brief_compose( array_merge( $base, array( 'watches' => array(
	array( 'id' => 'a', 'label' => 'first', 'note' => 'n1', 'read' => 'r1' ),
	array( 'id' => 'b', 'label' => 'second', 'note' => 'n2', 'read' => 'r2' ),
) ) ) );
ok( 2 === substr_count( $two, 'Watch due —' ), 'each ripe watch gets its own sentence' );

// The subject line must reflect them, or a due watch arrives under a heading
// that says nothing needs attention.
$s_quiet = snt_morning_brief_subject( $base );
$s_loud  = snt_morning_brief_subject( array_merge( $base, array( 'watches' => array( array( 'id' => 'a', 'label' => 'l', 'note' => 'n', 'read' => 'r' ) ) ) ) );
ok( $s_quiet !== $s_loud, 'a ripe watch changes the SUBJECT — it must not arrive under "nothing needs attention"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
