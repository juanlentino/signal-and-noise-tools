<?php
/**
 * Both twins of the unmatched-paths report (2026-10-29): the classic
 * Integrity > Reports section (inc/health-render-unmatched.php) and the native
 * leaf (apps/sn-dashboard/parts/leaves/tools-reports-unmatched.php) say the
 * same words from one payload, escape a path on their own (not only because
 * the cleaner upstream is strict), never fold, and state an empty report.
 *
 * Run: php tests/os-leaf-tools-reports-unmatched.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers — stubbed rather than requiring inc/health-checks.php
// (which pulls in a long chain of per-check modules): these three are pure
// data accessors, and the fixtures below already pre-filter to the
// 'integrity' surface, so `sn_health_checks_for_surface()` need not
// re-derive the surface map.
$GLOBALS['__scan'] = null;
function sn_health_last_scan() {
	return $GLOBALS['__scan'];
}
function sn_health_checks_for_surface( $scan, $surface ) {
	unset( $surface );
	return is_array( $scan ) ? (array) ( $scan['checks'] ?? array() ) : array();
}
function sn_health_check_has_report( $check ) {
	return is_array( $check ) && ! empty( $check['report'] ) && is_array( $check['report'] );
}

// health-render-contrast.php reads this constant from inc/health-contrast-usage.php,
// which is not required here (it belongs to the scan producer, not the
// renderer) — defined directly to match the classic value.
if ( ! defined( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS' ) ) {
	define( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS', 25 );
}

// The link-isolation report's per-row "Edit" link — stubbed here (not by the
// harness) since only this leaf's report view reads it; a fixed, recognisable
// URL lets the assertions below tell "the door painted" from "nothing painted".
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}

require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/health-render-reports.php';

// #1599: the three Info-panel facts the leaf paints as a box. The JS-package
// reader over a stubbed script registry (the shape tests/script-package-origin.php
// uses), the override count stubbed (it lives in the classic Dashboard tab),
// and the footprint module real, its scan pointed at a fixture directory.
if ( ! function_exists( 'content_url' ) ) { function content_url( $p = '' ) { return 'https://example.test/wp-content' . $p; } }
$GLOBALS['__registered'] = array();
function wp_scripts() {
	$o             = new stdClass();
	$o->registered = array();
	foreach ( (array) $GLOBALS['__registered'] as $handle => $src ) {
		$dep                      = new stdClass();
		$dep->src                 = $src;
		$o->registered[ $handle ] = $dep;
	}
	return $o;
}
$GLOBALS['__overrides'] = 0;
function snt_dashboard_override_count() { return $GLOBALS['__overrides']; }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return time(); } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
require SNT_PATH . 'inc/script-package-origin.php';
require SNT_PATH . 'inc/plugin-footprint.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/tools-reports.php';
require SNT_PATH . 'inc/redirects-store.php';
require SNT_PATH . 'inc/redirects-404-log.php'; // SN_404_LIST_CAP, the classic table's cap.

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}

$row    = static fn( $path, $extra = array() ) => $extra + array( 'path' => $path, 'human' => 3, 'suspect' => 1, 'bot' => 0, 'first_seen' => '2026-10-02', 'last_seen' => '2026-10-20', 'status' => null, 'referrer' => null, 'suggest' => null, 'variant' => false );
$report = array(
	'people'          => array(
		$row( '/blog/the-free-version-already-shipped', array( 'status' => 404, 'referrer' => 'linkedin.com', 'suggest' => '/notes/the-free-version-already-shipped' ) ),
		$row( '/Notes/X', array( 'variant' => true, 'suggest' => '/notes/x', 'first_seen' => '2026-10-29', 'last_seen' => '2026-10-29' ) ),
		$row( '/x<b>y</b>&"q"' ),
	),
	'people_total'    => 3,
	'human_views'     => 9,
	'served'          => array( $row( '/provhub', array( 'status' => 200, 'served' => 'page' ) ), $row( '/notes/subscribe', array( 'status' => 301, 'served' => 'redirect' ) ) ),
	'served_total'    => 2,
	'automated'       => array( $row( '/wp-login.php', array( 'human' => 0, 'bot' => 6 ) ) ),
	'automated_total' => 1,
	'capped'          => false,
	'generated_at'    => 1700000000,
	'coverage'        => 'Every stored path that is not a note or page.',
);
$scan = static fn( $r ) => array( 'scanned_at' => 1700000000, 'checks' => array( 'unmatched_paths' => array( 'label' => 'Unmatched paths', 'count' => 0, 'findings' => array(), 'report' => $r ) ) );

$GLOBALS['__scan'] = $scan( $report );
$classic           = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit               = snt_leaf_paint( 'tools', 'reports' );
foreach ( array(
	'3 paths visitors reached are not a note or page (9 human views in all).',
	'/blog/the-free-version-already-shipped',
	'3 human · 1 suspect',
	'2026-10-02 to 2026-10-20',
	'404 · from linkedin.com',
	'Redirect to /notes/the-free-version-already-shipped?',
	'Same page, another spelling',
	'not in the 404 log',
	'No close match',
	'Served by the site',
	'/provhub',
	'served (200)',
	'redirects (301)',
	'Scanners and bots',
	'/wp-login.php',
	'6 bot',
) as $needle ) {
	ok( false !== strpos( $classic, $needle ) && false !== strpos( $kit, $needle ), "both twins say: $needle" );
}
ok( false === strpos( $classic, '<b>y' ) && false === strpos( $kit, '<b>y' ) && false !== strpos( $classic, '/x&lt;b&gt;y' ) && false !== strpos( $kit, '/x&lt;b&gt;y' ), 'a path with markup is escaped by each renderer on its own' );
$between = static fn( $h ) => (string) strstr( substr( $h, (int) strrpos( $h, 'Served by the site' ) ), 'Scanners and bots', true ); // the last mention: the coverage line names it too.
ok( '' !== $between( $classic ) && '' !== $between( $kit ) && false === strpos( $between( $classic ), 'No close match' ) && false === strpos( $between( $kit ), 'No close match' ), 'the served list carries a status and no suggested fix, on both twins' );
ok( array() === snt_leaf_folds( $kit ) && false === strpos( $classic, '<details' ), 'no fold on either twin' );
ok( false === strpos( $kit, 'This report has no detail view yet' ), 'the native leaf has its own view for this report' );
ok( false === strpos( $kit, '—' ) && false === strpos( $classic, ' — ' ), 'no em dash on either twin' );
ok( 0 === preg_match( '/<a [^>]*>[^<]*\/blog\/the-free/', $classic . $kit ), 'a visitor path is never a link' );

// Review 2026-10-29: "+N more" counts from the true total, not the 100 rows the scan keeps.
$GLOBALS['__scan'] = $scan( array( 'people_total' => 250, 'automated_total' => 40 ) + $report );
$kit               = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $kit, '+247 more paths' ) && false !== strpos( $kit, '+39 more paths' ), 'the native "+N more" counts from people_total and automated_total' );

// Review 2026-10-30: nothing broken, but served paths: the headline says so, not "no visitor".
$GLOBALS['__scan'] = $scan( array( 'people' => array(), 'people_total' => 0, 'human_views' => 0 ) + $report );
$classic           = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit               = snt_leaf_paint( 'tools', 'reports' );
$none_broken       = 'None of these paths answers with an error: the 2 paths the site serves or already redirects are listed below.';
ok( false !== strpos( $classic, $none_broken ) && false !== strpos( $kit, $none_broken ) && false === strpos( $kit, 'No visitor reached' ), 'no broken path but served ones: both twins say so, never "No visitor reached"' );

$GLOBALS['__scan'] = $scan( array( 'people' => array(), 'people_total' => 0, 'human_views' => 0, 'automated' => array(), 'automated_total' => 0, 'capped' => false, 'generated_at' => 1700000000, 'coverage' => 'x' ) );
$classic           = snt_leaf_classic_html( 'sn_admin_render_health_reports_section' );
$kit               = snt_leaf_paint( 'tools', 'reports' );
ok( false !== strpos( $classic, 'No visitor reached a path that is not a note or page.' ) && false !== strpos( $kit, 'No visitor reached a path that is not a note or page.' ), 'an empty report says so on both twins' );
ok( false !== strpos( $classic, 'None among the paths checked.' ) && false !== strpos( $kit, 'None among the paths checked.' ), 'an empty served list says the same words on both twins' );

echo ( 0 === $fail ) ? "\nOK ($pass passed, $fail failed): os-leaf-tools-reports-unmatched.php\n" : "\nFAILURES ($pass passed, $fail failed): os-leaf-tools-reports-unmatched.php\n";
exit( $fail > 0 ? 1 : 0 );
