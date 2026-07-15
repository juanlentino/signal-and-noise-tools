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
// Real-behavior esc_html (not a pass-through): the hostile-value asserts below
// can only kill an esc_html-removal mutation if the stub actually escapes.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
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

// Zone ID getter stub: mirrors the real sn_cf_get_zone() precedence
// (inc/cloudflare-purge.php) — constant wins over the option — so the render
// function under test is exercised the same way the real accessor behaves.
$GLOBALS['__cf_zone'] = 'zoneABC123';
function sn_cf_get_zone() {
	if ( defined( 'SN_CLOUDFLARE_ZONE_ID' ) && '' !== (string) SN_CLOUDFLARE_ZONE_ID ) {
		return (string) SN_CLOUDFLARE_ZONE_ID;
	}
	return (string) $GLOBALS['__cf_zone'];
}

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

// Zone ID row: value present (option-configured, not locked).
ok( strpos( $h, 'Zone ID' ) !== false, 'zone row label shown' );
ok( strpos( $h, '<code>zoneABC123</code>' ) !== false, 'zone value shown when the option is set' );
ok( strpos( $h, 'tab=connections&sub=cloudflare' ) !== false, 'zone row links to Connections → Cloudflare' );
ok( strpos( $h, 'Locked by the' ) === false, 'zone row carries no locked note when only the option is set' );

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

// Zone ID: not-set state — neither the constant nor the option is configured.
$GLOBALS['__cf_zone'] = '';
ob_start(); snt_analytics_render_mirrors(); $h5 = ob_get_clean();
ok( strpos( $h5, 'Not set' ) !== false, 'zone row shows a not-set state when unconfigured' );
ok( strpos( $h5, 'Locked by the' ) === false, 'zone row carries no locked note when unset' );

// Zone ID: hostile value — only the entity-escaped form may reach the markup
// (kills the drop-the-esc_html mutation the pass-through stub couldn't see).
$GLOBALS['__cf_zone'] = 'z"><b';
ob_start(); snt_analytics_render_mirrors(); $h7 = ob_get_clean();
ok( strpos( $h7, 'z&quot;&gt;&lt;b' ) !== false, 'hostile zone value renders entity-escaped' );
ok( strpos( $h7, 'z"><b' ) === false, 'raw hostile zone value never reaches the markup' );

// Zone ID: constant-locked state — SN_CLOUDFLARE_ZONE_ID wins over the option.
define( 'SN_CLOUDFLARE_ZONE_ID', 'locked-zone-999' );
ob_start(); snt_analytics_render_mirrors(); $h6 = ob_get_clean();
ok( strpos( $h6, '<code>locked-zone-999</code>' ) !== false, 'zone row shows the constant value when locked' );
ok( strpos( $h6, 'Locked by the' ) !== false && strpos( $h6, 'SN_CLOUDFLARE_ZONE_ID' ) !== false, 'zone row names the locking constant' );
ok( strpos( $h6, '<input' ) === false && strpos( $h6, '<select' ) === false && strpos( $h6, '<button' ) === false && strpos( $h6, '<textarea' ) === false, 'MIRROR RULE: locked zone row still has no write controls' );

// v9.45.0 (§4): the inline per-filter accordion moved to docs/FILTERS.md —
// the leaf now renders one deep link instead (parity with the real filter
// seams is covered separately by tests/analytics-filter-reference-parity.php).
ob_start();
snt_analytics_render_filter_reference();
$h3 = ob_get_clean();
ok( strpos( $h3, 'docs/FILTERS.md' ) !== false, 'filter reference links to docs/FILTERS.md' );
ok( strpos( $h3, '<details' ) === false, 'the old collapsed-details accordion is gone' );
ok( strpos( $h3, 'sn_analytics_signal_config' ) === false, 'the old inline per-filter list is gone' );
ok( strpos( $h3, '<input' ) === false && strpos( $h3, '<button' ) === false && strpos( $h3, '<textarea' ) === false, 'filter reference is read-only too' );

// i18n: this suite's __()/esc_html__() stubs are pass-throughs (no domain
// recording), so verify the new zone-row strings are text-domain-wrapped at
// the source instead (source-contract needles).
$src = file_get_contents( __DIR__ . '/../inc/analytics-render-settings.php' );
ok( strpos( $src, "esc_html__( 'Zone ID', 'signal-and-noise-tools' )" ) !== false, 'i18n: zone row label wrapped with text domain' );
ok( strpos( $src, "esc_html__( 'Not set', 'signal-and-noise-tools' )" ) !== false, 'i18n: not-set state string wrapped with text domain' );
ok( strpos( $src, "'Connections → Cloudflare →', 'signal-and-noise-tools'" ) !== false, 'i18n: zone deep-link label wrapped with text domain' );
ok( strpos( $src, "'Also gates cache purge and the Edge view.', 'signal-and-noise-tools'" ) !== false, 'i18n: zone caption wrapped with text domain' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
