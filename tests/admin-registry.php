<?php
/**
 * Contract + routing tests for the data-driven admin render registry (Phase 1).
 * Standalone CLI fixture: stubs the WP admin layer, loads the registry data +
 * dispatcher, and asserts every leaf names a real render function, the
 * dispatcher routes (tab, sub) to the right leaf, and the POST allowlist is
 * registry-derived. Behaviour-preserving: this guards the switch→registry move.
 *
 * @since plugin v6.17.x (admin refactor Phase 1)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Record render invocations instead of producing real admin HTML.
$GLOBALS['__calls'] = array();
function sn_admin_render_section( $slug, $cb ) { $GLOBALS['__calls'][] = "section:$slug"; call_user_func( $cb ); }
function sn_admin_render_sub_tabs( $tab, $sub ) { $GLOBALS['__calls'][] = "subtabs:$tab"; }
function sn_admin_render_toc( $tab, $sub ) { $GLOBALS['__calls'][] = "toc:$tab/$sub"; }
function do_action( $tag ) { $GLOBALS['__calls'][] = "action:$tag"; }
function has_action( $tag ) { return true; }
function sn_admin_render_identity_and_seo_form() { $GLOBALS['__calls'][] = 'form:identity'; }
function esc_html( $s ) { return (string) $s; }
function esc_html__( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function __( $s ) { return (string) $s; }
function add_action() {} // admin-post-handler.php registers on admin_init at load — no-op it.

// Function-backed section renderers referenced by the registry (stubbed present,
// each records its own call so routing assertions can see which fired).
function sn_admin_render_login_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_login_section'; }
function snt_audit_log_render_tab() { $GLOBALS['__calls'][] = 'fn:snt_audit_log_render_tab'; }
function sn_admin_render_indexnow_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_indexnow_section'; }
function snt_analytics_render_settings_section() { $GLOBALS['__calls'][] = 'fn:snt_analytics_render_settings_section'; }
function sn_admin_render_music_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_music_section'; }
function sn_admin_render_links_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_links_section'; }
function sn_admin_render_performance_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_performance_section'; }
function sn_admin_render_release_notes_section() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_release_notes_section'; }
function sn_admin_render_front_end_form() { $GLOBALS['__calls'][] = 'fn:sn_admin_render_front_end_form'; }

// NB: we stub sn_admin_render_section/sub_tabs/toc above to record routing, so we
// must NOT require inc/admin-tabs.php (it defines them for real → redeclare fatal).
// The dispatcher lives in its own inc/admin-dispatch.php (required from Task 3 on).
require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/admin-render-sections.php';

echo "Admin registry suite — Phase 1\n\n";

// ── Wrappers exist (Task 1) ──
foreach ( array(
	'sn_admin_render_dashboard', 'sn_admin_render_cloudflare_section', 'sn_admin_render_cron_section',
	'sn_admin_render_webhooks_section', 'sn_admin_render_health_section', 'sn_admin_render_insights_section',
	'sn_admin_render_reading_time_section', 'sn_admin_render_block_migrations_section', 'sn_admin_render_rss_section',
) as $fn ) {
	ok( function_exists( $fn ), "wrapper $fn() is defined" );
}

// ── Every leaf names an existing render function (Task 2) ──
$tabs = sn_admin_top_tabs();
foreach ( $tabs as $top ) {
	$subs = is_array( $top['sub_tabs'] ?? null ) ? $top['sub_tabs'] : array();
	if ( empty( $subs ) ) {
		ok( isset( $top['render'] ) && function_exists( $top['render'] ),
			"tab '{$top['tab']}' (no sub-tabs) names an existing render fn" );
		continue;
	}
	foreach ( $subs as $slug => $sub ) {
		ok( isset( $sub['render'] ) && function_exists( $sub['render'] ),
			"leaf '{$top['tab']}/$slug' names an existing render fn: " . ( $sub['render'] ?? '(missing)' ) );
	}
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
