<?php
/**
 * Only the read door's tools are public to the official MCP Adapter (2026-10-26
 * audit, N2): sn_mcp_adapter_public_floor() runs on wp_register_ability_args and
 * clears meta.mcp.public for any signal-noise/ or signal-and-noise/ ability that
 * is not on the read-door allowlist, so a hand-set flag can no longer put a
 * write ability behind the adapter's execute tool.
 *
 * Run: php tests/mcp-adapter-public-floor.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__filters'] = array();
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][] = $c; return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function __( $s, $d = null ) { return $s; }

require_once __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require_once __DIR__ . '/../inc/mcp/mcp-endpoint.php';

ok( in_array( 'sn_mcp_adapter_public_floor', $GLOBALS['__filters']['wp_register_ability_args'] ?? array(), true ), 'the floor is hooked on wp_register_ability_args' );

$public = static function () {
	return array( 'label' => 'x', 'meta' => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ) ) );
};
$read = sn_mcp_allowlist();
ok( count( $read ) > 10, 'the read-door allowlist is loaded' );

$kept = sn_mcp_adapter_public_floor( $public(), $read[0] );
ok( true === $kept['meta']['mcp']['public'], 'a read-door tool stays public' );

foreach ( array( 'signal-noise/forms-spam-apply', 'signal-noise/jev-tells-pass', 'signal-noise/jev-tells-check', 'signal-noise/get-analytics-events', 'signal-noise/sn-apply', 'signal-and-noise/some-theme-ability' ) as $slug ) {
	$out = sn_mcp_adapter_public_floor( $public(), $slug );
	ok( false === $out['meta']['mcp']['public'] && 'tool' === $out['meta']['mcp']['type'] && true === $out['meta']['show_in_rest'], "$slug is not public; the rest of its meta is untouched" );
}
$rw     = sn_mcp_rw_allowlist();
$leaked = array_filter( $rw, static function ( $slug ) use ( $public ) {
	return false !== sn_mcp_adapter_public_floor( $public(), $slug )['meta']['mcp']['public'];
} );
ok( count( $rw ) > 5 && array() === $leaked, 'no write-door tool stays public (' . count( $rw ) . ' checked)' . ( $leaked ? ': ' . implode( ', ', $leaked ) : '' ) );

$other = sn_mcp_adapter_public_floor( $public(), 'core/get-site-info' );
ok( true === $other['meta']['mcp']['public'], 'another plugin\'s ability is never touched' );
$private = array( 'meta' => array( 'mcp' => array( 'public' => false ) ) );
ok( $private === sn_mcp_adapter_public_floor( $private, 'signal-noise/sn-apply' ), 'a non-public registration passes through unchanged' );
ok( array( 'label' => 'y' ) === sn_mcp_adapter_public_floor( array( 'label' => 'y' ), 'signal-noise/sn-apply' ), 'a registration without meta passes through unchanged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
