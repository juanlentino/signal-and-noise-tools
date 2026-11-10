<?php
/**
 * What each link reader answers on the same markup (tests/lib/html-link-cases.php).
 *
 * Five readers ask "what does this HTML link to?": the two health link checks,
 * the citation verifier, the related-notes link signal and the link graph's
 * raw-body fallback. Each used its own regex over the bytes. This suite pins
 * every reader's answer on every shared case, first as the regexes gave it and
 * then, case by case, as core's HTML parser gives it: an expectation that
 * changed says in its comment what the regex answered and why that was wrong.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/html-link-readers.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

require __DIR__ . '/lib/wp-html-api.php'; // core's real Tag Processor, or the suite fails.

function add_action() {}
function add_filter() {}
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }

$root = dirname( __DIR__ );
require "$root/inc/health-check-broken-links.php";
require "$root/inc/health-external-links.php";
require "$root/inc/citations-core.php";
require "$root/inc/ml-link-isolation.php";
require "$root/inc/ml-artifacts.php";

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$cases = require __DIR__ . '/lib/html-link-cases.php';

// The citation verifier answers yes or no per target, so it is asked about every URL a case could link.
$targets = array();
foreach ( range( 'a', 'p' ) as $l ) {
	$targets[] = "https://example.test/notes/$l/";
	$targets[] = "https://other.example/$l";
}
array_push( $targets, 'https://example.test/notes/d/?a=1&lang=en', 'https://other.example/d?a=1&b=2&c=3', "https://example.test/notes/it's/", "https://other.example/it's", 'https://example.test/notes/señal/', 'https://other.example/añejo' );

$readers = array(
	'internal' => array( 'sn_health_extract_internal_links', static fn( $h ) => sn_health_extract_internal_links( $h, 'example.test' ) ),
	'external' => array( 'sn_health_extract_external_links', static fn( $h ) => sn_health_extract_external_links( $h, 'example.test' ) ),
	'cites'    => array( 'sn_cit_html_links_to', static fn( $h ) => array_values( array_filter( $targets, static fn( $t ) => sn_cit_html_links_to( $h, $t ) ) ) ),
	'notes'    => array( 'snt_ml_extract_note_links', static fn( $h ) => snt_ml_extract_note_links( $h ) ),
	'paths'    => array( 'snt_ml_outbound_paths', static fn( $h ) => snt_ml_outbound_paths( $h, 'example.test' ) ),
);

$want_internal = array(
	'nested inline markup' => array( 'https://example.test/notes/a/' ),
	'attributes in any order' => array( 'https://example.test/notes/b/' ),
	'uppercase tags' => array( 'https://example.test/notes/c/' ),
	'entities in the href' => array( 'https://example.test/notes/d/?a=1&lang=en' ),
	'a > inside an earlier attribute' => array(),
	'unquoted href' => array(),
	'data-href is not href' => array( 'https://example.test/notes/g/' ),
	'self-closing and void tags' => array( 'https://example.test/notes/h/' ),
	'links inside an HTML comment' => array( 'https://example.test/notes/i/' ),
	'block delimiters with JSON' => array( 'https://example.test/notes/j/' ),
	'multibyte text and paths' => array( 'https://example.test/notes/señal/' ),
	'empty input' => array(),
	'an apostrophe in a quoted href' => array( 'https://example.test/notes/it' ),
	'links in a textarea and a script' => array( 'https://example.test/notes/l/' ),
	'href text inside another attribute' => array( 'https://example.test/notes/m/' ),
	'schemes and fragments' => array( '//example.test/notes/n/', 'https://example.test/notes/n/#part' ),
	'the same link twice' => array( 'https://example.test/notes/o/' ),
	'no value, empty value' => array( 'https://example.test/notes/p/' ),
);
$want_external = array(
	'nested inline markup' => array( 'https://other.example/a' ),
	'attributes in any order' => array( 'https://other.example/b' ),
	'uppercase tags' => array( 'https://other.example/c' ),
	'entities in the href' => array( 'https://other.example/d?a=1&b=2&c=3' ),
	'a > inside an earlier attribute' => array(),
	'unquoted href' => array(),
	'data-href is not href' => array( 'https://other.example/g' ),
	'self-closing and void tags' => array( 'https://other.example/h' ),
	'links inside an HTML comment' => array( 'https://other.example/i' ),
	'block delimiters with JSON' => array( 'https://other.example/j' ),
	'multibyte text and paths' => array( 'https://other.example/añejo' ),
	'empty input' => array(),
	'an apostrophe in a quoted href' => array( 'https://other.example/it' ),
	'links in a textarea and a script' => array( 'https://other.example/l' ),
	'href text inside another attribute' => array(),
	'schemes and fragments' => array(),
	'the same link twice' => array( 'https://other.example/o' ),
	'no value, empty value' => array(),
);
$want_cites = array(
	'nested inline markup' => array( 'https://example.test/notes/a/', 'https://other.example/a' ),
	'attributes in any order' => array( 'https://other.example/b' ),
	'uppercase tags' => array( 'https://example.test/notes/c/', 'https://other.example/c' ),
	'entities in the href' => array( 'https://example.test/notes/d/?a=1&lang=en', 'https://other.example/d?a=1&b=2&c=3' ),
	'a > inside an earlier attribute' => array(),
	'unquoted href' => array( 'https://other.example/f' ),
	'data-href is not href' => array( 'https://other.example/g' ),
	'self-closing and void tags' => array( 'https://other.example/h' ),
	'links inside an HTML comment' => array( 'https://other.example/i' ),
	'block delimiters with JSON' => array( 'https://example.test/notes/j/', 'https://other.example/j' ),
	'multibyte text and paths' => array( 'https://example.test/notes/señal/', 'https://other.example/añejo' ),
	'empty input' => array(),
	'an apostrophe in a quoted href' => array( 'https://other.example/it\'s' ),
	'links in a textarea and a script' => array( 'https://other.example/l' ),
	'href text inside another attribute' => array( 'https://other.example/m' ),
	'schemes and fragments' => array(),
	'the same link twice' => array( 'https://example.test/notes/o/', 'https://other.example/o' ),
	'no value, empty value' => array(),
);
$want_notes = array(
	'nested inline markup' => array( 'a' ),
	'attributes in any order' => array( 'b' ),
	'uppercase tags' => array( 'c' ),
	'entities in the href' => array( 'd' ),
	'a > inside an earlier attribute' => array( 'e' ),
	'unquoted href' => array(),
	'data-href is not href' => array( 'g' ),
	'self-closing and void tags' => array( 'h' ),
	'links inside an HTML comment' => array( 'i' ),
	'block delimiters with JSON' => array( 'j' ),
	'multibyte text and paths' => array( 'señal' ),
	'empty input' => array(),
	'an apostrophe in a quoted href' => array( 'it' ),
	'links in a textarea and a script' => array( 'l' ),
	'href text inside another attribute' => array( 'm' ),
	'schemes and fragments' => array( 'n' ),
	'the same link twice' => array( 'o' ),
	'no value, empty value' => array(),
);
$want_paths = array(
	'nested inline markup' => array( '/notes/a' ),
	'attributes in any order' => array( '/notes/b' ),
	'uppercase tags' => array( '/notes/c' ),
	'entities in the href' => array( '/notes/d' ),
	'a > inside an earlier attribute' => array(),
	'unquoted href' => array(),
	'data-href is not href' => array( '/notes/g' ),
	'self-closing and void tags' => array( '/notes/h' ),
	'links inside an HTML comment' => array( '/notes/i' ),
	'block delimiters with JSON' => array( '/notes/j' ),
	'multibyte text and paths' => array( '/notes/señal' ),
	'empty input' => array(),
	'an apostrophe in a quoted href' => array( '/notes/it' ),
	'links in a textarea and a script' => array( '/notes/l' ),
	'href text inside another attribute' => array( '/notes/m' ),
	'schemes and fragments' => array( '/notes/n', '/notes/n' ),
	'the same link twice' => array( '/notes/o', '/notes/o', '/notes/o' ),
	'no value, empty value' => array( '/notes/p' ),
);

foreach ( $readers as $key => $reader ) {
	echo "\nGroup: {$reader[0]}\n";
	$want = ${'want_' . $key};
	ok( array_keys( $want ) === array_keys( $cases ), "{$reader[0]}: an answer is pinned for every shared case" );
	foreach ( $cases as $name => $html ) {
		$got = $reader[1]( $html );
		ok( ( $want[ $name ] ?? null ) === $got, "{$reader[0]}: $name" . ( ( $want[ $name ] ?? null ) === $got ? '' : ' (got ' . json_encode( $got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ')' ) );
	}
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
