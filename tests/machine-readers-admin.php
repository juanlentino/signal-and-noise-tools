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
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $format, $timestamp = null, $timezone = null ) { return gmdate( $format, null === $timestamp ? time() : (int) $timestamp ); } } // 2026-10-12: times print in the site's format
// Storage-side URL sanitizer stub (lane 4): valid http(s) URL passes, else ''.
function esc_url_raw( $url ) { $url = trim( (string) $url ); return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }

// v10.2.2 composition group: enough WP surface to render the whole tab.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
// Real _n() shape: returns (never echoes) and selects on the count.
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function current_user_can( $cap ) { return true; }
function wp_nonce_field( $action ) { echo '<!-- nonce -->'; }
// PR #2025 review: the classic Re-read now link (a nonce-gated flag).
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u . '&' . $n . '=nonce-' . $a; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $q, $u = '' ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $q ); } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'nonce-' . $a === $n ? 1 : false; } }
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

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require_once __DIR__ . '/lib/admin-post-url-stub.php';
require_once __DIR__ . '/lib/wp-admin-notice-stub.php'; // 17.4.4: every classic notice is wp_admin_notice() (#1618).
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-compose.php'; // v12.22.0: snt_mr_render_tab() delegates the arrangement to the pure composer.
// The stylesheet registrar the S&N Dashboard host window calls: the desktop
// page carries none of the hook suffixes sn_admin_page_hooks() names, so the
// enqueue below never fires there and the leaf painted unstyled until the
// registration was split out of it.
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '13.104.0' ); }
$GLOBALS['__mr_registered'] = array();
$GLOBALS['__mr_enqueued']   = array();
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( (string) $path, '/' ); }
function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__mr_registered'][ $handle ] = array( $src, $deps, $ver ); return true; }
function wp_style_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__mr_registered'][ $handle ] ); }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__mr_enqueued'][] = $handle; return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__mr_hooks'][ $hook ][] = $cb; return true; }
function sn_admin_page_hooks() { return array( 'toplevel_page_sn-theme-options' ); }

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

echo "\nGroup: v10.2.3 — the tab IS the Analytics leaf silhouette (owner UAT, third pass)\n";
ob_start();
snt_mr_render_tab();
$tab = ob_get_clean();
// Configuration only since the evidence-basis arc: the readings are S&N
// Analytics views (tests/analytics-machine-views.php pins each moved figure).
ok( false !== strpos( $tab, 'sn-an-settings-leaf' ), 'whole tab wrapped in the Analytics leaf class' );
ok( false !== strpos( $tab, '<div class="sn-fieldset sn-fieldset--wide sn-an-pipeline">' ), 'the sensor status hero runs full width' );
ok( 1 === substr_count( $tab, 'sn-fieldset--wide' ), 'exactly one width modifier: the hero' );
ok( false !== strpos( $tab, 'Machine readers in S&amp;N Analytics' ) || false !== strpos( $tab, 'Machine readers in S&N Analytics' ), 'a visible-text link to Machine readers in S&N Analytics' );
ok( false !== strpos( $tab, 'Reader evidence in S&amp;N Analytics' ) || false !== strpos( $tab, 'Reader evidence in S&N Analytics' ), 'a visible-text link to Reader evidence in S&N Analytics' );
ok( false !== strpos( $tab, 'sn_mr_worker_url' ) && false !== strpos( $tab, 'sn_machine_readers_save' ), 'the worker URL form posts machine_readers_save, unchanged' );
ok( false === strpos( $tab, 'sn-disclosure' ) && false === strpos( $tab, '<details' ), 'nothing folded: the settings form is open' );
foreach ( array( 'sn-kpi-row', 'Reads per crawler family', 'Rights-surface reads, in full', 'Unclassified user agents', 'Feed fetches per window', 'Observed vs declared' ) as $sn_gone ) {
	ok( false === strpos( $tab, $sn_gone ), "no reading left on the tab ($sn_gone)" );
}
ok( false !== strpos( $tab, 'notice notice-info notice-alt inline' ), 'Edge sensor readout is the Analytics notice-info treatment' );
ok( strpos( $tab, 'sn-fieldset--wide sn-an-pipeline"' ) < strpos( $tab, 'Edge sensor' ) && strpos( $tab, 'Edge sensor' ) < strpos( $tab, 'Sensor settings' ), 'hero first, then the readout beside the settings' );

echo "\nGroup: v10.2.6 — zebra tables carry gutters from the CENTRAL sheet\n";
$sn_an_css = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
$sn_mr_css = (string) file_get_contents( __DIR__ . '/../assets/machine-readers.css' );
ok( 1 === preg_match( '/\.sn-an-table\.widefat th,\s*\.sn-an-table\.widefat td \{ padding: 8px 12px; \}/', $sn_an_css ), 'central sheet gives striped sn-an-tables the dashboard cell gutters (the glued-text fix, plugin-wide)' );
ok( false !== strpos( $sn_an_css, '.sn-an-table.widefat td:last-child { padding-right: 12px; }' ), 'the trailing number column keeps a right gutter (not glued to the card border)' );
ok( false === strpos( $sn_mr_css, '6px 10px 6px 0' ), 'machine-readers.css no longer carries its own flush cell override (superseded by the central rule)' );
// The fold rules (MR4) and the reading layout's KPI rules left with the readings
// (S&N Analytics views since the evidence-basis arc): none may come back.
ok( false === strpos( $sn_mr_css, 'sn-disclosure' ) && false === strpos( $sn_mr_css, 'sn-mr-reference' ) && false === strpos( $sn_mr_css, 'sn-mr-fold' ), 'machine-readers.css carries no fold rule: the leaf has no folds' );
// Non-vacuity: the CSS file must actually parse as rules, not be truncated
// mid-comment (the v10.83.0 comment-delimiter lesson).
ok( substr_count( $sn_mr_css, '/*' ) === substr_count( $sn_mr_css, '*/' ), 'machine-readers.css comment delimiters balance (no orphaned block killing the rules below it)' );

echo "\nGroup: the stylesheet registrar the host window calls\n";
snt_mr_admin_register_style();
ok( isset( $GLOBALS['__mr_registered']['sn-machine-readers'] ), 'snt_mr_admin_register_style() registers the handle by name -- the host window enqueues nothing it cannot register first' );
ok( array( 'sn-admin' ) === ( $GLOBALS['__mr_registered']['sn-machine-readers'][1] ?? array() )
	&& false !== strpos( (string) $GLOBALS['__mr_registered']['sn-machine-readers'][0], 'assets/machine-readers.css' ),
	'   ...with the SAME source and the SAME dependency on admin.css the enqueue used: one path, one dep list, one place' );
$mr_registrations = count( $GLOBALS['__mr_registered'] );
snt_mr_admin_register_style();
ok( $mr_registrations === count( $GLOBALS['__mr_registered'] ), '   ...and it is idempotent: a second call registers nothing twice' );
$GLOBALS['__mr_registered'] = array();
$GLOBALS['__mr_enqueued']   = array();
snt_mr_admin_enqueue( 'toplevel_page_sn-theme-options' );
ok( in_array( 'sn-machine-readers', $GLOBALS['__mr_enqueued'], true ) && isset( $GLOBALS['__mr_registered']['sn-machine-readers'] ),
	'the classic page still ENQUEUES it, and does it THROUGH the registrar -- an inline wp_enqueue_style() with its own src would work here and leave the window with nothing to enqueue' );
$GLOBALS['__mr_enqueued'] = array();
snt_mr_admin_enqueue( 'edit.php' );
ok( array() === $GLOBALS['__mr_enqueued'], '   ...and still not on a foreign screen' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
