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
ok( false === strpos( json_encode( $tabs ), 'machine-readers' ), 'flag OFF: registry unchanged (GA flip is v10.0.0\'s)' );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
