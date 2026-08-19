<?php
/**
 * Tests: the Google Search Console service-account credential (R6b step 0).
 *
 * Run: php tests/search-console-credential.php
 *
 * The load-bearing property is NEGATIVE: the private key must never leave this
 * module except through the accessor that signs. Everything a screen touches —
 * the identity card, the drift snapshot — must be provably key-free, and
 * "provably" here means asserting the key's own bytes are absent, not that a
 * field is unset.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Minimal settings surface: the credential module reads through sn_setting().
$GLOBALS['__leaf'] = '';
function sn_setting( $path, $default = null ) {
	return 'search_console.gsc_credential' === $path ? $GLOBALS['__leaf'] : $default;
}
function sn_setting_update( $path, $value ) {
	if ( 'search_console.gsc_credential' === $path ) { $GLOBALS['__leaf'] = (string) $value; }
	return true;
}
function __( $s, $d = null ) { return $s; }
function sprintf_stub() {}

require __DIR__ . '/../inc/search-console-credential.php';

// A structurally real service-account key. The private_key is a MARKER string,
// not a real PEM body — every assertion below searches for these exact bytes,
// so a leak anywhere is caught by value rather than by shape.
const KEY_MARKER = 'KEY-BYTES-THAT-MUST-NEVER-LEAK';
function snt_gsc_test_json( array $over = array() ) {
	return wp_json( array_merge( array(
		'type'           => 'service_account',
		'project_id'     => 'sn-search-console',
		'private_key_id' => 'abc123def456',
		'private_key'    => "-----BEGIN PRIVATE KEY-----\n" . KEY_MARKER . "\n-----END PRIVATE KEY-----\n",
		'client_email'   => 'sn-gsc@sn-search-console.iam.gserviceaccount.com',
		'token_uri'      => 'https://oauth2.googleapis.com/token',
	), $over ) );
}
function wp_json( $a ) { return json_encode( $a, JSON_UNESCAPED_SLASHES ); }

echo "Group: validation refuses what cannot mint a token\n";
// PAIRS, not a keyed map: two cases share the expected code `not_json`, and as
// array keys the second silently overwrote the first — a test case that vanished
// without a word. A list cannot lose a case.
$cases = array(
	array( 'empty',                  '' ),
	array( 'empty',                  "   \n  " ),
	array( 'not_json',               'this is not json' ),
	array( 'not_json',               '{"unclosed": ' ),
	array( 'not_json',               '[1,2,3]' ),
	array( 'not_service_account',    wp_json( array( 'type' => 'authorized_user', 'client_id' => 'x' ) ) ),
	array( 'missing_client_email',   snt_gsc_test_json( array( 'client_email' => '' ) ) ),
	array( 'missing_private_key',    snt_gsc_test_json( array( 'private_key' => '' ) ) ),
	array( 'missing_private_key_id', snt_gsc_test_json( array( 'private_key_id' => '' ) ) ),
	array( 'missing_project_id',     snt_gsc_test_json( array( 'project_id' => '' ) ) ),
	array( 'private_key_not_pem',    snt_gsc_test_json( array( 'private_key' => 'not-a-pem' ) ) ),
	array( 'client_email_malformed', snt_gsc_test_json( array( 'client_email' => 'no-at-sign' ) ) ),
);
foreach ( $cases as $i => $case ) {
	list( $expected, $input ) = $case;
	$r = snt_gsc_credential_validate( $input );
	ok( false === $r['ok'] && $expected === $r['error'], "refused [$i]: $expected" );
}
ok( 12 === count( $cases ), 'all 12 refusal cases are present — a keyed map silently dropped one' );
$good = snt_gsc_credential_validate( snt_gsc_test_json() );
ok( true === $good['ok'] && '' === $good['error'], 'a well-formed service-account key is accepted' );
ok( 'service_account' === $good['parsed']['type'], 'and comes back parsed' );

echo "\nGroup: an OAuth client JSON is the mistake worth naming\n";
// Same download screen, same shape, cannot do a JWT grant. Its own error code
// exists so the notice can say WHICH wrong file was pasted.
$oauth = wp_json( array( 'installed' => array( 'client_id' => 'x.apps.googleusercontent.com', 'client_secret' => 's' ) ) );
ok( 'not_service_account' === snt_gsc_credential_validate( $oauth )['error'], 'an OAuth client JSON is refused as not_service_account, not as generic bad JSON' );

echo "\nGroup: the key never leaves the module\n";
$GLOBALS['__leaf'] = snt_gsc_test_json();
ok( true === snt_gsc_credential_is_configured(), 'a stored key reads as configured' );
$id = snt_gsc_credential_identity();
ok( is_array( $id ), 'identity card is produced' );
ok( false === strpos( wp_json( $id ), KEY_MARKER ), 'the identity card does NOT contain the private key bytes' );
ok( ! array_key_exists( 'private_key', $id ), 'and carries no private_key field at all' );
ok( 'sn-gsc@sn-search-console.iam.gserviceaccount.com' === $id['client_email'], 'it names the service account (non-secret, and the value the owner must grant in Search Console)' );
ok( 0 === strpos( $id['key_fingerprint'], 'sha256:' ), 'the key is represented by a hash' );
ok( false === strpos( $id['key_fingerprint'], KEY_MARKER ), 'and the hash is not the key' );

// The signing accessor is the ONE that hands the key back — assert it does, so
// "nothing leaks" is not accidentally achieved by nothing working.
$signing = snt_gsc_credential();
ok( false !== strpos( (string) $signing['private_key'], KEY_MARKER ), 'snt_gsc_credential() DOES return the key — the negative assertions above are about the other accessors, not about a broken read' );

echo "\nGroup: a stored-but-broken credential is not 'configured'\n";
$GLOBALS['__leaf'] = '{"type":"authorized_user"}';
ok( false === snt_gsc_credential_is_configured(), 'a stored value that no longer parses reads as NOT configured' );
ok( null === snt_gsc_credential_identity(), 'and yields no identity card' );
ok( '' !== snt_gsc_credential_raw(), 'while raw storage still reports it present — so the screen can say "stored but unparseable" rather than "not configured"' );

$GLOBALS['__leaf'] = '';
ok( false === snt_gsc_credential_is_configured(), 'unset reads as not configured' );
ok( null === snt_gsc_credential_identity(), 'and no identity card' );

echo "\nGroup: signing readiness is reported, not discovered later\n";
$GLOBALS['__leaf'] = snt_gsc_test_json();
ok( array_key_exists( 'signing_ready', snt_gsc_credential_identity() ), 'the card reports whether RS256 signing is even possible on this host' );
ok( snt_gsc_credential_identity()['signing_ready'] === function_exists( 'openssl_sign' ), 'and reports it from the actual openssl availability' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
