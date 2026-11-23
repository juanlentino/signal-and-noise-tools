<?php
/**
 * Fixture for the Settings › AI suites (tests/os-leaf-settings-ai.php and
 * tests/os-leaf-settings-ai-doors.php): the readers the nine settings read,
 * driven from globals, then both twins of the pane and of the three leaves
 * the settings came from. The pane's own files are required only when they
 * exist, so before the change the suites report red instead of dying.
 *
 * @package SignalNoiseTools
 */

require_once __DIR__ . '/os-leaf-harness.php';
require_once __DIR__ . '/whole-forms.php';

$GLOBALS['__settings'] = array(
	'theme.ai_model'                     => 'claude-sonnet-5',
	'theme.ai_alt_model'                 => 'gemini-2.5-flash-lite',
	'theme.ai_monthly_budget'            => 50.0,
	'theme.jev_credit'                   => 5,
	'theme.jev_cycle_day'                => 17,
	'operations.scheduled_reads_enabled' => true,
);
$GLOBALS['__written']  = array();
function sn_setting( $key, $default = '' ) { return array_key_exists( $key, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $key ] : $default; }
function sn_setting_update( $key, $value ) { $GLOBALS['__settings'][ $key ] = $value; $GLOBALS['__written'][ $key ] = $value; return true; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( ...$a ) { return true; }
function wp_unschedule_event( ...$a ) { return true; }
function sn_mask_secret( $v ) { return '' === (string) $v ? '' : '••••'; }

// The MCP doors, as tests/os-leaf-ai-mcp-connect.php stubs them.
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function sn_mcp_namespace() { return 'signal-noise/v1'; }
function get_edit_profile_url() { return 'https://example.test/wp-admin/profile.php'; }
function sn_mcp_allowlist() { return array( 'signal-noise/sn-status' ); }
function sn_mcp_rw_allowlist() { return array( 'signal-noise/sn-apply' ); }
function sn_mcp_rw_bound_uuid() { return $GLOBALS['__rw_bound_uuid'] ?? ''; }
function sn_mcp_read_bound_uuid() { return $GLOBALS['__read_bound_uuid'] ?? ''; }
function sn_mcp_rw_kill_switch_constant_disabled() { return ! empty( $GLOBALS['__rw_constant_killed'] ); }
function sn_mcp_rw_enabled_option() { return $GLOBALS['__rw_option_on'] ?? true; }
function sn_mcp_read_kill_switch_engaged() { return ! empty( $GLOBALS['__read_off'] ); }
function sn_mcp_remote_kill_switch_engaged() { return $GLOBALS['__remote_kill_engaged'] ?? true; }
function sn_bridge_secret() { return 'secret'; }
function sn_mcp_remote_log_summary_text() { return 'Last used yesterday, 2 refused'; }

/** Two Application Passwords; `password` is the stored hash, which no screen may print. */
const SNT_AI_UUID_A = '11111111-aaaa-4aaa-8aaa-111111111111';
const SNT_AI_UUID_B = '22222222-bbbb-4bbb-8bbb-222222222222';
$GLOBALS['__passwords'] = array(
	array( 'uuid' => SNT_AI_UUID_A, 'name' => 'Laptop', 'password' => 'HASH-A-never-printed', 'created' => 1, 'last_used' => 2 ),
	array( 'uuid' => SNT_AI_UUID_B, 'name' => 'Phone', 'password' => 'HASH-B-never-printed', 'created' => 1, 'last_used' => 0 ),
);
class WP_Application_Passwords {
	public static function get_user_application_passwords( $user_id ) { return $GLOBALS['__passwords']; }
}

require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/admin-legacy-redirect.php';
require SNT_PATH . 'inc/admin-tabs.php';
require SNT_PATH . 'inc/admin-post-actions/theme-ai.php';
require SNT_PATH . 'inc/admin-post-actions/reports.php';
require SNT_PATH . 'inc/admin-forms/ai-settings.php';
require SNT_PATH . 'inc/admin-forms/mcp-connect.php';
require SNT_PATH . 'inc/scheduled-reads.php';
require SNT_PATH . 'inc/openstation-host-pipelines.php'; // snt_os_host_expand(): a native form's values as the handler receives them.
require SNT_PATH . 'inc/admin-post-actions/mcp.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-models-budget.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-mcp-connect.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-cron-parts.php';
foreach ( array( 'inc/admin-forms/settings-ai.php', 'apps/sn-dashboard/parts/leaves/settings-ai.php' ) as $snt_ai_file ) {
	if ( is_file( SNT_PATH . $snt_ai_file ) ) { // Absent before the change: the assertions go red, the suite still reports.
		require_once SNT_PATH . $snt_ai_file;
	}
}

/** The classic pane, or '' before the change. */
function ai_classic() { return function_exists( 'sn_admin_render_settings_ai_section' ) ? snt_leaf_classic_html( 'sn_admin_render_settings_ai_section' ) : ''; }
/** The native pane, or '' before the change. */
function ai_kit() { return snt_leaf_paint( 'settings', 'ai' ); }
/** A function's echo, or '' when it does not exist yet. */
function ai_out( $fn, ...$args ) {
	if ( ! function_exists( $fn ) ) {
		return '';
	}
	ob_start();
	$returned = $fn( ...$args );
	return (string) ob_get_clean() . ( is_string( $returned ) ? $returned : '' );
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
