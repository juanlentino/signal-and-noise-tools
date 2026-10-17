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

// A correction is recognized by its TEXT, whatever wraps it: the italic,
// colon form on note 1514 (fixed by hand 2026-10-17) matched nothing before.
$para  = static function ( $inner, $cls = '' ) { return '<!-- wp:paragraph' . ( $cls ? ' {"className":"' . $cls . '"}' : '' ) . " -->\n<p" . ( $cls ? ' class="' . $cls . '"' : '' ) . '>' . $inner . "</p>\n<!-- /wp:paragraph -->"; };
$arg   = $para( 'Argument.' ) . "\n\n";
$msgs  = static function ( $f ) { return implode( ' | ', array_column( (array) $f, 'message' ) ); };
$f = snt_editorial_conventions_detect( parse_blocks( $arg . $para( '<em>Correction, October 1, 2026: An earlier version said X. Y is correct.</em>' ) ) );
ok( array( 'correction:form', 'correction:form' ) === ids( $f ), 'an italic colon-form correction without the class → a missing-class finding AND a form finding, neither with a fix' );
ok( '' === $f[0]['replacement'] && false !== strpos( $f[0]['message'], 'sn-correction class' ), '   ...the missing-class finding carries NO class-only replacement (adding it would leave the lead-in wrong)' );
ok( '' === $f[1]['replacement'] && false !== strpos( $f[1]['message'], 'lead-in' ), '   ...the form finding names the lead-in and carries no replacement (signed prose: the owner applies it)' );
$f = snt_editorial_conventions_detect( parse_blocks( $arg . $para( '<em>Correction, October 1, 2026: x</em>', 'sn-correction' ) ) );
ok( array( 'correction:form' ) === ids( $f ) && false !== strpos( $f[0]['message'], 'lead-in' ), 'the class present but the lead-in italic and colon → one form finding' );
ok( array( 'correction:form' ) === ids( snt_editorial_conventions_detect( parse_blocks( $arg . $para( '<strong>Correction, October 1, 2026:</strong> x', 'sn-correction' ) ) ) ), 'bold but a colon after the date → a form finding' );
$f = snt_editorial_conventions_detect( parse_blocks( $arg . $para( '<strong>Correction, October 1, 2026.</strong> An earlier version said X.' ) ) );
ok( array( 'correction:class' ) === ids( $f ) && false !== strpos( $f[0]['replacement'], 'class="sn-correction"' ), 'the house lead-in without the class → the class finding with its fix (unchanged)' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $arg . $para( '<strong>Correction, October 1, 2026.</strong> x', 'sn-correction' ) ) ), 'house form with the class, last block → nothing' );
$f = snt_editorial_conventions_detect( parse_blocks( $para( '<strong>Correction, October 1, 2026.</strong> x', 'sn-correction' ) . "\n\n" . $para( 'After.' ) ) );
ok( array( 'correction:form' ) === ids( $f ) && isset( $f[0]['evidence']['position'] ), 'house form with the class, not last → the not-last finding only' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $arg . $para( 'We ran a correction, October 1, 2026, on the figure.' ) ) ), 'a paragraph that mentions a correction mid-sentence → nothing' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $arg . $para( 'See the <em>Correction, October 1, 2026.</em> below.' ) ) ), '"Correction," that does not OPEN the paragraph → nothing' );
foreach ( array( 'b' => '<b>Correction, October 1, 2026.</b> x', 'i' => '<i>Correction, October 1, 2026.</i> x', 'none' => 'Correction, October 1, 2026. x', 'nested' => '<em><strong> Correction, October 1, 2026:</strong></em> x', 'lower' => '<strong>correction, October 1, 2026.</strong> x' ) as $k => $inner ) {
	$f = snt_editorial_conventions_detect( parse_blocks( $arg . $para( $inner ) ) );
	ok( in_array( 'correction:form', ids( $f ), true ) && false !== strpos( $msgs( $f ), 'sn-correction class' ) && false !== strpos( $msgs( $f ), 'lead-in' ), 'the "' . $k . '" variant is detected: missing class (no fix) + form' );
}

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

