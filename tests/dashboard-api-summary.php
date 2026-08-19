<?php
/**
 * Standalone fixture tests for snt_dashboard_render_api_summary()
 * (inc/admin-tab-dashboard.php) — the Dashboard "External APIs" line.
 *
 * Locks the v4.5.5 contract: a tracked host is rendered ONLY when it has a
 * real rate-limit snapshot. A host that never reports (Cloudflare uses a
 * non-standard `Ratelimit` header the monitor doesn't parse) must NOT appear
 * as a permanent "—" — it's simply omitted. GitHub, which does report, still
 * shows. Self-healing: if a host ever starts reporting, it appears
 * automatically.
 *
 * Run: php tests/dashboard-api-summary.php
 *
 * @since plugin v4.5.5
 */

// SECURITY: CLI / WP-CLI only (mirrors sibling fixtures).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

define( 'ABSPATH', '/' );

// ─── WP stubs ─────────────────────────────────────────────────────────
// Load-time: the dashboard file registers render hooks via add_action.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'esc_html' ) )   { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) )   { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) )    { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return $s; } }
if ( ! function_exists( '__' ) )         { function __( $s, $d = '' ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }
// v9.54.0: the API summary now prints each snapshot's age — a rate readout that
// can only update on success must show how old it is, or it poses as live while
// every call fails (the 2026-07-16 incident).
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $from, $to = 0 ) { return floor( abs( ( $to ?: time() ) - $from ) / 60 ) . ' mins'; } }
if ( ! function_exists( 'admin_url' ) )  { function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u . '&_n=1'; } }

// The function-under-test calls this; we control its return per-case.
$GLOBALS['__statuses'] = array();
if ( ! function_exists( 'snt_rate_limit_all_statuses' ) ) {
    function snt_rate_limit_all_statuses() { return $GLOBALS['__statuses']; }
}

// v11.28.0: split out of admin-tab-dashboard.php.
require __DIR__ . '/../inc/dash-api-summary.php';
require __DIR__ . '/../inc/admin-tab-dashboard.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "PASS: $label\n"; }
    else { $fail++; echo "FAIL: $label\n"; }
}

/** Capture the function's echoed HTML for a given status map. */
function render_with( $statuses ) {
    $GLOBALS['__statuses'] = $statuses;
    ob_start();
    snt_dashboard_render_api_summary();
    return ob_get_clean();
}

$snap = function ( $remaining, $limit, $label ) {
    return array( 'remaining' => $remaining, 'limit' => $limit, 'reset_at' => 0, 'fetched_at' => 100, 'label' => $label );
};

// ── Case 1: GitHub reports; CF has no snapshot ──
$html = render_with( array(
    'api.github.com'     => array( 'label' => 'GitHub API',     'snapshot' => $snap( 4998, 5000, 'GitHub API' ) ),
    'api.cloudflare.com' => array( 'label' => 'Cloudflare API', 'snapshot' => null ),
) );

ok( strpos( $html, 'GitHub API' ) !== false,        'reporting host (GitHub) is shown' );
ok( strpos( $html, '4,998' ) !== false,             'GitHub remaining count rendered' );
ok( strpos( $html, 'Cloudflare API' ) === false,    'non-reporting Cloudflare is OMITTED (not shown as —)' );
ok( strpos( $html, '—' ) === false,                 'no em-dash placeholder anywhere' );
ok( strpos( $html, 'External APIs' ) !== false,     'section heading still rendered' );
ok( strpos( $html, 'Refresh now' ) !== false,       'Refresh link still rendered' );

// ── Case 2: ALL hosts have snapshots → all shown ──
$html2 = render_with( array(
    'api.github.com'     => array( 'label' => 'GitHub API',     'snapshot' => $snap( 4998, 5000, 'GitHub API' ) ),
    'api.cloudflare.com' => array( 'label' => 'Cloudflare API', 'snapshot' => $snap( 1100, 1200, 'Cloudflare API' ) ),
) );
ok( strpos( $html2, 'GitHub API' ) !== false && strpos( $html2, 'Cloudflare API' ) !== false, 'all reporting hosts shown (self-heal path)' );

// ── Case 3: NO hosts report → graceful (heading + Refresh, no items, no —) ──
$html3 = render_with( array(
    'api.github.com' => array( 'label' => 'GitHub API', 'snapshot' => null ),
) );
ok( strpos( $html3, 'External APIs' ) !== false, 'heading renders even with zero reporting hosts' );
ok( strpos( $html3, '—' ) === false,             'no em-dash when zero hosts report' );
ok( strpos( $html3, 'Refresh now' ) !== false,   'Refresh link renders with zero reporting hosts' );
// No dangling separator immediately before the Refresh link when there are
// zero host items (the v4.5.5 empty-items guard).
ok( ! preg_match( '/__sep__\s*<a/', str_replace( '<span class="sn-api-summary__sep" aria-hidden="true">&middot;</span>', '__sep__', $html3 ) ), 'no dangling separator before Refresh when zero hosts report' );

// ── Case 4: critical host still triggers the warning notice ──
$html4 = render_with( array(
    'api.github.com' => array( 'label' => 'GitHub API', 'snapshot' => $snap( 30, 5000, 'GitHub API' ) ),
) );
ok( strpos( $html4, 'Rate limit critical' ) !== false, 'critical (<10%) host still surfaces the warning notice' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
