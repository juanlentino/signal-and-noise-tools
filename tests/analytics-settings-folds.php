<?php
/**
 * Tests for the settings-leaf prune (v9.45.0 design, §1-§4):
 *  - snt_an_settings_fold(): the native <details> writable-column wrapper.
 *  - Per-form snapshot builders: credentials / exclusion / tuning / funnels.
 *  - sn_analytics_pipeline_complete(): the shared completeness seam behind
 *    both the credentials fold's open state and the worker-setup conditional.
 *  - snt_analytics_render_worker_setup(): renders ONLY while incomplete.
 *  - snt_analytics_render_filter_reference(): the new one-line link, old
 *    inline per-filter list gone (moved to docs/FILTERS.md).
 * Hostile-value assertions use REAL esc_html (not a pass-through stub) so an
 * esc_html-removal mutation on the fold summary would be caught.
 *
 * Run: php tests/analytics-settings-folds.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// Real-behavior esc_html/esc_attr (not pass-throughs): the hostile-value
// asserts below can only kill an esc_html-removal mutation if the stub
// actually escapes.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function wp_nonce_field( $a = -1, $b = '_wpnonce', $c = true, $d = true ) { echo '<input type="hidden" name="_wpnonce" value="x">'; return ''; }
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function sn_mask_secret( $s ) { return '' === (string) $s ? '' : '••••'; }
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }

$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}

// ── Pipeline-completeness sources (the same seam
// snt_analytics_render_pipeline_status() reads) — controllable per stage so
// sn_analytics_pipeline_complete() can be driven to both true and false.
$GLOBALS['__beacon'] = 'beacon-token-x';
function sn_rss_tracker_token() { return (string) $GLOBALS['__beacon']; }
$GLOBALS['__worker'] = array( 'ok' => true, 'data' => array( 'version' => '1.11.0', 'config' => array( 'px_token_set' => true, 'ae_bound' => true ) ) );
function sn_worker_version_get( $force = false ) { return $GLOBALS['__worker']; }
$GLOBALS['__cfg'] = true;
function sn_analytics_config() { return $GLOBALS['__cfg'] ? array( 'account' => 'a', 'token' => 't' ) : null; }
$GLOBALS['__srv'] = 'srv-token-y';
function sn_analytics_refresh_secret() { return (string) $GLOBALS['__srv']; }
$GLOBALS['__zone'] = 'zoneABC';
function sn_cf_get_zone() { return (string) $GLOBALS['__zone']; }
function an_all_pipeline_ok() {
	$GLOBALS['__beacon'] = 'beacon-token-x';
	$GLOBALS['__worker'] = array( 'ok' => true, 'data' => array( 'version' => '1.11.0', 'config' => array( 'px_token_set' => true, 'ae_bound' => true ) ) );
	$GLOBALS['__cfg']    = true;
	$GLOBALS['__srv']    = 'srv-token-y';
	$GLOBALS['__zone']   = 'zoneABC';
}

require __DIR__ . '/../inc/analytics-render-settings.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function render( $fn ) { ob_start(); $fn(); return ob_get_clean(); }

echo "Group: sn_analytics_pipeline_complete(): the shared completeness seam\n";
an_all_pipeline_ok();
ok( true === sn_analytics_pipeline_complete(), 'complete: all five stages ok' );
$GLOBALS['__beacon'] = '';
ok( false === sn_analytics_pipeline_complete(), 'incomplete: a single missing stage (beacon) flips it' );
an_all_pipeline_ok();
$GLOBALS['__zone'] = '';
ok( false === sn_analytics_pipeline_complete(), 'incomplete: a single missing stage (zone) flips it' );
an_all_pipeline_ok();

echo "\nGroup: snt_an_settings_fold(): the <details> wrapper contract\n";
$h = render( function () {
	snt_an_settings_fold( 'My Title', 'My Snapshot', false, function () { echo '<p>INNER</p>'; } );
} );
ok( strpos( $h, '<details class="sn-an-form-fold">' ) !== false, 'closed fold: no open attribute' );
ok( strpos( $h, '<summary>' ) !== false, 'summary element present' );
ok( strpos( $h, 'My Title' ) !== false && strpos( $h, 'My Snapshot' ) !== false, 'summary carries both the title and the snapshot' );
ok( strpos( $h, '<p>INNER</p>' ) !== false, 'the render callback output is present inside the fold' );
ok( strpos( $h, '</details>' ) !== false, 'fold closes' );
ok( strpos( $h, '<summary>' ) < strpos( $h, '<p>INNER</p>' ), 'summary precedes the callback output' );

$h2 = render( function () {
	snt_an_settings_fold( 'T', 'S', true, function () { echo 'X'; } );
} );
ok( strpos( $h2, '<details class="sn-an-form-fold" open>' ) !== false, 'open fold: carries the open attribute' );

// Hostile title/snapshot — only the entity-escaped form may reach the markup
// (kills the drop-the-esc_html mutation the pass-through stub couldn't see).
$h3 = render( function () {
	snt_an_settings_fold( 'T"<b>', 'S"<i>', false, function () {} );
} );
ok( strpos( $h3, 'T&quot;&lt;b&gt;' ) !== false, 'hostile title renders entity-escaped' );
ok( strpos( $h3, 'S&quot;&lt;i&gt;' ) !== false, 'hostile snapshot renders entity-escaped' );
ok( strpos( $h3, 'T"<b>' ) === false && strpos( $h3, 'S"<i>' ) === false, 'raw hostile values never reach the markup' );

echo "\nGroup: credentials snapshot. Not configured / Configured / locked by wp-config\n";
$GLOBALS['__cfg'] = false;
ok( 'Not configured' === snt_an_credentials_snapshot(), 'unconfigured' );
$GLOBALS['__cfg'] = true;
ok( 'Configured' === snt_an_credentials_snapshot(), 'configured via options only' );
if ( ! defined( 'SN_CF_ACCOUNT_ID' ) ) { define( 'SN_CF_ACCOUNT_ID', 'abc123' ); }
ok( 'Configured: locked by wp-config' === snt_an_credentials_snapshot(), 'configured AND locked by a wp-config constant' );

echo "\nGroup: exclusion snapshot. Off / singular / plural\n";
$GLOBALS['__settings']['analytics.exclude_roles'] = array();
ok( 'Off' === snt_an_exclusion_snapshot(), 'no excluded roles' );
$GLOBALS['__settings']['analytics.exclude_roles'] = array( 'editor' );
ok( '1 role excluded' === snt_an_exclusion_snapshot(), 'singular (1 role)' );
$GLOBALS['__settings']['analytics.exclude_roles'] = array( 'editor', 'author' );
ok( '2 roles excluded' === snt_an_exclusion_snapshot(), 'plural (2 roles)' );

echo "\nGroup: tuning snapshot: baseline + preset label (reuses the radio labels)\n";
$GLOBALS['__settings']['analytics.signal_baseline_days'] = 30;
$GLOBALS['__settings']['analytics.anomaly_sensitivity']   = 'standard';
ok( '30-day baseline · Standard: designed default (≈3.5σ)' === snt_an_tuning_snapshot(), 'default snapshot' );
$GLOBALS['__settings']['analytics.signal_baseline_days'] = 60;
$GLOBALS['__settings']['analytics.anomaly_sensitivity']   = 'strict';
ok( '60-day baseline · Strict: only extremes (≈4.5σ)' === snt_an_tuning_snapshot(), 'stored preset (strict) reflected' );
$GLOBALS['__settings']['analytics.anomaly_sensitivity'] = 'garbage-preset';
ok( strpos( snt_an_tuning_snapshot(), 'Standard' ) !== false, 'unknown stored preset falls back to standard (engine parity)' );
$GLOBALS['__settings']['analytics.signal_baseline_days'] = 30;
$GLOBALS['__settings']['analytics.anomaly_sensitivity']   = 'standard';

echo "\nGroup: funnels snapshot. None / singular / plural\n";
$GLOBALS['__settings']['analytics.funnels'] = array();
ok( 'None' === snt_an_funnels_snapshot(), 'no funnels' );
$GLOBALS['__settings']['analytics.funnels'] = array( array( 'name' => 'a' ) );
ok( '1 funnel' === snt_an_funnels_snapshot(), 'singular (1 funnel)' );
$GLOBALS['__settings']['analytics.funnels'] = array( array( 'name' => 'a' ), array( 'name' => 'b' ) );
ok( '2 funnels' === snt_an_funnels_snapshot(), 'plural (2 funnels)' );
$GLOBALS['__settings']['analytics.funnels'] = array();

echo "\nGroup: credentials fold open-state (§2): the review-fold pin for both directions\n";
an_all_pipeline_ok();
ok( false === snt_an_credentials_fold_open(), 'complete pipeline: credentials fold starts CLOSED' );
$GLOBALS['__zone'] = '';
ok( true === snt_an_credentials_fold_open(), 'incomplete pipeline: credentials fold starts OPEN' );
an_all_pipeline_ok();

echo "\nGroup: worker-setup conditional (§3): present when incomplete, absent when complete\n";
an_all_pipeline_ok();
$h = render( 'snt_analytics_render_worker_setup' );
ok( '' === $h, 'complete pipeline: worker-setup renders nothing at all' );
$GLOBALS['__zone'] = '';
$h = render( 'snt_analytics_render_worker_setup' );
ok( strpos( $h, 'Cloudflare Worker setup' ) !== false, 'incomplete pipeline (missing zone): worker-setup renders' );
an_all_pipeline_ok();
$GLOBALS['__beacon'] = '';
$h = render( 'snt_analytics_render_worker_setup' );
ok( strpos( $h, 'Cloudflare Worker setup' ) !== false, 'incomplete pipeline (missing beacon): worker-setup renders' );
an_all_pipeline_ok();

echo "\nGroup: filter reference: the new link line (§4), old inline list gone\n";
$h = render( 'snt_analytics_render_filter_reference' );
ok( strpos( $h, 'docs/FILTERS.md' ) !== false, 'links to docs/FILTERS.md' );
ok( strpos( $h, 'href=' ) !== false, 'renders a real anchor' );
ok( stripos( $h, 'Developer filter seams' ) !== false, 'the link line label is present' );
ok( strpos( $h, '<details' ) === false, 'the old collapsible accordion is gone' );
ok( strpos( $h, 'sn_analytics_signal_config' ) === false, 'the old inline per-filter list is gone (moved to docs/FILTERS.md)' );
ok( strpos( $h, 'sn_analytics_narrator' ) === false, 'a second old inline filter entry is also gone' );
ok( strpos( $h, '<input' ) === false && strpos( $h, '<button' ) === false && strpos( $h, '<textarea' ) === false, 'filter reference stays read-only' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
