<?php
/**
 * Standalone fixture tests for inc/health-contrast-tokens.php — the
 * token-level contrast REPORT (R2C, report only; R3 owns fixes).
 *
 * The ratio pins are HAND-DERIVED from the WCAG 2.x formula (or are the
 * spec's own published examples), never recomputed with the code under
 * test — a test that recomputes its expectation with the SUT passes on
 * identical garbage (docs/r2-prep.md §2C, and the determinism-tests
 * lesson).
 *
 * Run: php tests/health-contrast-tokens.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// The named-palette reader consumes wp_get_global_settings; fixture-driven.
$GLOBALS['__palette'] = null;
function wp_get_global_settings( $path = array() ) { return $GLOBALS['__palette']; }
function sn_health_normalize_hex( $color ) {
	$c = strtolower( trim( (string) $color ) );
	if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) { return ''; }
	$h = $m[1];
	return '#' . ( 3 === strlen( $h ) ? $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] : $h );
}
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint );
}

if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', __DIR__ . '/../' ); }
require __DIR__ . '/../inc/health-contrast-tokens.php';
// The check now embeds the usage tier's report (v10.90.0); without the module
// the whole suite fatals mid-run — which prints neither FAIL nor a summary.
require __DIR__ . '/../inc/health-contrast-usage.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Token-level contrast report (R2C)\n\n";

echo "Group: the arithmetic — hand-derived pins\n";
// Black on white is the spec's own boundary case: exactly 21:1.
ok( abs( sn_health_contrast_ratio( '#000000', '#ffffff' ) - 21.0 ) < 1e-9, 'black/white is exactly 21:1' );
ok( abs( sn_health_contrast_ratio( '#ffffff', '#ffffff' ) - 1.0 ) < 1e-9, 'a color against itself is exactly 1:1' );
// #767676 on white = 4.54:1 — the canonical "just passes AA" gray.
// Hand derivation: 118/255 = 0.462745; ((0.462745+0.055)/1.055)^2.4 = 0.181164
// per channel; luminance = same (gray); (1.0+0.05)/(0.181164+0.05) = 4.5424.
$r = sn_health_contrast_ratio( '#767676', '#ffffff' );
ok( abs( $r - 4.5424 ) < 0.001, "#767676 on white = 4.54:1 (hand-derived 4.5424, got " . round( $r, 4 ) . ')' );
ok( true === ( $r >= SN_HEALTH_CONTRAST_AA_BODY ), 'and it PASSES body AA — the classic boundary gray' );
// Pure blue on white = 8.59:1 (spec-published example): L(blue) = 0.0722.
$r = sn_health_contrast_ratio( '#0000ff', '#ffffff' );
ok( abs( $r - 8.592 ) < 0.01, 'pure blue on white = 8.59:1 (got ' . round( $r, 3 ) . ')' );
ok( sn_health_contrast_ratio( '#ffffff', '#0000ff' ) === $r, 'ratio is order-independent' );
ok( null === sn_health_contrast_ratio( 'blue', '#ffffff' ), 'a malformed color is null, never a fabricated number' );

echo "\nGroup: thresholds — body and large differ, and both apply\n";
// #949494 on white: 148/255 = 0.5803922; ((0.5803922+0.055)/1.055)^2.4
// = 0.2961374; ratio = 1.05/0.3461374 = 3.0335 — passes LARGE, fails BODY.
$table = sn_health_contrast_pair_table( array( 'concrete' => '#949494', 'void' => '#ffffff' ) );
ok( 1 === count( $table ), 'two tokens make exactly one pair' );
ok( false === $table[0]['aa_body'] && true === $table[0]['aa_large'], 'a ~3.03:1 pair FAILS body AA (4.5) and PASSES large AA (3.0) — the two thresholds are genuinely different tests' );
ok( 3.03 === $table[0]['ratio'], 'displayed ratio rounds to 2 decimals (3.03, hand-derived 3.0335)' );

echo "\nGroup: the pair table\n";
$table = sn_health_contrast_pair_table( array( 'bone' => '#000000', 'void' => '#ffffff', 'signal' => '#ff4c47' ) );
ok( 3 === count( $table ), 'three tokens make exactly three unordered pairs' );
ok( $table[0]['ratio'] <= $table[1]['ratio'] && $table[1]['ratio'] <= $table[2]['ratio'], 'sorted worst-first — the reader meets the risk before the reassurance' );
ok( 'bone / void' === $table[2]['pair'] && 21.0 === $table[2]['ratio'], 'the best pair is black/white at 21' );

echo "\nGroup: the named palette (both wp_get_global_settings shapes)\n";
$GLOBALS['__palette'] = array(
	array( 'slug' => 'void', 'color' => '#FFFFFF', 'name' => 'Void' ),
	array( 'slug' => 'signal', 'color' => '#ff4c47', 'name' => 'Signal' ),
	array( 'slug' => 'broken', 'color' => 'not-a-color', 'name' => 'Broken' ),
);
ok( array( 'void' => '#ffffff', 'signal' => '#ff4c47' ) === sn_health_contrast_named_palette(), 'flat shape: slugs kept, hex normalized, malformed entries dropped' );
$GLOBALS['__palette'] = array(
	'default' => array( array( 'slug' => 'core-blue', 'color' => '#0000ff' ) ),
	'theme'   => array( array( 'slug' => 'bone', 'color' => '#000' ) ),
);
ok( array( 'bone' => '#000000' ) === sn_health_contrast_named_palette(), 'origin-keyed shape: theme kept, core defaults excluded (defaultPalette:false makes them drift, not tokens)' );

echo "\nGroup: the check — report only, coverage honest\n";
$GLOBALS['__palette'] = array(
	array( 'slug' => 'concrete', 'color' => '#949494' ),
	array( 'slug' => 'void', 'color' => '#ffffff' ),
);
$check = sn_health_check_contrast_tokens();
ok( 0 === $check['count'] && array() === $check['findings'], 'ZERO findings by design — report only, R3 owns fixes' );
ok( 1 === $check['report']['would_fail_body'], 'the would-fail count is in the REPORT, not in findings' );
// The sentence changed when the usage tier shipped (v10.90.0): it now names
// which half of the gap is closed. The pins follow the CLAIMS, not the old
// wording: the arithmetic tier must disclaim live-defect status, point at the
// usage tier for the rendered question, and still refuse the clean-site
// overclaim even with both tiers green.
$coverage = (string) $check['report']['coverage'];
ok( false !== strpos( $coverage, 'Arithmetic tier' ) && false !== strpos( $coverage, 'not a live defect' ), 'coverage names the arithmetic tier and disclaims live-defect status' );
ok( false !== strpos( $coverage, 'usage tier' ), 'coverage points at the usage tier for the rendered question' );
ok( false !== strpos( $coverage, 'not proof of a clean site' ), 'coverage still refuses the clean-site overclaim with both tiers green' );
ok( isset( $check['report']['thresholds']['aa_body'] ) && 4.5 === $check['report']['thresholds']['aa_body'], 'thresholds published with the data' );
ok( false !== strpos( $check['fix_hint'], 'Report only' ), 'the fix hint says there is nothing to fix from here' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
