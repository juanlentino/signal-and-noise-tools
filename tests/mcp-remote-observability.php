<?php
/**
 * Tests: the remote door's observability store.
 *
 * THE PROPERTY THAT MATTERS MOST across this suite is that recording is
 * OBSERVATIONAL. The door must work identically with this module absent, so
 * nothing here may become a dependency of the bridge. That pin lives in
 * tests/mcp-bridge-route.php, because it is a property of the BRIDGE.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
// The module follows the WordPress timezone setting; these suites do not load
// WordPress, so wp_date() is stubbed to the server's. What matters is that the
// module CALLS wp_date and not gmdate — pinned below.
$GLOBALS['__wp_date_calls'] = array();
function wp_date( $format, $ts = null ) {
	$GLOBALS['__wp_date_calls'][] = $format;
	return date( $format, null === $ts ? time() : $ts );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
$GLOBALS['__autoload'] = array();
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__options'][ $k ]  = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return true;
}

$GLOBALS['__transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; $GLOBALS['__ttls'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

require __DIR__ . '/../inc/mcp/mcp-remote-observability.php';

echo "Group: the outcome list is a closed set\n";
ok( in_array( 'dispatched', SN_MCP_REMOTE_OUTCOMES, true ), 'dispatched is an outcome' );
ok( in_array( 'refused_shut', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_shut is an outcome' );
ok( in_array( 'refused_auth', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_auth is an outcome' );
ok( in_array( 'refused_slug', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_slug is an outcome' );
ok( in_array( 'refused_request', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_request is an outcome' );
ok( 5 === count( SN_MCP_REMOTE_OUTCOMES ), 'and there are exactly five — a new one must be added deliberately, with a counter and a label' );

echo "Group: the day key follows the WordPress timezone setting\n";
// PINNED BY CALL, NOT BY VALUE, and deliberately. On a UTC server wp_date() and
// gmdate() return the SAME STRING, so a value comparison would pass against
// either and prove nothing — it would be green on CI and green on a mutation
// that swapped the call. Recording that wp_date was asked for 'Y-m-d' is the
// only assertion here that a swap to gmdate() can fail.
$GLOBALS['__wp_date_calls'] = array();
$key = sn_mcp_remote_log_day_key();
ok( in_array( 'Y-m-d', $GLOBALS['__wp_date_calls'], true ), 'THE TIMEZONE PIN: the day key is produced by wp_date, so it agrees with snt_audit_today_key()' );
ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $key ), 'and it is shaped Y-m-d' );

echo "Group: the blob lazy-initialises to a valid shape\n";
$GLOBALS['__options'] = array();
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['schema'], 'a missing option yields schema 1' );
ok( null === $blob['last_used'], 'with no last_used' );
ok( array() === $blob['counters'], 'no counters' );
ok( array() === $blob['recent'], 'and no recent rows' );

echo "Group: a corrupt option does not poison the reader\n";
// An option can be hand-edited, half-written, or restored from an older schema.
// Returning garbage here would propagate into every caller.
$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = 'not an array';
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['schema'] && array() === $blob['counters'], 'a non-array option falls back to the empty shape rather than propagating garbage' );

$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = array( 'schema' => 1 );
$blob = sn_mcp_remote_log_get_blob();
ok( array() === $blob['counters'] && array() === $blob['recent'] && null === $blob['last_used'], 'and a partial blob gains its missing keys instead of returning undefined ones' );

echo "Group: saving does NOT autoload\n";
// This option is read by the admin panel and by nothing on a front-end request.
// Autoloading it would tax every page view for one screen's data.
$GLOBALS['__options'] = array();
sn_mcp_remote_log_save_blob( sn_mcp_remote_log_get_blob() );
ok( false === $GLOBALS['__autoload'][ SN_MCP_REMOTE_LOG_OPTION ], 'THE ONE THAT MATTERS FOR EVERY PAGE LOAD: the log option is saved with autoload FALSE' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-remote-observability.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-remote-observability.php\n";
exit( $fail > 0 ? 1 : 0 );
