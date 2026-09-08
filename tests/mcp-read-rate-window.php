<?php
/** Deterministic fixed-window regressions; no WordPress or wall-clock sleeps. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
$now = 1200;
$cache = array();
$external = false;
$broken = false;
$adds = 0;
$increments = 0;
function wp_using_ext_object_cache() { return $GLOBALS['external']; }
function get_transient( $key ) {
	$entry = $GLOBALS['cache'][ $key ] ?? null;
	return $entry && $entry[1] > $GLOBALS['now'] ? $entry[0] : false;
}
function set_transient( $key, $value, $ttl ) {
	if ( $GLOBALS['broken'] ) { return false; }
	$GLOBALS['cache'][ $key ] = array( $value, $GLOBALS['now'] + $ttl );
	return true;
}
function wp_cache_get( $key, $group ) { return get_transient( $key ); }
function wp_cache_set( $key, $value, $group, $ttl ) { return set_transient( $key, $value, $ttl ); }
function wp_cache_add( $key, $value, $group, $ttl ) {
	$GLOBALS['adds']++;
	if ( ! empty( $GLOBALS['race'] ) ) {
		$GLOBALS['race'] = false;
		$GLOBALS['race_result'] = sn_mcp_read_rate_limit_check( 'user:race', true, $GLOBALS['now'] );
	}
	if ( false !== get_transient( $key ) ) { return false; }
	return set_transient( $key, $value, $ttl );
}
function wp_cache_incr( $key, $offset, $group ) {
	$GLOBALS['increments']++;
	if ( $GLOBALS['broken'] || false === get_transient( $key ) ) { return false; }
	$GLOBALS['cache'][ $key ][0] += $offset;
	return $GLOBALS['cache'][ $key ][0];
}
require __DIR__ . '/../inc/mcp/mcp-read-guard.php';
$passed = 0;
function check( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
	$GLOBALS['passed']++;
}
foreach ( array( false, true ) as $external ) {
	$cache = array();
	// Both widgets plus modest dashboard traffic: six reads every 15s, never
	// 60s of silence. The old refreshing TTL eventually refuses this load.
	for ( $now = 1200; $now < 4800; $now += 15 ) {
		for ( $i = 0; $i < 6; $i++ ) {
			check( sn_mcp_read_rate_limit_check( 'user:polls', true, $now )['allow'], 'sustained sub-cap polling must not accumulate across minutes' );
		}
	}
	$cache = array();
	$now = 6000;
	for ( $i = 0; $i < 120; $i++ ) {
		check( sn_mcp_read_rate_limit_check( 'user:burst', true, $now )['allow'], 'exactly 120 calls allowed' );
	}
	$now = 6045;
	$d = sn_mcp_read_rate_limit_check( 'user:burst', true, $now );
	check( ! $d['allow'] && 15 === $d['retry_after'], 'retry_after is remaining window, not another full minute' );
	$now = 6059;
	check( ! sn_mcp_read_rate_limit_check( 'user:burst', true, $now )['allow'], 'refusal does not reset or extend window' );
	$now = 6060;
	check( sn_mcp_read_rate_limit_check( 'user:burst', true, $now )['allow'], 'exact boundary starts a new window without idle time' );
	$broken = true;
	check( ! sn_mcp_read_rate_limit_check( 'user:broken', true, $now )['allow'], 'remote store write failure fails closed' );
	check( sn_mcp_read_rate_limit_check( 'user:broken', false, $now )['allow'], 'local store write failure remains fail open' );
	$broken = false;
}
check( $adds > 0 && $increments > 0, 'external cache uses atomic add and increment' );
$external = true;
$cache = array();
$race = true;
check( sn_mcp_read_rate_limit_check( 'user:race', true, $now )['allow'], 'outer concurrent cold-key caller allowed' );
check( ! empty( $race_result['allow'] ), 'interleaved cold-key caller allowed' );
for ( $i = 0; $i < 118; $i++ ) {
	check( sn_mcp_read_rate_limit_check( 'user:race', true, $now )['allow'], 'remaining concurrent budget allocated once' );
}
check( ! sn_mcp_read_rate_limit_check( 'user:race', true, $now )['allow'], 'cold-key race loses neither count and still caps at 120' );
// A fresh PHP process has no WP store functions at all. This exercises the
// real failure branch rather than merely asserting the pure decision helper.
$script = 'define("ABSPATH", "/"); require ' . var_export( __DIR__ . '/../inc/mcp/mcp-read-guard.php', true ) . '; echo json_encode(array(sn_mcp_read_rate_limit_check("remote", true), sn_mcp_read_rate_limit_check("local", false)));';
$pipes = array();
$process = proc_open( array( PHP_BINARY, '-r', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
check( is_resource( $process ), 'missing-store subprocess starts' );
$output = stream_get_contents( $pipes[1] );
fclose( $pipes[1] );
check( 0 === proc_close( $process ), 'missing-store subprocess completes' );
$decisions = json_decode( $output, true );
check( false === ( $decisions[0]['allow'] ?? null ), 'actual absent store refuses remote call' );
check( true === ( $decisions[1]['allow'] ?? null ), 'actual absent store preserves local fail-open' );
echo "Result: $passed passed, 0 failed. (Transients and persistent cache.)\n";