echo "\nGroup 7b: timeline -- a dated sequence set as steps\n";
$li    = static function ( $inner ) { return "<!-- wp:list-item -->\n<li>$inner</li>\n<!-- /wp:list-item -->\n"; };
$dated = "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" . $li( '<strong>Dec 2015.</strong> Beta opens.' ) . $li( '<strong>Jan 2017.</strong> Chrome 56 warns.' ) . $li( '<strong>Jul 2018.</strong> Chrome 68 warns everywhere.' ) . "</ol>\n<!-- /wp:list -->";
$f = snt_editorial_conventions_detect( parse_blocks( $dated ) );
ok( array( 'timeline:form' ) === ids( $f ) && 3 === $f[0]['evidence']['dated_items'], 'an ordered list whose every item opens with a date → ONE timeline finding, and no steps finding on the same list (' . implode( ' ', ids( $f ) ) . ')' );
ok( isset( $f[0] ) && false !== strpos( $f[0]['message'], 'convention id: timeline' ) && '' === $f[0]['replacement'], '   ...a form fix (a re-wrap) naming the convention id' );
$mixed = str_replace( '<strong>Jul 2018.</strong>', '<strong>Later.</strong>', $dated );
ok( array( 'steps-enumerated:form' ) === ids( snt_editorial_conventions_detect( parse_blocks( $mixed ) ) ), 'one undated item → not a timeline; the steps finding stands' );
$years = "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" . $li( '2019. The first registry.' ) . $li( 'March 2021: the second.' ) . "</ol>\n<!-- /wp:list -->";
ok( array( 'timeline:form' ) === ids( snt_editorial_conventions_detect( parse_blocks( $years ) ) ), 'a bare year and a full month name both count as a date opener, with or without <strong>' );
$in_steps = "<!-- wp:group {\"className\":\"sn-pattern-steps-enumerated\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group sn-pattern-steps-enumerated\"><!-- wp:paragraph {\"className\":\"sn-steps__label\"} -->\n<p class=\"sn-steps__label\">History</p>\n<!-- /wp:paragraph -->\n" . str_replace( '{"ordered":true}', '{"ordered":true,"className":"sn-steps__list"}', str_replace( '<ol class="wp-block-list">', '<ol class="wp-block-list sn-steps__list">', $dated ) ) . "</div>\n<!-- /wp:group -->";
$f = snt_editorial_conventions_detect( parse_blocks( $in_steps ) );
ok( array( 'timeline:form' ) === ids( $f ) && '0/0' === $f[0]['block_path'], 'a steps GROUP whose every item is dated → a timeline finding at the group' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( str_replace( array( '{"ordered":true}', '<ol class="wp-block-list">', '</ol>' ), array( '', '<ul class="wp-block-list">', '</ul>' ), $dated ) ) ), 'an UNORDERED plain list of dated items is left alone (prose lists of dates are not the shape)' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" . $li( '<strong>Dec 2015.</strong> Only one.' ) . "</ol>\n<!-- /wp:list -->" ) ), 'a single dated item is not a sequence' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" . $li( '1500 tracks a day.' ) . $li( 'Mayday is not a month.' ) . "</ol>\n<!-- /wp:list -->" ) ), 'a number that only looks like a year beside a word that only starts like a month: no finding' );

echo "\nGroup 7c: a steps or compare group nested inside its own kind (malformed)\n";
$steps_group = static function ( $inner ) { return "<!-- wp:group {\"className\":\"sn-pattern-steps-enumerated\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group sn-pattern-steps-enumerated\">$inner</div>\n<!-- /wp:group -->"; };
$steps_list  = "<!-- wp:list {\"ordered\":true,\"className\":\"sn-steps__list\"} -->\n<ol class=\"wp-block-list sn-steps__list\">" . $li( '<strong>One.</strong> A.' ) . $li( '<strong>Two.</strong> B.' ) . "</ol>\n<!-- /wp:list -->";
$f = snt_editorial_conventions_detect( parse_blocks( $steps_group( $steps_group( $steps_list ) ) ) );
ok( array( 'steps-enumerated:form' ) === ids( $f ) && 1 === $f[0]['evidence']['nested'] && '0/0' === $f[0]['block_path'], 'steps-in-steps → ONE malformed finding at the outer group, nested:1' );
ok( isset( $f[0] ) && false !== strpos( $f[0]['message'], 'nested' ) && false !== strpos( $f[0]['message'], 'convention id: steps-enumerated' ), '   ...saying it is nested, naming the convention id' );
$wrapper = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">" . $steps_group( $steps_group( $steps_list ) ) . "</div>\n<!-- /wp:group -->";
ok( array( 'steps-enumerated:form' ) === ids( snt_editorial_conventions_detect( parse_blocks( $wrapper ) ) ), 'found when the malformed pair sits inside a plain group too' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $steps_group( $steps_list ) . "\n\n" . $steps_group( $steps_list ) ) ), 'two steps groups side by side are fine' );
$cmp = static function ( $inner ) { return "<!-- wp:group {\"className\":\"sn-pattern-compare-columns\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group sn-pattern-compare-columns\">$inner</div>\n<!-- /wp:group -->"; };
$f = snt_editorial_conventions_detect( parse_blocks( $cmp( $cmp( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) ) ) );
ok( array( 'compare-columns:form' ) === ids( $f ) && 1 === $f[0]['evidence']['nested'], 'compare-in-compare → one malformed finding' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $cmp( $steps_group( $steps_list ) ) ) ), 'a steps group inside compare columns is two different conventions, not a nesting error' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( "<!-- wp:group {\"className\":\"sn-pattern-steps-enumerated-x\"} -->\n<div class=\"wp-block-group\">" . $steps_group( $steps_list ) . "</div>\n<!-- /wp:group -->" ) ), 'a class that merely starts with the name is not the class (whole token)' );

