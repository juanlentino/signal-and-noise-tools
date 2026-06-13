<?php
/**
 * Behavioral tests for the choropleth recolor transform + render orchestrator
 * (inc/analytics-admin-render.php). Transform tests use a tiny fixture SVG so they
 * are deterministic and file-independent; the orchestrator empty-state needs no file.
 * Run: php tests/analytics-choropleth-render.php
 * @since plugin v6.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }

require_once __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); $cb(); return (string) ob_get_clean(); }

// Fixture: 3 country paths mirroring the real SimpleMaps shape (multi-line, self-closing, per-path fill).
$FIX = '<svg viewBox="0 0 2000 1001">' . "\n"
	. '<path id="US" data-id="US" d="m1,1 2,2 z" style="fill:#f2f2f2;fill-rule:evenodd" />' . "\n"
	. '<path id="DE" data-id="DE" d="m3,3 4,4 z" style="fill:#f2f2f2;fill-rule:evenodd" />' . "\n"
	. '<path id="FR" data-id="FR" d="m5,5 6,6 z" style="fill:#f2f2f2;fill-rule:evenodd" />' . "\n"
	. '<path id="robinson" d="m0,0" style="fill:none" />' . "\n"
	. '</svg>';

echo "Choropleth recolor transform\n\n";

$out = snt_analytics_recolor_world_svg( $FIX, array( 'US' => 1000, 'DE' => 10 ), array( 'US' => 'United States', 'DE' => 'Germany' ) );

// Extract the fill alpha for a given country path.
function fix_alpha( $svg, $iso ) {
	if ( preg_match( '/<path[^>]*\bid="' . $iso . '"[^>]*style="fill:rgba\(34,113,177,([0-9.]+)\)/', $svg, $m ) ) { return (float) $m[1]; }
	return null;
}
ok( fix_alpha( $out, 'US' ) !== null && fix_alpha( $out, 'DE' ) !== null, 'recolor: US + DE get a WP-blue rgba fill' );
ok( fix_alpha( $out, 'US' ) > fix_alpha( $out, 'DE' ), 'recolor: higher-views country (US) gets a denser tier than DE' );
ok( strpos( $out, 'id="FR"' ) !== false && preg_match( '/<path[^>]*\bid="FR"[^>]*style="fill:#f0f0f1/', $out ) === 1, 'recolor: zero/absent country (FR) gets the neutral fill' );
ok( preg_match( '/<path[^>]*\bid="robinson"[^>]*fill:none/', $out ) === 1, 'recolor: structural non-country path (robinson) left untouched' );
ok( strpos( $out, '<title>United States — 1,000 views</title>' ) !== false, 'recolor: per-country <title> injected with name + count' );
ok( substr_count( $out, '</path>' ) >= 3, 'recolor: self-closing paths converted to titled <path>…</path>' );

echo "\nGroup: case normalization + escaping\n";
$lc = snt_analytics_recolor_world_svg( $FIX, array( 'us' => 50 ), array() );
ok( fix_alpha( $lc, 'US' ) !== null, 'recolor: lowercase input "us" still shades the US path (uppercase join)' );
$xss = snt_analytics_recolor_world_svg( $FIX, array( 'US' => 5 ), array( 'US' => 'X"<script>' ) );
ok( strpos( $xss, '<script>' ) === false, 'recolor: injected country name is escaped in the title' );
$dn = snt_analytics_recolor_world_svg(
	'<svg><path id="US" data-name="United States" d="m1,1 z" style="fill:#f2f2f2" /></svg>',
	array( 'US' => 7 ),
	array()
);
ok( strpos( $dn, '<title>United States — 7 views</title>' ) !== false, "recolor: falls back to the SVG path's data-name when no name is passed" );

echo "\nGroup: orchestrator empty-state (no file needed)\n";
$empty = capture( function () { snt_analytics_render_choropleth( 'Countries map', array(), 'No country data in this range yet.' ); } );
ok( strpos( $empty, 'No country data in this range yet.' ) !== false, 'render: empty rows → empty-state message' );
ok( strpos( $empty, '<svg' ) === false, 'render: empty rows → no SVG emitted' );

echo "\nGroup: orchestrator with the real vendored asset\n";
$real = capture( function () { snt_analytics_render_choropleth( 'Countries map', array( array( 'value' => 'US', 'views' => 100, 'visits' => 40 ) ), 'No country data.' ); } );
ok( strpos( $real, 'role="img"' ) !== false && strpos( $real, 'aria-label' ) !== false, 'render: accessible panel (role=img + aria-label)' );
ok( strpos( $real, '<svg' ) !== false && strpos( $real, 'rgba(34,113,177' ) !== false, 'render: real SVG loaded + US recolored' );
ok( strpos( $real, 'United States' ) !== false, 'render: tooltip uses the country name (data-name) from the asset, not the bare ISO code' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
