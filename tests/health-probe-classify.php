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
ok( false === sn_health_is_bot_challenge( 403, array( 'server' => 'cloudflare' ) ), 'CF 403 WITHOUT cf-mitigated → NOT a challenge (it is a CF block; edge-gated detection handles it, see below)' );
ok( false === sn_health_is_bot_challenge( 404, array( 'cf-mitigated' => 'challenge' ) ), '404 stays real rot even with a challenge header (code allowlist 403/503)' );
ok( false === sn_health_is_bot_challenge( 200, array() ), 'healthy 200 → not a challenge' );
ok( false === sn_health_is_bot_challenge( 403, array() ), 'bare 403, empty headers → still rot' );

// ─── Production path: a CaseInsensitiveDictionary-style ArrayAccess bag ───
ok( true  === sn_health_is_bot_challenge( 403, new SN_CI_Headers( array( 'CF-Mitigated' => 'challenge' ) ) ), 'detects a challenge through an ArrayAccess (CaseInsensitiveDictionary) bag — the real WP path' );
ok( false === sn_health_is_bot_challenge( 403, new SN_CI_Headers( array( 'server' => 'cloudflare', 'cf-ray' => 'abc' ) ) ), 'ArrayAccess bag without cf-mitigated → not a challenge (a CF block; edge-gated below)' );

// ─── sn_health_is_edge_gated(): a CF block / rate-limit is a LIVE page the edge
// gates for automated clients, NOT rot. Cloudflare serves cf-ray on every
// response + server:cloudflare; a block carries those but no cf-mitigated. HTTP
// semantics: 403/429 = access-restricted, not "gone" (that is 404/410). A plain
// non-CF 403 is left alone (the prior author's deliberate guard). ───
ok( function_exists( 'sn_health_is_edge_gated' ), 'sn_health_is_edge_gated() defined' );
ok( true  === sn_health_is_edge_gated( 403, array( 'cf-ray' => '8ab' ) ), 'CF block: 403 + cf-ray → edge-gated (live, not rot)' );
ok( true  === sn_health_is_edge_gated( 403, array( 'server' => 'cloudflare' ) ), 'CF block: 403 + server:cloudflare → edge-gated' );
ok( true  === sn_health_is_edge_gated( 429, array( 'cf-ray' => '8ab' ) ), 'CF rate-limit: 429 + cf-ray → edge-gated' );
ok( false === sn_health_is_edge_gated( 403, array() ), 'plain 403 (no CF fingerprint) → NOT edge-gated (prior guard: still rot)' );
ok( false === sn_health_is_edge_gated( 403, array( 'server' => 'nginx' ) ), 'non-CF 403 (server:nginx) → NOT edge-gated (still rot)' );
ok( false === sn_health_is_edge_gated( 404, array( 'cf-ray' => '8ab' ) ), '404 behind CF → GONE, stays rot (edge-gated is 403/429 only)' );
ok( false === sn_health_is_edge_gated( 410, array( 'cf-ray' => '8ab' ) ), '410 behind CF → GONE, stays rot' );
ok( false === sn_health_is_edge_gated( 503, array( 'cf-ray' => '8ab' ) ), '503 behind CF (no cf-mitigated) → possible real outage, stays rot' );
ok( false === sn_health_is_edge_gated( 200, array( 'cf-ray' => '8ab' ) ), 'healthy 200 behind CF → not edge-gated' );
ok( true  === sn_health_is_edge_gated( 403, new SN_CI_Headers( array( 'CF-RAY' => '8ab' ) ) ), 'detects CF fingerprint through the ArrayAccess CaseInsensitiveDictionary (real WP path)' );

// ─── sn_health_is_nonstandard_status(): a probe answered with a code OUTSIDE the
// valid HTTP range (100–599, RFC 9110 §15 — first digit is the class 1–5) is not
// a real HTTP status and cannot mean "gone" (that is a well-defined 404/410). In
// practice it is an anti-bot / anti-scraping refusal of the automated probe; the
// canonical case is LinkedIn's HTTP 999 "Request denied", returned to non-browser
// clients hitting a profile that is fully LIVE for a human. Host-agnostic (LinkedIn
// is NOT behind Cloudflare, so the CF classifiers above never catch it) and keys
// purely on the code, needing no header fingerprint. The code-0 network-error
// sentinel the probes use stays BELOW 600, so a genuine failure is never masked. ───
ok( function_exists( 'sn_health_is_nonstandard_status' ), 'sn_health_is_nonstandard_status() defined' );
ok( true  === sn_health_is_nonstandard_status( 999 ), 'HTTP 999 (LinkedIn anti-bot "Request denied") → non-standard, unverifiable (not rot)' );
ok( true  === sn_health_is_nonstandard_status( 600 ), '600 (first code past the valid HTTP range) → non-standard' );
ok( true  === sn_health_is_nonstandard_status( 700 ), 'any code ≥ 600 → non-standard (not just 999)' );
ok( false === sn_health_is_nonstandard_status( 599 ), '599 (top of the valid HTTP range) → standard, NOT caught' );
ok( false === sn_health_is_nonstandard_status( 500 ), '500 server error → standard, stays real rot' );
ok( false === sn_health_is_nonstandard_status( 404 ), '404 → standard, GONE, stays real rot' );
ok( false === sn_health_is_nonstandard_status( 410 ), '410 → standard, GONE, stays real rot' );
ok( false === sn_health_is_nonstandard_status( 200 ), 'healthy 200 → not non-standard' );
ok( false === sn_health_is_nonstandard_status( 0 ), 'code 0 (network-error sentinel) → NOT caught here (stays a network-failure rot signal)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
