<?php
/**
 * Render tests for snt_analytics_render_mirrors() + snt_analytics_render_filter_reference()
 * — the settings-hub read-only mirrors (v9.36.0). Hard rule under test: NO input
 * elements (a mirror never gets a write control); every row deep-links to its
 * real home.
 *
 * Run: php tests/analytics-mirrors-render.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { return ''; }
function checked( $a, $b = true, $echo = true ) { return ''; }

$GLOBALS['__settings'] = array( 'theme.ai_model' => 'claude-sonnet-5', 'theme.ai_monthly_budget' => 10 );
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}
function sn_theme_ai_models() { return array( 'claude-sonnet-5' => 'Claude Sonnet 5 (balanced, default)' ); }
function snt_ai_spend_this_month() { return isset( $GLOBALS['__spend'] ) ? (float) $GLOBALS['__spend'] : 4.2; }
function snt_insights_weekly_cron_enabled() { return true; }
function sn_rss_tracker_settings() { return array( 'collector_url' => 'https://example.com/_sn/px' ); }

require __DIR__ . '/../inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ob_start();
snt_analytics_render_mirrors();
$h = ob_get_clean();

ok( strpos( $h, 'Claude Sonnet 5' ) !== false, 'AI model label shown' );
ok( strpos( $h, '4.20' ) !== false && strpos( $h, '10.00' ) !== false, 'spend + budget shown' );
ok( strpos( $h, 'tab=content&sub=front-end' ) !== false, 'AI row links to Content → Front-End' );
ok( strpos( $h, 'tab=monitoring&sub=insights' ) !== false, 'cron row links to Monitoring → Insights' );
ok( strpos( $h, 'tab=content&sub=rss' ) !== false, 'collector row links to Content → RSS' );
ok( strpos( $h, 'https://example.com/_sn/px' ) !== false, 'collector URL shown' );
ok( strpos( $h, '<input' ) === false && strpos( $h, '<select' ) === false && strpos( $h, '<button' ) === false && strpos( $h, '<textarea' ) === false, 'MIRROR RULE: no write controls of any kind' );
ok( strpos( $h, 'sn-an-mirror-meter' ) !== false, 'budget meter rendered when a cap is set' );
ok( strpos( $h, 'width:42%' ) !== false, 'meter width reflects 4.2/10 spend' );

// No budget cap → no meter, "no cap" copy instead.
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 0;
ob_start(); snt_analytics_render_mirrors(); $h2 = ob_get_clean();
ok( strpos( $h2, 'sn-an-mirror-meter' ) === false, 'no meter without a budget cap' );
ok( stripos( $h2, 'no monthly budget' ) !== false, 'uncapped copy shown' );

// Over budget: label tells the truth, meter clamps.
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 10;
$GLOBALS['__spend'] = 15.0;
ob_start(); snt_analytics_render_mirrors(); $h4 = ob_get_clean();
ok( strpos( $h4, '(150%)' ) !== false, 'over-budget label shows the true 150%' );
ok( strpos( $h4, 'width:100%' ) !== false, 'meter width clamps to 100%' );

ob_start();
snt_analytics_render_filter_reference();
$h3 = ob_get_clean();
ok( strpos( $h3, '<details' ) !== false, 'filter reference is a collapsed details' );
ok( strpos( $h3, 'sn_analytics_signal_config' ) !== false, 'new signal-config filter documented' );
ok( strpos( $h3, 'sn_analytics_session_config' ) !== false, 'session config filter documented' );
ok( strpos( $h3, 'sn_analytics_refresh_secret' ) !== false, 'refresh-secret filter documented' );
ok( strpos( $h3, 'sn_beacon_token' ) !== false, 'beacon-token filter documented' );
ok( strpos( $h3, '<input' ) === false && strpos( $h3, '<button' ) === false && strpos( $h3, '<textarea' ) === false, 'filter reference is read-only too' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
