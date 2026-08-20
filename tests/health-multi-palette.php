<?php
/**
 * Tests: Health measures EVERY palette the theme serves, not just the root one.
 *
 * THE DEFECT. Two plugin call sites read `wp_get_global_settings(['color','palette'])`,
 * which returns the ROOT palette only:
 *
 *   sn_health_contrast_named_palette()  — inc/health-contrast-tokens.php  (Contrast panel)
 *   sn_health_allowed_palette_hexes()   — inc/health-check-color-drift.php (the color_drift CHECK)
 *
 * Theme v12.0.0 serves a second palette under `:root[data-theme="dark"]`. All SEVEN
 * slugs are shared and every one has a different hex, so after that ships both sites
 * measure one of two and report as though it were all. Verified consequence: blood
 * #e00404 on the dark ground #0a0a0a is 3.95:1 and FAILS AA, which is why the theme
 * re-points blood to #ff4c47 (6.01:1). The panel would read green on that pair.
 *
 * THE TWO SITES NEED OPPOSITE TREATMENTS — this is the whole design:
 *
 *   color_drift wants a UNION. A dark-palette hex is a theme colour; flagging it as
 *   drift is a false positive. Merging is strictly correct.
 *
 *   The contrast panel must NOT merge. The slugs collide 7-for-7, so a flat merge
 *   OVERWRITES every light value with its dark counterpart and scores a palette that
 *   exists nowhere. And pairing light.void with dark.bone scores a pair that never
 *   co-occurs on screen. Each palette is scored on its own.
 *
 * HONEST DEGRADATION. The theme supplies the second palette via sn_theme_dark_palette();
 * when it is absent (theme older than v12.0.0, or absent entirely) the report must SAY
 * it measured one palette rather than implying completeness — v11.33.0's rule that a
 * check which could not run is not a check that passed, applied to coverage.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800 ) as $k => $v ) { if ( ! defined( $k ) ) { define( $k, $v ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }

// The WP seam: the root palette. Stubbed because it IS the WordPress boundary.
$GLOBALS['__palette'] = array(
	array( 'slug' => 'void',  'color' => '#ffffff' ),
	array( 'slug' => 'bone',  'color' => '#000000' ),
	array( 'slug' => 'blood', 'color' => '#e00404' ),
);
function wp_get_global_settings( $path ) { return $GLOBALS['__palette']; }

if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
require_once __DIR__ . '/../inc/health-checks.php';       // sn_health_pack_check()
require_once __DIR__ . '/../inc/health-check-color-drift.php';
require_once __DIR__ . '/../inc/health-contrast-tokens.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "health: every palette the theme serves\n\n";

// ── WITHOUT the theme accessor: one palette, and the report SAYS so ────────
$p1 = sn_health_theme_palettes();
ok( array( 'light' ) === array_keys( $p1 ), 'no theme accessor -> exactly one palette, keyed light' );
ok( 3 === count( $p1['light'] ), 'and it carries the root palette' );

$r1 = sn_health_check_contrast_tokens();
ok( 1 === (int) ( $r1['report']['palettes_measured'] ?? 0 ), 'the report states it measured ONE palette' );
ok( false === ( $r1['report']['palettes_complete'] ?? null ), 'AND that this is not the whole picture — never a silent implication of completeness' );

// ── WITH the theme accessor: both palettes, scored SEPARATELY ─────────────
// Declared inside a conditional ON PURPOSE. A top-level `function` is hoisted at
// COMPILE time, so function_exists() would be true from line 1 and the
// "theme absent" phase above could never actually run — it would pass while
// testing nothing. Inside a block, the declaration happens HERE, at runtime.
if ( true ) {
	function sn_theme_dark_palette() {
		return array( 'void' => '#0a0a0a', 'bone' => '#ffffff', 'blood' => '#ff4c47' );
	}
}
// No memo to bust — sn_health_theme_palettes() reads live every call, on purpose.

$p2 = sn_health_theme_palettes();
ok( array( 'light', 'dark' ) === array_keys( $p2 ), 'with the accessor -> two palettes' );
ok( '#0a0a0a' === $p2['dark']['void'], 'dark carries the theme values' );
ok( '#ffffff' === $p2['light']['void'], 'AND LIGHT IS NOT OVERWRITTEN — the 7 slugs collide, so a flat merge would score a palette that exists nowhere' );

$r2 = sn_health_check_contrast_tokens();
ok( 2 === (int) $r2['report']['palettes_measured'], 'the report measured both' );
ok( true === $r2['report']['palettes_complete'], 'and says so' );
ok( isset( $r2['report']['by_palette']['light'], $r2['report']['by_palette']['dark'] ), 'pairs are scored PER PALETTE, not pooled' );

// The pair that motivated all of this.
$dark_pairs = $r2['report']['by_palette']['dark']['pairs'];
// Match on the SLUGS, not on a separator I guessed. The real producer emits
// "blood / void"; my first draft assumed "blood + void" and failed for a reason
// that had nothing to do with the behaviour under test.
$blood_void = null;
foreach ( $dark_pairs as $row ) {
	if ( false !== strpos( $row['pair'], 'blood' ) && false !== strpos( $row['pair'], 'void' ) ) { $blood_void = $row; }
}
ok( null !== $blood_void, 'the dark palette scores its own blood/void pair' );
ok( $blood_void && $blood_void['ratio'] > 4.5, 'DARK blood #ff4c47 on #0a0a0a PASSES AA (6.01) — the theme re-pointed it for exactly this reason' );

// And the light pair is still scored on LIGHT values, unpolluted.
$light_pairs = $r2['report']['by_palette']['light']['pairs'];
$lv = null;
foreach ( $light_pairs as $row ) {
	if ( false !== strpos( $row['pair'], 'blood' ) && false !== strpos( $row['pair'], 'void' ) ) { $lv = $row; }
}
ok( $lv && abs( $lv['ratio'] - 5.01 ) < 0.02, 'light blood #e00404 on #ffffff still scores 5.01 — not silently replaced by the dark value' );

// ── color_drift takes the UNION — opposite treatment, same source ─────────
$hexes = sn_health_allowed_palette_hexes();
foreach ( array( '#ffffff', '#000000', '#e00404', '#0a0a0a', '#ff4c47' ) as $hex ) {
	ok( isset( $hexes[ $hex ] ), "allowed-hex set is a UNION and contains $hex" );
}
ok( 5 === count( $hexes ), 'exactly the five distinct hexes across both palettes — a dark colour is a THEME colour, never drift' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
