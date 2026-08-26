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

/* ════════════════════════════════════════════════════════════════════════
 * sn-normalize-v2 (v13.4.0): expand signal-noise void-block attribute text
 * (step 0), then the v1 pipeline unchanged. Every vector here has a mirror
 * in the ledger repo's normalize/parity tests (JS reference impl + the
 * live-PHP oracle that shells THIS implementation).
 * ════════════════════════════════════════════════════════════════════════ */
echo "\nsn-normalize-v2 suite\n\n";

// V2.1: the headline case — a sidenote's content attribute becomes a
// paragraph at the block's position in document order.
$v2_side = "<!-- wp:paragraph -->\n<p>Before the note.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:signal-noise/sidenote {\"content\":\"Tufte set his notes in the margin.\"} /-->\n\n<!-- wp:paragraph -->\n<p>After the note.</p>\n<!-- /wp:paragraph -->";
nv_eq( "Before the note.\n\nTufte set his notes in the margin.\n\nAfter the note.", sn_prov_normalize_v2( $v2_side ), 'v2: sidenote content signs as a paragraph at the block\'s position' );
nv_eq( "Before the note.\n\nAfter the note.", sn_prov_normalize_v1( $v2_side ), 'v1 (unchanged): the same text stays invisible — the exact gap v2 closes' );

// V2.2: pull-quote — body then attribution, the serialized JSON's own
// order (= the block's attribute-definition order as core saves it, = the
// render order the theme's parity test enforces).
$v2_pq = "<!-- wp:signal-noise/pull-quote {\"body\":\"The pen is not the notary.\",\"attribution\":\"Notes, 2026\"} /-->";
nv_eq( "The pen is not the notary.\n\nNotes, 2026", sn_prov_normalize_v2( $v2_pq ), 'v2: pull-quote body then attribution, each its own paragraph' );

// V2.3: an EMPTY attribute is skipped — mirrors render.php\'s slot
// omission (an unattributed quote renders no attribution line).
nv_eq( 'The pen is not the notary.', sn_prov_normalize_v2( "<!-- wp:signal-noise/pull-quote {\"body\":\"The pen is not the notary.\",\"attribution\":\"\"} /-->" ), 'v2: an empty string attribute extracts nothing (render slot omission mirrored)' );

// V2.4: BYTE-IDENTITY with v1 wherever no signal-noise void block exists —
// the property that makes the generation bump re-sign nothing. Includes a
// CORE void block with a string attr (spacer height): core/* is excluded
// by the namespace rule, so "20px" is a setting, never prose.
foreach ( array(
	"<!-- wp:paragraph -->\n<p>Plain prose only.</p>\n<!-- /wp:paragraph -->",
	"<!-- wp:spacer {\"height\":\"20px\"} /-->\n\n<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->",
	"<!-- wp:signal-noise/pillar-essays /-->", // void, NO attrs (page 1490\'s real usage): removed, like v1
	'',
) as $v2_same ) {
	nv_eq( sn_prov_normalize_v1( $v2_same ), sn_prov_normalize_v2( $v2_same ), 'v2 == v1 byte-identically without signal-noise attr blocks: ' . substr( var_export( $v2_same, true ), 0, 40 ) );
}

// V2.5: inline markup + entities INSIDE an attribute value normalize the
// same way the rendered page's copy of that text does (tags stripped,
// entities decoded once).
nv_eq( 'Emphasis & more.', sn_prov_normalize_v2( "<!-- wp:signal-noise/sidenote {\"content\":\"<em>Emphasis</em> &amp; more.\"} /-->" ), 'v2: attr-embedded markup stripped, entities decoded once — render-equivalent' );

// V2.6: a } inside a string value — the trailing /--> anchor forces the
// regex past it; the whole JSON decodes.
nv_eq( 'a } b stays whole.', sn_prov_normalize_v2( "<!-- wp:signal-noise/sidenote {\"content\":\"a } b stays whole.\"} /-->" ), 'v2: a brace inside a string value never truncates the extraction' );

// V2.7: malformed attrs JSON extracts nothing — v1\'s step-1 removal,
// unchanged (never a guess).
nv_eq( '', sn_prov_normalize_v2( "<!-- wp:signal-noise/sidenote {\"content\":broken} /-->" ), 'v2: malformed attrs JSON expands to nothing' );

// V2.7b (review MEDIUM repro): an integer-like attribute KEY is skipped by
// the identifier grammar — ECMA-262's Object.keys hoists such keys first
// while PHP iterates insertion order, so without the grammar the two
// reference implementations would sign DIFFERENT prose from the same
// bytes. Mirrored in the ledger repo's parity suite with a live-PHP oracle.
nv_eq( "one\n\nthree", sn_prov_normalize_v2( "<!-- wp:signal-noise/future-block {\"b\":\"one\",\"0\":\"two\",\"a\":\"three\"} /-->" ), 'v2: integer-like keys are outside the identifier grammar — skipped, cross-language order identical by construction' );

// V2.8: non-string top-level values are skipped; string ones still sign.
nv_eq( 'Only the words.', sn_prov_normalize_v2( "<!-- wp:signal-noise/future-block {\"count\":3,\"flag\":true,\"text\":\"Only the words.\"} /-->" ), 'v2: only top-level STRING values are prose; numbers/bools skipped' );

// V2.9: determinism — identical bytes in, identical bytes out, twice.
nv_eq( sn_prov_normalize_v2( $v2_side ), sn_prov_normalize_v2( $v2_side ), 'v2: deterministic across runs' );

