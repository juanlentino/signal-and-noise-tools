<?php
/**
 * Signal & Noise Tools -- tests for the contrast USAGE tier.
 *
 * The tier exists because two scans that were individually correct both missed
 * a live failure: the arithmetic tier because it scores token pairs rather than
 * rendered ones, and the theme-side original because it required an enclosing
 * token-painted surface and the failing component had none. So the headline
 * test here is not "the parser parses" — it is the PROVENANCE CHIP REGRESSION
 * (Group 4), a component with a hardcoded colour, no background of its own, and
 * a 3.49:1 ratio against the page it lands on. If that group ever goes quiet,
 * this module has stopped earning its place.
 *
 * Every ratio below is hand-derived from the WCAG formula and pinned as a
 * literal. The tests never recompute an expectation with the code under test —
 * that only proves the code agrees with itself.
 *
 * Run: php tests/health-contrast-usage.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );

function sn_health_normalize_hex( $color ) {
	$hex = strtolower( trim( (string) $color ) );
	if ( preg_match( '/^#[0-9a-f]{3}$/', $hex ) ) {
		return '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
	}
	return preg_match( '/^#[0-9a-f]{6}$/', $hex ) ? $hex : '';
}

require_once __DIR__ . '/../inc/health-contrast-tokens.php';
require_once __DIR__ . '/../inc/health-contrast-usage.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$void    = array( 'kind' => 'literal', 'value' => '#ffffff' );
$palette = array(
	'root'          => array( 'void' => '#ffffff', 'asphalt' => '#f5f5f5', 'blood' => '#e00404', 'bone' => '#000000' ),
	'High Contrast' => array( 'void' => '#ffffff', 'asphalt' => '#e0e0e0', 'blood' => '#e00404', 'bone' => '#000000' ),
);

// ─── Group 1: rule parsing ─────────────────────────────────────────
echo "\nGroup 1: rule parsing\n";
$rules = sn_health_contrast_usage_rules( '/* c */ .a { color: red; } @media x { .b { color: blue; } }' );
ok( count( $rules ) >= 1, 'parses rules and strips comments' );
$sels = array_column( $rules, 'sel' );
ok( ! in_array( '@media x', $sels, true ), 'at-rule preludes are not treated as selectors' );

// ─── Group 2: colour reading ───────────────────────────────────────
echo "\nGroup 2: colour reading\n";
$tok = sn_health_contrast_usage_read_color( 'color: var(--wp--preset--color--blood);', 'color' );
ok( 'token' === $tok['kind'] && 'blood' === $tok['value'], 'reads a token reference' );

$lit = sn_health_contrast_usage_read_color( 'color:#1F9D55;', 'color' );
ok( $lit && 'literal' === $lit['kind'] && '#1f9d55' === $lit['value'], 'reads and normalises a literal hex' );

ok( null === sn_health_contrast_usage_read_color( 'background: transparent;', 'background' ), 'transparent is not a surface' );
ok( null === sn_health_contrast_usage_read_color( 'color: currentColor;', 'color' ), 'currentColor is unscoreable, not guessed' );
ok( null === sn_health_contrast_usage_read_color( 'color: color-mix(in srgb, #fff 40%, transparent);', 'color' ), 'color-mix() is unscoreable' );
ok( null === sn_health_contrast_usage_read_color( 'border-color:#000;', 'color' ), 'border-color is not text colour' );
ok( null === sn_health_contrast_usage_read_color( 'background-image: url(x.png);', 'background' ), 'a background image is not a colour' );

// ─── Group 3: surface derivation ───────────────────────────────────
echo "\nGroup 3: surface derivation excludes pseudo-elements and states\n";
$css = '.card { background: var(--wp--preset--color--asphalt); }'
	. '.card::before { background: var(--wp--preset--color--blood); }'
	. '.card:hover { background: var(--wp--preset--color--bone); }';
$surfaces = sn_health_contrast_usage_surfaces( sn_health_contrast_usage_rules( $css ) );
ok( isset( $surfaces['.card'] ) && 'asphalt' === $surfaces['.card']['value'], 'the element\'s own background is the surface' );
ok( ! isset( $surfaces['.card::before'] ), 'a pseudo-element rail is NOT a surface (the blood-on-blood false positive)' );
ok( ! isset( $surfaces['.card:hover'] ), 'a hover background is NOT a resting surface (the ~60 false positives)' );
ok( 1 === count( $surfaces ), 'exactly one surface derived from three rules' );

