<?php
/**
 * The owner identity signer (SN Traffic Lanes, Phase 1): the device marker
 * and the agent token, pinned byte for byte to the build contract's test
 * vectors (docs/plans/traffic-lanes-2026-10-15.md, checkpoint 2), plus the
 * tampered, expired, too-far-future, too-old and previous-key cases.
 * Run: php tests/owner-identity-sign.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
require dirname( __DIR__ ) . '/inc/owner-identity-sign.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "owner-identity-sign -- the device marker and the agent token\n";

$key   = str_repeat( 'ab', 32 );
$other = str_repeat( 'cd', 32 );
$dev   = 'v1.0123456789abcdef.20261015.anHSQ9ch5h07TmKcjtSoniVfPuP9aRaSNJ5UjEgtLpc';
$agent = 'v1.claude-code.1792140000.jJwzmWAzlHun5v56fuqtWUyTbzVfAVozM01-OHUd0Lg';

// ── The contract's vectors, byte for byte.
ok( 'anHSQ9ch5h07TmKcjtSoniVfPuP9aRaSNJ5UjEgtLpc' === sn_owner_sign( $key, 'v1|dev|0123456789abcdef|20261015' ), 'device vector: the signature matches the contract' );
ok( $dev === sn_owner_device_marker( $key, '0123456789abcdef', '20261015' ), 'device vector: the cookie value matches the contract' );
ok( 'jJwzmWAzlHun5v56fuqtWUyTbzVfAVozM01-OHUd0Lg' === sn_owner_sign( $key, 'v1|agent|claude-code|1792140000' ), 'agent vector: the signature matches the contract' );
ok( $agent === sn_owner_agent_token( $key, 'claude-code', 1792140000 ), 'agent vector: the header value matches the contract' );
ok( false === strpos( sn_owner_sign( $key, 'x' ), '=' ), 'base64url carries no padding' );
ok( false === strpbrk( sn_owner_sign( $key, 'v1|agent|claude-code|1792140000' ), '+/' ), 'base64url uses - and _, never + or /' );

// ── Keys: 64 hex or nothing, never the raw string.
ok( '' === sn_owner_sign( '', 'x' ) && '' === sn_owner_sign( 'abc', 'x' ) && '' === sn_owner_sign( str_repeat( 'zz', 32 ), 'x' ), 'an empty, short or non-hex key signs nothing' );
ok( '' === sn_owner_device_marker( '', '0123456789abcdef', '20261015' ) && '' === sn_owner_agent_token( '', 'claude-code', 1 ), 'no key, no marker and no token' );
ok( sn_owner_sign( $key, 'm' ) !== rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'm', $key, true ) ), '+/', '-_' ), '=' ), 'the HMAC key is the 32 hex-decoded bytes, not the 64-character string' );

// ── Device marker verification.
$v = sn_owner_device_verify( $dev, array( $key ), '20261015' );
ok( is_array( $v ) && '0123456789abcdef' === $v['id'] && '20261015' === $v['day'] && 'current' === $v['key'], 'the contract cookie verifies under the current key' );
ok( null === sn_owner_device_verify( 'v1.0123456789abcdee.20261015.anHSQ9ch5h07TmKcjtSoniVfPuP9aRaSNJ5UjEgtLpc', array( $key ), '20261015' ), 'tampered device id fails (contract case)' );
ok( null === sn_owner_device_verify( 'v1.0123456789abcdef.20261016.anHSQ9ch5h07TmKcjtSoniVfPuP9aRaSNJ5UjEgtLpc', array( $key ), '20261016' ), 'tampered day fails' );
ok( null === sn_owner_device_verify( $dev, array( $other ), '20261015' ), 'a different key fails' );
$p = sn_owner_device_verify( $dev, array( $other, $key ), '20261015' );
ok( is_array( $p ) && 'previous' === $p['key'], 'the previous key verifies and says so' );
$e = sn_owner_device_verify( $dev, array( '', $key ), '20261015' );
ok( is_array( $e ) && 'previous' === $e['key'], 'an empty current key never matches; the previous still can' );
ok( null === sn_owner_device_verify( $dev, array( '', '' ), '20261015' ), 'no keys, nothing verifies' );
ok( is_array( sn_owner_device_verify( $dev, array( $key ), '20271119' ) ), '400 days after issue still verifies (2026-10-15 + 400 = 2027-11-19)' );
ok( null === sn_owner_device_verify( $dev, array( $key ), '20271120' ), '401 days after issue is rejected' );
ok( null === sn_owner_device_verify( 'v1.0123456789ABCDEF.20261015.' . sn_owner_sign( $key, 'v1|dev|0123456789ABCDEF|20261015' ), array( $key ), '20261015' ), 'an uppercase device id is not the contract shape' );
ok( null === sn_owner_device_verify( 'v1.0123456789abcdef.20261340.' . sn_owner_sign( $key, 'v1|dev|0123456789abcdef|20261340' ), array( $key ), '20261015' ), 'a day that is not a date fails even when signed' );
ok( null === sn_owner_device_verify( 'v2.0123456789abcdef.20261015.anHSQ9ch5h07TmKcjtSoniVfPuP9aRaSNJ5UjEgtLpc', array( $key ), '20261015' ), 'another version prefix fails' );
ok( null === sn_owner_device_verify( '', array( $key ), '20261015' ) && null === sn_owner_device_verify( 'garbage', array( $key ), '20261015' ) && null === sn_owner_device_verify( $dev . '.x', array( $key ), '20261015' ), 'empty, garbage and extra parts fail' );

// ── Agent token verification: valid iff sig ok AND now < exp AND exp - now <= 43500.
$a = sn_owner_agent_verify( $agent, array( $key ), 1792140000 - 60 );
ok( is_array( $a ) && 'claude-code' === $a['agent'] && 1792140000 === $a['exp'] && 'current' === $a['key'], 'the contract header verifies a minute before expiry' );
ok( null === sn_owner_agent_verify( $agent, array( $key ), 1792140001 ), 'expired: checked at now = 1792140001 it fails (contract case)' );
ok( null === sn_owner_agent_verify( $agent, array( $key ), 1792140000 ), 'now == exp is already expired' );
ok( is_array( sn_owner_agent_verify( $agent, array( $key ), 1792140000 - 43500 ) ), 'exactly 12h05m ahead is accepted' );
ok( null === sn_owner_agent_verify( $agent, array( $key ), 1792140000 - 43501 ), 'more than 12h05m ahead fails (contract case)' );
ok( null === sn_owner_agent_verify( 'v1.claude-code.1792140001.jJwzmWAzlHun5v56fuqtWUyTbzVfAVozM01-OHUd0Lg', array( $key ), 1792130000 ), 'a moved expiry fails' );
ok( null === sn_owner_agent_verify( 'v1.playwright.1792140000.jJwzmWAzlHun5v56fuqtWUyTbzVfAVozM01-OHUd0Lg', array( $key ), 1792130000 ), 'a swapped agent fails' );
$pa = sn_owner_agent_verify( $agent, array( $other, $key ), 1792130000 );
ok( is_array( $pa ) && 'previous' === $pa['key'], 'the previous agent key verifies and says so' );
$bad = sn_owner_agent_token( $key, 'not-listed', 1792140000 );
ok( '' === $bad, 'an agent outside the allowlist is never minted' );
ok( null === sn_owner_agent_verify( 'v1.not-listed.1792140000.' . sn_owner_sign( $key, 'v1|agent|not-listed|1792140000' ), array( $key ), 1792130000 ), 'an agent outside the allowlist never verifies, even when signed' );
ok( array( 'browser', 'chrome-extension', 'claude-code', 'playwright', 'script', 'mcp-local', 'other' ) === sn_owner_agents(), 'the allowlist is the contract\'s agent codes 1 to 7, in order' );

// ── Coordinator addition: canonical base64url only, and exp with no leading zero.
ok( is_array( sn_owner_agent_verify( 'v1.claude-code.1792140000.jJwzmWAzlHun5v56fuqtWUyTbzVfAVozM01-OHUd0Lg', array( $key ), 1792130000 ) ), 'canonical ...Lg verifies' );
ok( null === sn_owner_agent_verify( 'v1.claude-code.1792140000.jJwzmWAzlHun5v56fuqtWUyTbzVfAVozM01-OHUd0Lh', array( $key ), 1792130000 ), 'non-canonical ...Lh (same decoded bytes) fails' );
ok( null === sn_owner_device_verify( 'v1.0123456789abcdef.20261015.anHSQ9ch5h07TmKcjtSoniVfPuP9aRaSNJ5UjEgtLpd', array( $key ), '20261015' ), 'a non-canonical device signature (...Lpd for ...Lpc) fails' );
ok( null === sn_owner_agent_verify( 'v1.claude-code.01792140000.' . sn_owner_sign( $key, 'v1|agent|claude-code|01792140000' ), array( $key ), 1792130000 ), 'an exp with a leading zero fails even when signed' );
ok( null === sn_owner_agent_verify( 'v1.claude-code.1234567890123.' . sn_owner_sign( $key, 'v1|agent|claude-code|1234567890123' ), array( $key ), 1234567890000 ), 'an exp of 13 digits fails' );
ok( null === sn_owner_agent_verify( $agent, array( substr( $key, 0, 63 ) . 'g' ), 1792130000 ) && null === sn_owner_agent_verify( $agent, array( $key . 'ab' ), 1792130000 ), 'a key that is not exactly 64 hex is treated as unset' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
