<?php
/**
 * Standalone tests for OG-card provenance embedding (machine-readability D2).
 * @since plugin v9.25.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_OG_CARD_PROV_TEST', true );

if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'sn_prov_note_uid' ) ) { function sn_prov_note_uid( $id ) { return 'test-uid-1234'; } }
// The D1 gate lives inside sn_prov_credential(); stub it controllably.
$GLOBALS['__vc'] = array( 'type' => array( 'VerifiableCredential', 'AuthorshipCredential' ), 'issuer' => 'did:web:juanlentino.com', 'proof' => array( 'proofValue' => 'abc' ) );
if ( ! function_exists( 'sn_prov_credential' ) ) { function sn_prov_credential( $id, $v = null ) { return $GLOBALS['__vc']; } }

require __DIR__ . '/../inc/og-card-provenance.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// --- test-local PNG helpers (independent of the code under test) ---
function sn_og_test_count_itxt( $png, $keyword ) {
	$off = 8; $len = strlen( $png ); $n = 0;
	while ( $off + 12 <= $len ) {
		$dlen = unpack( 'N', substr( $png, $off, 4 ) )[1];
		$type = substr( $png, $off + 4, 4 );
		$full = 8 + $dlen + 4;
		if ( $off + $full > $len ) { break; }
		if ( 'iTXt' === $type && strstr( substr( $png, $off + 8, $dlen ), "\x00", true ) === $keyword ) { $n++; }
		$off += $full;
		if ( 'IEND' === $type ) { break; }
	}
	return $n;
}
function sn_og_test_crc_ok( $png ) {
	$off = 8; $len = strlen( $png );
	while ( $off + 12 <= $len ) {
		$dlen = unpack( 'N', substr( $png, $off, 4 ) )[1];
		$type = substr( $png, $off + 4, 4 );
		if ( $off + 8 + $dlen + 4 > $len ) { return false; }
		$data = substr( $png, $off + 8, $dlen );
		$crc  = unpack( 'N', substr( $png, $off + 8 + $dlen, 4 ) )[1];
		if ( ( crc32( $type . $data ) & 0xFFFFFFFF ) !== $crc ) { return false; }
		$off += 8 + $dlen + 4;
		if ( 'IEND' === $type ) { break; }
	}
	return true;
}

echo "og-card provenance — v9.25.0\n\n";

// Verified-valid 1x1 PNG (IHDR/pHYs/IDAT/IEND, all chunk CRCs check out — produced by GD imagepng()).
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC' );
ok( strncmp( $png, "\x89PNG\r\n\x1a\n", 8 ) === 0, 'fixture is a valid PNG' );
ok( sn_og_test_crc_ok( $png ), 'fixture chunk CRCs validate (parser sanity)' );

// insert an iTXt 'provenance' chunk
$out = sn_og_png_set_itxt( $png, 'provenance', '{"hello":"world"}' );
ok( $out !== $png && strlen( $out ) > strlen( $png ), 'set_itxt grows the PNG' );
ok( strncmp( $out, "\x89PNG\r\n\x1a\n", 8 ) === 0, 'signature intact' );
ok( substr( $out, -12, 4 ) === "\x00\x00\x00\x00" && substr( $out, -8, 4 ) === 'IEND', 'IEND is still the terminal chunk' );
ok( sn_og_test_crc_ok( $out ), 'every chunk CRC validates after insert' );
ok( sn_og_png_get_itxt( $out, 'provenance' ) === '{"hello":"world"}', 'iTXt text round-trips byte-exact' );

// idempotency: re-insert replaces, never duplicates
$twice = sn_og_png_set_itxt( $out, 'provenance', '{"v":2}' );
ok( sn_og_png_get_itxt( $twice, 'provenance' ) === '{"v":2}', 'second insert replaces the value' );
ok( sn_og_test_count_itxt( $twice, 'provenance' ) === 1, 'exactly one provenance chunk after re-insert (idempotent)' );

// non-PNG / junk is returned unchanged
ok( sn_og_png_set_itxt( 'not a png', 'provenance', 'x' ) === 'not a png', 'non-PNG returned unchanged' );
ok( sn_og_png_get_itxt( 'not a png', 'provenance' ) === null, 'get_itxt on non-PNG is null' );

// --- block builder + D1 gate inheritance ---
$block = sn_og_card_provenance_block( 7 );
ok( is_array( $block ) && ( $block['type'] ?? '' ) === 'OGCardProvenance', 'block has the OGCardProvenance type' );
ok( ( $block['credential'] ?? null ) === $GLOBALS['__vc'], 'block embeds the D1 credential verbatim' );
ok( ( $block['note_uid'] ?? '' ) === 'test-uid-1234' && strpos( (string) ( $block['credential_url'] ?? '' ), 'credential/test-uid-1234' ) !== false, 'block carries the uid + live credential URL' );
ok( strpos( (string) ( $block['did_document'] ?? '' ), '/.well-known/did.json' ) !== false, 'block points at the DID document' );
$GLOBALS['__vc'] = null; // simulate the D1 visibility gate (non-public / uncredentialed Note)
ok( sn_og_card_provenance_block( 7 ) === null, 'no credential → no block (D1 gate inherited)' );

// --- file injector ---
$tmp = tempnam( sys_get_temp_dir(), 'sncard' ); file_put_contents( $tmp, $png );
ok( sn_og_card_inject_provenance( $tmp, 7 ) === false && file_get_contents( $tmp ) === $png, 'gated Note → inject is a byte-exact no-op' );
$GLOBALS['__vc'] = array( 'type' => array( 'VerifiableCredential' ), 'proof' => array( 'proofValue' => 'abc' ) );
ok( sn_og_card_inject_provenance( $tmp, 7 ) === true, 'public Note → inject succeeds' );
$injected = file_get_contents( $tmp );
ok( sn_og_png_get_itxt( $injected, 'provenance' ) !== null && sn_og_test_crc_ok( $injected ), 'injected card carries a CRC-valid provenance chunk' );
$decoded = json_decode( (string) sn_og_png_get_itxt( $injected, 'provenance' ), true );
ok( is_array( $decoded ) && ( $decoded['type'] ?? '' ) === 'OGCardProvenance', 'embedded JSON decodes to the provenance block' );
unlink( $tmp );

// --- manifest advertisement ---
$surf = sn_og_card_advertise_surface( array() );
$og   = null;
foreach ( $surf as $s ) { if ( ( $s['type'] ?? '' ) === 'og-card-provenance' ) { $og = $s; } }
ok( $og !== null, 'advertises the og-card-provenance surface' );
ok( strpos( (string) ( $og['url'] ?? '' ), 'https://juanlentino.com' ) === 0 && strpos( (string) ( $og['url'] ?? '' ), '/post-{post-id}.png' ) !== false, 'surface url is an absolute, host-derived card URL' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
