<?php
/**
 * Tests for the shared Health-probe response classifier (plugin v6.48.4).
 *
 * inc/health-probe-classify.php provides sn_health_is_bot_challenge(), the single
 * seam both Health probes (internal broken-links + external link-rot) use to tell
 * a genuinely dead/forbidden URL apart from a LIVE page behind a Cloudflare bot
 * challenge (which answers an automated probe with a 403/503 interstitial carrying
 * `cf-mitigated: challenge`). Detection must (a) key on that purpose-built header
 * so an origin 4xx passed THROUGH Cloudflare is never masked, (b) be constrained to
 * the challenge-bearing codes (403/503) so a real 404/410 still rots, and (c) work
 * against the CaseInsensitiveDictionary (ArrayAccess) shape wp_remote_retrieve_headers
 * returns in production, not just a plain array.
 *
 * @since plugin v6.48.4
 */

// SECURITY: Prevent web access.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Faithful stand-in for WP's WpOrg\Requests\Utility\CaseInsensitiveDictionary
// (what wp_remote_retrieve_headers() returns in production): ArrayAccess with
// case-insensitive keys. Lets the classifier be tested through the SAME shape it
// sees on the live site, not just a plain array.
class SN_CI_Headers implements ArrayAccess {
	private $d = array();
	public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->d[ strtolower( (string) $k ) ] = $v; } }
	public function offsetExists( $k ): bool { return isset( $this->d[ strtolower( (string) $k ) ] ); }
	public function offsetGet( $k ): mixed { return $this->d[ strtolower( (string) $k ) ] ?? null; }
	public function offsetSet( $k, $v ): void { $this->d[ strtolower( (string) $k ) ] = $v; }
	public function offsetUnset( $k ): void { unset( $this->d[ strtolower( (string) $k ) ] ); }
}

require __DIR__ . '/../inc/health-probe-classify.php';

ok( function_exists( 'sn_health_is_bot_challenge' ), 'sn_health_is_bot_challenge() defined' );

// ─── Plain-array header bag (the test seam most probes inject) ───
ok( true  === sn_health_is_bot_challenge( 403, array( 'cf-mitigated' => 'challenge' ) ), '403 + cf-mitigated:challenge → bot challenge (not rot)' );
ok( true  === sn_health_is_bot_challenge( 503, array( 'cf-mitigated' => 'challenge' ) ), '503 + cf-mitigated:challenge → bot challenge (legacy IUAM)' );
ok( true  === sn_health_is_bot_challenge( 403, array( 'CF-Mitigated' => 'Challenge' ) ), 'header name + value match is case-insensitive' );
ok( false === sn_health_is_bot_challenge( 403, array( 'server' => 'cloudflare' ) ), 'plain CF 403 WITHOUT cf-mitigated → still rot (no over-suppression of real 403s)' );
ok( false === sn_health_is_bot_challenge( 404, array( 'cf-mitigated' => 'challenge' ) ), '404 stays real rot even with a challenge header (code allowlist 403/503)' );
ok( false === sn_health_is_bot_challenge( 200, array() ), 'healthy 200 → not a challenge' );
ok( false === sn_health_is_bot_challenge( 403, array() ), 'bare 403, empty headers → still rot' );

// ─── Production path: a CaseInsensitiveDictionary-style ArrayAccess bag ───
ok( true  === sn_health_is_bot_challenge( 403, new SN_CI_Headers( array( 'CF-Mitigated' => 'challenge' ) ) ), 'detects a challenge through an ArrayAccess (CaseInsensitiveDictionary) bag — the real WP path' );
ok( false === sn_health_is_bot_challenge( 403, new SN_CI_Headers( array( 'server' => 'cloudflare', 'cf-ray' => 'abc' ) ) ), 'ArrayAccess bag without cf-mitigated → still rot (not a challenge)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
