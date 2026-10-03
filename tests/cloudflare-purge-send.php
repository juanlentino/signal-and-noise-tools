<?php
/**
 * Standalone test: Cloudflare cache calls read the answer (20.9.0).
 * Run: php tests/cloudflare-purge-send.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

function add_action( $h, $c = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][] = array( $h, $c, $a ); }
function add_filter( $h, $c = null, $p = 10, $a = 1 ) {}
function is_wp_error( $t ) { return false; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function wp_schedule_single_event( $at, $hook, $args = array() ) { $GLOBALS['__sched'][] = array( $at - time(), $hook, $args ); return true; }
// Replies are consumed in order; the last one repeats.
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( basename( $url ), $args );
	$r = count( $GLOBALS['__replies'] ) > 1 ? array_shift( $GLOBALS['__replies'] ) : $GLOBALS['__replies'][0];
	return array( 'response' => array( 'code' => $r[0] ), 'body' => json_encode( array( 'success' => $r[1] ) ) );
}
function reset_all( array $replies ) {
	$GLOBALS['__http'] = array(); $GLOBALS['__sched'] = array(); $GLOBALS['__replies'] = $replies;
	$GLOBALS['__opts'] = array( 'sn_cf_api_token' => 'T', 'sn_cf_zone_id' => 'Z' ) + array_intersect_key( $GLOBALS['__opts'] ?? array(), array( 'sn_cf_purge_failure' => 1 ) );
}
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-purge.php';

echo "Group: pure rules\n";
ok( sn_cf_retryable( 0 ) && sn_cf_retryable( 429 ) && sn_cf_retryable( 502 ) && ! sn_cf_retryable( 401 ) && ! sn_cf_retryable( 400 ) && ! sn_cf_retryable( 200 ), 'a transport error, a throttle and a 5xx are retried; a 4xx and an unconfirmed 200 are not' );
ok( 'invalidate_cache' === sn_cf_edge_endpoint( 'update' ) && 'invalidate_cache' === sn_cf_edge_endpoint( 'rollover' ) && 'purge_cache' === sn_cf_edge_endpoint( 'publish' ) && 'purge_cache' === sn_cf_edge_endpoint( 'manual' ) && 'purge_cache' === sn_cf_edge_endpoint( '' ), 'a code update marks stale; content changes, the manual command and anything unnamed delete' );

echo "\nGroup: the answer is read\n";
reset_all( array( array( 200, true ) ) );
ok( true === sn_cf_api_send( 'purge_cache', array( 'files' => array( 'https://x/' ) ) ) && 1 === count( $GLOBALS['__http'] ) && true === $GLOBALS['__http'][0][1]['blocking'], 'a confirmed call is one blocking request' );
ok( array() === $GLOBALS['__sched'] && null === sn_cf_purge_failure(), 'and leaves nothing scheduled, nothing recorded' );
ok( true === sn_cf_purge_urls( array( 'https://x/' ) ) && true === $GLOBALS['__http'][1][1]['blocking'], 'the post-save URL purge goes through it: blocking, no fire-and-forget left' );
ok( false === strpos( (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' ), "'blocking' => false" ), 'no non-blocking Cloudflare call remains in the purge module' );

echo "\nGroup: a transient failure retries, then is recorded\n";
reset_all( array( array( 429, false ) ) );
ok( false === sn_cf_api_send( 'purge_cache', array( 'purge_everything' => true ) ) && array( array( 60, 'sn_cf_purge_retry', array( 'purge_cache', array( 'purge_everything' => true ), 1 ) ) ) === $GLOBALS['__sched'], 'a throttle queues try 2 a minute out, carrying the same call' );
ok( null === sn_cf_purge_failure(), 'a queued retry is not a failure yet' );
sn_cf_api_send( 'purge_cache', array( 'purge_everything' => true ), 1 );
sn_cf_api_send( 'purge_cache', array( 'purge_everything' => true ), 2 );
ok( array( 60, 300, 900 ) === array_column( $GLOBALS['__sched'], 0 ), 'tries 3 and 4 wait five and fifteen minutes' );
sn_cf_api_send( 'purge_cache', array( 'purge_everything' => true ), 3 );
$f = sn_cf_purge_failure();
ok( 3 === count( $GLOBALS['__sched'] ) && is_array( $f ) && 429 === $f['http'] && 4 === $f['attempts'] && 'everything' === $f['what'], 'the fourth failure schedules nothing more and records what failed' );
ok( in_array( array( 'sn_cf_purge_retry', 'sn_cf_api_send', 3 ), $GLOBALS['__actions'], true ), 'the retry hook runs the same sender with its three arguments' );

echo "\nGroup: a failure time cannot fix is recorded at once\n";
reset_all( array( array( 401, false ) ) );
unset( $GLOBALS['__opts']['sn_cf_purge_failure'] );
sn_cf_api_send( 'purge_cache', array( 'files' => array( 'a', 'b' ) ) );
$f = sn_cf_purge_failure();
ok( array() === $GLOBALS['__sched'] && 401 === $f['http'] && 1 === $f['attempts'] && '2 urls' === $f['what'], 'a rejected token is not retried: one try, recorded' );
reset_all( array( array( 200, true ) ) );
sn_cf_api_send( 'purge_cache', array( 'purge_everything' => true ) );
ok( null === sn_cf_purge_failure(), 'the next confirmed call clears the record' );

echo "\nGroup: invalidate falls back to the purge it replaced\n";
reset_all( array( array( 400, false ), array( 200, true ) ) );
ok( true === sn_cf_api_send( 'invalidate_cache', array( 'purge_everything' => true ) ) && array( 'invalidate_cache', 'purge_cache' ) === array_column( $GLOBALS['__http'], 0 ) && null === sn_cf_purge_failure(), 'an invalidate Cloudflare refuses becomes a purge in the same request' );
reset_all( array( array( 503, false ) ) );
sn_cf_api_send( 'invalidate_cache', array( 'purge_everything' => true ) );
ok( array( 'invalidate_cache' ) === array_column( $GLOBALS['__http'], 0 ) && 'invalidate_cache' === $GLOBALS['__sched'][0][2][0], 'a Cloudflare 5xx on invalidate retries the invalidate, it does not purge' );

echo "\nGroup: not configured\n";
$GLOBALS['__opts'] = array(); $GLOBALS['__http'] = array();
ok( false === sn_cf_api_send( 'purge_cache', array( 'purge_everything' => true ) ) && array() === $GLOBALS['__http'], 'no token, no call, no record' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
