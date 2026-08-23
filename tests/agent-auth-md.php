<?php
/**
 * /auth.md — the agent authentication guide.
 *
 * The assertions that matter are the NEGATIVE ones. This document exists
 * because the site is NOT an OAuth authorization server and has no registration
 * endpoint; if it ever starts claiming one to satisfy a conformance scanner,
 * this suite fails and says why.
 *
 * Run: php tests/agent-auth-md.php
 *
 * @since 12.21.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
define( 'SN_AGENT_DISCOVERY_TEST', true );
define( 'SN_MCP_TEST', true );

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'sn_mcp_namespace' ) ) {
	function sn_mcp_namespace() { return 'signal-noise/v1'; }
}
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return $s; } }

require_once __DIR__ . '/../inc/agent-discovery.php';
require_once __DIR__ . '/../inc/agent-auth-md.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

$doc = sn_agent_auth_md_document();
// Hard-wrapped prose: normalise whitespace before phrase matching, or a line
// wrap that changes nothing semantically fails the assertion.
$flat = preg_replace( '/\s+/', ' ', $doc );

// 1. Address + routing.
ok( SN_AGENT_AUTH_MD_PATH === '/auth.md', 'served at the site root as /auth.md' );
ok( sn_agent_auth_md_is_request( '/auth.md' ), 'matches its own path' );
ok( sn_agent_auth_md_is_request( '/auth.md?x=1' ), 'matches with a query string' );
ok( ! sn_agent_auth_md_is_request( '/auth.md/more' ), 'does NOT match a longer path' );
ok( ! sn_agent_auth_md_is_request( '/AUTH.MD' ), 'does NOT match a different case' );

// 2. THE NEGATIVE PINS. This document's whole reason for existing is that the
//    site has none of these. Claiming any of them would advertise a door that
//    does not open — the failure inc/agent-discovery.php exists to prevent.
foreach ( array( 'register_uri', 'registration_endpoint', 'token_endpoint',
	'client_id', 'client_secret' ) as $never ) {
	// The document NAMES these in order to deny them, so a flat ban would pin
	// the prose rather than the behaviour. Forbid them only where they are
	// being ADVERTISED — given a value or a URL.
	ok( 0 === preg_match( '/' . preg_quote( $never, '/' ) . '\s*[:=]\s*\S/i', $flat ),
		"never advertises '$never' with a value — the site operates no such endpoint" );
	ok( 0 === preg_match( '/' . preg_quote( $never, '/' ) . '[^.]{0,40}https?:\/\//i', $flat ),
		"never points '$never' at a URL" );
}
ok( false !== stripos( $flat, 'publishes no `register_uri`' ),
	'explicitly denies operating a register_uri' );
ok( false !== stripos( $flat, 'no programmatic registration' ),
	'states plainly that there is no programmatic registration' );
ok( false !== stripos( $flat, 'not an OAuth' ),
	'states plainly that the site is not an OAuth authorization server' );

// 3. It tells the truth about the credential that DOES work.
ok( false !== strpos( $doc, 'application password' ), 'names the credential type' );
ok( false !== strpos( $doc, 'manage_options' ), 'names the required capability' );
ok( false !== strpos( $doc, '401' ), 'says what an unauthenticated request receives' );
ok( false !== strpos( $doc, sn_agent_mcp_endpoint_url() ), 'names the real MCP endpoint' );
ok( false !== strpos( $doc, 'https://juanlentino.com' . SN_AGENT_CARD_PATH ),
	'points at the server card for discovery' );

// 4. It does not send a read-only agent down an auth path it does not need.
ok( false !== stripos( $flat, 'you do not need to authenticate' ),
	'tells a content-only agent it needs no credential at all' );

// 5. The write door is never obtainable through this process.
ok( false !== stripos( $flat, 'write door is a separate endpoint' ),
	'states the write door is not reachable via this process' );

// 6. Revocation guidance exists — a 401 must not be retried forever.
ok( false !== stripos( $flat, 'revocation' ) || false !== stripos( $flat, 'revoke' ),
	'covers revocation' );
ok( false !== stripos( $flat, 'stop retrying' ), 'tells the agent to stop retrying on 401' );

// 7. Surface advertisement.
$s = sn_agent_auth_md_advertise_surface( array() );
ok( 1 === count( $s ) && 'auth-guide' === $s[0]['type'], 'appends exactly one auth-guide surface' );
ok( $s[0]['url'] === 'https://juanlentino.com/auth.md', 'surface url is absolute and correct' );
ok( 'text/markdown' === $s[0]['format'], 'surface declares text/markdown' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
