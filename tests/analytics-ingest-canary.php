<?php
/**
 * The ingest canary: one pageview to the real collector under a SignalNoise-
 * user agent (kept out of every count), and what the collector answered.
 * Run: php tests/analytics-ingest-canary.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_INGEST_CANARY_TEST', true );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['opt'] = array(); $GLOBALS['tok'] = 'pub-key';
function sn_rss_tracker_token() { return $GLOBALS['tok']; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'timed out'; } }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : ''; }
require __DIR__ . '/../inc/analytics-ingest-canary.php';

$sent = null;
$last = sn_ingest_canary_send( function ( $url, $args ) use ( &$sent ) { $sent = array( $url, $args ); return array( 'code' => 204 ); } );
ok( 'https://juanlentino.com/_sn/px' === $sent[0], 'posts to the real collector route on the site' );
ok( 0 === strpos( $sent[1]['user-agent'], 'SignalNoise-' ), 'with a SignalNoise- user agent, so the Worker files it as this site\'s own tool, in no class' );
ok( array( 'k' => 'pub-key', 'e' => 'pv', 'u' => '/' ) === json_decode( $sent[1]['body'], true ), 'a pageview of / with the public beacon key' );
ok( 0 === $sent[1]['redirection'], 'never follows a redirect' );
ok( 204 === $last['http'] && 204 === $GLOBALS['opt'][ SN_INGEST_CANARY_OPT ]['http'], 'stores what the collector answered' );

$last = sn_ingest_canary_send( function () { return new WP_Error(); } );
ok( 0 === $last['http'] && 'timed out' === $last['why'], 'a failed request is stored with its reason, never as a 204' );

$GLOBALS['tok'] = ''; $called = false;
$last = sn_ingest_canary_send( function () use ( &$called ) { $called = true; return array( 'code' => 204 ); } );
ok( ! $called && 'no beacon key configured' === $last['why'], 'no beacon key: nothing is sent, and the reason is stored' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
