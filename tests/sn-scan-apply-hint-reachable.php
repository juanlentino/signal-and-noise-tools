<?php
/**
 * Tests: sn-scan's apply_hint must never name a tool the caller cannot call.
 *
 * v11.34.0 retired 41 tools from the MCP doors. sn-scan STAYS doored and hands
 * every candidate an apply_hint naming the next-step apply tool — so a retired
 * target turns that hint into a dead pointer: a doored tool telling a client to
 * call something the door refuses. v11.13.0 named this exact failure ("a
 * pointer to a tab that isn't there would be a worse failure than the clutter
 * removed"), about a different surface.
 *
 * ai-orphan-apply was ALREADY a dead pointer before this release — it has been
 * off both doors the whole time (tests/mcp-capabilities.php pins it in the
 * deliberate `$excluded` list), and orphan_media's hint named it anyway.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
require __DIR__ . '/../inc/mcp/mcp-capabilities.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "sn-scan apply_hint targets are reachable\n\n";

$doors = array_merge( sn_mcp_allowlist(), sn_mcp_rw_allowlist() );
$src   = file_get_contents( __DIR__ . '/../inc/sn-scan-adapters.php' );

// Every tool named in an apply_hint, read out of the shipped source.
preg_match_all( "/'tool'\s*=>\s*'([^']+)'/", $src, $m );
$named = array_values( array_unique( $m[1] ) );
ok( count( $named ) > 0, 'the adapters name at least one apply_hint tool (guard: a regex matching nothing would pass vacuously)' );

foreach ( $named as $tool ) {
	ok( in_array( $tool, $doors, true ), "apply_hint target is REACHABLE on a door: $tool" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
