<?php
/**
 * The HTML readers that moved from regular expressions to core's
 * WP_HTML_Tag_Processor and WP_Block_Processor:
 *
 *   - snt_sn_apply_link_reshape_locate()   the link_reshape and unlink WRITE locator
 *   - snt_anchor_violations_scan_content() headings, references lists and links
 *   - snt_emdash_markup_ranges()           what is markup and what is copy
 *   - snt_editorial_table_header(), snt_editorial_svg_missing_attributes(),
 *     snt_editorial_nested_same_class()    the editorial detector's helpers
 *
 * This suite is the characterization: $expect is what each reader returns for
 * every fixture, and wherever that differs from what the former pattern
 * returned, $former holds the old output and the reason. A reader and fixture
 * not in $former returned exactly this before the move.
 *
 * It also pins the write: what the locator hands the splice is, byte for byte,
 * the opening tag core found, the anchor text and "</a>", so the bytes around
 * the link cannot change.
 *
 * Runs core's own classes (tests/lib/wp-core-blocks.php), never a stub.
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/html-readers-on-core.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
// Core's wp_strip_all_tags(), which the anchor scan reads text with.
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }

require_once __DIR__ . '/lib/wp-core-blocks.php';
require_once __DIR__ . '/lib/html-reader-summary.php';
require __DIR__ . '/../inc/emdash-scan.php';
require __DIR__ . '/../inc/sn-scan-anchor-violations.php';
require __DIR__ . '/../inc/editorial-conventions-detect-aids.php';
require __DIR__ . '/../inc/sn-apply/link-reshape.php';

$fixtures = require __DIR__ . '/fixtures/html-reader-inputs.php';
$anchors  = require __DIR__ . '/fixtures/html-reader-anchors.php';
$readers  = array(
	'locate'     => 'snt_sn_apply_link_reshape_locate',
	'violations' => 'snt_anchor_violations_scan_content',
	'ranges'     => 'snt_emdash_markup_ranges',
	'emdash'     => 'snt_emdash_scan_content',
	'thead'      => 'snt_editorial_table_header',
	'svg'        => 'snt_editorial_svg_missing_attributes',
);

// Label => what each reader returns, as tests/lib/html-reader-summary.php prints it.
$expect = array(
	'empty' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'classic, no blocks' => array(
		'locate' => 'the note=7+26',
		'violations' => '',
		'markup' => '0-3 7-21 29-33 54-58 59-63 76-81',
		'emdash' => '42:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'block paragraph with a link' => array(
		'locate' => 'the report=30+58',
		'violations' => '',
		'markup' => '0-21 22-25 30-74 84-88 111-115 116-138',
		'emdash' => '94:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'anchor equals its sentence' => array(
		'locate' => 'The DAW signs the assembly.=37+45',
		'violations' => 'anchor_equals_sentence:The DAW signs the assembly',
		'markup' => '0-21 22-25 37-51 78-82 94-98 99-121',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'heading with a link' => array(
		'locate' => 'the thing=55+27',
		'violations' => 'heading_contains_link:the thing:h2',
		'markup' => '0-19 20-49 55-69 78-82 88-93 94-114 115-136 137-140 154-158 159-181',
		'emdash' => '144:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'references list' => array(
		'locate' => 'a.org=64+34',
		'violations' => '',
		'markup' => '0-50 64-89 94-107 114-139 144-158',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'two identical links' => array(
		'locate' => 'same text=ambiguous',
		'violations' => '',
		'markup' => '0-3 7-21 30-34 43-57 66-70 71-75',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'attributes any order, quotes' => array(
		'locate' => 'this way=6+65',
		'violations' => '',
		'markup' => '0-3 6-59 67-71 76-80',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'entities in the anchor' => array(
		'locate' => 'R&amp;D &lt;fast&gt; caf&eacute;=7+50',
		'violations' => '',
		'markup' => '0-3 7-21 53-57 64-68',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'multibyte' => array(
		'locate' => 'café ☕ 日本語=14+37',
		'violations' => '',
		'markup' => '0-3 14-28 47-51 68-72',
		'emdash' => '9:prose 59:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'CRLF' => array(
		'locate' => 'a link=16+24',
		'violations' => '',
		'markup' => '0-3 16-30 36-40 41-45 47-50 59-63',
		'emdash' => '11:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'code and pre' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-3 7-27 40-44 45-98 99-102 115-119',
		'emdash' => '15:structural 32:prose 80:structural 107:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'script and style' => array(
		'locate' => 'in script=not_found',
		'violations' => '',
		'markup' => '0-3 17-125 131-135',
		'emdash' => '9:prose 40:structural 109:structural',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'em dash in a block comment' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-47 48-51 56-60 61-83',
		'emdash' => '36:structural',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'table with a head' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-17 18-83 84-93 94-127 128-137 144-179 180-198',
		'emdash' => '138:prose',
		'thead' => 'yes 2/1',
		'svg' => 'role="img", aria-labelledby',
	),
	'table without a head' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-53 54-89',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'svg with the trio' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-70 71-92 93-106',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => '',
	),
	'svg without them' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-45',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'> inside an attribute' => array(
		'locate' => 'greater=7+45',
		'violations' => '',
		'markup' => '0-3 7-41 48-52 63-67',
		'emdash' => '34:structural 53:structural',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'link inside a comment' => array(
		'locate' => 'hidden link=not_found; shown link=54+28',
		'violations' => '',
		'markup' => '0-46 54-68 78-82 83-87',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'link inside an attribute' => array(
		'locate' => 'attr link=not_found; real link=60+27',
		'violations' => '',
		'markup' => '0-55 60-74 83-87 88-92',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'heading inside a comment' => array(
		'locate' => 'linked=not_found',
		'violations' => '',
		'markup' => '0-54 55-58 63-67',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'custom element named a-b' => array(
		'locate' => 'not an anchor=not_found',
		'violations' => '',
		'markup' => '0-19 32-36 42-46',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'upper-case tags' => array(
		'locate' => 'upper link=not_found',
		'violations' => 'heading_contains_link:link:h2',
		'markup' => '0-3 7-21 31-35 41-49 52-66 70-79',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'references, single quotes' => array(
		'locate' => 'c.org=44+34',
		'violations' => '',
		'markup' => '0-36 44-69 74-88',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'references with a nested list' => array(
		'locate' => 'd.org=56+34; e.org=115+34',
		'violations' => '',
		'markup' => '0-36 41-49 56-81 86-109 115-140 145-159',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'less-than in prose' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-3 34-38',
		'emdash' => '13:prose',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'comment that never closes' => array(
		'locate' => 'lost link=not_found',
		'violations' => '',
		'markup' => '0-3 18-66',
		'emdash' => '8:prose 35:structural',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'tag cut off at the end' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-3 18-54',
		'emdash' => '8:prose 47:structural',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
	'th scope in single quotes' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-34 35-54 55-78 79-105',
		'emdash' => '',
		'thead' => 'yes 3/1',
		'svg' => 'role="img", aria-labelledby',
	),
	'svg role in single quotes' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-48 49-63',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => '',
	),
	'svg role inside a title attr' => array(
		'locate' => '',
		'violations' => '',
		'markup' => '0-72',
		'emdash' => '',
		'thead' => 'no 0/0',
		'svg' => 'role="img", aria-labelledby',
	),
);

// Fixture, reader, what the former regular expression returned, and why core's reading is the right one.
$former = array(
	array( 'script and style', 'locate', 'in script=46+27', 'a "<a>" spelled inside a <script> is script text, not a link' ),
	array( '> inside an attribute', 'locate', 'greater=not_found', 'the pattern ended the tag at the ">" inside title="a > b", so the link was not found and the title text read as prose' ),
	array( '> inside an attribute', 'markup', '0-3 7-31 48-52 63-67', 'the pattern ended the tag at the ">" inside title="a > b", so the link was not found and the title text read as prose' ),
	array( '> inside an attribute', 'emdash', '33:prose 53:structural', 'the pattern ended the tag at the ">" inside title="a > b", so the link was not found and the title text read as prose' ),
	array( 'link inside a comment', 'locate', 'hidden link=10+29; shown link=54+28', 'a link inside an HTML comment is comment text' ),
	array( 'link inside an attribute', 'locate', 'attr link=13+27; real link=60+27', 'markup spelled inside an attribute value is that value; the pattern would have spliced inside alt=' ),
	array( 'link inside an attribute', 'markup', '0-27 36-40 60-74 83-87 88-92', 'markup spelled inside an attribute value is that value; the pattern would have spliced inside alt=' ),
	array( 'heading inside a comment', 'locate', 'linked=13+24', 'a heading inside an HTML comment is comment text' ),
	array( 'heading inside a comment', 'violations', 'heading_contains_link:linked:h2', 'a heading inside an HTML comment is comment text' ),
	array( 'custom element named a-b', 'locate', 'not an anchor=3+33', 'the pattern also matched <a-b>, which is another element' ),
	array( 'references, single quotes', 'violations', 'anchor_equals_sentence:c.org', 'the pattern knew a double-quoted class only; single quotes spell the same attribute' ),
	array( 'references with a nested list', 'violations', 'anchor_equals_sentence:e.org', 'the lazy pattern ended the references list at the nested list closer' ),
	array( 'less-than in prose', 'markup', '0-3 10-25 34-38', '"< b ... >" in running text is text; the pattern took it for a tag and hid the dash' ),
	array( 'less-than in prose', 'emdash', '14:structural', '"< b ... >" in running text is text; the pattern took it for a tag and hid the dash' ),
	array( 'comment that never closes', 'locate', 'lost link=39+27', 'a browser shows nothing after a comment that never closes, so it is not prose and holds no link' ),
	array( 'comment that never closes', 'markup', '0-3 18-22 39-53 62-66', 'a browser shows nothing after a comment that never closes, so it is not prose and holds no link' ),
	array( 'comment that never closes', 'emdash', '8:prose 34:prose', 'a browser shows nothing after a comment that never closes, so it is not prose and holds no link' ),
	array( 'tag cut off at the end', 'markup', '0-3 18-22', 'a tag cut off at the end is not shown, so its attribute text is not prose' ),
	array( 'tag cut off at the end', 'emdash', '8:prose 46:prose', 'a tag cut off at the end is not shown, so its attribute text is not prose' ),
	array( 'th scope in single quotes', 'markup', '0-34 35-54 55-74 79-105', 'single-quoted and upper-case scope values are the same attribute, and ">" inside title="" does not end the tag' ),
	array( 'th scope in single quotes', 'thead', 'yes 3/3', 'single-quoted and upper-case scope values are the same attribute, and ">" inside title="" does not end the tag' ),
	array( 'svg role in single quotes', 'svg', 'role="img", aria-labelledby', 'a single-quoted role and an unquoted aria-labelledby are the attributes the house form asks for' ),
	array( 'svg role inside a title attr', 'svg', '', 'the text role="img" inside another attribute value is not the attribute' ),
);

echo "Group: every fixture, every reader\n";
ok( array_keys( $fixtures ) === array_keys( $expect ), 'every fixture has an expectation' );
$was = array();
foreach ( $former as $row ) {
	$was[ $row[0] ][ $row[1] ] = $row;
}
foreach ( $fixtures as $label => $content ) {
	$got = snt_test_reader_summary( $content, $anchors, $readers );
	foreach ( $expect[ $label ] as $reader => $want ) {
		$note = isset( $was[ $label ][ $reader ] ) ? ' (formerly "' . $was[ $label ][ $reader ][2] . '": ' . $was[ $label ][ $reader ][3] . ')' : '';
		ok( $want === $got[ $reader ], "$label, $reader: \"$want\"$note" . ( $want === $got[ $reader ] ? '' : ". Got \"{$got[ $reader ]}\"" ) );
	}
}
foreach ( $former as $row ) {
	ok( $expect[ $row[0] ][ $row[1] ] !== $row[2], "{$row[0]}, {$row[1]} really is a difference from the former pattern" );
}

echo "\nGroup: what the locator hands the write is exactly the link's bytes\n";
$located = 0;
foreach ( $fixtures as $label => $content ) {
	foreach ( $anchors as $anchor ) {
		$m = false === strpos( $content, $anchor ) ? null : snt_sn_apply_link_reshape_locate( $content, $anchor );
		if ( ! is_array( $m ) ) {
			continue;
		}
		++$located;
		$bytes = substr( $content, $m['offset'], $m['length'] );
		$probe = new WP_HTML_Tag_Processor( $m['open_tag'] );
		ok( $m['open_tag'] . $anchor . '</a>' === $bytes && $probe->next_tag() && 'A' === $probe->get_tag() && ! $probe->next_token(), "$label, \"$anchor\": the match is one whole <a> opening tag, the anchor text and </a>, as stored" );
		// unlink is substr_replace() of that range with the anchor text (snt_sn_apply_unlink_compute).
		$unlinked = substr_replace( $content, $anchor, $m['offset'], $m['length'] );
		ok( substr( $content, 0, $m['offset'] ) . $anchor . substr( $content, $m['offset'] + $m['length'] ) === $unlinked, "$label, \"$anchor\": every byte before and after the link is as stored" );
	}
}
ok( $located >= 14, "the locator found real links ($located), so the two checks above are not vacuous" );
$two = snt_sn_apply_link_reshape_locate( $fixtures['two identical links'], 'same text' );
ok( is_wp_error( $two ) && 'snt_sn_apply_anchor_ambiguous' === $two->get_error_code(), 'two identical links without a context snippet are still refused as ambiguous' );
$one = snt_sn_apply_link_reshape_locate( $fixtures['two identical links'] . str_repeat( ' pad', 200 ) . '<p>Far <a href="/c/">same text</a> END</p>', 'same text', 'END' );
ok( is_array( $one ) && '<a href="/c/">' === $one['open_tag'], 'and the context snippet still picks one of several' );

echo "\nGroup: an inline SVG's <title> text stays copy\n";
$svg = '<svg role="img"><title id="t" data-x="a > b">Signal — noise</title><desc>Plain</desc></svg>';
$row = snt_emdash_scan_content( $svg )[0];
ok( 'prose' === $row['classification'], 'core hands <title> over as one token with its text; the dash in that text is still prose' );
ok( 'structural' === snt_emdash_scan_content( '<svg><title data-x="a — b">T</title></svg>' )[0]['classification'], 'and a dash in the <title> tag\'s own attribute is still markup' );

echo "\nGroup: a group nested in a group of its own class\n";
$groups = array(
	'no nesting'                  => array( 0, '<!-- wp:group {"className":"sn-x"} --><div><!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ),
	'nested'                      => array( 1, '<!-- wp:group {"className":"k sn-x"} --><div><!-- wp:group {"className":"sn-x"} --><div></div><!-- /wp:group --></div><!-- /wp:group -->' ),
	'siblings'                    => array( 0, '<!-- wp:group {"className":"sn-x"} --><div></div><!-- /wp:group --><!-- wp:group {"className":"sn-x"} --><div></div><!-- /wp:group -->' ),
	'a void group inside'         => array( 0, '<!-- wp:group {"className":"sn-x"} --><div><!-- wp:group {"className":"sn-x"} /--></div><!-- /wp:group -->' ),
	'another group between'       => array( 1, '<!-- wp:group {"className":"sn-x"} --><div><!-- wp:group --><div><!-- wp:group {"className":"sn-x"} --><div></div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->' ),
	'a brace in a JSON string'    => array( 1, '<!-- wp:group {"className":"sn-x","note":"a } b"} --><div><!-- wp:group {"className":"sn-x"} --><div></div><!-- /wp:group --></div><!-- /wp:group -->' ),
	'empty'                       => array( 0, '' ),
	// Formerly 0: the pattern knew "wp:group" only, and core/group is the same block.
	'spelled wp:core/group'       => array( 1, '<!-- wp:core/group {"className":"sn-x"} --><div><!-- wp:core/group {"className":"sn-x"} --><div></div><!-- /wp:core/group --></div><!-- /wp:core/group -->' ),
);
foreach ( $groups as $label => $case ) {
	ok( $case[0] === snt_editorial_nested_same_class( $case[1], 'sn-x' ), "$label: {$case[0]}" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
