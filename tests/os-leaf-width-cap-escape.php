<?php
/**
 * The 820px leaf cap and the selectors that release it
 * (apps/sn-dashboard/sn-dashboard.css).
 *
 * Every leaf in the S&N Home app is capped at 820px. A short list of escape
 * selectors re-opens it to 100% for leaves that carry a table, a rail or a
 * column grid. A CSS cascade rule makes that list fragile: an escape selector
 * only wins if it OUTRANKS the cap. Two of them did not.
 *
 * Measured live 2026-09-10 in a 1820px window: connections/webhooks matched
 * BOTH `.snt-dashboard-body > .snt-leaf` (max-width 820px, specificity 0,4,0)
 * and `.snt-leaf:has( os-row )` (max-width 100%, specificity 0,3,1). The hatch
 * matched the element and lost. Computed max-width was 820px; the leaf painted
 * a 820px column inside an 1820px window with the right half empty -- the
 * defect a 19-leaf redesign was about to be written against.
 *
 * `:has( os-table )` and `:has( os-row )` take TYPE-selector specificity, one
 * point below the cap. The class-based hatches tied at (0,4,0) and won only on
 * source order. This asserts every escape selector strictly outranks the cap,
 * so neither a reorder nor a new type-selector hatch can silently re-cap a leaf.
 *
 * Run: php tests/os-leaf-width-cap-escape.php
 */

$css_path = dirname( __DIR__ ) . '/apps/sn-dashboard/sn-dashboard.css';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Specificity of one selector as (ids, classes, types).
 *
 * Comments are stripped by the caller. :has()/:is()/:where() are handled the way
 * the cascade does: :where() contributes nothing, :has()/:is() contribute their
 * most specific argument, and the functional pseudo-class itself contributes
 * nothing of its own.
 */
function spec( $sel ) {
	$sel = trim( $sel );
	$ids = 0; $cls = 0; $typ = 0;

	// Pull out functional pseudo-classes first so their inner selectors are not
	// double-counted by the scalar passes below.
	$inner_best = array( 0, 0, 0 );
	while ( preg_match( '/:(has|is|where|not)\(([^()]*)\)/', $sel, $m ) ) {
		$fn = $m[1]; $args = $m[2];
		$sel = str_replace( $m[0], '', $sel );
		if ( 'where' === $fn ) { continue; }
		foreach ( explode( ',', $args ) as $arg ) {
			$s = spec( $arg );
			if ( $s > $inner_best ) { $inner_best = $s; }
		}
	}

	$ids += preg_match_all( '/#[A-Za-z0-9_-]+/', $sel );
	$cls += preg_match_all( '/\.[A-Za-z0-9_-]+/', $sel );
	$cls += preg_match_all( '/\[[^\]]+\]/', $sel );
	// Remaining pseudo-classes (:hover, :first-child) count as classes;
	// pseudo-ELEMENTS (::before) count as types.
	$cls += preg_match_all( '/(?<!:):[A-Za-z-]+/', $sel );
	$typ += preg_match_all( '/(?<![.#\[:A-Za-z0-9_-])\b[a-z][a-z0-9-]*\b(?![^\[]*\])/', $sel );

	return array( $ids + $inner_best[0], $cls + $inner_best[1], $typ + $inner_best[2] );
}

/**
 * True when the selector targets the LEAF ITSELF, not something inside it.
 *
 * `.snt-leaf os-row > [col]` also declares max-width:100% and also mentions the
 * leaf, but it styles a column inside one -- it is not an escape and its
 * specificity is irrelevant. Compare the FINAL compound only. :has() is masked
 * first so the spaces inside it are not mistaken for descendant combinators.
 */
function targets_the_leaf( $sel ) {
	$masked = preg_replace( '/:(has|is|where|not)\([^()]*\)/', ':fn', $sel );
	$parts  = preg_split( '/\s*[\s>+~]\s*/', trim( $masked ), -1, PREG_SPLIT_NO_EMPTY );
	$last   = end( $parts );
	return is_string( $last ) && false !== strpos( $last, 'snt-leaf' );
}

function spec_str( $s ) { return '(' . implode( ',', $s ) . ')'; }

// ── The calculator itself, before it is trusted on real input. ──
ok( spec( '.a .b > .c' ) === array( 0, 3, 0 ), 'spec: three classes' );
ok( spec( '.a[data-x="1"] .b' ) === array( 0, 3, 0 ), 'spec: an attribute counts as a class' );
ok( spec( '.a .b:has( os-table )' ) === array( 0, 2, 1 ), 'spec: :has() of a TYPE adds a type, not a class' );
ok( spec( '.a .b:has( .snt-cols )' ) === array( 0, 3, 0 ), 'spec: :has() of a CLASS adds a class' );
ok( spec( '.a .b[data-snt-leaf="tags"]' ) === array( 0, 3, 0 ), 'spec: an attribute hatch is class-weight' );
ok( spec( '.a:where( .b )' ) === array( 0, 1, 0 ), 'spec: :where() contributes nothing' );

