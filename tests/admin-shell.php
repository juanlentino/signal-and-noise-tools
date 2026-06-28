<?php
/**
 * Standalone test: sn_admin_shell_* two-column layout primitive (v6.42.0).
 *
 * Locks the markup contract of the echo-style main+rail shell: the wrapper
 * divs, the sticky rail <aside> with an escaped aria-label, balanced tags,
 * and main-before-rail DOM order (so the rail degrades to read-after-main
 * when it stacks below on narrow viewports).
 *
 * Standalone — no PHPUnit. Run: php tests/admin-shell.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

// ─── WP stubs ────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

require_once __DIR__ . '/../inc/admin-shell.php';

function sh_contains( $haystack, $needle, $msg ) {
	global $pass, $fail;
	if ( false !== strpos( $haystack, $needle ) ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Missing: $needle\n";
	}
}
function sh_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

// ─── Test A: open() emits the shell + main column ────────────────────
echo "Test A: shell open\n";
ob_start();
sn_admin_shell_open();
$open = ob_get_clean();
sh_contains( $open, '<div class="sn-shell">', 'opens the shell grid' );
sh_contains( $open, '<div class="sn-shell__main">', 'opens the main column' );

// ─── Test B: rail() closes main + opens the labelled rail ────────────
echo "\nTest B: rail divider\n";
ob_start();
sn_admin_shell_rail( 'Scan status' );
$rail = ob_get_clean();
sh_contains( $rail, '</div>', 'closes the main column' );
sh_contains( $rail, '<aside class="sn-shell__rail"', 'opens the rail aside' );
sh_contains( $rail, 'aria-label="Scan status"', 'labels the rail landmark' );

// ─── Test C: default rail label ──────────────────────────────────────
echo "\nTest C: default rail label\n";
ob_start();
sn_admin_shell_rail();
$rail_default = ob_get_clean();
sh_contains( $rail_default, 'aria-label="Summary"', 'falls back to a generic label' );

// ─── Test D: close() closes the rail + shell ─────────────────────────
echo "\nTest D: shell close\n";
ob_start();
sn_admin_shell_close();
$close = ob_get_clean();
sh_contains( $close, '</aside>', 'closes the rail' );
sh_contains( $close, '</div>', 'closes the shell' );

// ─── Test E: full sequence is balanced + main-before-rail ────────────
echo "\nTest E: balanced markup, main precedes rail\n";
ob_start();
sn_admin_shell_open();
echo 'MAIN_CONTENT';
sn_admin_shell_rail( 'Summary' );
echo 'RAIL_CONTENT';
sn_admin_shell_close();
$full = ob_get_clean();
sh_assert( 2 === substr_count( $full, '<div' ) && 2 === substr_count( $full, '</div>' ), 'div tags balance (2 open / 2 close)' );
sh_assert( 1 === substr_count( $full, '<aside' ) && 1 === substr_count( $full, '</aside>' ), 'aside tags balance (1 open / 1 close)' );
sh_assert( strpos( $full, 'MAIN_CONTENT' ) < strpos( $full, 'RAIL_CONTENT' ), 'main content precedes rail in DOM order' );
sh_assert( strpos( $full, 'MAIN_CONTENT' ) > strpos( $full, 'sn-shell__main' ), 'main content sits inside the main column' );
sh_assert( strpos( $full, 'RAIL_CONTENT' ) > strpos( $full, 'sn-shell__rail' ), 'rail content sits inside the rail' );

// ─── Test F: aria-label is escaped ───────────────────────────────────
echo "\nTest F: aria-label escaping\n";
ob_start();
sn_admin_shell_rail( 'a"b<c' );
$escaped = ob_get_clean();
sh_assert( false === strpos( $escaped, 'a"b<c' ), 'raw unescaped label is not present' );
sh_contains( $escaped, 'a&quot;b&lt;c', 'label is HTML-attribute escaped' );

// ─── Test G: the shell grid is full-width + asymmetric (v6.45.0) ─────────────
echo "\nTest G: full-width asymmetric grid in admin.css\n";
$css   = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );
$start = strpos( $css, '.sn-shell {' );
$end   = false !== $start ? strpos( $css, '}', $start ) : false;
$block = ( false !== $start && false !== $end ) ? substr( $css, $start, $end - $start ) : '';
sh_assert( false !== strpos( $block, 'minmax(0,' ) && false !== strpos( $block, 'fr)' ), 'the .sn-shell grid is full-width and fluid (fr-based, no fixed cap)' );
sh_assert( false === strpos( $block, '820px' ) && false === strpos( $block, '300px' ), 'the .sn-shell grid no longer caps the main at 820px / pins a 300px rail' );
sh_assert( false !== strpos( $block, '1.7fr' ) || ( false === strpos( $block, 'auto-fit' ) ), 'the .sn-shell grid is asymmetric (main wider than side), not forced-equal auto-fit' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
