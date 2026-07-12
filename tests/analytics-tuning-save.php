<?php
/**
 * Handler tests for sn_handle_analytics_tuning_save() + its dispatch-map entry
 * (settings hub, v9.36.0). Clamps [14,90], preset whitelist, unchanged
 * detection, flash codes.
 *
 * Run: php tests/analytics-tuning-save.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['__options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['__options'][ $n ] ); return true; }
function get_bloginfo( $w ) { return ''; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }

// sn_setting store backed by a plain map (the real sparse-write semantics are
// covered by tests/settings-save-preserves-subtrees.php; here we test the handler).
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}
function sn_setting_update( $path, $value ) { $GLOBALS['__settings'][ $path ] = $value; return true; }

require __DIR__ . '/../inc/admin-post-actions.php';
require __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: dispatch map\n";
$map = sn_admin_post_handlers();
ok( isset( $map['analytics_tuning_save'] ) && 'sn_handle_analytics_tuning_save' === $map['analytics_tuning_save'], 'analytics_tuning_save routed to its handler' );

echo "Group: happy path\n";
$flash = sn_handle_analytics_tuning_save( array( 'sn_signal_baseline_days' => '45', 'sn_anomaly_sensitivity' => 'strict' ) );
ok( 'analytics_tuning_saved' === $flash, 'valid save returns saved flash' );
ok( 45 === $GLOBALS['__settings']['analytics.signal_baseline_days'], 'baseline stored as int 45' );
ok( 'strict' === $GLOBALS['__settings']['analytics.anomaly_sensitivity'], 'preset stored' );

echo "Group: clamps + whitelist\n";
sn_handle_analytics_tuning_save( array( 'sn_signal_baseline_days' => '13', 'sn_anomaly_sensitivity' => 'relaxed' ) );
ok( 14 === $GLOBALS['__settings']['analytics.signal_baseline_days'], '13 clamps up to 14' );
sn_handle_analytics_tuning_save( array( 'sn_signal_baseline_days' => '91', 'sn_anomaly_sensitivity' => 'relaxed' ) );
ok( 90 === $GLOBALS['__settings']['analytics.signal_baseline_days'], '91 clamps down to 90' );
sn_handle_analytics_tuning_save( array( 'sn_signal_baseline_days' => 'abc', 'sn_anomaly_sensitivity' => 'dr-evil' ) );
ok( 14 === $GLOBALS['__settings']['analytics.signal_baseline_days'], 'junk baseline → (int)0 → clamped to 14' );
ok( 'standard' === $GLOBALS['__settings']['analytics.anomaly_sensitivity'], 'unknown preset → standard' );

echo "Group: absent fields (defaults, never notices)\n";
$GLOBALS['__settings'] = array();
$flash = sn_handle_analytics_tuning_save( array() );
ok( 30 === ( $GLOBALS['__settings']['analytics.signal_baseline_days'] ?? null ) || 'analytics_tuning_unchanged' === $flash, 'empty POST defaults to 30/standard or reports unchanged' );

echo "Group: unchanged detection\n";
$GLOBALS['__settings'] = array( 'analytics.signal_baseline_days' => 30, 'analytics.anomaly_sensitivity' => 'standard' );
$flash = sn_handle_analytics_tuning_save( array( 'sn_signal_baseline_days' => '30', 'sn_anomaly_sensitivity' => 'standard' ) );
ok( 'analytics_tuning_unchanged' === $flash, 'identical values return unchanged flash' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
