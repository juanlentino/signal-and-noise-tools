<?php
/**
 * The sn_apply block-delimiter reader, on core's WP_Block_Processor.
 *
 * Two regular expressions used to find block delimiters on the write path, one
 * for the block_edit spans and one for create_draft's balance check, and they
 * disagreed with each other and with core. Both now read
 * snt_sn_apply_block_delimiters() (inc/sn-apply/block-delimiters.php).
 *
 * This suite is the characterization: $expect is what the two consumers
 * return for every fixture, and wherever that differs from what the former
 * patterns returned, $former holds the old output and the reason. A fixture
 * not in $former returned exactly this before the move.
 *
 * It also pins the write: the span a block edit splices is, byte for byte, the
 * block core's real parser sees at that path, and a span the parser does not
 * see as the same block is refused.
 *
 * Runs core's own classes (tests/lib/wp-core-blocks.php), never a stub.
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/sn-apply-block-delimiters.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function snt_sn_validate_finding( $surface, $check, $severity, $message, $a, $b, $c, $key ) { return array( 'check' => $check, 'key' => $key ); }

define( 'SNT_TEST_WITH_BLOCKS_PHP', true ); // The real parse_blocks().
require_once __DIR__ . '/lib/wp-core-blocks.php';
require __DIR__ . '/../inc/sn-apply/block-delimiters.php';
require __DIR__ . '/../inc/sn-apply/block-edit.php';
require __DIR__ . '/lib/parser-walker-agree.php';
// create-draft.php needs the whole family; its one delimiter function is read alone.
preg_match( '/^function snt_sn_apply_block_delimiter_findings\(.*?^}/ms', (string) file_get_contents( __DIR__ . '/../inc/sn-apply/create-draft.php' ), $snt_fn );
$snt_tmp = tempnam( sys_get_temp_dir(), 'snt' );
file_put_contents( $snt_tmp, "<?php\n" . $snt_fn[0] );
require $snt_tmp;
unlink( $snt_tmp );

$fixtures = require __DIR__ . '/fixtures/block-delimiter-inputs.php';

/** "start-end:name ... | start-end ..." for a scan, and the finding keys without their common prefix. */
function spans_line( array $scan ) {
	$spans = array_map( static function ( $s ) { return $s['start'] . '-' . $s['end'] . ':' . $s['block_name']; }, $scan['spans'] );
	$delim = array_map( static function ( $d ) { return $d[0] . '-' . $d[1]; }, $scan['delimiters'] );
	return implode( ' ', $spans ) . ' | ' . implode( ' ', $delim );
}
function findings_line( $content ) {
	return implode( ' ', array_map( static function ( $f ) { return str_replace( 'create_draft|block_delimiters|', '', $f['key'] ); }, snt_sn_apply_block_delimiter_findings( $content ) ) );
}

// Label => array( top-level spans | every delimiter, create_draft findings ).
$expect = array(
	'empty'                     => array( ' | ', '' ),
	'classic, no blocks'        => array( ' | ', '' ),
	'one paragraph'             => array( '0-56:paragraph | 0-21 34-56', '' ),
	'two top-level'             => array( '0-56:paragraph 58-148:heading | 0-21 34-56 58-89 128-148', '' ),
	'nested group'              => array( '0-269:group | 0-35 66-87 99-121 123-139 166-187 199-221 227-244 251-269', '' ),
	'void blocks'               => array( '0-22:separator 23-76:paragraph 77-120:latest-posts | 0-22 23-44 54-76 77-120', '' ),
	'void inside a group'       => array( '0-108:group | 0-17 46-83 90-108', '' ),
	'freeform between blocks'   => array( '11-64:paragraph 83-136:paragraph | 11-32 42-64 83-104 114-136', '' ),
	'namespaced block'          => array( '0-93:acme/card | 0-30 71-93', '' ),
	'JSON with braces'          => array( '0-138:group | 0-84 120-138', '' ),
	'JSON with escaped arrow'   => array( '0-87:paragraph | 0-55 65-87', '' ),
	'multibyte text'            => array( '0-90:paragraph 92-153:paragraph | 0-21 68-90 92-113 131-153', '' ),
	'CRLF line endings'         => array( '0-58:paragraph 62-120:paragraph | 0-21 36-58 62-83 98-120', '' ),
	'entities and attributes'   => array( '0-105:paragraph | 0-21 83-105', '' ),
	'plain HTML comment'        => array( '0-53:paragraph 95-148:paragraph | 0-21 31-53 95-116 126-148', '' ),
	'unclosed opener'           => array( ' | 0-21', 'unclosed|paragraph' ),
	'stray closer'              => array( ' | 9-31', 'mismatch|paragraph' ),
	'mismatched closer'         => array( '0-51:paragraph | 0-21 31-51', 'mismatch|heading' ),
	'unclosed inside a group'   => array( ' | 0-17 23-44 60-78', 'mismatch|group unclosed|group' ),
	'core/ namespace spelled'   => array( '0-58:paragraph | 0-26 36-58', '' ),
	'no space after <!--'       => array( ' | ', '' ),
	'no space before -->'       => array( ' | ', 'unparseable' ),
	'closer with void flag'     => array( ' | 0-21 31-54', 'unclosed|paragraph' ),
	'delimiter in a comment'    => array( '46-99:paragraph | 46-67 77-99', 'unparseable' ),
	'broken JSON, then a block' => array( '38-101:heading | 38-69 81-101', 'unparseable' ),
	'truncated last delimiter'  => array( '0-53:paragraph | 0-21 31-53', 'unparseable' ),
	'only a truncated opener'   => array( ' | ', 'unparseable' ),
	'literal arrow in JSON'     => array( ' | 53-75', 'unparseable mismatch|paragraph' ),
	'attrs without a space'     => array( ' | 38-60', 'unparseable mismatch|paragraph' ),
);

