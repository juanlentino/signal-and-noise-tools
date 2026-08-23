<?php
/**
 * Standalone tests for inc/uptime-heartbeat-removal.php.
 *
 * The heartbeat module is GONE; this pins the janitor that cleans up after it.
 * The assertion that matters is the one about the live cron event: deleting the
 * module without unscheduling leaves `sn_uptime_kuma_heartbeat` firing every
 * five minutes against no handler, forever.
 *
 * @package SignalNoiseTools
 * @since 12.19.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_UPTIME_HEARTBEAT_REMOVAL_TEST', true );
define( 'SN_SETTINGS_OPTION', 'sn_settings' );
define( 'SNT_VERSION', '12.19.0' );

$GLOBALS['__opts']    = array();
$GLOBALS['__cleared'] = array();
$GLOBALS['__cache_reset'] = 0;

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $h ) { $GLOBALS['__cleared'][] = $h; return 1; } }
if ( ! function_exists( 'sn_setting_reset_cache' ) ) { function sn_setting_reset_cache() { $GLOBALS['__cache_reset']++; } }

require_once __DIR__ . '/../inc/uptime-heartbeat-removal.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
ob_start();
echo "uptime heartbeat removal janitor (v12.19.0)\n\n";

// ---- THE ASSERTION THAT MATTERS -------------------------------------------
// Without this the deleted module leaves a live 5-minute cron event with no
// handler, forever — the exact hazard the removed module's docblock warned of.
ok( 'sn_uptime_kuma_heartbeat' === SN_UPTIME_HEARTBEAT_REMOVED_HOOK, 'targets the historical hook name, not a renamed one' );
$GLOBALS['__cleared'] = array();
sn_uptime_heartbeat_unschedule_removed();
ok( in_array( 'sn_uptime_kuma_heartbeat', $GLOBALS['__cleared'], true ), 'UNSCHEDULES the live cron event' );

// ---- settings pruning ------------------------------------------------------
$GLOBALS['__opts'][ SN_SETTINGS_OPTION ] = array(
	'monitoring' => array(
		'uptime_kuma_enabled'  => true,
		'uptime_kuma_push_url' => 'https://uptime.betterstack.com/api/v1/heartbeat/tok',
		'uptime_api_token'     => 'KEEP-ME',
	),
	'seo_copy'   => array( 'home_title' => 'unrelated' ),
);
ok( true === sn_uptime_heartbeat_prune_settings(), 'reports true when it actually removed something' );
$after = $GLOBALS['__opts'][ SN_SETTINGS_OPTION ];
ok( ! isset( $after['monitoring']['uptime_kuma_enabled'] ), 'drops uptime_kuma_enabled' );
ok( ! isset( $after['monitoring']['uptime_kuma_push_url'] ), 'drops uptime_kuma_push_url' );
// The group SURVIVES: spend-watch and uptime-status write credentials under it.
ok( 'KEEP-ME' === ( $after['monitoring']['uptime_api_token'] ?? '' ), 'KEEPS the Better Stack token — the monitor we are not removing' );
ok( isset( $after['seo_copy']['home_title'] ), 'touches nothing outside the monitoring group' );
ok( $GLOBALS['__cache_reset'] > 0, 'resets sn_setting()\'s static cache so THIS request stops seeing the dead keys' );

// Idempotent: "already clean" is distinguishable from "cleaned".
ok( false === sn_uptime_heartbeat_prune_settings(), 'a second pass reports false (already clean), not a phantom write' );

// No monitoring group at all — must not fatal or invent one.
$GLOBALS['__opts'][ SN_SETTINGS_OPTION ] = array( 'seo_copy' => array() );
ok( false === sn_uptime_heartbeat_prune_settings(), 'a settings blob with no monitoring group is a no-op' );
ok( ! isset( $GLOBALS['__opts'][ SN_SETTINGS_OPTION ]['monitoring'] ), 'and does NOT create the group as a side effect' );

// ---- one-shot stamping -----------------------------------------------------
$GLOBALS['__opts'] = array( SN_SETTINGS_OPTION => array( 'monitoring' => array( 'uptime_kuma_enabled' => true ) ) );
$GLOBALS['__cleared'] = array();
sn_uptime_heartbeat_janitor();
ok( SNT_VERSION === ( $GLOBALS['__opts'][ SN_UPTIME_HEARTBEAT_JANITOR_OPT ] ?? '' ), 'stamps the version it ran under' );
ok( count( $GLOBALS['__cleared'] ) === 1, 'first run unschedules once' );
sn_uptime_heartbeat_janitor();
ok( count( $GLOBALS['__cleared'] ) === 1, 'second run on the SAME version does nothing (one-shot)' );

$report = ob_get_clean(); echo $report;
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
