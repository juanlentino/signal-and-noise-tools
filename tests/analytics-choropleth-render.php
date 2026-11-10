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
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }

require_once __DIR__ . '/lib/wp-html-api.php'; // core's real Tag Processor reads and rewrites the path attributes.
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

echo "\nGroup: the vendored map, read by core's HTML parser\n";
// The two hashes were taken from the regex transform this replaced, on the same
// inputs under this suite's stubs: the shipped map must recolor to the same bytes.
$map = (string) file_get_contents( __DIR__ . '/../assets/analytics/world-map.svg' );
$big = snt_analytics_recolor_world_svg( $map, array( 'US' => 1000, 'de' => 10, 'AR' => 77, 'CI' => 5 ), array( 'US' => 'United States', 'CI' => "Côte d'Ivoire" ) );
ok( 'f05b8e1e3e89198068d77fd4aff35811d21c2610a83e608dda947f585b1cd56d' === hash( 'sha256', $big ), 'the shipped map with views recolors to the bytes the regex transform produced' );
ok( '920417330228320fefcd8272f4ef28eb4b187b486543edeb7060c41f08f02f36' === hash( 'sha256', snt_analytics_recolor_world_svg( $map, array(), array() ) ), 'and with no views' );
ok( 211 === substr_count( $big, '<title>' ) && 211 === substr_count( $big, '</path>' ) && false === strpos( $big, 'data-sn-title' ), 'all 211 country paths are titled and closed, and no marker attribute is left behind' );
ok( false !== strpos( $big, '<title>Côte d&#039;Ivoire — 5 views</title>' ), 'a name with an apostrophe is escaped as before' );

// Where the regex was wrong. Each line says what it answered.
$odd = snt_analytics_recolor_world_svg( '<svg><path data-id="US" id="zz" style="fill:#f2f2f2"/><path id=\'DE\' style=\'fill:#f2f2f2\' /><PATH id="PT" style="fill:#f2f2f2" /><path id="ES" style="fill:#f2f2f2"></path><path id="IT" fill="#f2f2f2" /></svg>', array( 'US' => 9, 'DE' => 9, 'PT' => 9 ), array() );
ok( false !== strpos( $odd, '<path data-id="US" id="zz" style="fill:#f2f2f2"/>' ), 'a path whose id is not a country is left alone (the regex read data-id="US" as its id and shaded it)' );
ok( 1 === preg_match( '#<path id=\'DE\' style="fill:rgba\(34,113,177,[0-9.]+\)"><title>DE — 9 views</title></path>#', $odd ), 'a single-quoted id is a country (the regex needed double quotes and skipped it)' );
ok( 1 === preg_match( '#<PATH id="PT" style="fill:rgba\(34,113,177,[0-9.]+\)"><title>PT — 9 views</title></PATH>#', $odd ), 'an uppercase tag is a path (the regex was case-sensitive and skipped it)' );
ok( false !== strpos( $odd, '<path id="ES" style="fill:#f2f2f2"></path>' ), 'a path that is not self-closing is left alone, as before' );
ok( false !== strpos( $odd, '<path id="IT" fill="#f2f2f2"><title>IT</title></path>' ), 'a path with no style is titled and not recolored, as before' );
ok( false === strpos( $odd, 'data-sn-title' ), 'no marker attribute survives' );

echo "\nGroup: orchestrator empty-state (no file needed)\n";
unset( $GLOBALS['sn_an_empty_panels'] );
$empty = capture( function () { snt_analytics_render_choropleth( 'Countries map', array(), 'No country data in this range yet.' ); } );
ok( '' === trim( $empty ), 'render: empty rows → no panel emitted (omit + fold, v8.5.2)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Countries map' === $noted[0]['title'], 'render: empty rows → title noted for the fold' );
ok( 'No country data in this range yet.' === $noted[0]['why'], 'render: empty rows (SVG present) → $empty carried as the fold why, no asset-missing suffix (D4 §4)' );
ok( strpos( $empty, '<svg' ) === false, 'render: empty rows → no SVG emitted' );

echo "\nGroup: orchestrator missing-SVG fold causes (injected empty asset)\n";
// T5 review: two distinct fold causes must not share one why. Data present but
// the vendored asset missing is an operational fault — the why must say ONLY
// that, never the false "no data" copy. No data (asset state irrelevant) keeps
// plain $empty alone.
unset( $GLOBALS['sn_an_empty_panels'] );
$noasset = capture( function () { snt_analytics_render_choropleth( 'Countries map', array( array( 'value' => 'US', 'views' => 100, 'visits' => 40 ) ), 'No country data in this range yet.', '' ); } );
ok( '' === trim( $noasset ), 'render: data + missing SVG → folds, no panel' );
$noted_na = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_na ) && 'World map asset missing.' === $noted_na[0]['why'], 'render: data + missing SVG → why is ONLY the asset fault (no false "no data" copy)' );
unset( $GLOBALS['sn_an_empty_panels'] );
capture( function () { snt_analytics_render_choropleth( 'Countries map', array(), 'No country data in this range yet.', '' ); } );
$noted_nd = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_nd ) && 'No country data in this range yet.' === $noted_nd[0]['why'], 'render: no data (asset also missing) → plain $empty alone, the data gap dominates' );

echo "\nGroup: orchestrator with the real vendored asset\n";
$real = capture( function () { snt_analytics_render_choropleth( 'Countries map', array( array( 'value' => 'US', 'views' => 100, 'visits' => 40 ) ), 'No country data.' ); } );
ok( strpos( $real, 'role="img"' ) !== false && strpos( $real, 'aria-label' ) !== false, 'render: accessible panel (role=img + aria-label)' );
ok( strpos( $real, '<svg' ) !== false && strpos( $real, 'rgba(34,113,177' ) !== false, 'render: real SVG loaded + US recolored' );
ok( strpos( $real, 'United States' ) !== false, 'render: tooltip uses the country name (data-name) from the asset, not the bare ISO code' );
ok( strpos( $real, 'sn-map-legend' ) !== false, 'render: legend strip (Low/Med/High swatches) present' );
ok( strpos( $real, 'postbox' ) !== false, 'render: choropleth wrapped in native postbox' );
// v9.40.0 D4: the choropleth postbox now routes through the shared panel
// primitive — pin the new sn-an-postbox marker alongside the existing
// sn-an-choropleth class, and the exact title.
ok( strpos( $real, 'class="postbox sn-an-postbox sn-an-choropleth"' ) !== false,
	'render: choropleth panel adopts the primitive and keeps its sn-an-choropleth class' );
ok( strpos( $real, '<span>Countries map</span>' ) !== false, 'render: choropleth title stays pinned' );

echo "\nGroup: v9.68.1 — NULL rows (the accessor's failed-read verdict) fold with the read-failure copy\n";
unset( $GLOBALS['sn_an_empty_panels'] );
$failed = capture( function () { snt_analytics_render_choropleth( 'Countries map', null, 'No country data in this range yet.' ); } );
ok( '' === trim( $failed ), 'render: null rows → folds, no panel, no fatal' );
$noted_f = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted_f ) && 'Countries map' === $noted_f[0]['title'], 'render: null rows → title noted for the fold' );
ok( 'Countries map could not be read (read failure: not an empty window).' === ( $noted_f[0]['why'] ?? '' ),
	'render: null rows → the shared read-failure sentence, never the empty-window copy' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
