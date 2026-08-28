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

require __DIR__ . '/../inc/maturity-roadmap-merge.php'; // sn_maturity_roadmap_effective_board() now reads through the three-way merge
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
// 2026-08-12: ZERO empty cells — the last one (Accessibility considering)
// took the embeds decline. A full board is the current truth, not a rule;
// this pin moves whenever a cell honestly empties again.
// 2026-08-14: ONE empty cell again — ML considering emptied when both R4 rows
// (drift, reading paths) graduated to done in the v11.2.0/v11.3.0 pair. An
// honestly empty cell beats a padded one; this pin moves whenever a cell
// honestly empties or fills.
ok( 0 === substr_count( $html, 'sn-maturity-roadmap-board__empty' ), 'NO empty cell on this board — v13.18.0 refilled ML considering, the last one, when the override folded into the floor' );

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
ok( 21 === substr_count( $html, '<details class="sn-maturity-roadmap-fold">' ), 'every populated future cell folds (21 on this board, v13.18.0: 7 planned + 7 considering + 7 later — no future cell is empty any more)' );

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

// v10.73.1: the legend's grid tracks the status count — a hardcoded track
// count orphans the newest column onto its own row (shipped broken in
// v10.73.0, owner-caught on the live page). Pin the CSS to the constant so
// adding a fifth status REDS this line instead of the live page.
$css = (string) file_get_contents( __DIR__ . '/../assets/maturity-roadmap-front.css' );
ok( false !== strpos( $css, 'grid-template-columns:repeat(' . count( SN_MATURITY_ROADMAP_STATUSES ) . ',1fr)' ),
	'the legend grid declares one track per status (' . count( SN_MATURITY_ROADMAP_STATUSES ) . ')' );
ok( false === strpos( $css, 'grid-template-columns:repeat(3,1fr)' ), 'no stale three-track legend grid survives' );
ok( false !== strpos( $html, '3 considering</summary>' ), "a fold summary carries its item count" );
ok( substr_count( $html, 'sn-maturity-roadmap-fold__glyph' ) === substr_count( $html, '<details class="sn-maturity-roadmap-fold">' ), 'every fold has its glyph, aria-hidden decoration only' );

// Load-bearing copy: gates named on plans, nevers restated inline.
ok( false !== strpos( $html, 'no new collection' ), 'the public-stats-page plan names its gate' );
ok( false !== strpos( $html, 'once that runner is stable' ), 'the agents migration names its gate' );
// 2026-08-12: the row promoted considering -> planned and was reworded to name
// its gate; the CLAIM this pin protects (the profiling never stated inline)
// survives in the new phrasing, so the pin follows the claim, not the old words.
ok( false !== strpos( $html, 'never profiling a reader' ), 'the traffic-rhythm row restates the profiling never inline' );
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

/* ── v10.76.0: the 'done' column's own ceiling.
 *
 * 'done' is the only column that grows monotonically, and since v10.63.0
 * ("fold the future") it is the one left OPEN while the future tenses fold.
 * Left on the generic 12-item ceiling it walks into a wall whose failure is
 * WHOLESALE: the first family to overflow fails gate 2, and because the
 * roadmap write replaces the entire board, that blocks EVERY board edit —
 * including the one that would fix it. On the read side the same validator
 * guards sn_maturity_roadmap_override_board(), so an over-cap override
 * returns null and the public page silently reverts to the static floor.
 *
 * So 'done' gets a tighter, purpose-named ceiling, a refusal that names the
 * fix (the door's standing rule), and a CI canary that reds one row BEFORE
 * the wall rather than discovering it through a refused write on a live page. */
echo "\nThe done-column ceiling\n";

ok( defined( 'SN_MATURITY_ROADMAP_MAX_DONE' ), 'the done column has its own named ceiling' );
ok( SN_MATURITY_ROADMAP_MAX_DONE < SN_MATURITY_ROADMAP_MAX_ITEMS, 'the done ceiling is TIGHTER than the generic item ceiling — it exists to force graduation early, not to be generous' );

