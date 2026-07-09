<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $rb = false ) {
		$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
		return trim( strip_tags( $s ) );
	}
}
require_once SNT_PATH . 'inc/provenance-core.php';

$pass = 0;
$fail = 0;
function nv_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}

echo "sn-normalize-v1 suite\n\n";

// Vector 1: block markup -> plain prose, comments + tags stripped.
$in1 = "<!-- wp:paragraph -->\n<p>Hello&nbsp;world.</p>\n<!-- /wp:paragraph -->";
nv_eq( 'Hello world.', sn_prov_normalize_v1( $in1 ), 'strips wp comments, tags; NBSP -> space' );

// Vector 2: entity decoded exactly once.
nv_eq( 'A & B', sn_prov_normalize_v1( '<p>A &amp; B</p>' ), 'entity decoded once' );
nv_eq( '&amp; stays after one decode', sn_prov_normalize_v1( '<p>&amp;amp; stays after one decode</p>' ), 'no double decode' );

// Vector 3: CRLF -> LF, trailing whitespace trimmed, runs collapsed.
nv_eq( "Line one\nLine two", sn_prov_normalize_v1( "<p>Line   one  </p>\r\n<p>  Line\ttwo</p>" ), 'whitespace collapse + CRLF->LF + paragraph join' );

// Vector 4: multiple blank lines collapse to one; overall trim.
nv_eq( "A\n\nB", sn_prov_normalize_v1( "\n\n<p>A</p>\n\n\n\n<p>B</p>\n\n" ), 'blank-line collapse + trim' );

// Vector 5: empty content -> empty string.
nv_eq( '', sn_prov_normalize_v1( '<!-- wp:spacer --><div></div><!-- /wp:spacer -->' ), 'structural-only content -> empty' );

// Vector 6 (NFC) — only if ext-intl present, else skip (still print a line).
if ( function_exists( 'normalizer_normalize' ) ) {
	$decomposed = "e\u{0301}"; // e + combining acute
	$composed   = "\u{00e9}";  // é
	nv_eq( $composed, sn_prov_normalize_v1( '<p>' . $decomposed . '</p>' ), 'NFC composes é' );
} else {
	echo "  SKIP: NFC vector (ext-intl not loaded)\n";
}

echo "\nCanonical JSON\n";
// Keys sorted lexicographically (SORT_STRING); compact; slashes + unicode raw.
nv_eq(
	'{"algo":"sn-normalize-v1","author":"Juan","title":"a/b é"}',
	sn_prov_canonical_json( array( 'title' => 'a/b é', 'algo' => 'sn-normalize-v1', 'author' => 'Juan' ) ),
	'sorted keys, unescaped slash + unicode, compact'
);
// Nested object keys sorted; list arrays preserved in order.
nv_eq(
	'{"outer":{"a":1,"b":[3,2,1]}}',
	sn_prov_canonical_json( array( 'outer' => array( 'b' => array( 3, 2, 1 ), 'a' => 1 ) ) ),
	'recursive key sort; list order preserved'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
