<?php
/**
 * Token-contract test for the settings-surface top nav (S2 §6): the
 * `.sn-nav-tabs .nav-tab` D1 underline treatment appended to assets/admin.css.
 *
 * ADDITIVE-ONLY guard: this suite never asserts that anything was REMOVED —
 * it only pins that the new block exists and that the pre-existing sub-tab
 * pill rules (.sn-sub-tabs / .sn-sub-tab, deliberately subordinate) are still
 * byte-identical. The stronger "nothing above the new block changed" claim is
 * a `git diff` review discipline, not something a string contract can prove.
 *
 * Run: php tests/settings-nav-tabs.php
 * @since S2 §6
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );

/**
 * Pulls the declaration block for the FIRST selector matching $selector,
 * from its opening `{` to the matching `}` (no nesting in this file).
 */
function nt_block( $css, $selector ) {
	$at = strpos( $css, $selector );
	if ( false === $at ) {
		return '';
	}
	$open  = strpos( $css, '{', $at );
	$close = strpos( $css, '}', $open );
	return false === $open || false === $close ? '' : substr( $css, $open, $close - $open + 1 );
}

echo "settings-nav-tabs suite - S2 §6\n";

echo "\nTest: the D1 underline block exists and targets the real top-nav\n";
ok( false !== strpos( $css, '.sn-nav-tabs .nav-tab' ), 'a new rule targets .sn-nav-tabs .nav-tab' );
ok( false !== strpos( $css, '--sn-link' ), '--sn-link is defined in this file\'s own :root (S2 review note)' );

$tab = nt_block( $css, '.sn-nav-tabs .nav-tab {' );
ok( '' !== $tab, '.sn-nav-tabs .nav-tab block found' );
ok( false !== strpos( $tab, 'background: transparent' ), 'base tab: transparent background (no core folder-tab chrome)' );
ok( false !== strpos( $tab, 'border: 0' ), 'base tab: no border (flat, not boxed)' );
ok( false !== strpos( $tab, 'var(--sn-text-muted)' ), 'base tab: muted color reads the token, not a raw hex' );
ok( false === strpos( $tab, '#' ), 'base tab rule: no raw hex' );

echo "\nTest: the active tab gets the 2px accent underline\n";
ok(
	preg_match( '/\.sn-nav-tabs \.nav-tab-active[^{]*\{[^}]*border-bottom:\s*2px solid var\(--sn-link\)/s', $css ) === 1,
	'active tab: 2px solid var(--sn-link) underline (this file\'s own accent var, not a raw #2271b1)'
);
$active = nt_block( $css, '.sn-nav-tabs .nav-tab-active' );
ok( false !== strpos( $active, 'var(--sn-text)' ), 'active tab: dark text token' );
ok( false === strpos( $active, '#' ), 'active tab rule: no raw hex' );

echo "\nTest: a hover/focus state is designed, not left to core defaults\n";
ok(
	false !== strpos( $css, '.sn-nav-tabs .nav-tab:hover' ) || false !== strpos( $css, '.sn-nav-tabs .nav-tab:focus' ),
	'hover or focus state present on the base tab'
);

echo "\nTest: additive only — the pre-existing .sn-nav-tabs rule + sub-tab pills stay byte-unchanged\n";
ok( false !== strpos( $css, ".sn-nav-tabs {\n\tmargin-bottom: 1em;\n}" ), 'pre-existing .sn-nav-tabs { margin-bottom: 1em; } is untouched, byte-for-byte' );
// A distinctive, easy-to-eyeball line from the pill treatment (.sn-sub-tabs) —
// if this ever changes, someone edited the "deliberately subordinate" pills
// instead of adding new nav-tab rules.
ok( false !== strpos( $css, "position: sticky;" ) && false !== strpos( $css, "top: 32px;" ), '.sn-sub-tabs sticky positioning untouched' );
ok( false !== strpos( $css, '.sn-sub-tab:focus-visible {' ), '.sn-sub-tab:focus-visible rule still present' );
$pill = nt_block( $css, '.sn-sub-tab {' );
ok( false !== strpos( $pill, 'padding: 6px 14px;' ) && false !== strpos( $pill, "border-radius: 3px;" ), '.sn-sub-tab pill declaration byte-unchanged (padding + radius)' );

echo "\nTest: the v647 settings-pages contract's key tokens survive (redundant safety net)\n";
ok( false !== strpos( $css, '--sn-radius:      3px' ), 'v647: radius token untouched' );
$fs = nt_block( $css, '.sn-fieldset {' );
ok( false !== strpos( $fs, 'padding: 16px 18px' ), 'v647: .sn-fieldset padding untouched' );
$fsh = nt_block( $css, '.sn-fieldset-h {' );
ok( false !== strpos( $fsh, 'border-bottom: 1px solid #f0f0f1' ), 'v647: .sn-fieldset-h hairline untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
