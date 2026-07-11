<?php
/**
 * Standalone tests for the MCP capabilities module (allowlist SoT, protocol
 * negotiation, server info). Sub-project B of the machine-readability program.
 *
 * @since plugin v9.22.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '9.22.0' ); }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'name' === $k ? 'Signal & Noise' : ''; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP capabilities — plugin v9.22.0\n\n";

$list = sn_mcp_allowlist();
ok( is_array( $list ) && count( $list ) === 15, 'allowlist has exactly 15 slugs' );
ok( in_array( 'signal-noise/get-health-scan', $list, true ), 'plugin read is allowlisted' );
ok( in_array( 'signal-and-noise/get-design-tokens', $list, true ), 'theme read is allowlisted (cross-namespace)' );
ok( ! in_array( 'signal-noise/purge-all-caches', $list, true ), 'a write ability is NOT allowlisted' );
ok( ! in_array( 'signal-and-noise/get-llms-txt', $list, true ), 'the cut redundant read is NOT allowlisted' );

ok( sn_mcp_is_allowed( 'signal-noise/get-narration' ) === true, 'is_allowed true for an allowlisted slug' );
ok( sn_mcp_is_allowed( 'signal-noise/run-narration' ) === false, 'is_allowed false for a non-allowlisted slug' );

$info = sn_mcp_server_info();
ok( ( $info['name'] ?? '' ) === 'Signal & Noise', 'server_info carries the site name' );
ok( ( $info['version'] ?? '' ) === '9.22.0', 'server_info carries SNT_VERSION' );

ok( sn_mcp_negotiate_version( '2025-06-18' ) === '2025-06-18', 'negotiate echoes a supported client version' );
ok( sn_mcp_negotiate_version( '1999-01-01' ) === SN_MCP_PROTOCOL_VERSION, 'negotiate falls back to our default for an unknown version' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