echo "\nGroup 7d: table\n";
$table = static function ( $cls, $head ) {
	$attrs = '' === $cls ? '' : ' {"className":"' . $cls . '"}';
	$fig   = trim( 'wp-block-table ' . $cls );
	return "<!-- wp:table$attrs -->\n<figure class=\"$fig\"><table class=\"has-fixed-layout\">$head<tbody><tr><td>ISRC</td><td>Recording</td><td>Agency</td></tr></tbody></table></figure>\n<!-- /wp:table -->";
};
$head_ok  = '<thead><tr><th scope="col">Id</th><th scope="col">Names</th><th scope="col">Issuer</th></tr></thead>';
$head_bad = '<thead><tr><th>Id</th><th>Names</th><th>Issuer</th></tr></thead>';
$f = snt_editorial_conventions_detect( parse_blocks( $table( '', $head_ok ) ) );
ok( array( 'table:class' ) === ids( $f ) && false !== strpos( $f[0]['replacement'], '{"className":"is-style-sn-table"}' ) && false !== strpos( $f[0]['replacement'], '<figure class="wp-block-table is-style-sn-table">' ), 'an unstyled table with a proper header → a CLASS fix adding the style to attrs and the figure' );
$f = snt_editorial_conventions_detect( parse_blocks( $table( '', '' ) ) );
ok( array( 'table:form' ) === ids( $f ) && false !== strpos( $f[0]['message'], 'header row' ), 'an unstyled table with NO header → a FORM fix (the style alone would dress up a table that breaks its own convention)' );
$f = snt_editorial_conventions_detect( parse_blocks( $table( 'is-style-sn-table', $head_bad ) ) );
ok( array() === ids( $f ), 'a styled table whose header cells lack scope="col" (what the editor writes) is the house form: the theme adds scope on render' );
ok( array() === snt_editorial_conventions_detect( parse_blocks( $table( 'is-style-sn-table', $head_ok ) ) ), 'the house form yields nothing' );

echo "\nGroup 8: ACCEPTANCE -- every exemplar the registry hands out comes back clean\n";
// A session that has read no post bodies calls sn-site-facts{editorial_conventions},
// pastes the exemplars, and sn-validate says nothing. If an exemplar trips its
// own detector, the registry and the detector disagree and BOTH are wrong.
foreach ( snt_editorial_conventions_registry() as $id => $row ) {
	if ( '' === (string) $row['exemplar'] ) { continue; }
	$f = snt_editorial_conventions_detect( parse_blocks( (string) $row['exemplar'] ) );
	ok( array() === $f, 'exemplar "' . $id . '" yields no finding' . ( $f ? ' (' . implode( '; ', array_column( $f, 'message' ) ) . ')' : '' ) );
}
// ...and the seven the tasks name, composed by hand WITHOUT the convention, each warn.
$hand = array(
	'correction'       => "<!-- wp:paragraph -->\n<p><strong>Correction, May 1, 2026.</strong> x</p>\n<!-- /wp:paragraph -->",
	'references'       => "<!-- wp:heading {\"level\":2} -->\n<h2>References</h2>\n<!-- /wp:heading -->\n<!-- wp:list -->\n<ul><li>S.</li></ul>\n<!-- /wp:list -->",
	'steps-enumerated' => "<!-- wp:list {\"ordered\":true} -->\n<ol><li><strong>A.</strong> x</li><li><strong>B.</strong> y</li></ol>\n<!-- /wp:list -->",
	'svg-figure'       => "<!-- wp:html -->\n<svg><text>x</text></svg>\n<!-- /wp:html -->",
	'lead'             => "<!-- wp:paragraph -->\n<p><em>Claim.</em></p>\n<!-- /wp:paragraph -->",
	'timeline'         => "<!-- wp:list {\"ordered\":true} -->\n<ol><li><strong>Dec 2015.</strong> x</li><li><strong>Jul 2018.</strong> y</li></ol>\n<!-- /wp:list -->",
	'table'            => "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>a</td><td>b</td><td>c</td></tr></tbody></table></figure>\n<!-- /wp:table -->",
);
foreach ( $hand as $id => $markup ) {
	$f = snt_editorial_conventions_detect( parse_blocks( $markup ) );
	ok( ! empty( $f ) && $id === $f[0]['id'], 'hand-rolled "' . $id . '" without the convention → a warning naming ' . $id );
}
// The sidenote is the fifth composable form: a dynamic block, nothing to drift.
ok( array() === snt_editorial_conventions_detect( parse_blocks( snt_editorial_conventions_registry()['sidenote']['exemplar'] ) ), 'the sidenote exemplar (dynamic block) yields nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
