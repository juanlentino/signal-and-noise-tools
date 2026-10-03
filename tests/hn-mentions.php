<?php
/**
 * Standalone test: Hacker News mentions and their alerts (21.0.0).
 * Run: php tests/hn-mentions.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['opt'] = array(); $GLOBALS['http'] = array(); $GLOBALS['calls'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url_raw( $u ) { return $u; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function is_wp_error( $t ) { return false; }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['calls'][] = array( $url, $args );
	foreach ( $GLOBALS['http'] as $needle => $body ) {
		if ( false !== strpos( $url, $needle ) ) { return null === $body ? array( 'code' => 500, 'body' => '' ) : array( 'code' => 200, 'body' => json_encode( $body ) ); }
	}
	return array( 'code' => 404, 'body' => '' );
}
function wp_remote_retrieve_response_code( $r ) { return (int) $r['code']; }
function wp_remote_retrieve_body( $r ) { return (string) $r['body']; }
function apply_filters( $h, $v ) { return $v; }
function sn_analytics_is_excluded_path( $p ) { return false; }
require __DIR__ . '/../inc/hn-mentions.php';
require __DIR__ . '/../inc/alerts.php';

$now  = 1791060000;
$hit  = static fn( $id, $url, $age_h, $pts = 1, $com = 0 ) => array( 'objectID' => (string) $id, 'url' => $url, 'title' => "Story <b>$id</b>", 'created_at_i' => $now - $age_h * 3600, 'points' => $pts, 'num_comments' => $com );

echo "The merge\n";
$m = sn_hn_merge( array(), array( $hit( 1, 'https://juanlentino.com/notes/a/', 5 ), $hit( 2, 'https://notjuanlentino.com/x', 5 ), $hit( 3, 'https://example.com/?u=juanlentino.com', 5 ), $hit( 4, 'https://www.juanlentino.com/notes/b/', 900 ) ), 'juanlentino.com', $now );
ok( array( 1, 4 ) === array_keys( $m ), 'only stories whose URL host is this site, with or without www; a look-alike host and a mention in a query are not ours' );
ok( $now === $m[1]['first_seen'] && '/notes/a/' === $m[1]['path'] && 'Story 1' === $m[1]['title'] && 0 === $m[1]['rank'], 'a new row is stamped first seen now, with its path and a clean title' );
$m2 = sn_hn_merge( $m, array( $hit( 1, 'https://juanlentino.com/notes/a/', 5, 0, 0 ) ), 'juanlentino.com', $now + 3600 );
ok( $now === $m2[1]['first_seen'] && 1 === $m2[1]['points'], 'seen again: first_seen holds, and a lagging search never lowers the points' );
ok( '9 points, 1 comment, front page #4' === sn_hn_line( array( 'points' => 9, 'comments' => 1, 'rank' => 4 ) ) && '1 point, 0 comments' === sn_hn_line( array( 'points' => 1, 'comments' => 0 ) ), 'the line' );

echo "\nThe hourly read\n";
$GLOBALS['http'] = array( 'hn.algolia.com' => array( 'hits' => array( $hit( 1, 'https://juanlentino.com/notes/a/', 5 ), $hit( 4, 'https://juanlentino.com/notes/b/', 900 ) ) ), 'topstories' => array( 77, 88, 1, 99 ), 'item/1.json' => array( 'score' => 14, 'descendants' => 3 ) );
$items = sn_hn_refresh( $now );
ok( 14 === $items[1]['points'] && 3 === $items[1]['comments'] && 3 === $items[1]['rank'] && 3 === $items[1]['best_rank'], 'a young story is read live: points, comments, and its front-page position' );
ok( 1 === $items[4]['points'] && 0 === $items[4]['rank'] && 3 === count( array_filter( $GLOBALS['calls'], static fn( $c ) => true ) ), 'an old story is not re-read: one search, one top list, one item' );
ok( 'signal-and-noise-tools' === $GLOBALS['calls'][0][1]['headers']['User-Agent'] && false !== strpos( $GLOBALS['calls'][0][0], 'query=juanlentino.com' ), 'the search names itself and sends only the host' );
$GLOBALS['http']['hn.algolia.com'] = null; $GLOBALS['http']['topstories'] = array( 77 );
$items = sn_hn_refresh( $now + 3600 );
ok( 2 === count( $items ) && 'search failed' === $GLOBALS['opt'][ SN_HN_OPT ]['error'] && 0 === $items[1]['rank'] && 3 === $items[1]['best_rank'], 'a failed search keeps the stored rows and says so; off the front page, the best position is remembered' );

$GLOBALS['http'] = array( 'hn.algolia.com' => array( 'hits' => array_map( static fn( $i ) => $hit( 100 + $i, "https://juanlentino.com/notes/n$i/", $i ), range( 1, 8 ) ) ), 'topstories' => array( 101 ), 'item/' => array( 'score' => 2, 'descendants' => 0 ) );
$GLOBALS['opt'] = array( SN_HN_OPT => array( 'items' => array( 4 => array( 'id' => 4, 'created' => $now - 900 * 3600, 'rank' => 9, 'best_rank' => 9, 'points' => 1, 'comments' => 0, 'path' => '/notes/b/' ) ) ) ); $GLOBALS['calls'] = array();
$items = sn_hn_refresh( $now );
ok( 5 === SN_HN_LIVE_MAX && 5 === count( array_filter( $GLOBALS['calls'], static fn( $c ) => false !== strpos( $c[0], '/item/' ) ) ), 'eight young stories, five live reads: a slow API cannot hold the alert run' );
ok( 0 === $items[4]['rank'] && 9 === $items[4]['best_rank'], 'a story that aged out is no longer "on the front page"; its best position is kept' );
$GLOBALS['http']['topstories'] = array( 1, 2, 108 ); $GLOBALS['calls'] = array();
$items = sn_hn_refresh( $now );
ok( 3 === $items[108]['rank'], 'the cap is on the per-story reads only: the eighth young story is still ranked from the top list' );
$GLOBALS['http']['topstories'] = null; $GLOBALS['calls'] = array();
$items = sn_hn_refresh( $now );
ok( 0 === $items[108]['rank'] && 3 === $items[108]['best_rank'], 'a failed top-list read leaves no current rank: a saved position is never reported as now' );
ok( 0 === count( array_filter( $GLOBALS['calls'], static fn( $c ) => false !== strpos( $c[0], '/item/' ) ) ), 'the official API failing on the top list is not asked again five times' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/hn-mentions.php' ), "preg_replace( '/^www\\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) )" ), 'the search uses the bare host, so a www site still finds a story posted without it' );

echo "\nThe alerts\n";
$T    = snt_alerts_thresholds();
$eval = static fn( $in ) => snt_alerts_evaluate( $in + array( 'today' => '2026-10-03', 'excluded' => null, 'real' => null, 'hn_since' => $now - 86400 ), $T );
$new  = array( 'id' => 1, 'path' => '/notes/a/', 'title' => 'Story 1', 'created' => $now - 3600, 'first_seen' => $now, 'points' => 14, 'comments' => 3, 'rank' => 0 );
$a    = $eval( array( 'hn' => array( 1 => $new ) ) );
ok( array( 'hn_new|/notes/a/|hn1' ) === array_column( $a, 'key' ), 'a young story just found is news, keyed on its id' );
ok( array() === $eval( array( 'hn' => array( 1 => $new ), 'sent' => array( 'hn_new|/notes/a/|hn1' => 1 ) ) ), 'once' );
ok( array() === $eval( array( 'hn' => array( 4 => array( 'created' => $now - 900 * 3600 ) + $new ) ) ), 'the first run finds old stories too: history, not news' );
$a = $eval( array( 'hn' => array( 1 => array( 'rank' => 7, 'first_seen' => $now - 5 * 86400 ) + $new ) ) );
ok( array( 'hn_front|/notes/a/|hn1' ) === array_column( $a, 'key' ) && 7 === $a[0]['value'], 'on the front page: its own alert, once per story' );
$msg = snt_alerts_compose( $eval( array( 'hn' => array( 1 => array( 'rank' => 7 ) + $new ) ) ), array(), 'S', 'u' );
ok( '[S] Alert: Hacker News' === $msg[0] && false !== strpos( $msg[1], 'HACKER NEWS: "Story 1" (/notes/a/) was posted to Hacker News: 14 points, 3 comments, front page #7. https://news.ycombinator.com/item?id=1' ) && false !== strpos( $msg[1], 'is on the Hacker News front page at #7' ) && false === strpos( $msg[1], 'Top sources' ), 'the mail: what was posted, the numbers, the link; neither a spike nor a break' );
$sp = $eval( array( 'views' => array( '/notes/a' => 40 ), 'history' => array(), 'hn' => array( 1 => array( 'first_seen' => $now - 5 * 86400 ) + $new ) ) );
$sm = snt_alerts_compose( $sp, array(), 'S', 'u' );
ok( 1 === count( $sp ) && false !== strpos( $sm[1], 'SPIKE: /notes/a has 40 human views today' ) && false !== strpos( $sm[1], ' On Hacker News: 14 points, 3 comments.' ), 'a spike on a path Hacker News holds says so, trailing slash or not' );
$two = $eval( array( 'views' => array( '/notes/a/' => 40 ), 'history' => array(), 'hn' => array( 9 => array( 'id' => 9, 'points' => 80, 'first_seen' => $now - 5 * 86400 ) + $new, 1 => array( 'first_seen' => $now - 5 * 86400, 'created' => $now - 30 * 86400 ) + $new ) ) );
ok( 80 === $two[0]['hn']['points'], 'two submissions of one page: the spike names the newest (rows come newest first)' );
ok( false === strpos( snt_alerts_compose( $eval( array( 'views' => array( '/notes/z/' => 40 ), 'history' => array() ) ), array(), 'S', 'u' )[1], 'On Hacker News' ), 'and a spike elsewhere does not' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
