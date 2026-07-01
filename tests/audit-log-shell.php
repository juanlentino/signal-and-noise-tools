<?php
/**
 * Standalone test: the Audit-log sub-tab renders through the two-column
 * sn_admin_shell (v7.1.0) — wide data in the MAIN column, passive readouts +
 * config in the RAIL. Mirrors the Insights tab's main/rail split.
 *
 * Run: php tests/audit-log-shell.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function al_ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function al_before( $hay, $a, $b, $m ) {
	$pa = strpos( $hay, $a ); $pb = strpos( $hay, $b );
	al_ok( false !== $pa && false !== $pb && $pa < $pb, $m );
}

// ── Leaf WP + SN stubs ──────────────────────────────────────────────
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { echo ''; }
function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return (string) $u; }
function current_user_can( $c ) { return true; }
function check_admin_referer( $a = -1, $q = '_wpnonce' ) { return true; }
function sn_setting( $path, $default = null ) { return $default; }
function sn_admin_glance_grid( $cards ) { echo '<div class="sn-glance">glance</div>'; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}

// Data-boundary impls (stubbed — the real ones need a DB).
function snt_audit_get_summary_impl() {
	return array(
		'last_24h'             => array( 'all_total' => 12, 'failed_total' => 3, 'recon_total' => 2 ),
		'last_7d_vs_prior'     => array( 'pct_delta' => 5, 'current' => 40, 'prior' => 38 ),
		'unique_attackers_24h' => 7,
		'lla'                  => array( 'active_lockouts' => 1, 'most_recent_lockout_ts' => 0 ),
	);
}
function snt_audit_get_counters_impl( $days ) {
	return array( array( 'date' => '2026-07-01', 'login_failed' => 2, 'wp_login_404' => 1, 'wp_admin_unauth_404' => 0, 'lockout_triggered' => 0, 'password_reset' => 0, 'unique_ips_count' => 3 ) );
}
function snt_audit_get_login_successes_impl( $days ) {
	return array( array( 'formatted' => '2026-07-01 10:00:00', 'user' => 'admin' ) );
}

require_once __DIR__ . '/../inc/admin-shell.php';    // real shell primitive
require_once __DIR__ . '/../inc/audit-log-admin.php'; // SUT

$_POST = array(); // no prune action → skip the POST branch
ob_start();
snt_audit_log_render_tab();
$html = ob_get_clean();

echo "\nGroup: audit-log renders through the two-column shell\n";
al_ok( false !== strpos( $html, 'class="sn-shell"' ), 'opens the sn-shell wrapper' );
al_ok( false !== strpos( $html, 'sn-shell__main' ), 'has a main column' );
al_ok( false !== strpos( $html, 'sn-shell__rail' ), 'has a rail column' );

echo "\nGroup: wide DATA lives in MAIN (before the rail)\n";
al_before( $html, 'sn-glance', 'sn-shell__rail', 'the glance hero is in the main column' );
al_before( $html, 'sn-audit-timeline', 'sn-shell__rail', 'the counter timeline table is in the main column' );
al_before( $html, 'sn-audit-logins', 'sn-shell__rail', 'the recent-logins table is in the main column' );

echo "\nGroup: passive readouts + CONFIG live in the RAIL (after the rail marker)\n";
al_before( $html, 'sn-shell__rail', 'limit-login-attempts', 'the LLA status card is in the rail' );
al_before( $html, 'sn-shell__rail', 'audit_save_retention', 'the retention form is in the rail' );
al_before( $html, 'sn-shell__rail', 'audit_prune_now', 'the maintenance / prune form is in the rail' );
al_before( $html, 'sn-shell__rail', 'sn_audit_export', 'the export links are in the rail' );

echo "\nGroup: shell is balanced (no early return between open and close)\n";
al_ok( substr_count( $html, 'sn-shell__main' ) === 1 && substr_count( $html, 'sn-shell__rail' ) === 1, 'exactly one main + one rail (balanced)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
