<?php
/**
 * Tests for inc/maturity-roadmap-shortcode.php — [sn_maturity_roadmap],
 * the HUB-WIDE roadmap BOARD (family rows × done/planned/considering
 * columns). Mirrors the maturity-sibling fixture, PLUS the family's
 * SECURITY CONTRACT sweep: the rendered page must never leak option
 * names, endpoint paths, tool/change-type slugs, or meta keys.
 * Run: php tests/maturity-roadmap-shortcode.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function remove_all_filters( $tag ) { unset( $GLOBALS['__filters'][ $tag ] ); }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__enq'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( $handle, (string) $src );
	return true;
}
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function wp_json_encode( $d, $opts = 0 ) { return json_encode( $d, $opts ); }
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' );
}

require __DIR__ . '/../inc/maturity-roadmap-shortcode.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "[sn_maturity_roadmap] — the hub-wide roadmap BOARD\n\n";

// Registration + statuses whitelist.
ok( isset( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] ), 'shortcode registered' );
ok( array( 'done', 'planned', 'considering', 'later' ) === SN_MATURITY_ROADMAP_STATUSES, 'exactly the four roadmap statuses, in walk order (v10.73.0 adds later)' );

// Default render: wide wrapper, board table, status header badges, stylesheet.
$html = call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] );
ok( false !== strpos( $html, '<div class="sn-maturity-roadmap sn-maturity-roadmap--wide alignfull">' ), 'renders the WIDE wrapper riding alignfull — the constrained layout\'s own exemption, so the width is real (a margin breakout measured 760px live)' );
ok( false !== strpos( $html, '<table class="sn-maturity-roadmap-board" id="sn-maturity-roadmap-board">' ), 'renders the board table (with the legend\'s anchor id)' );
foreach ( array( 'done', 'planned', 'considering' ) as $status ) {
	ok( false !== strpos( $html, 'sn-maturity-roadmap-badge--' . $status ), "the '$status' column header carries its badge" );
	ok( false !== strpos( $html, 'sn-maturity-roadmap-board__cell--' . $status ), "cells carry the '$status' class" );
}
ok( ! empty( $GLOBALS['__enq'] ) && 'sn-maturity-roadmap-front' === $GLOBALS['__enq'][0][0], 'enqueues its own front stylesheet' );

// HUB-WIDE coverage: every family is a board row.
$families = array( 'Analytics', 'Proof of origin', 'AI', 'Machine learning', 'Machine readability', 'Accessibility', 'Operations' );
foreach ( $families as $family ) {
	ok( false !== strpos( $html, '>' . $family . '</td>' ), "the '$family' family has a board row" );
}
ok( 7 === substr_count( $html, 'sn-maturity-roadmap-board__family"' ), 'exactly seven family rows' );
ok( 28 === substr_count( $html, 'sn-maturity-roadmap-board__cell ' ), 'exactly 7×4 status cells (v10.73.0: the later column)' );

// Empty cells render the honest em-dash (ops planned, and a11y
// considering — emptied when both its ideas graduated to planned) — a
// family with no future tense is information, not a gap. v10.71.1:
// machine-readability planned is no longer empty; the live-read row
// landed there when the static floor was resynced to the override.
ok( 1 === substr_count( $html, 'sn-maturity-roadmap-board__empty' ), 'exactly one empty cell renders the em-dash (v10.72.1: Operations planned populated)' );

// v10.63.0 "fold the future": the legend trio + counts + folds.
$counts = sn_maturity_roadmap_counts( sn_maturity_roadmap_effective_board() );
ok( false !== strpos( $html, 'sn-maturity-roadmap-legend' ), 'the count-trio legend renders above the board' );
foreach ( array( 'done', 'planned', 'considering' ) as $status ) {
	ok( false !== strpos( $html, 'sn-maturity-roadmap-legend__cell--' . $status ), "the legend has a '$status' cell" );
	ok( false !== strpos( $html, '<span class="sn-maturity-roadmap-legend__stat">' . $counts[ $status ] . '</span>' ), "the legend's '$status' stat carries the computed count ({$counts[$status]})" );
}
ok( false !== strpos( $html, 'id="sn-maturity-roadmap-board"' ), 'the board carries the id the legend anchors to' );
ok( false !== strpos( $html, 'sn-maturity-roadmap-badge__n' ), 'header badges carry their counts' );
// Done NEVER folds — the record is the page's argument; the future tenses
// fold per cell, summaries carrying their counts. 7 families × up to 2
// future cells, minus the 2 empties = 12 folds on the static board.
ok( false === strpos( $html, 'cell--done" data-label="Done"><details' ), 'a done cell never folds' );
ok( 20 === substr_count( $html, '<details class="sn-maturity-roadmap-fold">' ), 'every populated future cell folds (20 on this board, v10.73.0: 7 planned + 6 considering + 7 later)' );

// v10.71.1: no sentence may appear in two columns of the same family.
// The static floor had the agent threat model in BOTH 'done' and
// 'considering' — the row graduated, was retired from the override, and
// was left standing here, so the floor proposed as an idea the thing it
// claimed as shipped one column to the left. An item moves; it is never
// copied. Checked on the RAW board, not the render, so the message can
// name the offender.
$dupes = array();
foreach ( sn_maturity_roadmap_static_board() as $family => $columns ) {
	$seen = array();
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		foreach ( ( $columns[ $status ] ?? array() ) as $item ) {
			if ( isset( $seen[ $item ] ) ) {
				$dupes[] = $family . ': "' . substr( $item, 0, 60 ) . '…" in both ' . $seen[ $item ] . ' and ' . $status;
			}
			$seen[ $item ] = $status;
		}
	}
}
ok( array() === $dupes, 'no item sits in two columns of one family — a row moves, it is never copied' . ( $dupes ? ' — FOUND: ' . implode( '; ', $dupes ) : '' ) );
ok( false !== strpos( $html, '3 considering</summary>' ), "a fold summary carries its item count" );
ok( substr_count( $html, 'sn-maturity-roadmap-fold__glyph' ) === substr_count( $html, '<details class="sn-maturity-roadmap-fold">' ), 'every fold has its glyph, aria-hidden decoration only' );

// Load-bearing copy: gates named on plans, nevers restated inline.
ok( false !== strpos( $html, 'no new collection' ), 'the public-stats-page plan names its gate' );
ok( false !== strpos( $html, 'once that runner is stable' ), 'the agents migration names its gate' );
ok( false !== strpos( $html, 'without ever profiling a reader' ), 'the traffic-rhythm idea restates the profiling never inline' );
ok( false !== strpos( $html, 'sentence-scale change' ), 'the staged-edit done item is present in prose' );

// SECURITY CONTRACT: no option names, endpoint paths, tool/change-type
// slugs, or meta keys on the public page — the family's leak-proof sweep.
foreach ( array( 'sn_mcp', 'snt_', '_sn_', 'wp-json', 'sn_apply', 'sn-apply', 'sentence_replace', 'restore_revision', 'openstation', 'desktop_mode', 'MCP' ) as $token ) {
	ok( false === strpos( $html, $token ), "leak sweep: '$token' never reaches the page" );
}

// Filter seam: the board is owner-editable; unknown statuses never
// render; content is escaped at build, family labels included.
add_filter( 'sn_maturity_roadmap_board', function ( $board ) {
	return array(
		'Family <b>x</b>' => array(
			'done'  => array( 'Custom <script>alert(1)</script> item' ),
			'bogus' => array( 'Never rendered' ),
		),
	);
} );
$html2 = call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] );
ok( false !== strpos( $html2, 'Custom &lt;script&gt;' ) && false === strpos( $html2, '<script>' ), 'filtered items render escaped — markup never survives' );
ok( false !== strpos( $html2, 'Family &lt;b&gt;' ), 'the family label is escaped too' );
ok( false === strpos( $html2, 'Never rendered' ) && false === strpos( $html2, 'bogus' ), 'a status outside the whitelist never renders' );
ok( 3 === substr_count( $html2, 'sn-maturity-roadmap-board__empty' ), 'the statuses the filter omitted render as honest em-dashes, not collapsed cells (3 of 4 omitted, v10.73.0)' );
remove_all_filters( 'sn_maturity_roadmap_board' );

/* ── Board-as-data: the option override (written only by sn_apply's
 * 'roadmap_board' change type) replaces the static board when VALID,
 * falls back silently when not, and the filter seam still applies on
 * top. The fingerprint binds to the pre-filter effective board. ── */
