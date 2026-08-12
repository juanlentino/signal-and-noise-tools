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
// Plain translate: RETURNS, never escapes. A renderer that sprintf()s and then
// escapes the RESULT needs this, not esc_html__(), which would escape the
// template before the values land in it.
function __( $s, $d = null ) { return (string) $s; }
// Real _n() shape: returns (never echoes) and selects on the COUNT. A stub
// ignoring $n would green a renderer that says "1 logins" on the live site.
function _n( $one, $many, $n, $d = null ) { return (string) ( 1 === (int) $n ? $one : $many ); }
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
$GLOBALS['__logins'] = array( array( 'formatted' => '2026-07-01 10:00:00', 'user' => 'admin' ) );
function snt_audit_get_login_successes_impl( $days ) {
	return $GLOBALS['__logins'];
}

// SN_AUDIT_LOGIN_SUCCESS_CAP lives in inc/audit-log.php, which this harness
// cannot load (it stubs that module's impls above — loading would redeclare
// them and fatal). READ the real value out of the source rather than mirroring
// a literal: a hardcoded 500 here would keep passing after production changed
// its cap, which is exactly the stub-drift trap this suite exists to avoid.
if ( ! defined( 'SN_AUDIT_LOGIN_SUCCESS_CAP' ) ) {
	preg_match( '/SN_AUDIT_LOGIN_SUCCESS_CAP\s*=\s*(\d+)/', (string) file_get_contents( __DIR__ . '/../inc/audit-log.php' ), $sn_cap_m );
	define( 'SN_AUDIT_LOGIN_SUCCESS_CAP', (int) ( $sn_cap_m[1] ?? 0 ) );
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

echo "\nGroup: v8.0.2 cohesion — table panels use the system card, not meta-box chrome\n";
al_ok( false === strpos( $html, 'postbox' ), 'no native postbox chrome remains' );
al_ok( false !== strpos( $html, '<div class="sn-fieldset sn-fieldset--wide">' ), 'table panels are wide fieldset cards' );
al_ok( false === strpos( $html, 'sn-an-table-inside' ), 'analytics-dashboard gutter class no longer referenced' );
al_ok( false === strpos( $html, 'sn-an-empty' ), 'no dangling dependency on the analytics stylesheet' );
$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );
al_ok( false !== strpos( $css, '.sn-fieldset--wide' ), '.sn-fieldset--wide modifier exists in admin.css' );

// ── AL1 (IA fold arc): the recent-logins table folds ───────────────────────
// Same shape MR1 fixed on the Machine Readers tab: SN_AUDIT_LOGIN_SUCCESS_CAP
// bounds what is STORED (500, oldest dropped), and the renderer then printed
// every row it was handed, fully open. The cap was real at the store and
// absent at the display.
echo "\nGroup: AL1 — the recent-logins log folds, newest first, true count in the summary\n";
$GLOBALS['__logins'] = array();
for ( $i = 1; $i <= 60; $i++ ) {
	// ASCENDING timestamps, so the newest row is LAST in input order. A
	// renderer that slices without sorting would drop the most recent login,
	// which is the one a security readout most needs to show.
	$GLOBALS['__logins'][] = array(
		'ts'        => 1000 + $i,
		'formatted' => sprintf( '2026-07-%02d 10:00:00', $i % 28 + 1 ),
		'user'      => 'user' . $i,
	);
}
ob_start();
snt_audit_log_render_tab();
$html_many = ob_get_clean();
al_ok( false !== strpos( $html_many, '<details class="sn-audit-logins-log sn-disclosure">' ), 'the logins table sits inside its own disclosure' );
al_ok( false === strpos( $html_many, '<details class="sn-audit-logins-log sn-disclosure" open' ), 'and it is CLOSED by default' );
$al_det = strpos( $html_many, '<details class="sn-audit-logins-log' );
$al_sum = substr( $html_many, (int) $al_det, 220 );
al_ok( false !== strpos( $al_sum, '60' ), 'the summary carries the TRUE login count (60), not the displayed 50' );
al_ok( 50 === substr_count( $html_many, '<tr><td><code>' ), 'the display cap renders exactly 50 rows' );
al_ok( false !== stripos( $html_many, 'capped, not complete' ), 'the remainder line uses the house wording' );
al_ok( false !== strpos( $html_many, '+10 more' ), 'and names how many were withheld' );
// Non-vacuity: if the regex above failed to find the constant it would define
// 0, and an "at most 0" assertion would be meaningless. Pin the parse first.
al_ok( SN_AUDIT_LOGIN_SUCCESS_CAP > 0, 'the real storage cap was parsed out of inc/audit-log.php (not defaulted to 0)' );
al_ok( false !== stripos( $html_many, 'at most ' . number_format_i18n( SN_AUDIT_LOGIN_SUCCESS_CAP ) ), 'the STORE cap is named from the REAL constant, so a tighter display cap cannot imply the site keeps less than it does' );
// Newest-first: user60 is the most recent and arrived last in input order.
al_ok( false !== strpos( $html_many, 'user60' ), 'the newest login is shown even though it arrived last' );
al_ok( false === strpos( $html_many, 'user1<' ), 'and the oldest is the one the cap drops' );
$al_first = strpos( $html_many, '<tr><td><code>' );
al_ok( false !== strpos( substr( $html_many, (int) $al_first, 200 ), 'user60' ), 'the newest login sorts FIRST' );
// A security readout must not be dressed as a defect list.
al_ok( false === strpos( $html_many, 'sn-badge-warn' ), 'a successful login carries no warning styling — it is a record, not a finding' );
// Under the cap: still folded, no remainder line.
$GLOBALS['__logins'] = array( array( 'ts' => 5, 'formatted' => '2026-07-01 10:00:00', 'user' => 'admin' ) );
ob_start();
snt_audit_log_render_tab();
$html_one = ob_get_clean();
al_ok( false !== strpos( $html_one, '<details class="sn-audit-logins-log' ), 'a short log still folds (consistency beats a size heuristic)' );
al_ok( false === stripos( $html_one, 'capped, not complete' ), 'but prints no remainder line when nothing was withheld' );

// Empty-logins branch: its early return must stay balanced with the new closers.
$GLOBALS['__logins'] = array();
ob_start();
snt_audit_log_render_tab();
$html_empty = ob_get_clean();
al_ok( false !== strpos( $html_empty, 'No successful logins recorded' ), 'empty-logins state renders its message' );
// AL1: an empty window keeps its sentence and renders NO disclosure — a fold
// whose summary read "0 logins" would rhyme with a measured zero.
al_ok( false === strpos( $html_empty, '<details class="sn-audit-logins-log' ), 'empty-logins state renders no disclosure at all' );
al_ok( false === strpos( $html_empty, 'postbox' ) && false === strpos( $html_empty, 'sn-an-empty' ), 'empty-logins state carries no postbox / analytics classes' );
al_ok( substr_count( $html_empty, 'sn-shell__main' ) === 1 && substr_count( $html_empty, 'sn-shell__rail' ) === 1, 'empty-logins render stays shell-balanced' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
