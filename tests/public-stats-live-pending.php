<?php
/**
 * Tests: /stats never flashes "NOT READ" in its display figures (2026-10-31).
 *
 * The live strip is edge-cached HTML, so its figures wait for live-now.js.
 * They used to print "not read" in display type until the first answer
 * arrived, a big false statement on every load. The server now puts the words
 * in <noscript>: a reader without script still reads "not read" under "The
 * live figures need JavaScript.", a reader with script sees nothing until the
 * answer (the figure keeps its height). A read that fails writes "not read"
 * itself (owner, 2026-10-12: an unread figure says so, never 0), and the
 * strip is aria-busy only while the script waits.
 *
 * Run: php tests/public-stats-live-pending.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function esc_html__( $s, $d = null ) { return $s; }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sn_analytics_live_attrs( $key, $class ) { return array( 'data-sn-live' => $key, 'data-sn-live-class' => $class ); }
require dirname( __DIR__ ) . '/inc/public-stats-live.php';

echo "Group 1: the server paints no visible \"not read\" for a reader with script\n";
$strip = sn_public_stats_live_html();
ok( 2 === preg_match_all( '/data-sn-live="(now|today)"[^>]*data-sn-live-pending>not read<\/span>/', $strip ), 'both figures say not read as real text, marked pending' );
ok( false !== strpos( $strip, '<li class="sn-public-stats__live-empty" data-sn-live-pending>not read</li>' ), 'the Being read now list too' );
ok( false === strpos( $strip, '<noscript' ), 'not in noscript: the Markdown twin\'s converter skips noscript, and an agent reading the twin must read not read (review of #2325)' );
ok( 1 === preg_match( '/<section class="sn-public-stats__live"[^>]*data-sn-live-busy/', $strip ) && false === strpos( $strip, 'aria-busy' ), 'the strip is marked for the script; aria-busy is the script\'s to set (a server-set busy would never clear without script)' );
ok( false !== strpos( $strip, 'The live figures need JavaScript.' ), 'a reader without script is still told why the figures are empty' );
$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/public-stats-front.css' );
ok( 1 === preg_match( '/@media \(scripting:\s*enabled\)\{\.sn-public-stats \[data-sn-live-pending\]\{visibility:hidden\}\}/', $css ), 'hidden only where script runs, and hidden, not removed: the words hold their own line, so the answer shifts nothing' );

$stats_src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/public-stats.php' );
ok( false !== strpos( $stats_src, '<p class="sn-public-stats__method" id="sn-public-stats-method">' ), 'the method paragraph carries the id the theme\'s /stats hero links to ("How it\'s measured")' );
ok( 2 === substr_count( $stats_src, 'sn_public_stats_method_html()' ) - 1, 'and it is printed on the not-measured-yet page too, so the link always lands' );

echo "\nGroup 2: the script settles every figure\n";
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	ok( false, 'node is needed to run assets/live-now.js' );
} else {
	$run = static fn( $arg ) => json_decode( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/live-now-public.js' ) . ' ' . escapeshellarg( $arg ) ), true );
	$ok = $run( json_encode( array( 'now' => 3, 'today' => 120, 'pages' => null, 'fetched' => 1 ) ) );
	ok( 'true' === ( $ok['before']['busy'] ?? null ), 'busy while the first read is out' );
	ok( '3' === ( $ok['after']['now'] ?? null ) && '120' === ( $ok['after']['today'] ?? null ), 'an answer fills both figures' );
	ok( 'not read' === ( $ok['after']['li'] ?? null ), 'a part the answer leaves out says not read' );
	ok( array_key_exists( 'busy', $ok['after'] ?? array() ) && null === $ok['after']['busy'] && 0 === ( $ok['after']['pending'] ?? -1 ), 'and the strip is no longer busy, nothing pending' );
	$bad = $run( 'fail' );
	ok( 'not read' === ( $bad['after']['now'] ?? null ) && 'not read' === ( $bad['after']['today'] ?? null ) && 'not read' === ( $bad['after']['li'] ?? null ), 'a failed read says not read in every part, never blank' );
	ok( 'Not measured right now.' === ( $bad['after']['meta'] ?? null ) && array_key_exists( 'busy', $bad['after'] ?? array() ) && null === $bad['after']['busy'], 'with the line under it, and no longer busy' );
	ok( 0 === ( $bad['after']['pending'] ?? -1 ), 'every part was written, not left as the server painted it' );
	$odd = $run( 'null' );
	ok( 'not read' === ( $odd['after']['now'] ?? null ) && 0 === ( $odd['after']['pending'] ?? -1 ) && array_key_exists( 'busy', $odd['after'] ?? array() ) && null === $odd['after']['busy'], 'an answer the strip cannot use (a null body) settles like a failed read, never stuck busy' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
