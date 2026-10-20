<?php
/**
 * Standalone test: /stats hands live-now.js its config in a JSON data block.
 *
 * wp_localize_script printed `var snLiveNow = {...}` as an EXECUTING inline
 * script whose values (the REST URL, the strings) are no fixed bytes, so the
 * strict CSP (no 'unsafe-inline') could pin no hash for it and would block it.
 * A <script type="application/json"> runs nothing, so CSP ignores it; the
 * deferred live-now.js reads it by id (2026-10-20).
 *
 * Run: php tests/public-stats-live-config.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $s, $d = null ) { return $s; }
function rest_url( $p = '' ) { return 'https://site.test/wp-json/' . $p; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_enqueue_script( $h ) { $GLOBALS['__enq'][] = $h; }
function wp_localize_script( $h, $n, $d ) { $GLOBALS['__loc'][] = $n; return true; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
require "$root/inc/public-stats-live.php";

$GLOBALS['__enq'] = array(); $GLOBALS['__loc'] = array();
sn_public_stats_live_enqueue();
ok( array( 'sn-live-now' ) === $GLOBALS['__enq'], 'the updater is still enqueued' );
ok( array() === $GLOBALS['__loc'], 'no wp_localize_script: it prints an executing inline script no hash can pin' );

$tag = function_exists( 'sn_public_stats_live_config_tag' ) ? sn_public_stats_live_config_tag() : '';
ok( 1 === preg_match( '#^<script type="application/json" id="sn-live-now-config">(.*)</script>$#s', $tag, $m ), 'the config is a JSON data block, which CSP does not execute or check' );
$body = $m[1] ?? '';
$cfg  = json_decode( $body, true );
ok( is_array( $cfg ) && 'https://site.test/wp-json/signal-noise/v1/live' === ( $cfg['url'] ?? '' ) && 60 === ( $cfg['interval'] ?? 0 ) && 11 === count( $cfg ), 'it carries the same eleven fields the localized object did' );
ok( false === strpos( $body, '<' ), 'no < inside it, so no string can close the block' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/public-stats.php" ), 'sn_public_stats_live_config_tag()' ), 'the [sn_public_stats] shortcode prints it' );

$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	ok( false, 'node is needed to run assets/live-now.js' );
} else {
	$ans = array( 'pages' => array( array( 'label' => 'Alpha', 'url' => 'u1', 'readers' => 4, 'scroll' => array( 'reached_25' => 4, 'reached_50' => 3, 'reached_75' => 3, 'reached_100' => 1, 'readers_with_scroll' => 4 ) ) ) );
	$run = static fn( $mode, $carrier ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/live-now-scroll.js' ) . ' ' . escapeshellarg( json_encode( $ans ) ) . ' ' . $mode . ' ' . escapeshellarg( $carrier ) ), true );
	$p = $run( 'carrier', $body );
	ok( isset( $p[0] ) && null === $p[0]['depth'] && false !== strpos( $p[0]['text'], 'Alpha' ), 'live-now.js reads the data block and runs as the public strip (no admin-only scroll line)' );
	$a = $run( 'admin', '' );
	ok( isset( $a[0] ) && null !== $a[0]['depth'], 'without the block it still runs as the admin view (guard can fail)' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
