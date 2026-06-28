<?php
/**
 * Render-order smoke test for the Dashboard tab (admin refactor Phase 3 declutter).
 *
 * Drives the full snt_dashboard_tab_render() with stubs and asserts the layout
 * contract: (1) the redundant wayfinding grid is GONE — it duplicated the tab
 * bar + sidebar; (2) the at-a-glance hero (Phase 1: the .sn-glance grid →
 * External APIs → RSS) is grouped at the TOP, above Recent deploys (activity)
 * and Maintenance (actions). Guards against the wayfinder returning and against
 * the status summaries drifting back below the action buttons.
 *
 * @since plugin v6.19.1 (Phase 1 redesign: .sn-state-grid → .sn-glance hero)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SNT_VERSION', '6.19.1-test' );

function add_action() {}
function add_filter() {}
function apply_filters( $t, $v = null ) { return $v; }
function current_user_can( $c ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function human_time_diff( $a, $b = 0 ) { return '5 minutes'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; }
function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return (string) $u; }
function wp_get_theme( $s = null ) { return new class { public function get( $k ) { return '10.10.1'; } }; }
function get_posts( $a = array() ) { return array(); }
// function_exists-guarded helpers — DEFINE so the API + RSS summaries render
// (we assert their POSITION; empty data still emits their section headers).
function snt_rate_limit_all_statuses() { return array(); }
function sn_rss_tracker_window_stats_multi( $w ) { return array( 'windows' => array(), 'most_recent' => '' ); }

require __DIR__ . '/../inc/admin-tabs-data.php';   // (only the removed wayfinder used this; harmless to load)
require __DIR__ . '/../inc/admin-glance.php';      // Phase 1: the glance-grid helper the hero uses
require __DIR__ . '/../inc/admin-tab-dashboard.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Dashboard layout (declutter) — Phase 3\n\n";

// ── The wayfinder is gone ──
ok( ! function_exists( 'snt_dashboard_render_wayfinding' ), 'wayfinding render fn removed' );

ob_start();
snt_dashboard_tab_render();
$html = ob_get_clean();

ok( false === strpos( $html, 'Jump to' ), 'no "Jump to" wayfinding section in the rendered dashboard' );
ok( false === strpos( $html, 'page=sn-content' ), 'no per-tab wayfinding links (page=sn-content absent)' );

// ── Status is grouped at the top: glance hero → External APIs → above deploys/maintenance ──
$glance  = strpos( $html, 'sn-glance' );
$api     = strpos( $html, 'External APIs' );
$deploys = strpos( $html, 'Recent deploys' );
$maint   = strpos( $html, 'Maintenance' );

ok( false !== $glance && false !== $api && false !== $deploys && false !== $maint, 'all four sections render (fixture sanity)' );
ok( $glance < $api, 'glance hero renders before the External APIs status line' );
ok( $api < $deploys, 'External APIs status renders ABOVE Recent deploys (status grouped at top)' );
ok( $api < $maint, 'External APIs status renders ABOVE Maintenance (no longer dangling below the actions)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
