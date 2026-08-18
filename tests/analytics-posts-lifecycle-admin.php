<?php
/**
 * Tests for inc/analytics-posts-lifecycle-admin.php — the catalogue lifecycle
 * section render, including the annotation callout wired in v9.4.0. The render
 * fn had no test harness before; this adds one, anchored on the annotation.
 *
 * @since plugin v9.4.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ── WP stubs the section/table/pill render chain needs ──
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-posts-lifecycle-admin.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "analytics-posts-lifecycle-admin render suite (v9.4.0)\n";

echo "\nTest: empty catalogue folds to the empty state (no annotation)\n";
$empty = cap( function () { snt_analytics_render_lifecycle_section( array( 'rows' => array(), 'summary' => array() ) ); } );
ok( false !== strpos( $empty, 'No catalogue data yet' ), 'empty rows -> empty-state note' );
ok( false === strpos( $empty, 'sn-an-note' ), 'empty catalogue -> no annotation callout' );
// v9.40.0 D4: the empty idiom adopts the unified snt_an_gate() — its old bare
// postbox already carried the "Lifecycle at scale" title; that title is preserved.
ok( false !== strpos( $empty, 'sn-an-gate' ), 'empty catalogue -> unified gate marker present' );
ok( false !== strpos( $empty, '<span>Lifecycle at scale</span>' ), 'empty catalogue -> gate preserves the "Lifecycle at scale" title' );

echo "\nTest: catalogue with refresh candidates renders the annotation callout (integration)\n";
$mk_row = function ( $decay, $cand ) {
	return array( 'id' => 1, 'title' => 'X', 'permalink' => '/x', 'age' => 200, 'lifetime' => 50, 'per_day' => 0.2, 'decay' => $decay, 'evergreen' => false, 'refresh_candidate' => $cand, 'modified_ts' => 0 );
};
$rows = array();
for ( $i = 0; $i < 20; $i++ ) {
	$rows[] = $mk_row( $i < 4 ? 'cooling' : 'evergreen', $i < 3 );
}
$lifecycle = array(
	'rows'    => $rows,
	'summary' => array( 'counts' => array( 'spike' => 0, 'cooling' => 4, 'sustained' => 16, 'unknown' => 0 ), 'refresh_candidates' => 3, 'total' => 20 ),
);
$html = cap( function () use ( $lifecycle ) { snt_analytics_render_lifecycle_section( $lifecycle ); } );
ok( false !== strpos( $html, 'sn-an-note' ), 'render integration: catalogue with candidates emits the annotation callout' );
ok( false !== strpos( $html, '4 of 20 posts are cooling, and 3 are refresh candidates.' ), 'render integration: callout carries the lifecycle read for the summary' );
// v9.40.0 D4: the glance cards now route through the shared snt_an_kpi_row
// primitive — pin the row + the candidate-count-derived sub_class (down when
// refresh_candidates > 0).
ok( false !== strpos( $html, 'sn-kpi-row' ) && false !== strpos( $html, 'sn-delta-down">cooling, not evergreen' ), 'glance renders via the shared KPI row primitive (down class when candidates > 0)' );
// v9.40.0 D4: both postboxes in this section now route through the shared panel
// primitive — glance keeps sn-overview (KPI-scale contract); refresh queue is plain.
ok( false !== strpos( $html, 'class="postbox sn-an-postbox sn-overview"' ), 'glance panel adopts the primitive + keeps sn-overview' );
ok( false !== strpos( $html, 'class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>Refresh queue' ),
	'refresh-queue panel adopts the primitive (plain, no sn-overview)' );

echo "\nTest: refresh-queue table — byte-parity pin (v9.43.0, pre-kv_table-migration)\n";
// Literal capture of snt_analytics_render_lifecycle_table()'s FULL output under
// a fixed, hostile-char fixture, taken from the plugin at v9.43.0 before the
// kv_table column-spec migration. Covers all three Shape/Status cell shapes
// (decay text, decay em-dash fallback, refresh pill, evergreen pill, muted
// dash) plus the truncation-footer branch (n=3 rows, total=10). MUST pass
// unchanged both before and after the migration.
$parity_rows = array(
	array( 'id' => 1, 'title' => 'Cool & <Post>', 'permalink' => '/x/?a=1&b=2', 'age' => 200, 'lifetime' => 50, 'per_day' => 0.2, 'decay' => 'cooling', 'evergreen' => false, 'refresh_candidate' => true ),
	array( 'id' => 2, 'title' => 'Evergreen One', 'permalink' => '/y/', 'age' => 400, 'lifetime' => 900, 'per_day' => 2.25, 'decay' => 'sustained', 'evergreen' => true, 'refresh_candidate' => false ),
	array( 'id' => 3, 'title' => 'Plain One', 'permalink' => '/z/', 'age' => 10, 'lifetime' => 5, 'per_day' => 0.5, 'decay' => '', 'evergreen' => false, 'refresh_candidate' => false ),
);
$parity = cap( function () use ( $parity_rows ) { snt_analytics_render_lifecycle_table( $parity_rows, count( $parity_rows ) ); } );
ok(
	'<div class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>Refresh queue</span></h2></div><div class="inside sn-an-table-inside"><table class="wp-list-table widefat striped"><thead><tr><th scope="col" class="manage-column column-primary">Post</th><th scope="col" class="manage-column num">Lifetime</th><th scope="col" class="manage-column num">Per day</th><th scope="col" class="manage-column">Shape</th><th scope="col" class="manage-column">Status</th></tr></thead><tbody><tr><td class="column-primary" data-colname="Post"><a href="/x/?a=1&b=2"><strong>Cool &amp; &lt;Post&gt;</strong></a> <span class="sn-an-muted">200d</span></td><td class="num" data-colname="Lifetime">50</td><td class="num" data-colname="Per day">0</td><td data-colname="Shape">cooling</td><td data-colname="Status"><span class="sn-pill sn-pill--warn">Refresh</span></td></tr><tr><td class="column-primary" data-colname="Post"><a href="/y/"><strong>Evergreen One</strong></a> <span class="sn-an-muted">400d</span></td><td class="num" data-colname="Lifetime">900</td><td class="num" data-colname="Per day">2</td><td data-colname="Shape">sustained</td><td data-colname="Status"><span class="sn-pill sn-pill--ok">Evergreen</span></td></tr><tr><td class="column-primary" data-colname="Post"><a href="/z/"><strong>Plain One</strong></a> <span class="sn-an-muted">10d</span></td><td class="num" data-colname="Lifetime">5</td><td class="num" data-colname="Per day">1</td><td data-colname="Shape"><span class="sn-an-muted">&mdash;</span></td><td data-colname="Status"><span class="sn-an-muted" aria-hidden="true">&mdash;</span></td></tr></tbody></table></div></div>' === $parity,
	'refresh queue: full-string byte-parity pin holds (hostile title escapes, decay text/em-dash-fallback, Refresh/Evergreen/muted-dash pills)'
);

$parity_truncated = cap( function () use ( $parity_rows ) { snt_analytics_render_lifecycle_table( $parity_rows, 10 ); } );
ok(
	false !== strpos( $parity_truncated, '</table><p class="sn-an-foot">Showing the top 3 of 10 posts (refresh candidates first).</p></div></div>' ),
	'refresh queue: truncation footer renders inside the SAME panel, after </table>, before close — byte-parity holds with $total > count($shown)'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