// What the former patterns returned where it differs, and why core's reading is the right one.
$former = array(
	'JSON with braces'          => array( '0-138:group | 0-84 120-138', 'unparseable', 'the balance pattern counted braces, so a "}" inside a JSON string made a valid block unparseable' ),
	'core/ namespace spelled'   => array( '0-58:core/paragraph | 0-26 36-58', 'mismatch|paragraph', '"core/paragraph" and "paragraph" are one block type to core; the pattern compared the spellings' ),
	'no space before -->'       => array( ' | ', '', 'the balance pattern took "<!-- wp:paragraph-->" for a delimiter; core and the span pattern do not' ),
	'closer with void flag'     => array( '0-54:paragraph | 0-21 31-54', 'unclosed|paragraph', 'a delimiter with both flags is void to core; the span pattern read it as a closer' ),
	'delimiter in a comment'    => array( ' | 11-32 46-67 77-99', 'unclosed|paragraph', 'a delimiter inside a plain HTML comment is comment text to core\'s processor and a block to its 7.1 parser: the draft is refused as before, now as unparseable, and on core 7.1 a block edit is refused by the parser check' ),
	'broken JSON, then a block' => array( '0-101:paragraph | 0-69 81-101', 'unparseable', 'the lazy JSON group ran on through the next comment and swallowed the heading block: the draft is refused as before, and on core 7.1 a block edit is refused by the parser check' ),
	'truncated last delimiter'  => array( '0-53:paragraph | 0-21 31-53', '', 'a comment that never closes went unnoticed after a balanced block' ),
	'literal arrow in JSON'     => array( '0-75:paragraph | 0-43 53-75', '', 'a literal "-->" ends the comment for core\'s processor and for a browser; the patterns, like core\'s 7.1 parser, read on past it. Core writes it as a JSON escape. A deliberate tightening' ),
	'attrs without a space'     => array( ' | 38-60', 'mismatch|paragraph', 'refused before and now; the text that is not a delimiter is named as well' ),
);

echo "Group: every fixture, both consumers\n";
ok( array_keys( $fixtures ) === array_keys( $expect ), 'every fixture has an expectation' );
foreach ( $fixtures as $label => $content ) {
	$spans    = spans_line( snt_sn_apply_block_edit_scan_spans( $content ) );
	$findings = findings_line( $content );
	$was      = isset( $former[ $label ] ) ? ' (formerly ' . $former[ $label ][0] . ' and "' . $former[ $label ][1] . '": ' . $former[ $label ][2] . ')' : ' (as before)';
	ok( $expect[ $label ][0] === $spans, "$label: spans$was. Got $spans" );
	ok( $expect[ $label ][1] === $findings, "$label: findings \"{$expect[ $label ][1]}\". Got \"$findings\"" );
}
foreach ( $former as $label => $old ) {
	ok( array( $old[0], $old[1] ) !== $expect[ $label ], "$label really is a difference from the former patterns" );
}

echo "\nGroup: delimiters are returned as byte ranges of the given string\n";
foreach ( $fixtures as $label => $content ) {
	$bad = 0;
	foreach ( snt_sn_apply_block_delimiters( $content )['delimiters'] as $d ) {
		$text = substr( $content, $d['start'], $d['end'] - $d['start'] );
		$bad += (int) ! ( 0 === strpos( $text, '<!--' ) && '-->' === substr( $text, -3 ) && false !== strpos( $text, 'wp:' . ( 'paragraph' === $d['name'] && false !== strpos( $text, 'core/' ) ? 'core/' : '' ) . $d['name'] ) );
	}
	ok( 0 === $bad, "$label: every range is one whole delimiter comment naming its block" );
}
$incomplete = snt_sn_apply_block_delimiters( $fixtures['truncated last delimiter'] );
ok( null !== $incomplete['error'] && 2 === count( $incomplete['delimiters'] ), 'a comment that never closes is reported as core\'s error, after the delimiters before it' );
ok( null === snt_sn_apply_block_delimiters( $fixtures['nested group'] )['error'], 'well-formed markup carries no error' );

