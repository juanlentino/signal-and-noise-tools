<?php
/**
 * Render smoke test for the Dashboard wayfinding grid (admin refactor Phase 4).
 *
 * snt_dashboard_render_wayfinding() turns the plugin's Dashboard landing tab into
 * a home hub: one native .sn-card per top tab (minus Dashboard itself), linking to
 * ?page=<slug> with the tab's label + subtitle ("what's here"). It is REGISTRY-
 * DERIVED from sn_admin_top_tabs() — so it auto-reflects the v6.18.0 7-tab IA + the
 * new sn-content / sn-connections slugs with no second list to maintain. This guards
 * the card-per-tab contract + the link targets + the "no Dashboard self-card" rule.
 *
 * @since plugin v6.19.0 (admin refactor Phase 4)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function add_action() {}                                  // admin-tab-dashboard.php registers one at load.
function add_filter() {}                                  // ...and a debug_information filter at load.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function current_user_can( $c ) { return true; }

require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/admin-tab-dashboard.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Dashboard wayfinding grid — Phase 4\n\n";

ok( function_exists( 'snt_dashboard_render_wayfinding' ), 'snt_dashboard_render_wayfinding() is defined' );

ob_start();
snt_dashboard_render_wayfinding();
$html = ob_get_clean();

// ── Section header + grid shell ──
ok( false !== strpos( $html, 'sn-section-h' ), 'emits a section heading' );
ok( false !== strpos( $html, 'class="sn-card-grid"' ), 'reuses the native .sn-card-grid vocabulary' );

// ── One card per non-dashboard top tab (registry-derived, not hardcoded) ──
$tabs           = sn_admin_top_tabs();
$expected_cards = 0;
foreach ( $tabs as $t ) {
	if ( 'dashboard' !== ( $t['tab'] ?? '' ) && ! empty( $t['slug'] ) ) {
		$expected_cards++;
	}
}
ok( $expected_cards >= 6, "fixture sanity: registry has $expected_cards non-dashboard tabs" );
ok( substr_count( $html, '<div class="sn-card">' ) === $expected_cards,
	"one wayfinding card per non-dashboard tab ($expected_cards)" );

// ── Each non-dashboard tab: its label + a link to ?page=<slug> ──
foreach ( $tabs as $t ) {
	$slug = $t['slug'] ?? '';
	if ( 'dashboard' === ( $t['tab'] ?? '' ) || '' === $slug ) {
		continue;
	}
	ok( false !== strpos( $html, 'page=' . $slug ), "links to the {$t['label']} tab (page=$slug)" );
	ok( false !== strpos( $html, esc_html( $t['label'] ) ), "card shows the '{$t['label']}' label" );
}

// ── The Dashboard tab does NOT get a self-card (you're already on it) ──
ok( false === strpos( $html, 'page=sn-theme-options' ), 'no Dashboard self-card (page=sn-theme-options absent)' );

// ── Subtitles surface as the "what's here" blurb (reused registry data) ──
ok( false !== strpos( $html, 'Cloudflare edge cache' ), 'Connections card carries its subtitle blurb' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
