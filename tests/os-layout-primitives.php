<?php
/**
 * The rows and tiles are the kit's layout primitives (#1622).
 *
 * Until 17.4.3 the S&N window painted its rows with four house classes and
 * styled them by hand: `.snt-cols` (a fixed pair, 38 hits), `.snt-2up` and
 * `.snt-2up-col` (a second pair with a flex column that spaced siblings 36px),
 * `.snt-stats` and `.snt-systems` (auto-fit tile rows), and `.snt-col` (a
 * bordered cell). Each needed follow-up rules to hold: a container query per
 * grid, a width-cap hatch per class, a section-rhythm reset per grid, and an
 * outside restyle of the `<os-stat>` host. The kit ships the same shapes:
 * `<os-grid min-item-width>` (auto-fitting columns, no container query),
 * `<os-stack gap>` (the column), `<os-section stack>` (the body as a flex
 * column) and `<os-card>` (the bordered cell), and `<os-stat>` documents its
 * padding, radius, border and background as tokens.
 *
 * This pins the port both ways: no leaf, kit helper or the analytics paint
 * kit paints a retired class, and neither window sheet carries a rule for
 * one, nor the dead `.snt-card` and `.snt-dashboard-home` rules, nor an
 * `os-stat {` block that re-declares padding or border from outside. The
 * helpers are pinned by shape: snt_kit_section() passes `stack`, and
 * snt_kit_grid() writes the item count the track cap reads.
 *
 * COMMENTS ARE STRIPPED FIRST: prose in the leaves and the sheets names the
 * old classes as history, and a scan that read it would fail forever.
 *
 * Red against 17.4.3 (origin/main): the class sweep alone finds 38 `snt-cols`
 * hits in the leaves.
 *
 * Run: php tests/os-layout-primitives.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

/** @param string $src PHP or CSS source. @return string Without block and line comments. */
function strip_comments( $src ) {
	$src = preg_replace( '#/\*.*?\*/#s', '', (string) $src );
	return (string) preg_replace( '#^\s*//.*$#m', '', $src );
}

// ── 1. No leaf, helper or paint kit paints a retired class. ──
$files = array_merge(
	glob( $root . '/apps/sn-dashboard/parts/leaves/*.php' ),
	glob( $root . '/apps/sn-analytics/parts/*.php' ),
	glob( $root . '/inc/openstation-kit*.php' )
);
ok( count( $files ) > 40, 'the sweep found the leaves, the analytics parts and the kit helpers (' . count( $files ) . ' files)' );

$retired = array( 'snt-cols', 'snt-2up', 'snt-2up-col', 'snt-stats', 'snt-systems', 'class="snt-col"' );
$hits    = array();
foreach ( $files as $file ) {
	$src = strip_comments( (string) file_get_contents( $file ) );
	foreach ( $retired as $needle ) {
		$n = substr_count( $src, $needle );
		if ( $n > 0 ) {
			$hits[] = basename( $file ) . ': ' . $needle . ' x' . $n;
		}
	}
}
ok( array() === $hits, 'no leaf, analytics part or kit helper paints a retired row or tile class' . ( $hits ? " -- \n    " . implode( "\n    ", $hits ) : '' ) );

// The sweep is not blind: the same scan finds the kit shapes it replaced them with.
$leaves = '';
foreach ( glob( $root . '/apps/sn-dashboard/parts/leaves/*.php' ) as $file ) {
	$leaves .= strip_comments( (string) file_get_contents( $file ) );
}
ok( substr_count( $leaves, 'snt_kit_grid(' ) >= 30, 'the leaves paint their rows through snt_kit_grid() (' . substr_count( $leaves, 'snt_kit_grid(' ) . ' calls)' );
ok( substr_count( $leaves, 'snt_kit_stack(' ) >= 8, 'the two-up columns are snt_kit_stack() (' . substr_count( $leaves, 'snt_kit_stack(' ) . ' calls)' );
ok( substr_count( $leaves, "'os-card'" ) >= 12, 'the bordered cells are os-card (' . substr_count( $leaves, "'os-card'" ) . ' tags)' );