// ─── Group 4: THE PROVENANCE CHIP REGRESSION ───────────────────────
// A component with a hardcoded colour and NO background of its own. Its
// contrast is a property of placement, which is why the arithmetic tier
// cannot see it and why a surface-requiring scan drops it entirely.
// #1f9d55 on #ffffff = 3.49:1, hand-derived, below the 4.5 floor.
echo "\nGroup 4: the provenance chip — no background, hardcoded colour\n";
$chip  = sn_health_contrast_usage_rules( '.sn-prov-chip__state--confirmed { color: #1f9d55; font-size: .72rem; }' );
$pairs = sn_health_contrast_usage_pairings( $chip, sn_health_contrast_usage_surfaces( $chip ), $void, 'plugin/provenance-front.css' );
ok( 1 === count( $pairs ), 'a backgroundless component still yields a pairing' );
ok( false === $pairs[0]['anchored'], 'it is marked unanchored — scored against the page background' );
ok( 'literal' === $pairs[0]['fg']['kind'], 'its colour is recorded as a hardcoded literal' );

$ratio = sn_health_contrast_ratio( '#1f9d55', '#ffffff' );
ok( abs( $ratio - 3.49 ) < 0.01, 'confirmed-green on white is 3.49:1 (hand-derived), i.e. below AA' );

// ─── Group 5: anchored pairings ────────────────────────────────────
echo "\nGroup 5: anchored pairings resolve to the enclosing surface\n";
$nested = sn_health_contrast_usage_rules(
	'.panel { background: var(--wp--preset--color--asphalt); }'
	. '.panel-note { color: var(--wp--preset--color--blood); }'
);
$np = sn_health_contrast_usage_pairings( $nested, sn_health_contrast_usage_surfaces( $nested ), $void, 't' );
ok( 1 === count( $np ) && true === $np[0]['anchored'], 'a BEM child anchors to its parent surface' );
ok( 'asphalt' === $np[0]['bg']['value'], 'it resolves to asphalt, not the page background' );

$same = sn_health_contrast_usage_rules( '.x { color: var(--wp--preset--color--bone); background: var(--wp--preset--color--bone); }' );
ok( 0 === count( sn_health_contrast_usage_pairings( $same, array(), $void, 't' ) ), 'identical fg/bg is skipped, not reported as 1.00:1' );

// ─── Group 6: per-palette scoring — the false-green that started this ──
echo "\nGroup 6: the same pairing scored under every palette\n";
// blood #e00404 on asphalt: 4.60:1 at root (passes), 3.80:1 under High
// Contrast's #e0e0e0 (fails). Root-only scoring is how this was missed.
ok( abs( sn_health_contrast_ratio( '#e00404', '#f5f5f5' ) - 4.60 ) < 0.01, 'blood on root asphalt is 4.60:1 — passes' );
ok( abs( sn_health_contrast_ratio( '#e00404', '#e0e0e0' ) - 3.80 ) < 0.01, 'blood on High Contrast asphalt is 3.80:1 — fails' );

$resolved_root = sn_health_contrast_usage_resolve( array( 'kind' => 'token', 'value' => 'asphalt' ), $palette['root'] );
$resolved_hc   = sn_health_contrast_usage_resolve( array( 'kind' => 'token', 'value' => 'asphalt' ), $palette['High Contrast'] );
ok( '#f5f5f5' === $resolved_root && '#e0e0e0' === $resolved_hc, 'a token resolves differently per palette' );

$literal_root = sn_health_contrast_usage_resolve( array( 'kind' => 'literal', 'value' => '#1f9d55' ), $palette['root'] );
$literal_hc   = sn_health_contrast_usage_resolve( array( 'kind' => 'literal', 'value' => '#1f9d55' ), $palette['High Contrast'] );
ok( $literal_root === $literal_hc, 'a hardcoded literal is palette-invariant — the fidelity half of the bug' );

ok( null === sn_health_contrast_usage_resolve( array( 'kind' => 'token', 'value' => 'ghost' ), $palette['root'] ), 'an unknown token resolves to null rather than a guess' );

// ─── Group 7: negative control ─────────────────────────────────────
// A scan that only ever fires is as useless as one that never does.
echo "\nGroup 7: negative control — passing pairings stay quiet\n";
$good = sn_health_contrast_usage_rules( '.ok { background: var(--wp--preset--color--void); color: var(--wp--preset--color--bone); }' );
$gp   = sn_health_contrast_usage_pairings( $good, sn_health_contrast_usage_surfaces( $good ), $void, 't' );
ok( 1 === count( $gp ), 'the passing pairing is still detected' );
ok( 21.0 === round( sn_health_contrast_ratio( '#000000', '#ffffff' ), 1 ), 'and scores 21:1, so it will never be reported as a failure' );

// ─── Group 8: containment is not substring matching ────────────────
echo "\nGroup 8: containment\n";
ok( sn_health_contrast_usage_contains( '.card-title', '.card' ), 'BEM-ish child is contained' );
ok( sn_health_contrast_usage_contains( '.card .note', '.card' ), 'descendant is contained' );
ok( ! sn_health_contrast_usage_contains( '.cardboard', '.card-' ), 'a different class is not contained' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
