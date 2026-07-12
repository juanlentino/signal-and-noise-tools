<?php
/**
 * Unit tests for sn_analytics_signal_opts() — the settings→engine bridge
 * (settings hub, v9.36.0). Loads derived BEFORE signals (production loader
 * order) so constant collisions surface here (the v9.30.0 harness-isolation gap).
 *
 * Run: php tests/analytics-signal-opts.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); } }

// Controllable apply_filters stub: tests register a per-tag override callback.
$GLOBALS['__filters'] = array();
function apply_filters( $tag, $value, ...$args ) {
	if ( isset( $GLOBALS['__filters'][ $tag ] ) ) { return call_user_func( $GLOBALS['__filters'][ $tag ], $value ); }
	return $value;
}

require __DIR__ . '/../inc/analytics-derived.php';
require __DIR__ . '/../inc/analytics-signals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: harness-isolation fallback (sn_setting absent)\n";
$opts = sn_analytics_signal_opts();
ok( 30 === $opts['baseline_days'], 'absent sn_setting: baseline defaults to 30' );
ok( 3.5 === $opts['z'], 'absent sn_setting: z defaults to 3.5 (standard)' );

// Bind the settings stub AFTER the absence assertions (conditional declaration
// binds at runtime, so the calls above genuinely ran without sn_setting).
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $path, $default = null ) {
		return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
	}
}
$GLOBALS['__settings'] = array();

echo "Group: setting-backed values\n";
$GLOBALS['__settings'] = array( 'analytics.signal_baseline_days' => 60, 'analytics.anomaly_sensitivity' => 'standard' );
$opts = sn_analytics_signal_opts();
ok( 60 === $opts['baseline_days'], 'baseline setting 60 respected' );

$GLOBALS['__settings'] = array( 'analytics.signal_baseline_days' => 7 );
ok( 14 === sn_analytics_signal_opts()['baseline_days'], 'baseline 7 clamps up to the 14-day floor' );

$GLOBALS['__settings'] = array( 'analytics.signal_baseline_days' => 200 );
ok( 90 === sn_analytics_signal_opts()['baseline_days'], 'baseline 200 clamps down to 90' );

echo "Group: sensitivity preset map\n";
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'relaxed' );
ok( 2.5 === sn_analytics_signal_opts()['z'], 'relaxed → z 2.5' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'strict' );
ok( 4.5 === sn_analytics_signal_opts()['z'], 'strict → z 4.5' );
$GLOBALS['__settings'] = array( 'analytics.anomaly_sensitivity' => 'bogus' );
ok( 3.5 === sn_analytics_signal_opts()['z'], 'unknown preset falls back to standard 3.5' );

echo "Group: sn_analytics_signal_config filter\n";
$GLOBALS['__settings'] = array();
$GLOBALS['__filters']['sn_analytics_signal_config'] = static function ( $cfg ) {
	$cfg['baseline_days'] = 45; $cfg['z'] = 3.0; return $cfg;
};
$opts = sn_analytics_signal_opts();
ok( 45 === $opts['baseline_days'] && 3.0 === $opts['z'], 'filter can override both knobs' );

$GLOBALS['__filters']['sn_analytics_signal_config'] = static function ( $cfg ) {
	$cfg['baseline_days'] = 5; $cfg['z'] = 'garbage'; return $cfg;
};
$opts = sn_analytics_signal_opts();
ok( 14 === $opts['baseline_days'], 'post-filter re-clamp: baseline 5 → 14' );
ok( 0.5 === $opts['z'], 'post-filter re-clamp: junk z → 0.5 floor' );
$GLOBALS['__filters']['sn_analytics_signal_config'] = static function ( $cfg ) {
	$cfg['z'] = 42; return $cfg;
};
ok( 10.0 === sn_analytics_signal_opts()['z'], 'post-filter re-clamp: z 42 → 10.0 ceiling' );
$GLOBALS['__filters']['sn_analytics_signal_config'] = static function ( $cfg ) {
	return 'not-an-array';
};
$opts = sn_analytics_signal_opts();
ok( 30 === $opts['baseline_days'] && 3.5 === $opts['z'], 'filter returning a scalar collapses to settings-derived defaults' );
unset( $GLOBALS['__filters']['sn_analytics_signal_config'] );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
