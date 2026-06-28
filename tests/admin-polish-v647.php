<?php
/**
 * Standalone test: admin polish — audit-fix batch (v6.47.0).
 *
 * Locks the verified findings from the post-open-wide admin-surface audit:
 *   a11y    — #1 sub-tab focus ring, #7 AA delta colour, #18 deploy-status SR text,
 *             #19 url-preview focus ring
 *   layout  — #2 Security audit-log goes wide (+ carded Maintenance), #8 Health
 *             findings uncap, #14 prepop notice scoped to the meta box
 *   converge— #4 RSS + #10 audit-log heroes render through sn_admin_glance_grid
 *             (the bespoke .sn-rss-activity-card / .sn-audit-state-grid vocab gone)
 *   hygiene — #3 dead .sn-state-card vocabulary removed (shipped in v6.46.1 #104)
 *
 * Run: php tests/admin-polish-v647.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ap_ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// ── WP stubs (declared once, shared across the loaded modules) ──
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return (string) $n; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input name="_wpnonce">'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = '', $n = '' ) { return $u; } }
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $k, $d = '' ) { return $d; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }

require_once __DIR__ . '/../inc/admin-glance.php'; // sn_admin_glance_grid

// ── Group: CSS locks ───────────────────────────────────────────────────────
echo "Group: CSS locks (a11y + consistency)\n";
$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );
ap_ok( false !== strpos( $css, '.sn-sub-tab:focus-visible' ), '#1 cross-page sub-tab nav has a focus-visible ring' );
$gd      = strpos( $css, '.sn-glance-delta--up' );
$gd_line = false !== $gd ? substr( $css, $gd, 40 ) : '';
ap_ok( false !== strpos( $gd_line, '#0a7c2f' ), '#7 glance delta up uses the AA-passing #0a7c2f (not var(--sn-ok))' );
ap_ok( false !== strpos( $css, '.sn-health-findings .sn-fieldset' ), '#8 Health findings carry a scoped full-width uncap' );
ap_ok( false !== strpos( $css, '#sn_post_settings .sn-prepop-notice' ), '#14 prepop notice is scoped to the meta box' );
ap_ok( false !== strpos( $css, '.sn-url-preview:focus-visible' ), '#19 url-preview link has a focus-visible ring' );
ap_ok( false === strpos( $css, 'sn-state-card' ), '#3 dead .sn-state-card vocabulary is gone (v6.46.1 #104)' );

// ── Group: registry wide flags (#2) ────────────────────────────────────────
echo "\nGroup: registry — Security audit-log is wide; login/login-defense capped\n";
require_once __DIR__ . '/../inc/admin-tabs-data.php';
$sec = null;
foreach ( sn_admin_top_tabs() as $t ) {
	if ( ( $t['tab'] ?? '' ) === 'security' ) { $sec = $t; break; }
}
ap_ok( is_array( $sec ), 'security tab present' );
$subs = is_array( $sec ) ? ( $sec['sub_tabs'] ?? array() ) : array();
ap_ok( ! empty( $subs['audit-log']['wide'] ), '#2 audit-log leaf is wide' );
ap_ok( empty( $subs['login']['wide'] ) && empty( $subs['login-defense']['wide'] ), 'login + login-defense stay capped (only audit-log earns width)' );

// ── Group: audit-log glance hero (#10) + carded Maintenance (#2) ────────────
echo "\nGroup: audit-log — glance hero + carded Maintenance\n";
require_once __DIR__ . '/../inc/audit-log-admin.php';
$summary = array(
	'last_24h'         => array( 'all_total' => 12, 'failed_total' => 3, 'recon_total' => 2 ),
	'last_7d_vs_prior' => array( 'pct_delta' => 5, 'current' => 40, 'prior' => 38 ),
	'unique_attackers_24h' => 7,
	'lla'              => array( 'active_lockouts' => 1 ),
);
$cards = snt_audit_log_glance_cards( $summary );
ap_ok( count( $cards ) === 4, '#10 builds 4 glance cards' );
ap_ok( ( $cards[1]['pill']['kind'] ?? '' ) === 'warn', '#10 a rising 7d attack trend is pilled warn' );
ob_start(); snt_audit_log_render_hero( $summary ); $h = ob_get_clean();
ap_ok( false !== strpos( $h, 'class="sn-glance"' ), '#10 hero renders through the shared glance grid' );
ap_ok( false === strpos( $h, 'sn-audit-state-grid' ), '#10 the bespoke .sn-audit-state-grid hero is gone' );
ob_start(); snt_audit_log_render_prune_form(); $h = ob_get_clean();
ap_ok( false !== strpos( $h, 'class="sn-fieldset"' ), '#2 Maintenance block is carded (no bare float at full width)' );

// ── Group: rss glance hero (#4) ─────────────────────────────────────────────
echo "\nGroup: rss — activity hero via the shared glance grid\n";
require_once __DIR__ . '/../inc/rss-feed-tracker.php';
$stats = array(
	'windows'     => array(
		1  => array( 'total' => 5, 'uniques' => 3 ),
		7  => array( 'total' => 20, 'uniques' => 9 ),
		30 => array( 'total' => 80, 'uniques' => 30 ),
	),
	'most_recent' => '2026-06-28 00:00:00',
);
$rc = snt_rss_glance_cards( $stats );
ap_ok( count( $rc ) === 3, '#4 builds 3 activity glance cards' );
ob_start(); sn_rss_tracker_render_stats( $stats ); $h = ob_get_clean();
ap_ok( false !== strpos( $h, 'class="sn-glance"' ), '#4 RSS activity renders through the shared glance grid' );
ap_ok( false === strpos( $h, 'sn-rss-activity-card' ), '#4 the bespoke .sn-rss-activity-card hero is gone' );

// ── Group: deploy status SR text (#18) ──────────────────────────────────────
echo "\nGroup: deploy status — screen-reader text\n";
require_once __DIR__ . '/../inc/admin-tab-dashboard.php';
$g = snt_dashboard_run_glyph_html( 'sn-deploy-row__status--ok', '&#x2713;', 'Success' );
ap_ok( false !== strpos( $g, 'screen-reader-text' ) && false !== strpos( $g, 'Success' ), '#18 status glyph carries a screen-reader label' );
ap_ok( false !== strpos( $g, 'aria-hidden="true"' ), '#18 the decorative glyph is aria-hidden' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