// ── 2. Neither sheet carries a rule for one, nor the two dead rules. ──
$sheets = array(
	'assets/os-app.css'                  => strip_comments( (string) file_get_contents( $root . '/assets/os-app.css' ) ),
	'apps/sn-dashboard/sn-dashboard.css' => strip_comments( (string) file_get_contents( $root . '/apps/sn-dashboard/sn-dashboard.css' ) ),
	'apps/sn-analytics/sn-analytics.css' => strip_comments( (string) file_get_contents( $root . '/apps/sn-analytics/sn-analytics.css' ) ),
);
$selectors = array( '.snt-cols', '.snt-2up', '.snt-stats', '.snt-systems', '.snt-col ', '.snt-col,', '.snt-col{', '.snt-col {', '.snt-card', '.snt-dashboard-home' );
foreach ( $sheets as $name => $css ) {
	$found = array();
	foreach ( $selectors as $sel ) {
		if ( false !== strpos( $css, $sel ) ) {
			$found[] = $sel;
		}
	}
	ok( array() === $found, "$name carries no rule for a retired class" . ( $found ? ' -- ' . implode( ', ', $found ) : '' ) );
	ok( 0 === preg_match( '/os-stat\s*\{[^}]*(?<![-\w])(padding|border)\s*:/', $css ), "$name does not re-declare padding or border on the os-stat host from outside (its tokens are the way)" );
	ok( 0 === preg_match( '/@container[^{]*\{[^{}]*(os-grid|snt-cols|snt-stats|snt-systems|snt-2up)/', $css ), "$name has no container query for a row: min-item-width measures the grid itself" );
}

// ── 3. The helpers, by shape. ──
$kit = strip_comments( (string) file_get_contents( $root . '/inc/openstation-kit-display.php' ) );
ok( 1 === preg_match( "/function snt_kit_section\(.*?'stack'\s*=>\s*true/s", $kit ), 'snt_kit_section() passes `stack`, so every section body is the kit\'s flex column' );
ok( 1 === preg_match( "/function snt_kit_grid\(.*?'min-item-width'.*?'--snt-grid-items:' \. count\( \\\$items \)/s", $kit ), 'snt_kit_grid() paints <os-grid min-item-width> and writes the item count' );
ok( false !== strpos( $sheets['apps/sn-dashboard/sn-dashboard.css'], '.snt-leaf:has( os-grid )' ) && false !== strpos( $sheets['apps/sn-dashboard/sn-dashboard.css'], 'os-form:has( os-grid )' ), 'the leaf width-cap hatch and the form release key on :has( os-grid )' );
ok( 0 === preg_match( '/::part\( body \)\s*\{[^}]*flex-direction/', $sheets['apps/sn-dashboard/sn-dashboard.css'] ), 'the section body no longer re-declares the flex column the `stack` attribute provides' );

// The helper itself, against the kit's own escaping.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
require_once $root . '/inc/openstation-kit.php';
require_once $root . '/inc/openstation-kit-display.php';
ok( '<os-grid min-item-width="290" gap="18" style="--snt-grid-items:2">ab</os-grid>' === snt_kit_grid( array( 'a', 'b' ) ), 'a pair: the kit grid at the pair minimum and gap, two items' );
ok( 'b' === snt_kit_grid( array( '', 'b' ) ) && 'a' === snt_kit_grid( array( 'a', '' ) ), 'a lone side is returned bare, at full width (the tags_pair idiom)' );
ok( '' === snt_kit_grid( array( '', '' ) ), 'nothing painted is nothing' );
ok( '<os-grid min-item-width="160" gap="10" style="--snt-grid-items:3">abc</os-grid>' === snt_kit_grid( array( 'a', 'b', 'c' ), 160, 10 ), 'a tile row: the stats minimum and gap, the count the cap reads' );
ok( '<os-stack gap="18">x</os-stack>' === snt_kit_stack( 'x' ), 'snt_kit_stack() is the column at the leaf rhythm' );
ok( false !== strpos( snt_kit_section( 'H', 'x' ), ' stack>' ), 'snt_kit_section() paints the stack attribute' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
