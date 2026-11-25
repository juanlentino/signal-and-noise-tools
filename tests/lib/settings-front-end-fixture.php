<?php
/**
 * Fixture for the Settings › Front end suites
 * (tests/os-leaf-settings-front-end.php, the eight save_theme fields, and
 * tests/os-leaf-settings-front-end-pane.php, the pane around them): the
 * readers the nine settings read, driven from globals, then both twins of
 * the pane and of Site › Performance, the leaf the switch came from. The
 * pane's own files are required only when they exist, so before the change
 * the suites report red instead of dying.
 *
 * @package SignalNoiseTools
 */

require_once __DIR__ . '/os-leaf-harness.php';
require_once __DIR__ . '/whole-forms.php';

// sn_setting() answers from a flat path => value map, sn_note_reply_aliases()
// from a list the suite controls (the real list is inc/settings.php).
$GLOBALS['__settings'] = array();
$GLOBALS['__written']  = array();
$GLOBALS['__aliases']  = array( 'research', 'press', 'speaking', 'role', 'music' );
function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; }
function sn_setting_update( $key, $value ) { $GLOBALS['__written'][ $key ] = $value; return true; }
function sn_settings_snapshot() { return array(); }
function sn_settings_purge_if_changed( $before ) { return false; }
function sn_note_reply_aliases() { return $GLOBALS['__aliases']; }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); } }
function is_admin() { return false; } // the outbound-call module's load-time wiring asks.

require SNT_PATH . 'inc/admin-legacy-redirect.php';
require SNT_PATH . 'inc/admin-tabs.php';
require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/http-diagnostics.php';
require SNT_PATH . 'inc/ai-model-catalog.php';
require SNT_PATH . 'inc/admin-post-actions/theme-ai.php';
if ( is_file( SNT_PATH . 'inc/admin-post-actions/reading.php' ) ) { require SNT_PATH . 'inc/admin-post-actions/reading.php'; }
require SNT_PATH . 'inc/admin-post-actions/monitoring.php';
require SNT_PATH . 'inc/admin-forms/mcp-door-switches.php'; // the related-notes ranking field, in the Reading box
require SNT_PATH . 'inc/admin-forms/front-end.php';
require SNT_PATH . 'inc/admin-forms/performance.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-performance.php';
foreach ( array( 'inc/admin-forms/settings-front-end.php', 'apps/sn-dashboard/parts/leaves/settings-front-end.php' ) as $snt_fe_file ) {
	if ( is_file( SNT_PATH . $snt_fe_file ) ) { // Absent before the change: the assertions go red, the suite still reports.
		require_once SNT_PATH . $snt_fe_file;
	}
}

/** The classic pane, or '' before the change. */
function fe_classic() { return function_exists( 'sn_admin_render_settings_front_end_section' ) ? snt_leaf_classic_html( 'sn_admin_render_settings_front_end_section' ) : ''; }
/** The native pane, or '' before the change. */
function fe_kit() { return snt_leaf_paint( 'settings', 'front-end' ); }
/** The one form (or os-form) on a twin that posts $action, '' when none does. */
function fe_form( $html, $tag, $action ) {
	preg_match_all( '#<' . $tag . '\b.*?</' . $tag . '>#s', (string) $html, $m );
	foreach ( $m[0] as $form ) {
		if ( false !== strpos( $form, 'sn_' . $action ) ) {
			return $form;
		}
	}
	return '';
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
/** Every needle present in the haystack; the missing ones named. */
function has_all( $html, array $needles, &$missing ) { $missing = array(); foreach ( $needles as $n ) { if ( false === strpos( $html, $n ) ) { $missing[] = $n; } } return array() === $missing; }
