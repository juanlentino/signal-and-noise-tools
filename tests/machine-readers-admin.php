<?php
/**
 * Tests: Machine Readers admin registration + settings save (Session 3 lane 4).
 * SCAFFOLD-RED: written against the shells on purpose; lane 4 turns it green.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__opts'] = array( 'sn_machine_readers_preview' => '1' );
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
// Storage-side URL sanitizer stub (lane 4): valid http(s) URL passes, else ''.
function esc_url_raw( $url ) { $url = trim( (string) $url ); return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }

// v10.2.2 composition group: enough WP surface to render the whole tab.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function current_user_can( $cap ) { return true; }
function wp_nonce_field( $action ) { echo '<!-- nonce -->'; }
function sn_setting( $k, $d = '' ) { return $d; }
$GLOBALS['__mr_rows'] = array(
	array( 'family' => 'openai', 'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'uptime', 'surface' => 'html',   'day' => '2026-07-28', 'hits' => 40 ),
);
$GLOBALS['__mr_feed'] = array( 'most_recent' => '2026-07-28 12:00:00', 'windows' => array( 7 => array( 'total' => 42, 'uniques' => 9 ), 30 => array( 'total' => 946, 'uniques' => 30 ) ) );
function snt_mr_fetch( $days ) { return array( 'ok' => true, 'rows' => $GLOBALS['__mr_rows'] ); }
function snt_mr_sensor_info() { return array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T18:07:56Z' ); }
function snt_mr_crawler_list_status() { return array( 'last_check_ok' => '1', 'last_check_drift' => '' ); }
function sn_rss_tracker_window_stats_multi( $windows ) { return $GLOBALS['__mr_feed']; }

require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-admin.php';

echo "Group: registry callback (preview-flag gated, v9.67.0 Overview pattern)\n";
$tabs_in = array( 'analytics' => array( 'label' => 'Analytics' ), 'tools' => array( 'label' => 'Tools' ) );
$tabs = snt_mr_admin_register( $tabs_in );
ok( false !== strpos( json_encode( $tabs ), 'machine-readers' ), 'flag ON: registry gains the machine-readers entry' );
ok( isset( $tabs['analytics'], $tabs['tools'] ), 'existing entries preserved' );
// Integration pin: the registry's declared render entrypoint must actually be
// defined in this module, or the dispatcher's is_callable guard renders a
// SILENTLY blank tab (no fatal, no error) when the preview flag is on.
ok( 'snt_mr_render_tab' === ( $tabs['machine-readers']['render'] ?? '' ) && function_exists( 'snt_mr_render_tab' ), 'registry render entrypoint snt_mr_render_tab exists (no silently blank tab)' );
$GLOBALS['__opts']['sn_machine_readers_preview'] = '';
$tabs = snt_mr_admin_register( $tabs_in );
ok( false !== strpos( json_encode( $tabs ), 'machine-readers' ), 'v10.0.0 GA: the tab no longer depends on the preview option (retired; its row is deleted by the orphan migration)' );
$GLOBALS['__opts']['sn_machine_readers_preview'] = '1';

echo "\nGroup: settings save — subtree preservation (the 4x-bitten class) + write-only token\n";
$settings_in = array(
	'analytics'       => array( 'exclude_roles' => array( 'editor' ) ),
	'seo'             => array( 'copy' => 'x' ),
	'machine_readers' => array( 'worker_url' => 'https://old.example/mr', 'read_token' => 'old-secret' ),
);
$snapshot = json_encode( $settings_in );
$out = snt_mr_settings_save( array( 'worker_url' => 'https://juanlentino.com/_sn/rights-signals/machine-readers', 'read_token' => 'new-secret' ), $settings_in );
ok( ( $out['machine_readers']['worker_url'] ?? '' ) === 'https://juanlentino.com/_sn/rights-signals/machine-readers', 'worker_url saved under the machine_readers subtree' );
ok( ( $out['machine_readers']['read_token'] ?? '' ) === 'new-secret', 'token saved when provided' );
ok( ( $out['analytics']['exclude_roles'][0] ?? '' ) === 'editor' && ( $out['seo']['copy'] ?? '' ) === 'x', 'every foreign subtree preserved' );
ok( json_encode( $settings_in ) === $snapshot, 'input settings array not mutated (immutability)' );
$out2 = snt_mr_settings_save( array( 'worker_url' => 'https://juanlentino.com/_sn/rights-signals/machine-readers', 'read_token' => '' ), $settings_in );
ok( ( $out2['machine_readers']['read_token'] ?? '' ) === 'old-secret', 'blank token field keeps the stored token (write-only semantics)' );

echo "\nGroup: v10.2.2 — the tab composes as two aligned zones (the width-zigzag fix)\n";
ob_start();
snt_mr_render_tab();
$tab = ob_get_clean();
// The two secondary tables share one two-column row instead of stacking as
// 820px-capped orphans under the full-width family/surface pair.
$sn_pair = '<div class="sn-2up sn-mr-grid"><div class="sn-fieldset">' . snt_mr_render_compliance( $GLOBALS['__mr_rows'] ) . '</div>'
	. '<div class="sn-fieldset">' . snt_mr_render_feed_table( $GLOBALS['__mr_feed'] ) . '</div></div>';
ok( false !== strpos( $tab, $sn_pair ), 'observed-vs-declared and feed fetches share one .sn-2up row' );
ok( 2 === substr_count( $tab, '"sn-2up sn-mr-grid"' ), 'the data zone is exactly two aligned table rows' );
// The sensor zone is the Analytics settings-leaf shape wholesale.
ok( false !== strpos( $tab, 'sn-an-settings-leaf' ), 'sensor zone wrapped in the Analytics leaf class (token-card scoping applies)' );
ok( false !== strpos( $tab, 'sn-fieldset sn-fieldset--wide sn-an-pipeline' ), 'sensor status is the full-width Analytics pipeline hero, not an 820px orphan' );
preg_match( '/sn-an-settings-leaf(.*)$/s', $tab, $sn_leaf );
ok( isset( $sn_leaf[1] ) && 2 === substr_count( $sn_leaf[1], '<div class="sn-fieldset">' ), 'settings row: BOTH columns are fieldset cards (no floating fold beside a corner card)' );
// The strip carries the feed chip from the same tracker read the table uses.
ok( 2 === substr_count( $tab, '946' ), 'the 30d feed total reaches the strip chip AND the feed table (one tracker read)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
