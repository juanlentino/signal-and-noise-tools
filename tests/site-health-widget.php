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
ok( sn_health_finding_total( $scan3 ) === 5, 'finding_total: sums every check count (3+2+0=5)' );
$only_ext = mk_scan( array( 'external_links' => mk_check( 4, 'External link rot' ) ) );
ok( sn_health_finding_total( $only_ext ) === 4, 'finding_total: external link rot counts as a finding (parity with attention strip + Health tab)' );

// ═══ sn_health_flagged_checks() ═══
echo "\n-- accessor: sn_health_flagged_checks --\n";
ok( sn_health_flagged_checks( null ) === array(), 'flagged_checks: null scan -> empty' );
$flagged = sn_health_flagged_checks( $scan3 );
ok( count( $flagged ) === 2, 'flagged_checks: only checks with count>0 (2 of 3)' );
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
ok( isset( $GLOBALS['__widgets']['sn_site_health']['cb'] ) && $GLOBALS['__widgets']['sn_site_health']['cb'] === 'sn_site_health_widget_render', 'register: render callback wired' );

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
ok( stripos( $f, '12 finding' ) !== false, 'findings: headline total (3+7+2=12 findings)' );
ok( stripos( $f, '3 of 4' ) !== false, 'findings: subline "3 of 4 checks" flagged' );
ok( strpos( $f, 'sn-aw-list' ) !== false, 'findings: ranked list present' );
$p_broken = strpos( $f, 'Broken internal links' );
$p_alt    = strpos( $f, 'Missing alt text' );
$p_ext    = strpos( $f, 'External link rot' );
ok( false !== $p_broken && false !== $p_alt && false !== $p_ext && $p_broken < $p_alt && $p_alt < $p_ext, 'findings: list ranked by count desc (7 -> 3 -> 2)' );
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

// ═══ never triggers a scan (zero-cost render is a hard requirement) ═══
echo "\n-- never-scan guard --\n";
ok( empty( $GLOBALS['__scanned'] ), 'never-scan: no render path called sn_health_run_scan (index.php renders every login)' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
