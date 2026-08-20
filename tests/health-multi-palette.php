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
ok( array( 'resolved' ) === array_keys( $p1 ), 'no theme accessor -> ONE palette, keyed `resolved`' );
ok( '' === $p1['resolved']['scheme'], 'and its scheme is EMPTY — we do not know which palette WordPress resolved, so we do not claim one (12.1.0 called this `light`, which asserts something unmeasured)' );
ok( 3 === count( $p1['resolved']['colors'] ), 'it carries the resolved palette' );

$r1 = sn_health_check_contrast_tokens();
ok( 1 === (int) ( $r1['report']['palettes_measured'] ?? 0 ), 'the report states it measured ONE palette' );
ok( false === ( $r1['report']['palettes_complete'] ?? null ), 'AND that this is not the whole picture — never a silent implication of completeness' );

// ── WITH the theme's REAL v12.0.0 accessor ────────────────────────────────
// Declared inside a conditional ON PURPOSE. A top-level `function` is hoisted at
// COMPILE time, so function_exists() would be true from line 1 and the
// "theme absent" phase above could never actually run — it would pass while
// testing nothing.
//
// This mirrors sn_theme_all_palettes()'s shipped contract exactly: keys are
// palette IDENTITIES, every entry is a COMPLETE palette, and `scheme` is a
// FIELD. That distinction is the fix — v12.1.0 keyed light/dark, which conflates
// variation with scheme. High Contrast is a LIGHT-scheme variation; dark
// overrides whichever variation is active. They are orthogonal axes, so a High
// Contrast reader on a dark OS gets dark, not a blend — and a flat
// {light,dark,high-contrast} namespace would claim they are alternatives.
if ( true ) {
	function sn_theme_all_palettes() {
		return array(
			'root'          => array( 'scheme' => 'light', 'source' => 'theme.json',
				'colors' => array( 'void' => '#ffffff', 'bone' => '#000000', 'blood' => '#e00404' ) ),
			'high-contrast' => array( 'scheme' => 'light', 'source' => 'styles/high-contrast.json',
				'colors' => array( 'void' => '#ffffff', 'bone' => '#000000', 'blood' => '#e00404', 'rust' => '#333333' ) ),
			'dark'          => array( 'scheme' => 'dark', 'source' => 'assets/css/critical.css',
				'colors' => array( 'void' => '#0a0a0a', 'bone' => '#ffffff', 'blood' => '#ff4c47' ) ),
		);
	}
	function sn_theme_served_palette_id() { return 'high-contrast'; }
}

$p2 = sn_health_theme_palettes();
ok( array( 'root', 'high-contrast', 'dark' ) === array_keys( $p2 ), 'ALL THREE palettes, keyed by IDENTITY — not two, and not keyed by scheme' );
ok( 'light' === $p2['high-contrast']['scheme'], 'SCHEME IS A FIELD — High Contrast is a light-scheme VARIATION, not an alternative to dark' );
ok( 'dark' === $p2['dark']['scheme'], 'and dark carries its own scheme' );
ok( 'styles/high-contrast.json' === $p2['high-contrast']['source'], 'each palette says where it came from' );
ok( '#0a0a0a' === $p2['dark']['colors']['void'] && '#ffffff' === $p2['root']['colors']['void'], 'palettes are kept APART — no palette overwrites another' );

$r2 = sn_health_check_contrast_tokens();
ok( 3 === (int) $r2['report']['palettes_measured'], 'the panel scores all three' );
ok( true === $r2['report']['palettes_complete'], 'and reports the sweep as complete' );
ok( isset( $r2['report']['by_palette']['high-contrast'] ), 'HIGH CONTRAST IS SCORED — it shipped long before dark and was never measured' );
ok( 'high-contrast' === $r2['report']['served'], 'the report names which palette is actually SERVED — the one a reader sees today' );

// The pair that motivated the whole arc, scored on the palette that paints it.
$dark_pairs = $r2['report']['by_palette']['dark']['pairs'];
$bv = null;
foreach ( $dark_pairs as $row ) {
	if ( false !== strpos( $row['pair'], 'blood' ) && false !== strpos( $row['pair'], 'void' ) ) { $bv = $row; }
}
ok( $bv && $bv['ratio'] > 4.5, 'dark blood #ff4c47 on #0a0a0a passes AA (6.01)' );

// Top-level tokens/pairs follow the SERVED palette, not an assumed root.
ok( isset( $r2['report']['tokens']['rust'] ), 'top-level tokens follow the SERVED palette (high-contrast has rust; root does not)' );

// ── color_drift: UNION across every palette ───────────────────────────────
$hexes = sn_health_allowed_palette_hexes();
foreach ( array( '#ffffff', '#000000', '#e00404', '#0a0a0a', '#ff4c47', '#333333' ) as $hex ) {
	ok( isset( $hexes[ $hex ] ), "allowed-hex UNION contains $hex" );
}
ok( 6 === count( $hexes ), 'exactly the six distinct hexes across all three — a variation colour is a THEME colour, never drift' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
