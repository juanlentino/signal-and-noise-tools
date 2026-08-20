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
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $s = false ) { return $s ? '' : array(); } }
require __DIR__ . '/../inc/dash-zones.php';          // v11.28.0: zone contract + renderer
require __DIR__ . '/../inc/dash-pins.php';           // v11.28.0: per-user pins
require __DIR__ . '/../inc/dash-zone-attention.php'; // v11.28.0
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
// v11.29.1: the console — band + rail + stage.
require __DIR__ . '/../inc/dash-briefing.php';
require_once __DIR__ . '/../inc/dash-verdict.php';      // v11.30.0: the shared verdict
require_once __DIR__ . '/../inc/dash-signals.php';      // v11.30.0: signals with comparisons
require_once __DIR__ . '/../inc/dash-systems.php';      // v11.30.0: the systems grid
require __DIR__ . '/../inc/dash-trend.php';
require __DIR__ . '/../inc/dash-ops-render.php';
require __DIR__ . '/../inc/dash-console.php';
require __DIR__ . '/../inc/dash-ops-panels.php';  // v11.29.2: the ops wall
require __DIR__ . '/../inc/dash-zone-fleet.php';     // v11.28.0
require __DIR__ . '/../inc/dash-zone-measurement.php'; // v11.28.0: the five figures + strip
// v11.28.0: split out of admin-tab-dashboard.php.
require_once __DIR__ . '/../inc/dash-deploy-rows.php';
require __DIR__ . '/../inc/dash-api-summary.php';
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

// ── Status is grouped at the top: zone section → External APIs → above deploys/maintenance ──
// v11.28.0: the always-present glance hero is gone. Zones collapse when nothing
// needs attention, and a collapsed zone never builds a grid — so `sn-glance` is
// legitimately absent here. The zone SECTION is the stable anchor now.
$zones   = strpos( $html, 'sn-scr__verdict' );
$maint   = strpos( $html, 'Maintenance' );

ok( false !== $zones && false !== $maint, 'the screen and Maintenance both render (fixture sanity)' );
ok( $zones < $maint, 'status leads — the console renders above the Maintenance actions' );

// v11.28.0, three sections deliberately no longer stand alone:
//   External APIs  — surfaces ONLY when a host is warn/crit. This fixture is
//                    healthy, so its ABSENCE is the assertion.
//   RSS activity   — cut; the RSS tab owns the full view.
//   Recent deploys — folded INTO the fleet zone, not removed from the product.
ok( false === strpos( $html, 'External APIs' ), 'a healthy rate limit does not spend space on the Dashboard' );
ok( false === strpos( $html, 'RSS feed activity' ), 'RSS activity is cut — the RSS tab owns it' );
$deploys = strpos( $html, 'sn-deploy-list' );
$fleet   = strpos( $html, 'data-zone="fleet"' );
ok( false === $deploys || ( false !== $fleet && $deploys > $fleet ),
	'RECENT DEPLOYS IS FOLDED INSIDE THE FLEET ZONE, never a standalone section' );

// v11.29.2: the ops row must be wired through the REAL tab, not just unit-
// tested on the renderer. A renderer that works and a call site that never
// passes it data ships an empty panel — a part without its door.
ok( false !== strpos( $html, 'sn-scr__detail' ), 'the detail columns render on the real Dashboard tab' );
ok( false !== strpos( $html, 'Recent deploys' ), 'with its deploys panel' );
ok( false !== strpos( $html, 'Top queries' ), 'and its queries panel' );
ok( false === strpos( $html, 'sn-attn' ), 'and nothing repeats the verdict sentence below it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
