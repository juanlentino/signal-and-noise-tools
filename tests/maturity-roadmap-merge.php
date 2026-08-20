<?php
/**
 * Tests: the roadmap board's three-way merge.
 *
 * Two writers contend for one board — code (sn_maturity_roadmap_static_board)
 * and MCP (sn_apply's roadmap_board option write). Before v12.6.0 the override
 * shadowed code totally, so the first MCP write silently retired the code path.
 * These pin the merge that lets both land.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
// merge.php deliberately does NOT define this (it must stay loadable without
// maturity-roadmap-shortcode.php, whose top-level `const` of the same name
// can't be re-declared behind a defined() guard) — so the suite defines its
// own copy, standing in for the shortcode file's const in a real load.
define( 'SN_MATURITY_ROADMAP_OPTION', 'snt_maturity_roadmap_board' );

$GLOBALS['snt_options'] = array();
function get_option( $k, $d = null ) { return $GLOBALS['snt_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['snt_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['snt_options'][ $k ] ); return true; }
function __( $s, $d = null ) { return $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }

require_once dirname( __DIR__ ) . '/inc/maturity-roadmap-merge.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "roadmap board three-way merge\n\n";

$board = array( 'F' => array( 'done' => array( 'a' ), 'planned' => array( 'b' ) ) );

echo "Group: the stored envelope\n";
$GLOBALS['snt_options'] = array();
ok( null === snt_roadmap_stored_envelope(), 'no option stored -> null envelope' );

snt_roadmap_store_envelope( $board, $board );
$env = snt_roadmap_stored_envelope();
ok( is_array( $env ) && 2 === $env['v'], 'a written envelope reports v=2' );
ok( $board === $env['board'], 'the envelope round-trips the board' );
ok( $board === $env['base'], 'and the base it was derived from' );

// v1 = a BARE board array, the shape shipped before v12.6.0.
$GLOBALS['snt_options'] = array( SN_MATURITY_ROADMAP_OPTION => $board );
$env = snt_roadmap_stored_envelope();
ok( $board === $env['board'], 'a v1 bare array is read as the board' );
ok( null === $env['base'], 'with a NULL base — unknown provenance, so no code edit may land' );

echo "\nGroup: malformed and edge-case shapes\n";

// base present but not an array — must normalise to null, same defence as
// a missing base key gets below.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 2, 'board' => $board, 'base' => 'oops' ),
);
$env = snt_roadmap_stored_envelope();
ok( null === $env['base'], 'a non-array base normalises to null' );

// v2 envelope with no base key at all.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 2, 'board' => $board ),
);
$env = snt_roadmap_stored_envelope();
ok( null === $env['base'], 'a v2 envelope missing the base key entirely normalises to null' );

// board present but not an array — must not be silently coerced (e.g. a
// string cast to [0 => 'x']); an envelope this malformed is unreadable.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 2, 'board' => 'not-an-array', 'base' => $board ),
);
ok( null === snt_roadmap_stored_envelope(), 'a non-array board is unreadable, not coerced into a bare array' );

// the option explicitly set to array() (not absent) — same "nothing stored"
// outcome as get_option() returning its default.
$GLOBALS['snt_options'] = array( SN_MATURITY_ROADMAP_OPTION => array() );
ok( null === snt_roadmap_stored_envelope(), 'an explicit empty-array option reads as null, same as absent' );

// version skew: a stored envelope from a FUTURE version this code doesn't
// understand. It must not be misread as a v1 bare board — that would hand
// callers the whole {v, board, base} wrapper as if 'v' and 'board' were
// roadmap family keys. Unreadable envelope version = no override, code wins.
$GLOBALS['snt_options'] = array(
	SN_MATURITY_ROADMAP_OPTION => array( 'v' => 3, 'board' => $board ),
);
ok( null === snt_roadmap_stored_envelope(), 'an unrecognised envelope version reads as no override, not a bare board' );

// a v1 bare board that happens to have families literally named "v" and
// "board" must still be read as a bare board, never misread as an envelope.
// Safe today because a non-empty array cast to int is always 1, never 2 —
// but that's a cast quirk, not a design; is_int() is the deliberate guard.
$board_with_v_family = array(
	'v'     => array( 'done' => array( 'x' ), 'planned' => array() ),
	'board' => array( 'done' => array( 'y' ), 'planned' => array() ),
);
$GLOBALS['snt_options'] = array( SN_MATURITY_ROADMAP_OPTION => $board_with_v_family );
$env = snt_roadmap_stored_envelope();
ok(
	$board_with_v_family === $env['board'] && null === $env['base'],
	'a v1 board with families literally named "v" and "board" is read as a bare board, not an envelope'
);

echo "\nGroup: the three-way merge\n";

$base   = array( 'F' => array( 'done' => array( 'a' ), 'planned' => array( 'p' ) ) );

// Code moved a cell the override never touched -> code lands.
$ours   = array( 'F' => array( 'done' => array( 'a' ), 'planned' => array( 'OVERRIDE' ) ) );
$theirs = array( 'F' => array( 'done' => array( 'CODE' ), 'planned' => array( 'p' ) ) );
$r = snt_roadmap_merge( $base, $ours, $theirs );
ok( array( 'CODE' ) === $r['merged']['F']['done'], 'code lands on a cell the override never touched' );
ok( array( 'OVERRIDE' ) === $r['merged']['F']['planned'], 'and the override holds its own cell' );
ok( array( array( 'family' => 'F', 'column' => 'done' ) ) === $r['code_landed'], 'the report names the cell code won' );
ok( array( array( 'family' => 'F', 'column' => 'planned' ) ) === $r['override_held'], 'and the cell the override held' );
ok( array() === $r['conflicts'], 'with no conflicts' );

// Both moved the same cell -> conflict; the OVERRIDE renders.
$ours   = array( 'F' => array( 'done' => array( 'OVERRIDE' ), 'planned' => array( 'p' ) ) );
$theirs = array( 'F' => array( 'done' => array( 'CODE' ), 'planned' => array( 'p' ) ) );
$r = snt_roadmap_merge( $base, $ours, $theirs );
ok( array( 'OVERRIDE' ) === $r['merged']['F']['done'], 'a conflict renders the OVERRIDE — an install must not silently revert authored copy' );
ok( array( array( 'family' => 'F', 'column' => 'done' ) ) === $r['conflicts'], 'and the conflict is reported by name' );

// Both writers converge on the same value independently (base='old' -> both
// write 'new'): oc !== tc is false, so this is NOT filed as a conflict — the
// merged value is right either way, but the audit trail calls it
// override_held, as if the override held its ground, even though code
// independently landed on the identical value. Pinning current behaviour
// deliberately: see the comment at the elseif below for why.
$base_conv   = array( 'F' => array( 'done' => array( 'old' ) ) );
$ours_conv   = array( 'F' => array( 'done' => array( 'new' ) ) );
$theirs_conv = array( 'F' => array( 'done' => array( 'new' ) ) );
$r = snt_roadmap_merge( $base_conv, $ours_conv, $theirs_conv );
ok( array( 'new' ) === $r['merged']['F']['done'], 'both writers converging on the same value merges to that value' );
ok( array() === $r['conflicts'], 'when both writers converge on the same value it is not a conflict' );
ok(
	array( array( 'family' => 'F', 'column' => 'done' ) ) === $r['override_held'],
	'and is attributed to the override, even though code independently landed on the same value'
);

// A family only code knows about appears.
$theirs2 = $base;
$theirs2['NEW'] = array( 'done' => array( 'n' ) );
$r = snt_roadmap_merge( $base, $base, $theirs2 );
ok( isset( $r['merged']['NEW'] ), 'a family added in code appears in the merged board' );
ok(
	array( array( 'family' => 'NEW', 'column' => 'done' ) ) === $r['code_landed'],
	'and is recorded in code_landed with the exact expected entry'
);

// Code deleted a family the override never touched -> dropped.
$r = snt_roadmap_merge( $base, $base, array() );
ok( array() === $r['merged'], 'code deleting a family the override never touched drops it' );

// Code deleted a family the override HAD changed -> conflict, kept.
$ours3 = array( 'F' => array( 'done' => array( 'OVERRIDE' ), 'planned' => array( 'p' ) ) );
$r = snt_roadmap_merge( $base, $ours3, array() );
ok( isset( $r['merged']['F'] ), 'code deleting a family the override changed keeps it' );
ok(
	array( array( 'family' => 'F', 'column' => 'done' ) ) === $r['conflicts'],
	'and reports the exact conflict — code deleting "planned" (which the override never touched) names nothing'
);

// Absence is a value: a column code removed, untouched by the override.
$ours4   = $base;
$theirs4 = array( 'F' => array( 'done' => array( 'a' ) ) );
$r = snt_roadmap_merge( $base, $ours4, $theirs4 );
ok( ! isset( $r['merged']['F']['planned'] ), 'code removing a COLUMN the override never touched drops it' );
ok(
	array() === $r['conflicts'] && array() === $r['code_landed'] && array() === $r['override_held'],
	'and names nothing in any report list — the cell is gone, not landed'
);

// Identity.
$r = snt_roadmap_merge( $base, $base, $base );
ok( $base === $r['merged'], 'merging three identical boards is the identity' );
ok( array() === $r['conflicts'] && array() === $r['code_landed'] && array() === $r['override_held'], 'and reports nothing moved' );

// A null base (v1) means the override owns everything.
$r = snt_roadmap_merge( null, $ours3, $theirs2 );
ok( $ours3 === $r['merged'], 'a NULL base makes the override authoritative wholesale' );
ok(
	array() === $r['conflicts'] && array() === $r['code_landed'] && array() === $r['override_held'],
	'and nothing lands from code — the report is empty, not just merged === ours'
);

// A family present only in base: both writers deleted it. Nobody "held" or
// "landed" a cell that exists nowhere in the merged board.
$base_with_ghost = $base;
$base_with_ghost['GONE'] = array( 'done' => array( 'g' ) );
$r = snt_roadmap_merge( $base_with_ghost, $base, $base );
ok( ! isset( $r['merged']['GONE'] ), 'a family present only in base is omitted from merged' );
ok(
	array() === $r['conflicts'] && array() === $r['code_landed'] && array() === $r['override_held'],
	'and does not appear in any report list — nobody held or landed a cell that does not exist'
);

// Same shape one level down: a COLUMN present only in base, family present
// in all three. 'planned' existed in base but neither writer kept it.
$base_with_col_ghost = array( 'F' => array( 'done' => array( 'a' ), 'planned' => array( 'p' ) ) );
$both_dropped_col    = array( 'F' => array( 'done' => array( 'a' ) ) );
$r = snt_roadmap_merge( $base_with_col_ghost, $both_dropped_col, $both_dropped_col );
ok( ! isset( $r['merged']['F']['planned'] ), 'a column present only in base is omitted from merged' );
ok(
	array() === $r['conflicts'] && array() === $r['code_landed'] && array() === $r['override_held'],
	'and does not appear in any report list either'
);

// MIRROR of the phantom fixed in 8c49b7f, on the other branch: the OVERRIDE
// alone deletes a cell (code leaves it untouched, i.e. theirs === base for
// this cell). oc is null, tc equals bc, so ours_moved fires and theirs_moved
// does not — this exercises `elseif ( $ours_moved )` with a null pick, the
// mirror of the `elseif ( $theirs_moved )` case already covered above.
$ours6 = array( 'F' => array( 'done' => array( 'a' ) ) );
$r = snt_roadmap_merge( $base, $ours6, $base );
ok( ! isset( $r['merged']['F']['planned'] ), 'the override alone deleting a cell removes it from merged' );
ok(
	array() === $r['conflicts'] && array() === $r['code_landed'] && array() === $r['override_held'],
	'and names nothing in any report list — nobody "held" a cell that does not exist'
);

// Explicitly not sentence-level: a multi-sentence cell where ours and theirs
// differ in only ONE sentence must still resolve as a whole-cell conflict
// taking ours wholesale, never a sentence-by-sentence blend. This is the
// assertion that stops someone "improving" this into a sentence merge later.
$base_multi   = array( 'F' => array( 'done' => array( 'a', 'b' ) ) );
$ours_multi   = array( 'F' => array( 'done' => array( 'a', 'B2' ) ) );
$theirs_multi = array( 'F' => array( 'done' => array( 'a', 'B3' ) ) );
$r = snt_roadmap_merge( $base_multi, $ours_multi, $theirs_multi );
ok( array( 'a', 'B2' ) === $r['merged']['F']['done'], 'a conflict inside a multi-sentence cell takes OURS wholesale, not a sentence blend' );
ok( array( array( 'family' => 'F', 'column' => 'done' ) ) === $r['conflicts'], 'and is reported as one cell-level conflict' );

// Malformed shapes reaching the merge: a family value that is not itself an
// array (corrupted option storage, or a caller that skipped validation).
// Mirrors snt_roadmap_stored_envelope() treating a non-array 'board' as
// unreadable rather than fatal — here a non-array FAMILY must read as
// absent (coerced to array()), not crash the whole merge. This matters more
// once the merge is wired into the public page render (Task 3): a corrupted
// option must degrade the board, not fatal a reader's page.
$ours_malformed   = array( 'F' => 'not-an-array' );
$theirs_malformed = $base;
$r = snt_roadmap_merge( $base, $ours_malformed, $theirs_malformed );
ok( is_array( $r['merged'] ), 'a non-array family in ours degrades instead of crashing the merge' );

$theirs_malformed2 = array( 'F' => 'also-not-an-array' );
$r = snt_roadmap_merge( $base, $base, $theirs_malformed2 );
ok( is_array( $r['merged'] ), 'a non-array family in theirs degrades instead of crashing the merge' );

// NEGATIVE CONTROL: every cell that moved must appear in exactly one list,
// AND every cell named in a list must actually exist in merged. The first
// half alone passed straight through the base-ghost bug above, because it
// never constructs a cell absent from both ours and theirs; the second half
// is the assertion that catches it.
$ours5   = array( 'F' => array( 'done' => array( 'O' ), 'planned' => array( 'p' ) ) );
$theirs5 = array( 'F' => array( 'done' => array( 'C' ), 'planned' => array( 'C2' ) ) );
$r = snt_roadmap_merge( $base, $ours5, $theirs5 );
$named = count( $r['conflicts'] ) + count( $r['code_landed'] ) + count( $r['override_held'] );
ok( 2 === $named, 'NEGATIVE CONTROL: both moved cells are accounted for exactly once (' . $named . ')' );

$all_named = array_merge( $r['conflicts'], $r['code_landed'], $r['override_held'] );
$all_exist = true;
foreach ( $all_named as $cell ) {
	if ( ! isset( $r['merged'][ $cell['family'] ][ $cell['column'] ] ) ) {
		$all_exist = false;
	}
}
ok( $all_exist, 'NEGATIVE CONTROL: every cell named in a report list exists in merged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
