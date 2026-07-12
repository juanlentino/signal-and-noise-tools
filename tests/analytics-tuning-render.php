<?php
/**
 * Render tests for snt_analytics_render_engine_tuning() — the two settings-hub
 * engine knobs (v9.36.0): baseline window (14–90) + anomaly-sensitivity preset.
 *
 * Run: php tests/analytics-tuning-render.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { echo '<input type="hidden" name="_wpnonce" value="x">'; return ''; }
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}

require __DIR__ . '/../inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ob_start();
snt_analytics_render_engine_tuning();
$h = ob_get_clean();

ok( strpos( $h, 'name="sn_signal_baseline_days"' ) !== false, 'baseline number input present' );
ok( strpos( $h, 'value="30"' ) !== false, 'baseline shows the 30-day default' );
ok( strpos( $h, 'min="14"' ) !== false && strpos( $h, 'max="90"' ) !== false, 'client hints match the server clamp' );
ok( substr_count( $h, 'name="sn_anomaly_sensitivity"' ) === 3, 'three sensitivity radios' );
ok( preg_match( '/value="standard"\s+checked/', $h ) === 1, 'standard preset checked by default' );
ok( strpos( $h, 'value="analytics_tuning_save"' ) !== false, 'submit posts analytics_tuning_save' );
ok( strpos( $h, 'name="_wpnonce"' ) !== false, 'nonce present' );
ok( strpos( $h, 'class="sn-an-radio"' ) !== false, 'radio labels use the stylesheet class (no inline styles)' );
ok( strpos( $h, 'style=' ) === false, 'no inline style attributes' );

$GLOBALS['__settings'] = array( 'analytics.signal_baseline_days' => 60, 'analytics.anomaly_sensitivity' => 'strict' );
ob_start();
snt_analytics_render_engine_tuning();
$h = ob_get_clean();
ok( strpos( $h, 'value="60"' ) !== false, 'stored baseline (60) shown' );
ok( preg_match( '/value="strict"\s+checked/', $h ) === 1, 'stored preset (strict) checked' );
ok( preg_match( '/value="standard"\s+checked/', $h ) === 0, 'standard no longer checked' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