// V2.10: RENDER-ALIGNMENT — v2(raw) equals v1(rendered page shape after the
// extractor\'s restored boundaries): the property that makes public
// verification PASS for a signed subject using these blocks (it failed
// under v1 by construction). The rendered shape mirrors render.php\'s
// actual output for the sidenote (a .sn-sidenote paragraph).
$v2_rendered = "<p>Before the note.</p>\n\n<p class=\"wp-block-signal-noise-sidenote sn-sidenote\">Tufte set his notes in the margin.</p>\n\n<p>After the note.</p>\n\n";
nv_eq( sn_prov_normalize_v1( $v2_rendered ), sn_prov_normalize_v2( $v2_side ), 'v2 RENDER-ALIGNMENT: normalized raw content equals normalized rendered output — the byte-equality verify.mjs demands' );

/* ════════════════════════════════════════════════════════════════════════
 * The v1→v2 transition shim in sn_prov_record(): an algo generation bump
 * alone must never mint a version; a real edit still does.
 * ════════════════════════════════════════════════════════════════════════ */
echo "\ntransition shim suite\n\n";

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $k, $single = false ) { $v = $GLOBALS['__pm'][ $id ][ $k ] ?? ''; return $single ? $v : array( $v ); }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) { $GLOBALS['__pm'][ $id ][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $k ) { unset( $GLOBALS['__pm'][ $id ][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $p ) { return (string) ( is_object( $p ) ? $p->post_title : '' ); } }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
$GLOBALS['__pm'] = array();

if ( ! sn_prov_active() ) {
	echo "  SKIP: ext-intl absent — shim vectors need sn_prov_active()\n";
} else {
	$shim_body  = "<!-- wp:paragraph -->\n<p>The original sentence of this note, unchanged.</p>\n<!-- /wp:paragraph -->";
	$shim_post  = (object) array( 'ID' => 4242, 'post_title' => 'Shim note', 'post_content' => $shim_body, 'post_date_gmt' => '2026-08-01 10:00:00', 'post_date' => '2026-08-01 10:00:00' );

	// Build a REAL v1-era head by computing the v1 bearing fields exactly as
	// pre-13.4.0 code did (v1 algo string + v1 content), through the same
	// canonical/hash helpers.
	$v1_fields = array(
		'algo'         => 'sn-normalize-v1',
		'author'       => 'Juan',
		'content'      => sn_prov_normalize_v1( $shim_body ),
		'note_uid'     => sn_prov_note_uid( 4242 ),
		'published_at' => sn_prov_published_at( $shim_post ),
		'title'        => 'Shim note',
	);
	$v1_bearing = sn_prov_content_hash( sn_prov_canonical_json( $v1_fields ) );
	$v1_payload = sn_prov_build_payload_from_fields( $v1_fields, 1, null );
	$GLOBALS['__pm'][4242][ SN_PROV_CHAIN_META ] = array( array(
		'version'      => 1,
		'parent'       => null,
		'content_hash' => sn_prov_content_hash( sn_prov_canonical_json( $v1_payload ) ),
		'bearing_hash' => $v1_bearing,
		'payload'      => $v1_payload,
		'status'       => 'anchored',
		'committed_at' => '2026-08-01T10:00:00Z',
	) );

	nv_eq( null, sn_prov_record( $shim_post, 'Juan' ), 'shim: an unchanged save over a v1 head COALESCES — the algo bump alone is not an edit' );

	$shim_post->post_content = "<!-- wp:paragraph -->\n<p>The original sentence of this note, actually edited.</p>\n<!-- /wp:paragraph -->";
	$shim_chain = sn_prov_record( $shim_post, 'Juan' );
	nv_eq( 2, is_array( $shim_chain ) ? count( $shim_chain ) : 0, 'shim: a REAL edit still appends v2\'s commit' );
	nv_eq( 'sn-normalize-v2', $shim_chain[1]['payload']['algo'] ?? '', 'shim: the new commit records the v2 algo' );
	nv_eq( 2, $shim_chain[1]['version'] ?? 0, 'shim: version increments off the v1 head — one chain, two generations' );

	// A sidenote ADDED to the v1-era prose is a bearing change (its text is
	// prose now), never a coalesce.
	$GLOBALS['__pm'][5252] = array();
	$side_post = (object) array( 'ID' => 5252, 'post_title' => 'Side note', 'post_content' => $shim_body, 'post_date_gmt' => '2026-08-01 10:00:00', 'post_date' => '2026-08-01 10:00:00' );
	$sf = array(
		'algo'         => 'sn-normalize-v1',
		'author'       => 'Juan',
		'content'      => sn_prov_normalize_v1( $shim_body ),
		'note_uid'     => sn_prov_note_uid( 5252 ),
		'published_at' => sn_prov_published_at( $side_post ),
		'title'        => 'Side note',
	);
	$sp = sn_prov_build_payload_from_fields( $sf, 1, null );
	$GLOBALS['__pm'][5252][ SN_PROV_CHAIN_META ] = array( array( 'version' => 1, 'parent' => null, 'content_hash' => sn_prov_content_hash( sn_prov_canonical_json( $sp ) ), 'bearing_hash' => sn_prov_content_hash( sn_prov_canonical_json( $sf ) ), 'payload' => $sp, 'status' => 'anchored', 'committed_at' => '2026-08-01T10:00:00Z' ) );
	$side_post->post_content = $shim_body . "\n\n<!-- wp:signal-noise/sidenote {\"content\":\"New margin words the record must see.\"} /-->";
	$side_chain = sn_prov_record( $side_post, 'Juan' );
	nv_eq( 2, is_array( $side_chain ) ? count( $side_chain ) : 0, 'shim: ADDING a sidenote mints a version — its text is provenance-bearing now' );
	nv_eq( true, false !== strpos( (string) ( $side_chain[1]['payload']['content'] ?? '' ), 'New margin words the record must see.' ), 'shim: ...and the signed content carries the sidenote\'s words' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