$mk_done = function ( $n ) {
	$rows = array();
	for ( $i = 1; $i <= $n; $i++ ) { $rows[] = "a shipped row number $i"; }
	return array( 'F' => array( 'done' => $rows ) );
};
ok( array() === sn_maturity_roadmap_board_problems( $mk_done( SN_MATURITY_ROADMAP_MAX_DONE ) ), 'a done column exactly at the ceiling validates' );

$over_problems = sn_maturity_roadmap_board_problems( $mk_done( SN_MATURITY_ROADMAP_MAX_DONE + 1 ) );
ok( array() !== $over_problems, 'one row over the done ceiling is a validation problem' );
ok( false !== stripos( implode( ' ', $over_problems ), 'graduate' ), 'the done-ceiling refusal NAMES THE FIX (graduate a row onto its family maturity page)' );

// The ceiling is done-only: the same count in a future column is fine.
ok( array() === sn_maturity_roadmap_board_problems( array( 'F' => array( 'planned' => $mk_done( SN_MATURITY_ROADMAP_MAX_DONE + 1 )['F']['done'] ) ) ), 'the tighter ceiling applies to done ALONE — a future column still rides the generic item ceiling' );

// CANARY: the shipped board must stay a row below the ceiling.
$max_done = 0;
$fullest  = '';
foreach ( sn_maturity_roadmap_static_board() as $family => $columns ) {
	if ( count( $columns['done'] ?? array() ) > $max_done ) {
		$max_done = count( $columns['done'] );
		$fullest  = $family;
	}
}
ok( $max_done <= SN_MATURITY_ROADMAP_MAX_DONE - 1,
	"CANARY: the fullest done column is '$fullest' at $max_done, ceiling is " . SN_MATURITY_ROADMAP_MAX_DONE . ' — graduate a done row onto its family maturity page before adding another' );

/* ── v10.76.0: the static DR floor resynced to the live override. These
 * three rows ARE the drift the sync closed; pinned by substance so the
 * floor cannot silently fall behind the board again. The floor matters
 * precisely when the override is gone or invalid — an unpinned floor is a
 * disaster-recovery path that recovers to something wrong. ── */
