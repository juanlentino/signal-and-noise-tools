<?php
/**
 * Fixture for the Settings › Analytics suite (tests/os-leaf-settings-analytics.php):
 * the real renderers and the real save handlers over the harness's option
 * and settings stores, then both twins of the pane. The
 * pane's own files are required only when they exist, so before the change
 * the suites report red instead of dying.
 *
 * Every URL here is an obviously fake one.
 *
 * @package SignalNoiseTools
 */

function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; } // bound before the harness's guarded stub.
require_once __DIR__ . '/os-leaf-harness.php';
require_once __DIR__ . '/whole-forms.php';

const SA_FAKE_COLLECTOR = 'https://fake-collector.example.test/_sn/px';
const SA_FAKE_WORKER    = 'https://fake-sensor.example.test';
if ( ! defined( 'SN_SETTINGS_OPTION' ) ) { define( 'SN_SETTINGS_OPTION', 'sn_settings' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function delete_transient( $k ) { return true; }
/** The tracker's table is never read by the pane; its prefix is. */
class SNT_Test_SA_Wpdb { public $prefix = 'wp_'; public $last_error = ''; public function get_charset_collate() { return ''; } }
$GLOBALS['wpdb'] = new SNT_Test_SA_Wpdb();

$GLOBALS['__settings'] = array();
$GLOBALS['__roles']    = array( 'administrator' => 'Administrator', 'editor' => 'Editor', 'subscriber' => 'Subscriber' );
$GLOBALS['__cron_on']  = false;
function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; }
function sn_setting_update( $key, $value ) { $GLOBALS['__settings'][ $key ] = $value; return true; }
function sn_setting_reset_cache() {}
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function sn_beacon_excludable_roles() { return $GLOBALS['__roles']; }
function sn_beacon_sanitize_exclude_roles( $submitted ) { return array_values( array_intersect( array_map( 'strval', (array) $submitted ), array_keys( $GLOBALS['__roles'] ) ) ); }
function sn_beacon_owner_current_user_excluded() { return false; }
function snt_mr_cache_flush() {}
function snt_insights_weekly_cron_enabled() { return (bool) sn_setting( 'insights.weekly_cron_enabled', false ); }
function sn_admin_shell_open() {}
function sn_admin_shell_rail( $title = '' ) {}
function sn_admin_shell_close() {}

$snt_sa_files = array(
	'inc/admin-legacy-redirect.php', 'inc/analytics-sessions.php', 'inc/analytics-render-settings.php',
	'inc/rss-feed-tracker.php', 'inc/publish-window.php', 'inc/publish-window-settings.php', 'inc/north-star.php',
	'inc/machine-readers-admin.php', 'inc/insights-admin.php',
	'inc/admin-post-actions/analytics.php', 'inc/admin-post-actions/health-insights.php',
);
foreach ( $snt_sa_files as $snt_sa_file ) {
	require_once SNT_PATH . $snt_sa_file;
}
foreach ( array( 'inc/admin-forms/settings-analytics.php', 'apps/sn-dashboard/parts/leaves/settings-analytics.php' ) as $snt_sa_file ) {
	if ( is_file( SNT_PATH . $snt_sa_file ) ) { // Absent before the change: the assertions go red, the suite still reports.
		require_once SNT_PATH . $snt_sa_file;
	}
}

const SA_PANE  = 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=analytics';
const SA_LABEL = 'Settings › Analytics';

/** A site with every setting set, so a form that loses one shows. */
function sa_seed() {
	$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ] = array( 'enabled' => true, 'collector_url' => SA_FAKE_COLLECTOR, 'event_name' => 'RSS Feed Request', 'log_retention_days' => 45 );
	$GLOBALS['__options'][ SN_SETTINGS_OPTION ]          = array( 'machine_readers' => array( 'worker_url' => SA_FAKE_WORKER, 'read_token' => 'fake-read-token-for-test' ), 'theme' => array( 'kept' => 1 ) );
	$GLOBALS['__settings'] = array(
		'analytics.exclude_roles'        => array( 'administrator', 'editor' ),
		'analytics.signal_baseline_days' => 45,
		'analytics.anomaly_sensitivity'  => 'strict',
		'analytics.publish_window_minutes' => 90,
		'north_star'                     => array( 'sections' => array( 'notes' ), 'scroll' => 60, 'dwell_s' => 45 ),
		'analytics.funnels'              => array( array( 'name' => 'Home flow', 'steps' => array( array( 'match' => 'exact', 'value' => '/' ), array( 'match' => 'exact', 'value' => '/notes/' ) ) ) ),
		'machine_readers.worker_url'     => SA_FAKE_WORKER,
		'machine_readers.read_token'     => 'fake-read-token-for-test',
		'insights.weekly_cron_enabled'   => true,
	);
}
/** The classic pane, or '' before the change. */
function sa_classic() { return function_exists( 'sn_admin_render_settings_analytics_section' ) ? snt_leaf_classic_html( 'sn_admin_render_settings_analytics_section' ) : ''; }
/** The native pane, or '' before the change. */
function sa_kit() { return snt_leaf_paint( 'settings', 'analytics' ); }
/** Every form (or os-form) on a twin that posts $action. */
function sa_forms( $html, $tag, $action ) {
	preg_match_all( '#<' . $tag . '\b.*?</' . $tag . '>#s', (string) $html, $m );
	return array_values( array_filter( $m[0], static fn( $form ) => false !== strpos( $form, 'sn_' . $action ) ) );
}
/** The one form that posts $action, '' when none or several do. */
function sa_form( $html, $tag, $action ) {
	$forms = sa_forms( $html, $tag, $action );
	return 1 === count( $forms ) ? $forms[0] : '';
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
