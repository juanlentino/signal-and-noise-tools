<?php
/**
 * Standalone test: a stale post-save probe escalates to the whole-site cache
 * tag, not the zone, and its "fresh" read carries a new cache-buster each
 * time (2026-10-13).
 * Run: php tests/cloudflare-purge-probe-escalate.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

function add_action( $h, $c = null, $p = 10, $a = 1 ) {}
function add_filter( $h, $c = null, $p = 10, $a = 1 ) {}
function apply_filters( $h, $v ) { return $v; }
function do_action( $h, ...$a ) { $GLOBALS['__did'][] = array( $h, $a ); }
function __( $s, $d = '' ) { return $s; }
function is_wp_error( $t ) { return false; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function wp_unschedule_hook( $h ) { return 0; }
function wp_next_scheduled( $h, $a = array() ) { return false; }
function wp_schedule_single_event( $at, $h, $a = array() ) { $GLOBALS['__sched'][] = array( $h, $a ); return true; }
function snt_purge_ledger_add( array $row ) { $GLOBALS['__ledger'][] = $row; }
function snt_purge_trigger() { return 'cron:sn_cf_verify_post_purge'; }
function get_permalink( $id ) { return 'https://x.test/notes/a/'; }
function add_query_arg( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
// The bare URL answers the cached (old) page; any cache-busted URL the origin's.
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__gets'][] = $url;
	$fresh = false !== strpos( $url, 'sn-cache-probe=' );
	return array( 'response' => array( 'code' => 200 ), 'body' => $fresh ? $GLOBALS['__origin'] : $GLOBALS['__edge'] );
}
// Cloudflare replies, consumed in order; the last repeats.
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( basename( $url ), json_decode( $args['body'], true ) );
	$r = count( $GLOBALS['__replies'] ) > 1 ? array_shift( $GLOBALS['__replies'] ) : $GLOBALS['__replies'][0];
	return array( 'response' => array( 'code' => $r[0] ), 'body' => json_encode( array( 'success' => $r[1] ) ) );
}
function run_probe( array $replies, $edge, $origin ) {
	$GLOBALS['__http'] = array(); $GLOBALS['__ledger'] = array(); $GLOBALS['__gets'] = array(); $GLOBALS['__replies'] = $replies;
	$GLOBALS['__opts'] = array( 'sn_cf_api_token' => 'T', 'sn_cf_zone_id' => 'Z' );
	$GLOBALS['__edge'] = $edge; $GLOBALS['__origin'] = $origin;
	snt_cf_verify_post_purge( 7 );
	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	return $log[0] ?? array();
}
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-purge.php';
require_once __DIR__ . '/../inc/cloudflare-purge-verify.php';
require_once __DIR__ . '/../inc/cloudflare-purge-probe.php';
define( 'SN_EDGE_CACHE_TAG', 'sn-render' );

$old = '<main><p>old words</p></main>';
$new = '<main><p>new words</p></main>';

echo "Group: a stale page escalates to the whole-site tag\n";
$row = run_probe( array( array( 200, true ) ), $old, $new );
ok( 1 === count( $GLOBALS['__http'] ) && 'purge_cache' === $GLOBALS['__http'][0][0] && array( 'tags' => array( 'sn-render' ) ) === $GLOBALS['__http'][0][1], 'one purge_cache call carrying the sn-render tag, no purge_everything: images, styles and scripts stay cached' );
ok( 'stale' === $row['result'] && true === $row['escalated'] && 'tag' === $row['escalation'], 'the probe row says it escalated, and which purge ran: tag' );
ok( 'probe' === ( $GLOBALS['__ledger'][0]['trigger'] ?? '' ) && 'tag' === $GLOBALS['__ledger'][0]['scope'], 'the ledger row says probe, tag' );

echo "\nGroup: the zone goes only when Cloudflare refuses the tag call\n";
$row = run_probe( array( array( 400, false ), array( 200, true ) ), $old, $new );
ok( array( 'tags' => array( 'sn-render' ) ) === $GLOBALS['__http'][0][1] && array( 'purge_everything' => true ) === $GLOBALS['__http'][1][1] && 'purge_cache' === $GLOBALS['__http'][1][0], 'a refused tag call falls back to one hard zone purge' );
ok( 'zone' === $row['escalation'] && true === $row['escalated'] && 'probe' === $GLOBALS['__ledger'][0]['trigger'] && 'zone' === $GLOBALS['__ledger'][0]['scope'], 'the probe row and the ledger row both say zone' );
$row = run_probe( array( array( 503, false ) ), $old, $new );
ok( 1 === count( $GLOBALS['__http'] ) && 'tag' === $row['escalation'], 'a tag call waiting on a retry is handled: no zone purge on top of it' );
$row = run_probe( array( array( 400, false ) ), $old, $new );
ok( 'none' === $row['escalation'] && false === $row['escalated'], 'refused twice: the row says none, never a purge that did not happen' );

echo "\nGroup: the fresh read reaches the origin\n";
$row = run_probe( array( array( 200, true ) ), $new, $new );
$a   = $GLOBALS['__gets'][1] ?? '';
run_probe( array( array( 200, true ) ), $new, $new );
$b   = $GLOBALS['__gets'][1] ?? '';
ok( 'fresh' === $row['result'] && array() === $GLOBALS['__http'], 'a fresh page purges nothing' );
ok( 1 === preg_match( '/sn-cache-probe=[0-9a-f]{12}$/', $a ) && $a !== $b && false === strpos( $a, 'sn-cache-probe=1' ), 'each probe sends a new random cache-buster, never the fixed =1 the edge had cached' );
ok( false === snt_cf_probe_is_stale( '<link rel="canonical" href="https://x/a/?sn-cache-probe=9f3a2b7c1d0e"><p>Body</p>', '<link rel="canonical" href="https://x/a/"><p>Body</p>' ), 'a random cache-buster echoed into the markup is not staleness' );

echo "\nGroup: the words\n";
ok( '→ whole-site tag purge' === snt_cf_probe_escalation_label( array( 'result' => 'stale', 'escalated' => true, 'escalation' => 'tag' ) ) && '→ zone purge' === snt_cf_probe_escalation_label( array( 'result' => 'stale', 'escalated' => true ) ) && '' === snt_cf_probe_escalation_label( array( 'result' => 'stale', 'escalated' => false, 'escalation' => 'none' ) ), 'tag, zone (and a row from before the field: zone), and nothing for none' );
ok( 'The whole-site cache tag was purged.' === snt_cf_probe_escalation_sentence( array( 'result' => 'stale', 'escalated' => true, 'escalation' => 'tag' ) ), 'Attention says the tag in words' );
ok( false === strpos( snt_cf_probe_intro( 120 ), '—' ), 'no em dash in the intro' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
