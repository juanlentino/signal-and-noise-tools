<?php
/**
 * Tests: the Search analytics view always renders something.
 *
 * Run: php tests/analytics-view-search.php
 *
 * THE REGRESSION THIS EXISTS FOR (shipped in v11.19.0, caught by the owner):
 * all three setup states called snt_an_note_empty() and returned. That helper
 * COLLECTS into a fold which each view flushes at its END — so an early return
 * queued a note nobody emitted and the tab rendered BLANK. Every assertion here
 * is "output is non-empty", because the failure mode was silence, not wrongness.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__configured'] = false;
$GLOBALS['__property']   = '';
$GLOBALS['__data']       = null;

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function sn_setting( $p, $d = null ) { return 'search_console.property' === $p ? $GLOBALS['__property'] : $d; }
function snt_gsc_credential_is_configured() { return (bool) $GLOBALS['__configured']; }
function snt_gsc_data() { return $GLOBALS['__data']; }

// The REAL collector semantics, reproduced: note_empty collects, flush emits.
// Stubbing note_empty as "echo" would hide the very bug under test.
function snt_an_note_empty( $title, $why = '' ) { $GLOBALS['__fold'][] = array( $title, $why ); }
function snt_an_flush_empty_fold() {
	if ( ! empty( $GLOBALS['__fold'] ) ) { echo '<p class="sn-an-empty-fold">flushed ' . count( $GLOBALS['__fold'] ) . '</p>'; }
	$GLOBALS['__fold'] = array();
}
function snt_an_panel_open( $title, $args = array() ) { echo '<div class="postbox"><h2>' . esc_html( $title ) . '</h2><div class="inside">'; }
function snt_an_panel_close() { echo '</div></div>'; }

// v13.82.0: the side-by-side row. Stubbed as real markers so assertions can read
// WHICH panels fell inside the row, not merely that a wrapper was emitted.
function snt_an_cols_open() { echo '<div class="sn-an-cols">'; }
function snt_an_cols_close() { echo '</div><!--/cols-->'; }
$GLOBALS['__topic'] = null;
function snt_gsc_topic_interest() { return $GLOBALS['__topic']; }

// v13.81.0: the KPI strip. Stubbed to render a MARKER carrying the card labels
// and descriptors, so assertions can read both the values and the ORDER the
// strip appears in relative to the panel it lives inside.
$GLOBALS['__kpi'] = null;
function snt_an_kpi_row( $cards, $opts = array() ) {
	$GLOBALS['__kpi'] = $cards;
	echo '<div class="sn-kpi">';
	foreach ( (array) $cards as $c ) {
		echo '[' . esc_html( $c['l'] ) . '=' . esc_html( $c['n'] ) . '|' . esc_html( $c['sub'] ?? '' ) . ']';
	}
	echo '</div>';
}
$GLOBALS['__totals']   = null;
$GLOBALS['__coverage'] = null;
function snt_gsc_window_totals() { return $GLOBALS['__totals']; }
function snt_gsc_coverage_data() { return $GLOBALS['__coverage']; }
function snt_gsc_coverage_summary( $d, $inbound = null ) { return is_array( $d ) ? $d : null; }

require __DIR__ . '/../inc/analytics-view-search.php';

function render() {
	$GLOBALS['__fold'] = array();
	ob_start();
	snt_analytics_render_view_search();
	return (string) ob_get_clean();
}

echo "Group: every state renders VISIBLE output\n";

$GLOBALS['__configured'] = false; $GLOBALS['__property'] = ''; $GLOBALS['__data'] = null;
$out = render();
ok( '' !== trim( $out ), 'no credential -> renders something (this was BLANK in v11.19.0)' );
ok( false !== strpos( $out, 'Measurement' ), 'and names where to go' );

$GLOBALS['__configured'] = true;
$out = render();
ok( '' !== trim( $out ), 'credential but no property -> renders something' );
ok( false !== strpos( $out, 'Test connection' ), 'and names the exact next action' );

$GLOBALS['__property'] = 'sc-domain:x.test';
$out = render();
ok( '' !== trim( $out ), 'property but never synced -> renders something' );
ok( false !== strpos( $out, 'Sync now' ), 'and names the sync action' );

echo "\nGroup: with data, the tables render and the fold is flushed\n";
$GLOBALS['__data'] = array(
	'synced_at' => 1000,
	'property'  => 'sc-domain:x.test',
	'window'    => array( 'start' => '2026-07-01', 'end' => '2026-07-28' ),
	'pages'     => array(
		'/a' => array( 'clicks' => 5,  'impressions' => 100, 'ctr' => 0.05, 'position' => 4.2 ),
		'/b' => array( 'clicks' => 0,  'impressions' => 900, 'ctr' => 0.0,  'position' => 31.7 ),
	),
	'queries'   => array( array( 'key' => 'provenance', 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 6.5 ) ),
);
$out = render();
ok( '' !== trim( $out ), 'with data -> renders' );
ok( false !== strpos( $out, 'provenance' ), 'the query table renders its rows' );
ok( false !== strpos( $out, '/b' ), 'the pages table renders its rows' );
ok( false !== strpos( $out, 'sc-domain:x.test' ), 'the header names the property' );
ok( false !== strpos( $out, '2026-07-01' ), "and Google's window, which is NOT the dashboard range" );
ok( false !== strpos( $out, 'does NOT follow the date range' ), 'and says so explicitly, so a stored window is never read as the selected range' );

echo "\nGroup: units reach the screen correctly\n";
// 0.05 must render as 5.0%, not 0.1% and not 5000%.
ok( false !== strpos( $out, '5.0%' ), 'ctr 0.05 renders as 5.0% — a FRACTION, not a percentage already' );
ok( false === strpos( $out, '0.1%' ), 'and is not mistaken for an already-percentage value' );
ok( false !== strpos( $out, '31.7' ), 'position renders with one decimal' );

echo "\nGroup: the zero-click list is the point of the tab\n";
// /b has 900 impressions and 0 clicks; it has NO first-party row by definition,
// so it can only ever appear here — never in a merged table.
ok( false !== strpos( $out, 'Seen but never clicked' ), 'the zero-click section renders' );
$seen_at = strpos( $out, 'Seen but never clicked' );
ok( false !== strpos( substr( $out, (int) $seen_at ), '/b' ), 'and /b (900 impressions, 0 clicks) is in it' );
ok( false === strpos( substr( $out, (int) $seen_at ), '/a' ), 'while /a (which earned clicks) is not' );

echo "\nGroup: the fold contract is kept\n";
$GLOBALS['__data']['queries'] = array();
$GLOBALS['__data']['pages']   = array();
$out = render();
ok( false !== strpos( $out, 'sn-an-empty-fold' ), 'empty tables collect notes AND the view flushes them — omitting the flush is what rendered blank' );
ok( array() === $GLOBALS['__fold'], 'the collector is left empty, so notes cannot leak into the next view' );


echo "\nGroup: the KPI strip\n";

$GLOBALS['__configured'] = true; $GLOBALS['__property'] = 'https://x/';
$GLOBALS['__data'] = array(
	'property' => 'https://x/', 'synced_at' => 1, 'window' => array( 'start' => '2026-08-03', 'end' => '2026-08-30' ),
	'queries' => array(), 'pages' => array(),
);
$GLOBALS['__totals']   = array( 'clicks' => 3, 'impressions' => 233, 'days' => 28, 'capped' => false );
$GLOBALS['__coverage'] = array( 'inspected' => 37, 'indexed' => 24, 'not_indexed' => 13 );
$out = render();

ok( null !== $GLOBALS['__kpi'], 'the strip renders when totals are present' );
ok( 3 === count( $GLOBALS['__kpi'] ), 'three cards: impressions, clicks, indexed' );
ok( false !== strpos( $out, '[Impressions=233|over 28 days]' ), 'impressions carries its window length' );
ok( false !== strpos( $out, '[Clicks=3|1.3% of impressions]' ), 'CTR is the DESCRIPTOR, not a fourth card — it is derived from the two beside it' );
ok( false !== strpos( $out, '[Indexed=24 of 37|13 not indexed]' ), 'coverage states indexed of inspected' );

// STRUCTURE: the strip sits INSIDE the Window panel, below the caveat. A number
// read against the wrong window is worse than no number.
$kpi_at   = strpos( $out, '<div class="sn-kpi">' );
$caveat   = strpos( $out, 'does NOT follow the date range' );
$close_at = strpos( $out, '</div></div>', (int) $caveat );
ok( false !== $kpi_at && $caveat < $kpi_at, 'the strip renders BELOW the window caveat' );
ok( $kpi_at < $close_at, 'and INSIDE the Window panel, not floating after it' );

// CAPPED is surfaced, not hidden: the page dimension is API-capped at 250 rows,
// so past that the sum undercounts in a known direction while looking exact.
$GLOBALS['__totals'] = array( 'clicks' => 3, 'impressions' => 233, 'days' => 28, 'capped' => true );
$out = render();
ok( false !== strpos( $out, 'floor' ), 'a capped total says FLOOR rather than presenting as exact' );
ok( false === strpos( $out, '[Impressions=233|over 28 days]' ), 'and drops the unqualified descriptor' );

// Coverage is OMITTED, not blanked, when it has not synced — it runs weekly.
$GLOBALS['__totals'] = array( 'clicks' => 3, 'impressions' => 233, 'days' => 28, 'capped' => false );
$GLOBALS['__coverage'] = null;
$out = render();
ok( 2 === count( $GLOBALS['__kpi'] ), 'an unsynced coverage read omits its card rather than showing a blank' );
ok( false === strpos( $out, 'Indexed' ), 'and nothing named Indexed appears' );

// Zero impressions must not divide by zero.
$GLOBALS['__totals'] = array( 'clicks' => 0, 'impressions' => 0, 'days' => 28, 'capped' => false );
$out = render();
ok( false !== strpos( $out, 'no impressions to divide by' ), 'zero impressions says so instead of computing a CTR' );

// No totals at all: the strip is absent, and the rest of the view still renders.
$GLOBALS['__totals'] = null;
$GLOBALS['__kpi']    = null;
$out = render();
ok( null === $GLOBALS['__kpi'], 'no totals -> no strip' );
ok( false !== strpos( $out, 'Window' ), 'and the view still renders its panels' );



echo "\nGroup: the side-by-side row\n";

$GLOBALS['__configured'] = true; $GLOBALS['__property'] = 'https://x/';
$GLOBALS['__data'] = array(
	'property' => 'https://x/', 'synced_at' => 1, 'window' => array( 'start' => '2026-08-03', 'end' => '2026-08-30' ),
	'queries' => array( array( 'key' => 'provhub', 'clicks' => 0, 'impressions' => 15, 'ctr' => 0, 'position' => 6.2 ) ),
	// Pages must be NON-EMPTY or the panel never renders and the assertion that
	// it stays OUTSIDE the row passes through its own `false ===` branch,
	// proving nothing. Caught by a mutation that moved it inside and stayed green.
	'pages' => array( '/notes/where-ai-actually-saves-time-in-record-production' => array( 'clicks' => 0, 'impressions' => 3, 'ctr' => 0, 'position' => 6.3 ) ),
);
$GLOBALS['__totals'] = array( 'clicks' => 3, 'impressions' => 233, 'days' => 28, 'capped' => false );
$GLOBALS['__topic']  = array( array( 'topic' => 'stamps', 'notes' => 2, 'impressions' => 0, 'clicks' => 0, 'position' => null ) );
$out = render();

$open  = strpos( $out, '<div class="sn-an-cols">' );
$close = strpos( $out, '</div><!--/cols-->' );
ok( false !== $open && false !== $close, 'the row opens and closes' );

// The PAIRING is the point: both small panels must fall INSIDE the row.
$q = strpos( $out, 'Top search queries' );
$t = strpos( $out, 'Search interest by topic' );
ok( $open < $q && $q < $close, 'Top search queries is inside the row' );
ok( $open < $t && $t < $close, 'Search interest by topic is inside the row' );

// And the LONG-PATH tables must stay outside it — halving those wraps badly.
$pages = strpos( $out, 'Pages by impressions' );
ok( false === $pages || $pages > $close, 'Pages by impressions stays OUTSIDE the row, at full width' );

// The row degrades to one panel rather than breaking when a member is absent.
$GLOBALS['__topic'] = null;
$out = render();
$open  = strpos( $out, '<div class="sn-an-cols">' );
$close = strpos( $out, '</div><!--/cols-->' );
ok( false !== $open && false !== $close, 'the row still renders with only one member' );
ok( false === strpos( $out, 'Search interest by topic</h2>' ), 'and the absent panel emits no heading' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
