<?php
/**
 * Phase 0 of the breached-credential arc: the HIBP k-anonymity client.
 *
 * FIXTURES DRIVE PARSING, NEVER THE NETWORK. tests/fixtures/hibp-range-5BAA6.txt
 * is a real capture (2026-09-01) trimmed for repo size; nothing here makes an
 * outbound request, so the suite is deterministic and runs offline.
 *
 * THE ASSERTION THAT MATTERS is the empty-200 case. An empty body and "your
 * suffix is not in the list" are byte-identical outcomes with opposite
 * meanings, and a client that collapses them reports the safest-looking answer
 * at the exact moment it stopped working.
 *
 * @since plugin v13.54.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WP seam. $GLOBALS['__http'] is the scripted response; every call records
//    the URL so the privacy contract can be asserted rather than assumed. ──
class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['__http']      = array( 'status' => 200, 'body' => '', 'error' => null );
$GLOBALS['__http_urls'] = array();
$GLOBALS['__http_args'] = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__http_urls'][] = $url;
	$GLOBALS['__http_args'][] = $args;
	if ( null !== $GLOBALS['__http']['error'] ) { return $GLOBALS['__http']['error']; }
	return array( 'status' => $GLOBALS['__http']['status'], 'body' => $GLOBALS['__http']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['status'] : null; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

require __DIR__ . '/../inc/breached-credentials.php';

$FIXTURE = (string) file_get_contents( __DIR__ . '/fixtures/hibp-range-5BAA6.txt' );
ok( '' !== $FIXTURE, 'vacuity: the real range fixture loaded (an empty fixture would make every parse test below meaningless)' );
ok( substr_count( $FIXTURE, ':' ) > 20, 'vacuity: and it carries the expected order of magnitude of SUFFIX:COUNT rows' );

echo "\nGroup: the split — only the prefix may ever leave\n";
$h = sn_hibp_split( 'password' );
ok( '5BAA6' === $h['prefix'], 'split: prefix is the first 5 uppercase hex of the sha1' );
ok( '1E4C9B93F3F0682250B6CF8331B7EE68FD8' === $h['suffix'], 'split: suffix is the remaining 35' );
ok( 35 === strlen( $h['suffix'] ), 'split: suffix length is exactly 35' );
ok( $h['prefix'] === strtoupper( $h['prefix'] ), 'split: UPPERCASE — the API answers uppercase, and a case mismatch would silently never match (a false clean)' );
$e = sn_hibp_split( '' );
ok( '' === $e['prefix'] && '' === $e['suffix'], 'split: an empty password yields nothing to send' );

echo "\nGroup: THE NEGATIVE CONTROL — a known-breached password must go red\n";
// "A breach check that passes against a breached password is worse than none."
$r = sn_hibp_parse_range( $FIXTURE, '1E4C9B93F3F0682250B6CF8331B7EE68FD8' );
ok( SN_HIBP_BREACHED === $r['verdict'], 'the real corpus response for "password" reads BREACHED' );
ok( 52372427 === $r['count'], 'and carries its real count (52,372,427) rather than a boolean' );

echo "\nGroup: a suffix that is genuinely absent\n";
$r = sn_hibp_parse_range( $FIXTURE, 'F5CDC69EE96828A21D4EE287DEB654E8737' );
ok( SN_HIBP_NOT_BREACHED === $r['verdict'], 'a suffix absent from a WELL-FORMED response reads NOT_BREACHED' );
ok( 0 === $r['count'], 'and reports no count' );

echo "\nGroup: THE EMPTY-200 CASE — the one that matters\n";
// An empty body and "not in the list" are byte-identical on the wire and mean
// opposite things. Collapsing them reports "clean" the moment the API breaks.
$r = sn_hibp_parse_range( '', '1E4C9B93F3F0682250B6CF8331B7EE68FD8' );
ok( SN_HIBP_UNAVAILABLE === $r['verdict'], 'an EMPTY body is UNAVAILABLE, never NOT_BREACHED' );
ok( SN_HIBP_NOT_BREACHED !== $r['verdict'], 'stated the other way, because this is the assertion the design turns on' );
$r = sn_hibp_parse_range( "   \r\n\r\n  ", '1E4C9B93F3F0682250B6CF8331B7EE68FD8' );
ok( SN_HIBP_UNAVAILABLE === $r['verdict'], 'a whitespace-only body is UNAVAILABLE too' );

echo "\nGroup: malformed bodies are UNAVAILABLE, not clean\n";
ok( SN_HIBP_UNAVAILABLE === sn_hibp_parse_range( '<html>502 Bad Gateway</html>', '1E4C9B93F3F0682250B6CF8331B7EE68FD8' )['verdict'], 'an HTML error page parses to UNAVAILABLE — an unparseable answer is not a clean answer' );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_parse_range( "NOTHEX:12\r\nALSOBAD:3", '1E4C9B93F3F0682250B6CF8331B7EE68FD8' )['verdict'], 'rows that are not 35-hex suffixes do not count as parsed' );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_parse_range( "1E4C9B93F3F0682250B6CF8331B7EE68FD8", '1E4C9B93F3F0682250B6CF8331B7EE68FD8' )['verdict'], 'a suffix with NO colon/count is not a usable row' );

echo "\nGroup: padding rows are absence, not a zero-sized hit\n";
// Add-Padding fills a response with count-0 rows so its size cannot leak how
// many entries the prefix has. A padded row means "present as filler".
$padded = "0000000000000000000000000000000000A:0\r\n1E4C9B93F3F0682250B6CF8331B7EE68FD8:0\r\n003CD215739D7C1B2218670D26F81408237:2";
$r = sn_hibp_parse_range( $padded, '1E4C9B93F3F0682250B6CF8331B7EE68FD8' );
ok( SN_HIBP_NOT_BREACHED === $r['verdict'], 'a count of 0 is PADDING, not a breach of size zero' );
ok( SN_HIBP_BREACHED === sn_hibp_parse_range( $padded, '003CD215739D7C1B2218670D26F81408237' )['verdict'], 'while a real count in the same padded body still reads BREACHED' );

echo "\nGroup: line-ending and comment tolerance (the wire's realities)\n";
ok( SN_HIBP_BREACHED === sn_hibp_parse_range( str_replace( "\r\n", "\n", $FIXTURE ), '1E4C9B93F3F0682250B6CF8331B7EE68FD8' )['verdict'], 'LF-only bodies parse identically to CRLF' );
ok( SN_HIBP_BREACHED === sn_hibp_parse_range( $FIXTURE . "\r\n\r\n", '1E4C9B93F3F0682250B6CF8331B7EE68FD8' )['verdict'], 'trailing blank lines are tolerated' );
ok( SN_HIBP_BREACHED === sn_hibp_parse_range( $FIXTURE, strtolower( '1E4C9B93F3F0682250B6CF8331B7EE68FD8' ) )['verdict'], 'a lowercase suffix argument still matches — the client normalizes rather than silently missing' );

echo "\nGroup: HTTP outcomes short-circuit before the body is trusted\n";
ok( SN_HIBP_UNAVAILABLE === sn_hibp_status_blocks( 429 ), 'HTTP 429 blocks' );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_status_blocks( 503 ), 'HTTP 503 blocks' );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_status_blocks( 404 ), 'HTTP 404 blocks — the API does not use it for "clean", but a proxy might invent it' );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_status_blocks( null ), 'a null status (transport error) blocks' );
ok( '' === sn_hibp_status_blocks( 200 ), 'only 200 is usable' );

echo "\nGroup: the live path, end to end through the seam\n";
$GLOBALS['__http'] = array( 'status' => 200, 'body' => $FIXTURE, 'error' => null );
$GLOBALS['__http_urls'] = array();
$r = sn_hibp_check_password( 'password' );
ok( SN_HIBP_BREACHED === $r['verdict'] && 52372427 === $r['count'], 'check: a breached password reads BREACHED with its count' );

echo "\nGroup: THE PRIVACY CONTRACT, asserted rather than assumed\n";
$sent = $GLOBALS['__http_urls'][0] ?? '';
// Assert on the PATH, never the whole URL: "pwnedpasswords.com" contains the
// literal substring "password", so a naive whole-URL scan matches the API's own
// domain and reports a leak that is not there. (It did, on the first run of
// this suite — the same substring trap this codebase keeps finding.)
$sent_path = (string) parse_url( $sent, PHP_URL_PATH );
ok( '/range/5BAA6' === $sent_path, 'the request path is EXACTLY /range/<prefix> — nothing else is sent' );
ok( false === strpos( $sent_path, '1E4C9B93F3F0682250B6CF8331B7EE68FD8' ), 'the 35-char SUFFIX never leaves the origin' );
ok( false === strpos( strtoupper( $sent_path ), strtoupper( sha1( 'password' ) ) ), 'nor the full SHA-1' );
ok( null === parse_url( $sent, PHP_URL_QUERY ), 'and nothing rides in a query string' );

// A DISTINCTIVE plaintext, so the leak check cannot collide with the host name.
$GLOBALS['__http_urls'] = array();
$GLOBALS['__http'] = array( 'status' => 200, 'body' => $FIXTURE, 'error' => null );
sn_hibp_check_password( 'zzq-unmistakable-plaintext-7f31' );
$sent2 = $GLOBALS['__http_urls'][0] ?? '';
ok( false === strpos( $sent2, 'zzq-unmistakable-plaintext-7f31' ), 'a distinctive plaintext appears NOWHERE in the request' );
ok( 5 === strlen( (string) substr( (string) parse_url( $sent2, PHP_URL_PATH ), strlen( '/range/' ) ) ), 'and only 5 characters of its hash are sent' );
$hdrs = $GLOBALS['__http_args'][0]['headers'] ?? array();
ok( 'true' === ( $hdrs['Add-Padding'] ?? '' ), 'Add-Padding is requested, so response size cannot leak the range population' );

echo "\nGroup: every failure direction returns UNAVAILABLE from the live path\n";
$GLOBALS['__http'] = array( 'status' => 429, 'body' => $FIXTURE, 'error' => null );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_check_password( 'password' )['verdict'], 'a 429 is UNAVAILABLE even though the body would have matched' );
$GLOBALS['__http'] = array( 'status' => 200, 'body' => '', 'error' => null );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_check_password( 'password' )['verdict'], 'an empty 200 is UNAVAILABLE' );
$GLOBALS['__http'] = array( 'status' => 0, 'body' => '', 'error' => new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_check_password( 'password' )['verdict'], 'a timeout is UNAVAILABLE' );
$GLOBALS['__http'] = array( 'status' => 200, 'body' => $FIXTURE, 'error' => null );
ok( SN_HIBP_UNAVAILABLE === sn_hibp_check_password( '' )['verdict'], 'an empty password is UNAVAILABLE — nothing is sent' );

echo "\nGroup: Phase 0 wires NOTHING (Modes A and B are later phases)\n";
$src = (string) file_get_contents( __DIR__ . '/../inc/breached-credentials.php' );
ok( false === strpos( $src, 'user_profile_update_errors' ), 'no set-time hook is registered yet' );
ok( false === strpos( $src, 'validate_password_reset' ), 'no password-reset hook is registered yet' );
ok( false === strpos( $src, 'wp_login' ), 'no login hook is registered yet' );
ok( false === strpos( $src, 'add_action' ) && false === strpos( $src, 'add_filter' ), 'the client registers no hooks at all — it cannot reject or warn about anything' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
