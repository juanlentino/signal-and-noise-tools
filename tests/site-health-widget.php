<?php
/**
 * CLI fixture for the "S&N Health" dashboard widget (inc/site-health-widget.php)
 * and the shared scan-summary accessors it uses (inc/health-summary.php).
 *
 * The accessors are loaded FOR REAL; only the leaf WP functions + the get_option
 * data boundary are stubbed, so the widget exercises the same marshalling the
 * live render does (get_option -> sn_health_last_scan -> summary accessors).
 *
 * @since plugin v7.0.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

// ── Leaf WP-function stubs (escapers mirror core semantics). ──
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) $n; }
function human_time_diff( $from, $to = 0 ) { return '2 hours'; }
function admin_url( $p ) { return '/wp-admin/' . $p; }

// Registration-gate levers.
$GLOBALS['__can'] = true;
function current_user_can( $cap ) { return ! empty( $GLOBALS['__can'] ); }
$GLOBALS['__widgets'] = array();
function wp_add_dashboard_widget( $id, $title, $cb ) { $GLOBALS['__widgets'][ $id ] = array( 'title' => $title, 'cb' => $cb ); }
function add_action( $h, $cb ) {}

// The REAL data boundary: sn_health_last_scan() is get_option( SN_HEALTH_CACHE_KEY ).
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
define( 'SN_HEALTH_CACHE_KEY', 'sn_health_last_scan' );
function sn_health_last_scan() { $s = get_option( SN_HEALTH_CACHE_KEY ); return is_array( $s ) ? $s : null; }

// Zero-cost render is a hard requirement: no render path may trigger a scan.
// sn_health_run_scan() lives in inc/health-checks.php (NOT loaded here), so this
// stub is the only definition — if the widget ever calls it, the flag flips.
$GLOBALS['__scanned'] = false;
function sn_health_run_scan() { $GLOBALS['__scanned'] = true; return array(); }

require __DIR__ . '/../inc/health-summary.php';    // real accessors
require __DIR__ . '/../inc/site-health-widget.php'; // SUT

// ── Fixture helpers. ──
function mk_check( $count, $label ) { return array( 'count' => $count, 'findings' => array(), 'label' => $label, 'fix_hint' => '' ); }
function mk_scan( $checks, $scanned_at = null ) {
	return array(
		'scanned_at' => null === $scanned_at ? time() - 7200 : $scanned_at,
		'elapsed_ms' => 5,
		'site_url'   => 'https://juanlentino.com/',
		'checks'     => $checks,
	);
}

// ═══ sn_health_finding_total() ═══
echo "\n-- accessor: sn_health_finding_total --\n";
ok( sn_health_finding_total( null ) === 0, 'finding_total: null scan -> 0' );
ok( sn_health_finding_total( array() ) === 0, 'finding_total: array with no checks -> 0' );
$scan3 = mk_scan( array(
	'missing_alt'    => mk_check( 3, 'Missing alt text' ),
	'external_links' => mk_check( 2, 'External link rot' ),
	'stale_posts'    => mk_check( 0, 'Stale posts' ),
) );
// v8.0.4 owner re-tier: external link rot is an ADVISORY, not a finding —
// third-party rot must not flip the site off "all clear" (it self-clears on
// the next scan when the remote host recovers). The findings card on the
// Health tab stays visible; only the alarm calculus changes.
ok( sn_health_finding_total( $scan3 ) === 3, 'finding_total: sums every NON-advisory check count (3+0=3; external rot re-tiered)' );
$only_ext = mk_scan( array( 'external_links' => mk_check( 4, 'External link rot' ) ) );
ok( sn_health_finding_total( $only_ext ) === 0, 'finding_total: external link rot alone leaves the site all-clear (advisory tier)' );

// ═══ sn_health_advisory_checks() + sn_health_advisory_total() (v8.0.4) ═══
echo "\n-- accessor: advisory tier --\n";
// v8.1.0 DELIBERATE FLIP: this assert pinned external_links as the ONLY
// advisory check (the v8.0.4 state). link_opportunities joins the tier in
// v8.1.0 — semantic-pair candidates are opportunities, not rot, so they
// must not flip the site off "all clear" either.
ok( function_exists( 'sn_health_advisory_checks' ) && sn_health_advisory_checks() === array( 'external_links', 'link_opportunities' ), 'advisory_checks: external_links + link_opportunities are the advisory-tier checks' );
ok( sn_health_advisory_total( null ) === 0, 'advisory_total: null scan -> 0' );
ok( sn_health_advisory_total( $only_ext ) === 4, 'advisory_total: sums advisory-check counts' );
ok( sn_health_advisory_total( $scan3 ) === 2, 'advisory_total: mixed scan counts only the advisory checks' );

// ═══ sn_health_flagged_checks() ═══
echo "\n-- accessor: sn_health_flagged_checks --\n";
ok( sn_health_flagged_checks( null ) === array(), 'flagged_checks: null scan -> empty' );
$flagged = sn_health_flagged_checks( $scan3 );
ok( count( $flagged ) === 1, 'flagged_checks: only NON-advisory checks with count>0 (1 of 3; external rot re-tiered)' );
$rank = mk_scan( array(
	'a' => mk_check( 1, 'A' ),
	'b' => mk_check( 5, 'B' ),
	'c' => mk_check( 3, 'C' ),
) );
ok( array_keys( sn_health_flagged_checks( $rank ) ) === array( 'b', 'c', 'a' ), 'flagged_checks: ranked by count desc regardless of input order' );

// ═══ sn_health_check_total() (v7.1.0) ═══
echo "\n-- accessor: sn_health_check_total --\n";
ok( sn_health_check_total( null ) === 0, 'check_total: null scan -> 0' );
ok( sn_health_check_total( array() ) === 0, 'check_total: no checks -> 0' );
ok( sn_health_check_total( $scan3 ) === 3, 'check_total: counts every check regardless of findings (3)' );

// ═══ registration gate ═══
echo "\n-- registration gate --\n";
$GLOBALS['__can'] = false; $GLOBALS['__widgets'] = array();
sn_site_health_widget_register();
ok( empty( $GLOBALS['__widgets'] ), 'register: non-manage_options user does not register the widget' );
$GLOBALS['__can'] = true; $GLOBALS['__widgets'] = array();
sn_site_health_widget_register();
ok( isset( $GLOBALS['__widgets']['sn_site_health'] ), 'register: manage_options user registers sn_site_health' );
ok( isset( $GLOBALS['__widgets']['sn_site_health']['title'] ) && $GLOBALS['__widgets']['sn_site_health']['title'] === 'S&N Health', 'register: title is "S&N Health"' );
// v8.3.0: the registered callback is the _full wrapper (health render +
// uptime section) — asserted in depth in the uptime-section block below.
ok( isset( $GLOBALS['__widgets']['sn_site_health']['cb'] ) && $GLOBALS['__widgets']['sn_site_health']['cb'] === 'sn_site_health_widget_render_full', 'register: full (health + uptime) render callback wired' );

// ═══ render: state 1 (no scan) ═══
echo "\n-- render: no scan --\n";
$GLOBALS['__opt'] = array();
ob_start(); sn_site_health_widget_render(); $no = ob_get_clean();
ok( strpos( $no, 'sn-hw-head--dormant' ) !== false, 'no-scan: dormant status header (not an alarming error red)' );
ok( stripos( $no, 'No scan yet' ) !== false, 'no-scan: "No scan yet" headline' );
ok( strpos( $no, 'tab=monitoring&sub=health' ) !== false, 'no-scan: links to the Health tab' );
ok( strpos( $no, 'sn-aw-grid' ) === false && strpos( $no, 'sn-aw-list' ) === false, 'no-scan: no tiles / findings list' );

// ═══ render: state 2 (all clear) ═══
echo "\n-- render: all clear --\n";
$GLOBALS['__opt'][ SN_HEALTH_CACHE_KEY ] = mk_scan( array(
	'missing_alt'    => mk_check( 0, 'Missing alt text' ),
	'external_links' => mk_check( 0, 'External link rot' ),
) );
ob_start(); sn_site_health_widget_render(); $clear = ob_get_clean();
ok( strpos( $clear, 'sn-hw-head--ok' ) !== false, 'all-clear: green/ok status header' );
ok( stripos( $clear, 'all clear' ) !== false, 'all-clear: calm positive headline' );
ok( stripos( $clear, 'checks passed' ) !== false && strpos( $clear, '2' ) !== false, 'all-clear: reassuring "N checks passed" (2 checks)' );
ok( stripos( $clear, 'scanned' ) !== false && strpos( $clear, '2 hours' ) !== false, 'all-clear: relative scan timestamp' );
ok( strpos( $clear, 'sn-aw-grid' ) === false && strpos( $clear, 'sn-aw-list' ) === false, 'all-clear: no findings tiles/list' );

// ═══ render: state 3 (findings) ═══
echo "\n-- render: findings --\n";
$GLOBALS['__opt'][ SN_HEALTH_CACHE_KEY ] = mk_scan( array(
	'missing_alt'    => mk_check( 3, 'Missing alt text' ),
	'broken_links'   => mk_check( 7, 'Broken internal links' ),
	'external_links' => mk_check( 2, 'External link rot' ),
	'stale_posts'    => mk_check( 0, 'Stale posts (>12 months)' ),
) );
ob_start(); sn_site_health_widget_render(); $f = ob_get_clean();
ok( strpos( $f, 'sn-hw-head--warn' ) !== false, 'findings: warn status header' );
// v8.0.4 advisory re-tier: external rot (2) is excluded from the widget's
// alarm figures AND its attention list — the widget is the "does anything
// need me" surface, and third-party rot does not.
ok( stripos( $f, '10 finding' ) !== false, 'findings: headline total sums fault checks only (3+7=10)' );
ok( stripos( $f, '2 of 4' ) !== false, 'findings: subline counts only fault-tier flagged checks (2 of 4)' );
ok( strpos( $f, 'sn-aw-list' ) !== false, 'findings: ranked list present' );
$p_broken = strpos( $f, 'Broken internal links' );
$p_alt    = strpos( $f, 'Missing alt text' );
ok( false !== $p_broken && false !== $p_alt && $p_broken < $p_alt, 'findings: list ranked by count desc (7 -> 3)' );
ok( strpos( $f, 'External link rot' ) === false, 'findings: advisory-tier check stays OFF the widget attention list' );
ok( strpos( $f, 'Stale posts' ) === false, 'findings: passing checks (count 0) excluded from the list' );
ok( strpos( $f, 'sn-aw-foot' ) !== false && strpos( $f, 'tab=monitoring&sub=health' ) !== false, 'findings: footer link to the Health tab' );

// ═══ render: cap + overflow ═══
echo "\n-- render: cap at 4 + overflow --\n";
$many = array();
for ( $i = 1; $i <= 6; $i++ ) { $many[ 'c' . $i ] = mk_check( 10 - $i, 'Check ' . $i ); } // counts 9,8,7,6,5,4
$GLOBALS['__opt'][ SN_HEALTH_CACHE_KEY ] = mk_scan( $many );
ob_start(); sn_site_health_widget_render(); $cap = ob_get_clean();
ok( substr_count( $cap, '<li>' ) === 4, 'cap: ranked list capped at 4 rows with 6 flagged checks' );
ok( strpos( $cap, 'View all' ) !== false, 'cap: overflow footer says "View all N findings"' );
ok( strpos( $cap, '39' ) !== false, 'cap: overflow reflects total findings (9+8+7+6+5+4=39)' );

// ═══ escaping ═══
echo "\n-- escaping --\n";
$GLOBALS['__opt'][ SN_HEALTH_CACHE_KEY ] = mk_scan( array( 'x' => mk_check( 1, '<script>alert(1)</script>' ) ) );
ob_start(); sn_site_health_widget_render(); $xss = ob_get_clean();
ok( strpos( $xss, '<script>alert(1)</script>' ) === false, 'escaping: raw <script> label is not emitted' );
ok( strpos( $xss, '&lt;script&gt;' ) !== false, 'escaping: label passes through esc_html' );

// ═══ uptime section (v8.3.0 consolidation) ═══
// The standalone "S&N Uptime" widget was folded into this one: the
// registered callback is now the _full wrapper (health render + uptime
// section). The section is '' without a token and an async mount with one;
// either way the render stays remote-call-free.
echo "\n-- uptime section (v8.3.0) --\n";
require __DIR__ . '/../inc/uptime-status.php';
require __DIR__ . '/../inc/uptime-status-widget.php';
$GLOBALS['__can'] = true; $GLOBALS['__widgets'] = array();
sn_site_health_widget_register();
$full_cb = $GLOBALS['__widgets']['sn_site_health']['cb'] ?? '';
ok( 'sn_site_health_widget_render_full' === $full_cb, 'uptime: widget registers the full (health + uptime) callback' );

$GLOBALS['__opt'][ SN_HEALTH_CACHE_KEY ] = mk_scan( array( 'ok1' => mk_check( 0, 'Fine' ) ) );
unset( $GLOBALS['__opt']['sn_betterstack_api_token'] );
ob_start(); call_user_func( $full_cb ); $no_token = ob_get_clean();
ok( strpos( $no_token, 'data-sn-uptime-status' ) === false, 'uptime: no section without a token (no prompt, no dead box)' );

$GLOBALS['__opt']['sn_betterstack_api_token'] = 'tok-abcdef123456';
ob_start(); call_user_func( $full_cb ); $with_token = ob_get_clean();
ok( strpos( $with_token, 'sn-uw-section' ) !== false && strpos( $with_token, 'data-sn-uptime-status' ) !== false, 'uptime: configured render appends the async mount section' );
ok( strpos( $with_token, 'tok-abcdef123456' ) === false, 'uptime: token never in widget markup' );
unset( $GLOBALS['__opt']['sn_betterstack_api_token'] );

// ═══ never triggers a scan (zero-cost render is a hard requirement) ═══
echo "\n-- never-scan guard --\n";
ok( empty( $GLOBALS['__scanned'] ), 'never-scan: no render path called sn_health_run_scan (index.php renders every login)' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
