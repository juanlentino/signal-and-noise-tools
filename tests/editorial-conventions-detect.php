<?php
/**
 * Standalone test: the editorial-convention drift detector
 * (inc/editorial-conventions-detect.php), driven with real block markup
 * through the flat fixture parser and the THEME's real registry.
 *
 * Run: php tests/editorial-conventions-detect.php
 *
 * @since 14.7.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
require_once __DIR__ . '/lib/block-parser-fixture.php';
require_once __DIR__ . '/../inc/editorial-conventions-detect.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function ids( $f ) { return array_map( static function ( $x ) { return $x['id'] . ':' . $x['fix']; }, (array) $f ); }

echo "Group 1: no registry, no findings, no silence either\n";
ok( null === snt_editorial_conventions_detect( parse_blocks( '<!-- wp:paragraph --><p><strong>Correction, May 1, 2026.</strong> x</p><!-- /wp:paragraph -->' ) ), 'without the theme registry the detector answers NULL (unavailable), never an empty list' );

// The registry: the theme's real file when a checkout sits beside this repo
// (local), else the fixture copy (CI has no theme checkout). When BOTH exist
// the two function bodies must be byte-identical, so the fixture cannot go
// stale without this suite going red on a developer machine.
echo "\nGroup 0: the registry fixture is the theme's registry\n";
$fixture = __DIR__ . '/fixtures/editorial-conventions-registry.php';
$theme   = getenv( 'HOME' ) . '/Projects/signal-and-noise/inc/editorial-conventions.php';
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability() {} }
if ( file_exists( $theme ) ) {
	$fn = static function ( $file ) { $src = file_get_contents( $file ); $i = strpos( $src, 'function sn_theme_editorial_conventions() {' ); $j = strpos( $src, "\n}\n", $i ); return preg_replace( '/\s+/', ' ', substr( $src, $i, $j - $i ) ); };
	ok( $fn( $theme ) === $fn( $fixture ), 'tests/fixtures/editorial-conventions-registry.php is byte-identical (whitespace-folded) to the theme\'s sn_theme_editorial_conventions() — refresh the fixture from the theme when this goes red' );
	require_once $theme;
} else {
	echo "  (no theme checkout at $theme; using the fixture copy)\n";
	require_once $fixture;
}
ok( is_array( snt_editorial_conventions_registry() ) && isset( snt_editorial_conventions_registry()['correction'] ), 'the theme registry loads and is keyed by id' );

echo "\nGroup 2: correction\n";
$body = "<!-- wp:paragraph -->\n<p>Argument.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p><strong>Correction, September 13, 2026.</strong> An earlier version said X. Y is correct.</p>\n<!-- /wp:paragraph -->";
$f = snt_editorial_conventions_detect( parse_blocks( $body ) );
ok( array( 'correction:class' ) === ids( $f ), 'a trailing "<strong>Correction," paragraph without the class → one class finding' );
ok( '0/2' === $f[0]['block_path'] && false !== strpos( $f[0]['message'], 'convention id: correction' ), '   ...at block_path 0/2 (raw index, separators counted), naming the convention id' );
ok( false !== strpos( $f[0]['replacement'], '{"className":"sn-correction"}' ) && false !== strpos( $f[0]['replacement'], '<p class="sn-correction"><strong>Correction,' ), '   ...with a replacement that adds the class to attrs AND the tag' );
$fixed = str_replace( "<!-- wp:paragraph -->\n<p><strong>Correction", "<!-- wp:paragraph {\"className\":\"sn-correction\"} -->\n<p class=\"sn-correction\"><strong>Correction", $body );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $fixed ) ), 'the house form yields nothing' );
$moved = "<!-- wp:paragraph {\"className\":\"sn-correction\"} -->\n<p class=\"sn-correction\"><strong>Correction, May 1, 2026.</strong> x</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>More argument after it.</p>\n<!-- /wp:paragraph -->";
$f = snt_editorial_conventions_detect( parse_blocks( $moved ) );
ok( array( 'correction:form' ) === ids( $f ) && 1 === $f[0]['evidence']['position'], 'a correction that is not the last block → a placement finding (no replacement: it is a move, not a class)' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( '<!-- wp:paragraph --><p>The word Correction inside prose.</p><!-- /wp:paragraph -->' ) ), 'the word "Correction" in prose is NOT a correction: the shape is the opener, not the word' );

echo "\nGroup 3: references\n";
$refs = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">References</h2>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>Source.</li></ul>\n<!-- /wp:list -->";
$f = snt_editorial_conventions_detect( parse_blocks( $refs ) );
ok( array( 'references:class' ) === ids( $f ) && '0/2' === $f[0]['block_path'] && false !== strpos( $f[0]['replacement'], 'is-style-references' ), 'a list under H2 "References" without the style → class finding on the LIST, replacement styled' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( str_replace( '<!-- wp:list -->', '<!-- wp:list {"className":"is-style-references"} -->', str_replace( '<ul class="wp-block-list">', '<ul class="wp-block-list is-style-references">', $refs ) ) ) ), 'the house form yields nothing' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( str_replace( 'References', 'Further reading', $refs ) ) ), 'a list under a different H2 is not a references list' );

echo "\nGroup 4: lead\n";
$lead = "<!-- wp:paragraph -->\n<p><em>The claim, whole.</em></p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Argument.</p>\n<!-- /wp:paragraph -->";
$f = snt_editorial_conventions_detect( parse_blocks( $lead ) );
ok( array( 'lead:class' ) === ids( $f ) && '0/0' === $f[0]['block_path'], 'a first-block whole-<em> paragraph without sn-lead → class finding at 0/0' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:paragraph -->\n<p>Plain.</p>\n<!-- /wp:paragraph -->\n\n" . $lead ) ), 'the same paragraph NOT first is not a lead' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:paragraph -->\n<p><em>Part</em> and not the rest.</p>\n<!-- /wp:paragraph -->" ) ), 'a partly-italic opener is not a lead' );

echo "\nGroup 5: steps\n";
$steps = "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\"><li><strong>Collection.</strong> A.</li><li><strong>Training.</strong> B.</li><li><strong>Output.</strong> C.</li></ol>\n<!-- /wp:list -->";
$f = snt_editorial_conventions_detect( parse_blocks( $steps ) );
ok( array( 'steps-enumerated:form' ) === ids( $f ) && 3 === $f[0]['evidence']['strong_items'] && '' === $f[0]['replacement'], 'an ordered list of <strong>Term.</strong> items outside the group → form finding, no auto-replacement (it needs wrapping)' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( str_replace( '{"ordered":true}', '{"ordered":true,"className":"sn-steps__list"}', $steps ) ) ), 'inside the group (carrying sn-steps__list) yields nothing' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:list {\"ordered\":true} -->\n<ol><li>one</li><li>two</li></ol>\n<!-- /wp:list -->" ) ), 'a plain ordered list is not steps' );

echo "\nGroup 6: svg-figure\n";
$svg_ok = "<!-- wp:html -->\n<svg viewBox=\"0 0 700 400\" role=\"img\" aria-labelledby=\"t d\"><title id=\"t\">T</title><desc id=\"d\">D</desc><text fill=\"currentColor\">x</text></svg>\n<!-- /wp:html -->";
ok( array() === snt_editorial_conventions_detect( parse_blocks( $svg_ok ) ), 'the house SVG form yields nothing' );
$svg_bad = "<!-- wp:html -->\n<svg viewBox=\"0 0 700 400\"><text fill=\"#222\">x</text><rect fill=\"#dc2626\"/></svg>\n<!-- /wp:html -->";
$f = snt_editorial_conventions_detect( parse_blocks( $svg_bad ) );
ok( array( 'svg-figure:form', 'svg-figure:form' ) === ids( $f ), 'a bare SVG → two form findings: the missing accessible trio, and the fixed hex' );
ok( array( 'role="img"', 'aria-labelledby', '<title>', '<desc>' ) === $f[0]['evidence']['missing'] && array( '#222', '#dc2626' ) === $f[1]['evidence']['hex'], '   ...each naming exactly what is missing / which hexes' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:html -->\n<div>not an svg</div>\n<!-- /wp:html -->" ) ), 'an HTML block without an SVG is not a figure' );

echo "\nGroup 7: the replacement is a class ADD, never a prose change\n";
$b = parse_blocks( "<!-- wp:paragraph {\"className\":\"has-x\"} -->\n<p class=\"has-x\"><strong>Correction, May 1, 2026.</strong> x</p>\n<!-- /wp:paragraph -->" )[0];
$r = snt_editorial_block_with_class( $b, 'sn-correction' );
ok( 'has-x sn-correction' === $r['attrs']['className'] && false !== strpos( $r['innerHTML'], 'class="has-x sn-correction"' ), 'an existing className is extended, on both the attrs and the tag' );
ok( snt_editorial_block_text( $b ) === snt_editorial_block_text( $r ), 'the text is byte-identical after the class add (so the ledger coalesces)' );
ok( $r === snt_editorial_block_with_class( $r, 'sn-correction' ), 'adding a class already present is a no-op' );

echo "\nGroup 8: ACCEPTANCE -- every exemplar the registry hands out comes back clean\n";
// A session that has read no post bodies calls sn-site-facts{editorial_conventions},
// pastes the exemplars, and sn-validate says nothing. If an exemplar trips its
// own detector, the registry and the detector disagree and BOTH are wrong.
foreach ( snt_editorial_conventions_registry() as $id => $row ) {
	if ( '' === (string) $row['exemplar'] ) { continue; }
	$f = snt_editorial_conventions_detect( parse_blocks( (string) $row['exemplar'] ) );
	ok( array() === $f, 'exemplar "' . $id . '" yields no finding' . ( $f ? ' (' . implode( '; ', array_column( $f, 'message' ) ) . ')' : '' ) );
}
// ...and the five the task names, composed by hand WITHOUT the convention, each warn.
$hand = array(
	'correction'       => "<!-- wp:paragraph -->\n<p><strong>Correction, May 1, 2026.</strong> x</p>\n<!-- /wp:paragraph -->",
	'references'       => "<!-- wp:heading {\"level\":2} -->\n<h2>References</h2>\n<!-- /wp:heading -->\n<!-- wp:list -->\n<ul><li>S.</li></ul>\n<!-- /wp:list -->",
	'steps-enumerated' => "<!-- wp:list {\"ordered\":true} -->\n<ol><li><strong>A.</strong> x</li><li><strong>B.</strong> y</li></ol>\n<!-- /wp:list -->",
	'svg-figure'       => "<!-- wp:html -->\n<svg><text>x</text></svg>\n<!-- /wp:html -->",
	'lead'             => "<!-- wp:paragraph -->\n<p><em>Claim.</em></p>\n<!-- /wp:paragraph -->",
);
foreach ( $hand as $id => $markup ) {
	$f = snt_editorial_conventions_detect( parse_blocks( $markup ) );
	ok( ! empty( $f ) && $id === $f[0]['id'], 'hand-rolled "' . $id . '" without the convention → a warning naming ' . $id );
}
// The sidenote is the fifth composable form: a dynamic block, nothing to drift.
ok( array() === snt_editorial_conventions_detect( parse_blocks( snt_editorial_conventions_registry()['sidenote']['exemplar'] ) ), 'the sidenote exemplar (dynamic block) yields nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