$css = file_get_contents( $css_path );
ok( is_string( $css ) && '' !== $css, 'the dashboard stylesheet is readable' );
$css = preg_replace( '#/\*.*?\*/#s', '', $css );

// ── Locate the cap and the escape block by their DECLARATIONS, never by line
// number: a rule that moves must still be found. ──
preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER );

$cap = null; $escapes = array();
foreach ( $rules as $r ) {
	$sels = $r[1]; $body = $r[2];
	if ( false === strpos( $sels, 'snt-leaf' ) ) { continue; }
	if ( preg_match( '/max-width:\s*820px/', $body ) ) { $cap = trim( $sels ); }
	if ( preg_match( '/max-width:\s*100%/', $body ) ) {
		foreach ( explode( ',', $sels ) as $s ) {
			$s = trim( $s );
			if ( '' !== $s && targets_the_leaf( $s ) ) { $escapes[] = $s; }
		}
	}
}

ok( null !== $cap, 'the 820px cap rule is present and found by its declaration' );
ok( count( $escapes ) >= 10, 'the escape list is found and non-trivial (' . count( $escapes ) . ' selectors)' );

$cap_spec = spec( $cap );
ok( array( 0, 4, 0 ) === $cap_spec, 'the cap scores ' . spec_str( $cap_spec ) . ' as expected' );

// ── THE GUARD. Every escape must strictly outrank the cap. A tie is not enough:
// a tie is decided by source order, so a reorder would silently re-cap a leaf. ──
$weak = array();
foreach ( $escapes as $s ) {
	$sp = spec( $s );
	if ( ! ( $sp > $cap_spec ) ) { $weak[] = $s . ' ' . spec_str( $sp ); }
}
ok(
	array() === $weak,
	'every escape selector STRICTLY outranks the 820px cap'
		. ( $weak ? " -- these do not:\n    " . implode( "\n    ", $weak ) : '' )
);

// ── The two that were actually broken, pinned by name so a revert is loud. ──
foreach ( array( 'os-table', 'os-row' ) as $type_hatch ) {
	$found = array_values( array_filter( $escapes, function ( $s ) use ( $type_hatch ) {
		return false !== strpos( $s, ':has( ' . $type_hatch . ' )' ) || false !== strpos( $s, ':has(' . $type_hatch . ')' );
	} ) );
	ok( 1 === count( $found ), "the :has( $type_hatch ) hatch is still declared" );
	if ( $found ) {
		ok( spec( $found[0] ) > $cap_spec, "...and outranks the cap " . spec_str( spec( $found[0] ) ) );
		ok( false !== strpos( $found[0], '.snt-dashboard-body >' ), "...by carrying the cap's own .snt-dashboard-body > shape" );
	}
}


// ── The stack-rhythm margin must not reach a GRID CELL. ──
// `.snt-leaf os-section + os-section { margin-block-start: 24px }` is vertical
// rhythm, and it is still true of the SECOND CELL of a `.snt-cols` row -- which
// took the margin on top of the grid gap and sat 24px below its neighbour.
// Measured live 2026-09-10 on AI -> MCP Clients: 797 / 821 instead of 797 / 797.
//
// A tie is not enough here for the same reason it was not enough for the width
// cap: equal specificity is decided by source order.
$stack = null; $reset = null;
foreach ( $rules as $r ) {
	foreach ( explode( ',', $r[1] ) as $sel ) {
		$sel = trim( $sel );
		if ( false === strpos( $sel, 'os-section + os-section' ) ) { continue; }
		if ( preg_match( '/margin-block-start:\s*24px/', $r[2] ) ) { $stack = $sel; }
		if ( preg_match( '/margin-block-start:\s*0/', $r[2] ) )    { $reset = $sel; }
	}
}
ok( null !== $stack, 'the os-section stack-rhythm rule is present' );
ok( null !== $reset, 'a grid-cell reset for it exists' );
if ( $stack && $reset ) {
	ok( false !== strpos( $reset, '.snt-cols' ), 'the reset is scoped to .snt-cols, not global' );
	ok(
		spec( $reset ) > spec( $stack ),
		'the reset STRICTLY outranks the stack rule ' . spec_str( spec( $reset ) ) . ' > ' . spec_str( spec( $stack ) )
	);
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