echo "\nBoard-as-data: option override + fallback + fingerprint\n";

$static_html = call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] );
$static_fp   = sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() );

// A VALID override replaces the static board wholesale.
$override = array(
	'Analytics' => array( 'done' => array( 'An override sentence the static board never contained' ), 'planned' => array(), 'considering' => array() ),
	'AI'        => array( 'done' => array(), 'planned' => array( 'Another override-only sentence' ), 'considering' => array() ),
);
update_option( SN_MATURITY_ROADMAP_OPTION, $override );
$html3 = call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] );
ok( false !== strpos( $html3, 'An override sentence the static board never contained' ), 'a valid override renders its own copy' );
ok( 2 === substr_count( $html3, 'sn-maturity-roadmap-board__family"' ), 'the override replaces the board WHOLESALE (two families render, not seven)' );
ok( false === strpos( $html3, 'cookieless measurement' ), 'static copy does not bleed through a valid override' );
ok( sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ) !== $static_fp, 'the fingerprint moves when the effective board moves' );

// An INVALID override (unknown status) falls back to the static board.
update_option( SN_MATURITY_ROADMAP_OPTION, array( 'Analytics' => array( 'bogus' => array( 'x' ) ) ) );
ok( $static_html === call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] ), 'an invalid override is IGNORED wholesale — render byte-identical to static' );

// Markup and banned internal tokens are validation problems (the write
// gate's rejection list), so an override carrying them can never render.
ok( array() !== sn_maturity_roadmap_board_problems( array( 'F' => array( 'done' => array( 'has <b>markup</b>' ) ) ) ), 'markup in an item is a validation problem' );
ok( array() !== sn_maturity_roadmap_board_problems( array( 'F' => array( 'done' => array( 'mentions snt_ internals' ) ) ) ), 'a banned internal token in an item is a validation problem' );
ok( array() !== sn_maturity_roadmap_board_problems( array() ), 'an empty board is a validation problem (fallback, never a blank page)' );
ok( array() === sn_maturity_roadmap_board_problems( sn_maturity_roadmap_static_board() ), 'the static board itself passes the validator (parity: what ships is what the gate would accept)' );

// delete_option returns the page to code-canonical.
delete_option( SN_MATURITY_ROADMAP_OPTION );
ok( $static_html === call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] ), 'deleting the override returns the render to code-canonical, byte-identical' );
ok( sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ) === $static_fp, 'and the fingerprint returns with it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
