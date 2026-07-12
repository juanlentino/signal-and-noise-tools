<?php
/**
 * Render tests for snt_analytics_render_pipeline_status() — the settings-hub
 * presence strip (v9.36.0). Five pills in data-flow order (beacon → worker →
 * read → cron → edge); ok/warn/unknown states; secrets never echoed; the
 * SN_SRV_TOKEN warn names the silent-cron consequence.
 *
 * Run: php tests/analytics-pipeline-status-render.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { echo '<input type="hidden" name="_wpnonce" value="x">'; return ''; }
function checked( $a, $b = true, $echo = true ) { return ''; }
function sn_mask_secret( $s ) { return '••••'; }
function sn_setting( $path, $default = null ) { return $default; }
function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; }
$GLOBALS['__opts'] = array();

// ── Controllable pill sources ── (secret values are deliberately distinctive
// strings that appear in NO UI copy, so the "never echoed" asserts can't
// false-negative on a substring like 'tok' inside the word "token")
$GLOBALS['__beacon'] = 'beacon-sekrit-77x';
function sn_rss_tracker_token() { return (string) $GLOBALS['__beacon']; }
$GLOBALS['__worker'] = array( 'ok' => true, 'data' => array( 'version' => '1.11.0', 'config' => array( 'px_token_set' => true, 'ae_bound' => true ) ) );
function sn_worker_version_get( $force = false ) { return $GLOBALS['__worker']; }
$GLOBALS['__cfg'] = true;
function sn_analytics_config() { return $GLOBALS['__cfg'] ? array( 'account' => 'a', 'token' => 't' ) : null; }
$GLOBALS['__srv'] = 'srv-sekrit-99y';
function sn_analytics_refresh_secret() { return (string) $GLOBALS['__srv']; }
define( 'SN_CF_ZONE_OPT', 'sn_cf_zone_id' );

require __DIR__ . '/../inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function render() { ob_start(); snt_analytics_render_pipeline_status(); return ob_get_clean(); }

echo "Group: all-green pipeline\n";
$GLOBALS['__opts']['sn_cf_zone_id'] = 'z1';
$h = render();
ok( substr_count( $h, 'sn-an-pill--ok' ) === 5, 'five ok pills when everything is configured' );
ok( strpos( $h, 'Worker v1.11.0' ) !== false, 'worker pill shows the probed version' );
ok( strpos( $h, 'beacon-sekrit-77x' ) === false && strpos( $h, 'srv-sekrit-99y' ) === false, 'no secret value is ever echoed' );

echo "Group: SN_SRV_TOKEN missing (the invisible-cron failure)\n";
$GLOBALS['__srv'] = '';
$h = render();
ok( strpos( $h, 'sn-an-pill--warn' ) !== false, 'missing server token renders a warn pill' );
ok( strpos( $h, 'SN_SRV_TOKEN' ) !== false, 'warn names the constant' );
ok( strpos( $h, 'cron refresh' ) !== false, 'warn names the disabled */15 cron consequence' );
$GLOBALS['__srv'] = 'srv-sekrit-99y';

echo "Group: worker unreachable → unknown, never an error box\n";
$GLOBALS['__worker'] = array( 'ok' => false, 'data' => array(), 'error' => 'network' );
$h = render();
ok( strpos( $h, 'sn-an-pill--unknown' ) !== false, 'probe failure renders an unknown pill' );
ok( strpos( $h, 'notice-error' ) === false, 'no error notice markup' );
$GLOBALS['__worker'] = array( 'ok' => true, 'data' => array( 'version' => '1.11.0', 'config' => array( 'px_token_set' => false, 'ae_bound' => true ) ) );
$h = render();
ok( strpos( $h, 'SN_PX_TOKEN' ) !== false, 'reachable worker with unset px token warns naming the binding' );

echo "Group: zone + beacon + creds warn states\n";
unset( $GLOBALS['__opts']['sn_cf_zone_id'] );
$h = render();
ok( strpos( $h, 'Edge view' ) !== false, 'missing zone warn mentions the dormant Edge view' );
$GLOBALS['__beacon'] = '';
$GLOBALS['__cfg']    = false;
$h = render();
ok( strpos( $h, 'SN_BEACON_TOKEN' ) !== false, 'missing beacon token warn names the constant' );
ok( substr_count( $h, 'sn-an-pill--warn' ) >= 4, 'beacon+read+cron... every broken link warns' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