echo "\nGroup: the span a write splices is the parser's block, and nothing else moves\n";
// block_replace is substr_replace() at the resolved span (block-edit-compute-parts.php), so the
// bytes outside it cannot change; what must hold is that the span IS the block at that path.
$marker  = "<!-- wp:paragraph -->\n<p>REPLACED</p>\n<!-- /wp:paragraph -->";
$written = 0;
foreach ( $fixtures as $label => $content ) {
	foreach ( parse_blocks( $content ) as $i => $node ) {
		if ( null === $node['blockName'] ) {
			continue;
		}
		$r = snt_sn_apply_block_edit_resolve_span( $content, array( 'block_path' => "0/$i" ) );
		if ( is_wp_error( $r ) ) {
			continue; // Refusals are pinned below.
		}
		++$written;
		$length   = $r['span']['end'] - $r['span']['start'];
		$replaced = substr( $content, $r['span']['start'], $length );
		$new      = substr_replace( $content, $marker, $r['span']['start'], $length );
		ok( substr( $content, 0, $r['span']['start'] ) . $marker . substr( $content, $r['span']['end'] ) === $new, "$label, path 0/$i: every byte before and after the block is as stored" );
		ok( array( $node ) === parse_blocks( $replaced ), "$label, path 0/$i: the replaced bytes are exactly the block core's parser sees at that path" );
	}
}
ok( $written >= 18, "the splice ran on real blocks ($written), so the two checks above are not vacuous" );

/**
 * A write at $span splices exactly node $i of parse_blocks(): the bytes there parse, alone, to that
 * node, and with them replaced by $marker every other node parses back unchanged. That second half
 * is what "nothing else moves" means; the byte identity of a substr_replace() is a given.
 */
function splices_exactly( $content, array $span, $i, $marker ) {
	$tree       = parse_blocks( $content );
	$length     = $span['end'] - $span['start'];
	$want       = $tree;
	$want[ $i ] = parse_blocks( $marker )[0];
	return array( $tree[ $i ] ) === parse_blocks( substr( $content, $span['start'], $length ) )
		&& $want === parse_blocks( substr_replace( $content, $marker, $span['start'], $length ) );
}

// Core 7.1's parser tokenizes with its own pattern and reads these two inputs differently from
// WP_Block_Processor. From 7.2 the parser is built on the processor and the two agree. Each input
// asserts what the loaded core does (tests/lib/parser-walker-agree.php): where they disagree the
// write is refused, and where they agree it splices exactly the block parse_blocks() puts at the
// path. The anchor is the visible text of the block the delimiter reader finds.
echo "\nGroup: where core's parser and its delimiter reader disagree, nothing is located\n";
foreach ( array( 'delimiter in a comment' => '<p>x</p>', 'broken JSON, then a block' => '<h2>y</h2>' ) as $label => $anchor ) {
	$content = $fixtures[ $label ];
	$path    = null;
	foreach ( parse_blocks( $content ) as $i => $node ) {
		if ( null !== $node['blockName'] ) { $path = "0/$i"; break; }
	}
	$agree = snt_test_parser_agrees_with_walker( $content );
	foreach ( array( 'block_path' => (string) $path, 'anchor' => $anchor ) as $locator => $value ) {
		$r = snt_sn_apply_block_edit_resolve_span( $content, array( $locator => $value ) );
		if ( ! $agree ) {
			$refused = is_wp_error( $r ) && 'snt_sn_apply_block_path_unresolvable' === $r->get_error_code() && 422 === $r->data['status'];
			ok( $refused, 'block_path' === $locator ? "$label: a block_path write is refused" : "$label: an anchor write is refused too" );
			continue;
		}
		ok( ! is_wp_error( $r ) && splices_exactly( $content, $r['span'], (int) substr( (string) $path, 2 ), $marker ), "$label: core's parser and its delimiter reader agree in this core, and the $locator write splices exactly the block parse_blocks() puts at $path" );
	}
}
$scan  = snt_sn_apply_block_edit_scan_spans( $fixtures['delimiter in a comment'] );
$check = snt_sn_apply_block_edit_parser_agrees( $fixtures['delimiter in a comment'], $scan['spans'], 0 );
if ( snt_test_parser_agrees_with_walker( $fixtures['delimiter in a comment'] ) ) {
	ok( true === $check, 'delimiter in a comment: the parser reads the comment as text in this core, and the parser check passes the block' );
} else {
	ok( true !== $check, 'the name matches there and the bytes do not: the parser check is what refuses it' );
}
ok( true === snt_sn_apply_block_edit_parser_agrees( $fixtures['two top-level'], snt_sn_apply_block_edit_scan_spans( $fixtures['two top-level'] )['spans'], 1 ), 'and it passes a well-formed block' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
