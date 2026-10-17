<?php
/**
 * sn_prov_signed_headers(): the v2 envelope the provenance Worker (1.26.0,
 * src/webhook-auth.mjs) verifies. The golden signature below was computed
 * outside PHP (node crypto, the same HMAC/SHA-256 the Worker's WebCrypto runs),
 * so this suite does not just check PHP against itself.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
require_once dirname( __DIR__ ) . '/inc/provenance-signed-headers.php';

$pass = 0;
$fail = 0;
function sh_ok( $cond, $m ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ok   $m\n";
	} else {
		++$fail;
		echo "  FAIL $m\n";
	}
}

$body   = '{"action":"sweep"}';
$secret = str_repeat( 'shh', 12 );
$nonce  = 'abcdefghijklmnop0123456789ABCDEF';
$h      = sn_prov_signed_headers( $body, $secret, 1760000000, $nonce );

sh_ok( '1760000000' === ( $h['X-SN-Sig-Ts'] ?? null ), 'ts header is the pinned unix seconds' );
sh_ok( $nonce === ( $h['X-SN-Sig-Nonce'] ?? null ), 'nonce header is the pinned nonce' );
sh_ok( 'b682c7df50a1024483ef59a3bdccd00a7a3dc6ac9ff54137dd5ab1fe70df7d71' === ( $h['X-SN-Sig'] ?? null ), 'X-SN-Sig matches the golden v2 vector (hex HMAC over v2|ts|nonce|sha256(body))' );
sh_ok( ! isset( $h['X-SN-Signature'] ), 'the v1 body-only header is no longer sent (Worker 1.26.1 refuses v1)' );
sh_ok( 'application/json' === ( $h['Content-Type'] ?? null ), 'content type is json' );

// Defaults: a live ts and a nonce the Worker's regex accepts, fresh each call.
$a = sn_prov_signed_headers( $body, $secret );
$b = sn_prov_signed_headers( $body, $secret );
sh_ok( abs( time() - (int) $a['X-SN-Sig-Ts'] ) <= 1, 'default ts is now' );
sh_ok( 1 === preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $a['X-SN-Sig-Nonce'] ), 'default nonce passes the Worker nonce regex' );
sh_ok( $a['X-SN-Sig-Nonce'] !== $b['X-SN-Sig-Nonce'], 'default nonce is fresh per request' );
sh_ok( $h['X-SN-Sig'] !== sn_prov_signed_headers( $body . ' ', $secret, 1760000000, $nonce )['X-SN-Sig'], 'one changed body byte changes the v2 signature' );

// No provenance file sends the v1 header: every Worker POST goes through the
// helper, which sends v2 only. inc/webhooks.php and inc/webhooks-admin.php are
// the owner's own outbound webhooks, a different contract, and are exempt.
$hand = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/inc', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = $file->getPathname();
	if ( '.php' !== substr( $path, -4 ) || in_array( basename( $path ), array( 'webhooks.php', 'webhooks-admin.php' ), true ) ) {
		continue;
	}
	if ( false !== strpos( (string) file_get_contents( $path ), 'X-SN-Signature' ) ) {
		$hand[] = basename( $path );
	}
}
sh_ok( array() === $hand, 'no provenance file sends X-SN-Signature (found: ' . implode( ', ', $hand ) . ')' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