$floor = sn_maturity_roadmap_static_board();
ok( false !== strpos( implode( ' | ', $floor['Operations']['done'] ), 'Spend watched like uptime' ), 'DR floor: the spend-watch row sits in Operations DONE (it graduated on the live board)' );
ok( false === strpos( implode( ' | ', $floor['Operations']['planned'] ), 'Spend watched like uptime' ), 'DR floor: and no stale copy of it survives in Operations PLANNED — a row moves, it is never copied' );
// The row moved PLANNED -> CONSIDERING on 2026-08-11: §8.8 of the agent-surface
// threat model declined the edge broker. The pin moves with it rather than being
// deleted — the floor still has to say where the row IS, and a decision that
// leaves no assertion behind is a decision the next session cannot see.
// SUPERSEDED 2026-08-12 — the decline is REVERSED, so these pins move rather
// than being deleted. The three assertions above used to read: not-planned,
// sits-in-considering, names-DECLINED. All three are now false BY DECISION, not
// by regression. The replacements below assert the new position and, more
// importantly, the gate — because "planned" on this board is a promise, and a
// promise without its gate is the thing the considering column exists to avoid.
// Kept as a comment rather than dropped silently: a reversal that leaves no
// trace is exactly what the original pin's own comment warned about.
ok( false === strpos( implode( ' | ', $floor['AI']['considering'] ), 'Reach the read door' ), 'DR floor: the old declined phrasing is gone from considering' );
// The give-back row moved PLANNED -> DONE on 2026-08-11. Its gate (an explicit
// operator map) and its purpose clause both shipped in v10.91.0; only the literal
// RATIO was declined, because crawls are requests and referred visits are
// visitor-days. Graduating it is the honest column — leaving a shipped surface in
// 'planned' is exactly the drift v10.71.1 had to resync four rows to undo. The
// decline rides INSIDE the graduated sentence so the board records the shape the
// answer took, not merely that an answer exists.
ok( false === strpos( implode( ' | ', $floor['Analytics']['planned'] ), 'send a reader back' ), 'DR floor: the give-back row is NO LONGER promised as planned' );
ok( false !== strpos( implode( ' | ', $floor['Analytics']['done'] ), 'Which machines send a reader back' ), 'DR floor: it sits in Analytics DONE (the per-operator statement shipped in v10.91.0)' );
ok( false !== strpos( implode( ' | ', $floor['Analytics']['done'] ), 'not a ratio' ), 'DR floor: and the row NAMES what was declined, so "ratio" cannot quietly come back as an unmet promise' );
ok( false === strpos( implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Analytics'] ) ) ), 'Give-back ratio per crawler' ), 'DR floor: no stale "Give-back ratio per crawler" phrasing survives in ANY Analytics column' );

// The rights-read row moved PLANNED -> DONE on 2026-08-12. Its gate ("served
// from state the site already holds, so a reader's page never waits on a sensor
// call") is delivered by construction: inc/machine-readers-rights-reads.php
// reads a snapshot record it is HANDED — the only input is an array — and the
// count is live on /maturity/machine-readability/. The graduated sentence keeps
// the two claims that make the number honest: no sensor call on a reader's
// path, and never-measured renders as unmeasured rather than as a zero.
ok( false === strpos( implode( ' | ', $floor['Machine readability']['planned'] ), 'rights-read count' ), 'DR floor: the rights-read row is NO LONGER promised as planned' );
ok( false !== strpos( implode( ' | ', $floor['Machine readability']['done'] ), 'The rights-read count published on the machine-readability page itself' ), 'DR floor: it sits in Machine readability DONE (the count shipped in v10.91.0)' );
ok( false !== strpos( implode( ' | ', $floor['Machine readability']['done'] ), 'renders as unmeasured, never as zero' ), 'DR floor: and the row NAMES the three-valued contract, so a broken sensor can never be read back as a flattering zero' );
ok( false === strpos( implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Machine readability'] ) ) ), 'read from the crawler ledger at render' ), 'DR floor: no stale "read from the crawler ledger at render" phrasing survives in ANY Machine readability column' );

// Traffic rhythm flags promoted CONSIDERING -> PLANNED on 2026-08-12 (owner
// call, R4 prep: the ML pair stays held, this row promotes). A planned row
// names its gate, so the promoted sentence carries one — read from the rollups
// already kept, deterministic, never profiling a reader — where the
// considering copy committed to nothing.
ok( false === strpos( implode( ' | ', $floor['Analytics']['considering'] ), 'Traffic rhythm flags' ), 'DR floor: the rhythm-flags row is NO LONGER a considering idea' );
// 2026-08-12 evening: promoted in the morning, BUILT and GRADUATED by night
// (v10.94.0 verified live: the envelope carries views_skipped, the surface
// answered). A done row states what acts; the gate clause survives inside it.
ok( false !== strpos( implode( ' | ', $floor['Analytics']['done'] ), 'Traffic rhythm flags: the cadence watch now reads views' ), 'DR floor: the rhythm-flags row sits in Analytics DONE, stating what acts' );
ok( false === strpos( implode( ' | ', $floor['Analytics']['planned'] ), 'Traffic rhythm flags' ), 'DR floor: and no stale planned copy of it survives — a row moves, it is never copied' );
ok( false !== strpos( implode( ' | ', $floor['Analytics']['done'] ), 'read from the rollups already kept' ) && false !== strpos( implode( ' | ', $floor['Analytics']['done'] ), 'never profiling a reader' ), 'DR floor: the graduated row still carries its gate clauses — the promise it was built against rides inside the claim' );
ok( false === strpos( implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Analytics'] ) ) ), 'without ever profiling a reader' ), 'DR floor: no stale considering-era phrasing survives in ANY Analytics column' );

// The founding measurement row RETIRED to the family maturity page when the
// done-column ceiling bound (rhythm's graduation would have made 5 of 5 and
// tripped the canary). Retirement is removal from the HUB only — the
// Analytics family page states the whole pipeline — so the floor must show
// the row in NO column at all, not merely out of done.
ok( false === strpos( implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Analytics'] ) ) ), 'First-party, cookieless measurement at the edge' ), 'DR floor: the retired founding row appears in NO Analytics column — it lives on the family page now' );

// SECOND Analytics retirement, 2026-08-12 (owner call). The done column sat at
// 4 — the canary wall — so the planned digest row could not graduate without
// tripping it. The public stats page retires: it is the oldest and most settled
// of the four, and unlike an internal invariant it is SELF-EVIDENCING — /stats/
// is a live public page a reader can simply visit, so removing the board row
// conceals nothing. Same shape as the founding row: absent from EVERY column,
// not merely moved out of done.
ok( false === strpos( implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Analytics'] ) ) ), 'A public stats page' ), 'DR floor: the retired stats-page row appears in NO Analytics column' );

// R3 §3D REOPENED 2026-08-12 (owner: "I want to have the MCP on my phone").
// The row sat in AI 'considering' worded as DECLINED — and the reopening
// condition it named ("a real task needing an agent to read the CORPUS from a
// phone") is not the one that fired: the owner wants ANALYTICS from a phone,
// which is a different asset and a different risk. A public board row that
// states a decision the owner has reversed is worse than a stale row; it is a
// wrong one.
$ai_all = implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['AI'] ) ) );
ok( false === strpos( $ai_all, 'DECLINED' ), 'DR floor: no AI row still records the phone door as declined' );
ok( false === strpos( $ai_all, 'the asset is unpublished drafts' ), 'DR floor: and the old decline reasoning survives in no column' );
// 2026-08-14 GRADUATED planned -> done: the door shipped and is phone-proven.
// The three preconditions stay pinned, and they FOLLOW THE CLAIM into done
// rather than staying with the column — they were the reason this was a legal
// planned row, and they are now facts about a shipped door. Retargeting them
// is the point: a pin that guards a sentence must move when the sentence does,
// or it silently stops guarding anything while still reading green.
$ai_done = implode( ' | ', $floor['AI']['done'] );
ok( false !== strpos( $ai_done, 'phone' ), 'DR floor: the phone-door row is DONE (shipped v11.0.0-v11.1.0, phone-proven)' );
ok( false === strpos( implode( ' | ', $floor['AI']['planned'] ), 'phone' ), 'DR floor: and it is GONE from planned — a row in two columns is the drift this floor exists to catch' );
ok( false !== strpos( $ai_done, 'fail' ) && false !== strpos( $ai_done, 'closed' ), 'DR floor: it still names the fail-CLOSED ceiling — the local ceiling is deliberately fail-open and that is not acceptable where a credential exists' );
ok( false !== strpos( $ai_done, 'expire' ), 'DR floor: it still names a token that EXPIRES — a credential the site cannot rotate was what killed the first attempt' );
ok( false !== strpos( $ai_done, 'draft' ), 'DR floor: and it still names the drafts boundary, the asset the original decline was actually protecting' );
// THE RETIREMENT, pinned as its own claim. Promoting the phone door would have
// put AI done at 5 and red the wall canary above, so the threat-model row
// retired onto the AI maturity page. Retirement is removal from the HUB, so
// pin it in NO column — "not in done" alone would pass while the row sat in
// considering, which is the failure mode of a half-finished retirement.
$ai_all_post = implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['AI'] ) ) );
ok( false === strpos( $ai_all_post, 'threat model' ), 'DR floor: the threat-model row is in NO column — retirement is removal from the hub, not demotion within it' );
ok( count( $floor['AI']['done'] ) <= SN_MATURITY_ROADMAP_MAX_DONE - 1, 'DR floor: AI done clears the wall canary after the swap — the retirement bought exactly the slot the promotion spent' );
ok( false === strpos( implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Analytics'] ) ) ), 'aggregate numbers published for readers' ), 'DR floor: and no fragment of its sentence survives anywhere in the family' );
// The POINT of the retirement, stated as its own claim. The wall canary above
// (max_done <= MAX_DONE - 1) already reds at 5, and Analytics sat at 4 — legal,
// but with no room for the planned digest row to land. This pins the headroom
// that retirement bought: at MAX_DONE - 2, the digest row can graduate to 4 and
// still clear the canary. A future row quietly re-filling the slot reds HERE,
// with the reason attached, rather than surfacing later as a refused write.
// The three-release arc of this one slot, kept as one comment because the
// number alone reads like churn: v13.18.0 SPENT the headroom (the folded
// Search Console row put Analytics done at 4); v13.19.0 BOUGHT IT BACK by
// graduating the AI-referral row onto /maturity/analytics/; v13.20.0 spent it
// again — deliberately, on the digest row it was bought for. So the column is
// back AT the canary limit, and the next Analytics graduation needs another
// retirement first. That is the ceiling working, not drift: the slot was
// created for a named row and went to that row.
ok( SN_MATURITY_ROADMAP_MAX_DONE - 1 === count( $floor['Analytics']['done'] ), 'DR floor: Analytics done is back AT the wall-canary limit — the headroom v13.19.0 bought went to the digest row, as intended' );
$digest_done = implode( ' | ', $floor['Analytics']['done'] );
ok( false !== strpos( $digest_done, 'AI attention in the weekly digest' ), 'DR floor: the digest row is DONE, stating what acts (v13.20.0) — the section was built against this row and the board had simply never moved it' );
ok( false === strpos( implode( ' | ', $floor['Analytics']['planned'] ), 'AI attention' ) && false === strpos( implode( ' | ', $floor['Analytics']['planned'] ), 'AI-attention' ), 'DR floor: and it is GONE from planned — a row moves, it is never copied' );
ok( false !== strpos( $digest_done, 'thirty-day window is cited' ), 'DR floor: the graduated row keeps its window discipline — the ledger window is cited, never blended into the digest week, which is the rule the narration instruction actually enforces' );
ok( false !== strpos( $digest_done, 'measured nothing stays silent' ), 'DR floor: and the three-valued half — a window that measured nothing narrates no zero, the property Test 8b pins on the signal itself' );
$analytics_all = implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Analytics'] ) ) );
ok( false === strpos( $analytics_all, 'AI-referred humans as a channel' ), 'DR floor: the AI-referral row is in NO Analytics column — graduation is removal from the hub, not demotion within it' );
ok( false === strpos( $analytics_all, 'lumping them hides the shift' ), 'DR floor: and no fragment of its sentence survives anywhere in the family' );

// Charts that speak graduated the same night (v10.93.1 verified live:
// 5 calendar rows x 7 columns, 30 day cells on the bare URL).
ok( false !== strpos( implode( ' | ', $floor['Accessibility']['done'] ), 'ships with its voice built in' ), 'DR floor: the charts row sits in Accessibility DONE' );
ok( false === strpos( implode( ' | ', $floor['Accessibility']['planned'] ), 'Charts that speak' ), 'DR floor: and no stale planned copy of it survives' );

// The embeds row DECLINED 2026-08-12 (owner call): the facade was built,
// shipped (theme v11.8.0), tried live, and reverted the same day (v11.8.1).
// The read-door pattern: the decline and its reopening condition ride
// inside the sentence, in considering — the board goes on record about the
// shape the answer took rather than going quiet. Motion-that-asks-first
// took the freed planned slot (its gate was already in its own sentence).
ok( false === strpos( implode( ' | ', $floor['Accessibility']['planned'] ), 'third-party embeds' ), 'DR floor: the embeds row is NO LONGER promised as planned' );
ok( false !== strpos( implode( ' | ', $floor['Accessibility']['considering'] ), 'DECLINED in practice' ), 'DR floor: the decline sits in considering and NAMES the decision' );
ok( false !== strpos( implode( ' | ', $floor['Accessibility']['considering'] ), 'Reopens only if an embed ever lands in a note body' ), 'DR floor: and its reopening condition rides inside the sentence' );
// v13.18.0: motion SHIPPED, so the pin follows it from planned into done
// rather than being deleted — the floor still has to say where the row IS.
ok( false === strpos( implode( ' | ', $floor['Accessibility']['planned'] ), 'Motion that asks first' ), 'DR floor: motion-that-asks-first is GONE from planned — it shipped and graduated' );
ok( false !== strpos( implode( ' | ', $floor['Accessibility']['done'] ), 'Motion that asks first' ), 'DR floor: and it sits in Accessibility DONE, stating what acts' );
ok( false === strpos( implode( ' | ', $floor['Accessibility']['later'] ), 'Motion that asks first' ), 'DR floor: and no stale later copy of it survives — a row moves, it is never copied' );

// SUPERSEDED v13.18.0. These pins used to assert that the DELIVERED
// (per-palette) half of the contrast audit sat in done while the planned row
// owned the undelivered computed-styles half. BOTH halves have since moved,
// and the pins move with them rather than being deleted: the per-palette row
// graduated onto /maturity/a11y-maturity/ as the tenth principle back in
// v13.8.2 — through the board door, so the static floor only caught up when
// the override folded — and the computed-styles half SHIPPED into done. A pin
// that guards a sentence must follow it, or it stops guarding while still
// reading green.
$a11y_all = implode( ' | ', call_user_func_array( 'array_merge', array_values( $floor['Accessibility'] ) ) );
ok( false === strpos( $a11y_all, 'three palettes the site actually serves' ), 'DR floor: the per-palette contrast row is in NO Accessibility column — it graduated onto the a11y page (v13.8.2), and graduation is removal from the hub' );
ok( false !== strpos( implode( ' | ', $floor['Accessibility']['done'] ), 'COMPUTED styles' ), 'DR floor: the computed-styles half SHIPPED and sits in done — the half a stylesheet read could never answer' );
ok( false === strpos( implode( ' | ', $floor['Accessibility']['planned'] ), 'COMPUTED styles' ), 'DR floor: and no stale planned copy of it survives — a row moves, it is never copied' );
ok( false === strpos( $a11y_all, 'Alt-text coverage extended' ), 'DR floor: the alt-COVERAGE row is in NO column — it lives on the a11y page as the eleventh principle' );
ok( false === strpos( $a11y_all, 'Alt-text quality' ), 'DR floor: and the alt-QUALITY row likewise, as the twelfth — the pair moved together by owner call' );
ok( false === strpos( implode( ' | ', $floor['Accessibility']['done'] ), 'fingerprint-safe' ), 'DR floor: the structural-scan row is GONE from done - it graduated onto the a11y page, and a row moves, it is never copied' );
ok( 3 === count( $floor['Accessibility']['done'] ), 'DR floor: Accessibility done is at THREE — the alt-text pair graduated together (owner call: coverage and quality are one story), buying two slots where the ceiling demanded one' );

// delete_option returns the page to code-canonical.
delete_option( SN_MATURITY_ROADMAP_OPTION );
ok( $static_html === call_user_func( $GLOBALS['__shortcodes']['sn_maturity_roadmap'] ), 'deleting the override returns the render to code-canonical, byte-identical' );
ok( sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() ) === $static_fp, 'and the fingerprint returns with it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
