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
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'timed out'; } }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : ''; }
require __DIR__ . '/../inc/analytics-ingest-canary.php';

$sent = null;
$last = sn_ingest_canary_send( function ( $url, $args ) use ( &$sent ) { $sent = array( $url, $args ); return array( 'code' => 204 ); } );
ok( 'https://juanlentino.com/_sn/px' === $sent[0], 'posts to the real collector route on the site' );
ok( 0 === strpos( $sent[1]['user-agent'], 'SignalNoise-' ), 'with a SignalNoise- user agent, so the Worker files it as this site\'s own tool, in no class' );
ok( array( 'k' => 'pub-key', 'e' => 'pv', 'u' => '/_sn/canary' ) === json_decode( $sent[1]['body'], true ), 'a pageview of /_sn/canary, a path the site never serves, with the public beacon key' );
ok( '/_sn/canary' === SN_INGEST_CANARY_PATH && 1 === preg_match( '#^/[a-z_/]{1,31}$#', SN_INGEST_CANARY_PATH ) && false === strpos( SN_INGEST_CANARY_PATH, '.' ), 'the path is short, lowercase, and has no dot or slash to trim, so a path rule has nothing to rewrite' );
$since = $last['since'] ?? null;
ok( is_int( $since ) && abs( time() - $since ) <= 2, 'the first send on the new path records when it began' );
ok( 0 === $sent[1]['redirection'], 'never follows a redirect' );
ok( 204 === $last['http'] && 204 === $GLOBALS['opt'][ SN_INGEST_CANARY_OPT ]['http'], 'stores what the collector answered' );

$last = sn_ingest_canary_send( function () { return new WP_Error(); } );
ok( 0 === $last['http'] && 'timed out' === $last['why'], 'a failed request is stored with its reason, never as a 204' );
$GLOBALS['opt'][ SN_INGEST_CANARY_OPT ]['since'] = 1000;
$last = sn_ingest_canary_send( function () { return array( 'code' => 204 ); } );
ok( 1000 === $last['since'] && 1000 === $GLOBALS['opt'][ SN_INGEST_CANARY_OPT ]['since'], 'later sends keep the first time, never move it' );

$GLOBALS['tok'] = ''; $called = false;
$last = sn_ingest_canary_send( function () use ( &$called ) { $called = true; return array( 'code' => 204 ); } );
ok( ! $called && 'no beacon key configured' === $last['why'], 'no beacon key: nothing is sent, and the reason is stored' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
