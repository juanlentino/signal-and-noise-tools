<?php
/**
 * Feed reach: opens are counted once per (day, note, hashed UA), pruned to 90
 * days, bounded per day, windowed per note; and they sit beside the north
 * star as an input without ever moving the star itself.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['fo_opts'] = array();
function __( $s ) { return $s; }
function add_action() {}
function add_filter() {}
function apply_filters( $h, $v ) { return $v; }
function sn_setting( $k, $d = null ) { return $d; }
function get_option( $k, $d = false ) { return $GLOBALS['fo_opts'][ $k ] ?? $d; }
function get_transient() { return false; }
function set_transient() { return true; }
function get_posts() { return array(); }
require __DIR__ . '/../inc/feed-opens.php';
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Feed opens\n\n";

$now = gmmktime( 12, 0, 0, 9, 27, 2026 );
$s   = snt_feed_opens_record( array(), 10, 'aaaa', $now );
$s   = snt_feed_opens_record( $s, 10, 'aaaa', $now + 60 );
ok( 1 === snt_feed_opens_window( $s, 7, $now )['total'], 'the same reader opening the same note the same day counts once' );
$s = snt_feed_opens_record( $s, 10, 'bbbb', $now );
$s = snt_feed_opens_record( $s, 11, 'aaaa', $now );
$s = snt_feed_opens_record( $s, 10, 'aaaa', $now + DAY_IN_SECONDS );
$w = snt_feed_opens_window( $s, 7, $now + DAY_IN_SECONDS );
ok( 4 === $w['total'] && array( 10 => 3, 11 => 1 ) === $w['notes'], 'another reader, another note and another day each count; per-note totals, busiest first' );
ok( 1 === snt_feed_opens_window( $s, 1, $now + DAY_IN_SECONDS )['total'], 'a 1-day window reads only today' );
$old = snt_feed_opens_record( array(), 9, 'x', $now - 100 * DAY_IN_SECONDS );
ok( ! isset( snt_feed_opens_record( $old, 10, 'y', $now )[ gmdate( 'Y-m-d', $now - 100 * DAY_IN_SECONDS ) ] ), 'days older than 90 are pruned on write' );
$cap = array();
for ( $i = 0; $i < SNT_FEED_OPENS_PER_DAY + 20; $i++ ) {
	$cap = snt_feed_opens_record( $cap, 10, 'ua' . $i, $now );
}
ok( SNT_FEED_OPENS_PER_DAY === snt_feed_opens_window( $cap, 1, $now )['total'], 'a UA-spraying flood is bounded per note per day' );
$all = array();
for ( $i = 0; $i < 40000; $i++ ) {
	$all = snt_feed_opens_record( $all, 1 + ( $i % 80 ), 'ua' . $i, $now );
}
ok( snt_feed_opens_window( $all, 1, $now )['total'] <= 1000, 'a flood sprayed across every note is bounded per day in total, not only per note' );
ok( strlen( serialize( $all ) ) * SNT_FEED_OPENS_DAYS < 4 * 1024 * 1024, 'ninety flooded days stay a few MB, not tens' );

// Report summary: 7d/30d totals and a top list, labelled a floor.
$sum = array();
$sum = snt_feed_opens_record( $sum, 7, 'r1', $now );
$sum = snt_feed_opens_record( $sum, 7, 'r2', $now );
$sum = snt_feed_opens_record( $sum, 8, 'r1', $now - 20 * DAY_IN_SECONDS );
$r   = snt_feed_opens_summary( $sum, $now );
ok( 2 === $r['total_7d'] && 3 === $r['total_30d'], 'the summary carries 7-day and 30-day totals' );
ok( array( 7, 8 ) === array_column( $r['top_30d'], 'post_id' ) && 2 === $r['top_30d'][0]['opens'], 'the top list is per note, most opened first' );
ok( 'floor' === $r['basis'] && false !== stripos( $r['note'], 'at least' ), 'the summary is labelled a floor, "at least"' );

// North star pin: the star is on-site reads only; feed opens are an input beside it.
$t  = time();
$ev = function ( $vid, $e, $path, $x = array() ) use ( $t ) { return array_merge( array( 'vid' => $vid, 'ev' => $e, 'path' => $path, 'ts' => $t - 3600 ), $x ); };
function sn_analytics_fetch_session_events() {
	return array( 'visits' => $GLOBALS['fo_visits'], 'capped' => false, 'configured' => true );
}
$GLOBALS['fo_visits'] = array( array( $ev( 'a', 'pv', '/notes/x/' ), $ev( 'a', 'sc', '/notes/x/', array( 'scroll' => 80 ) ) ) );

$GLOBALS['fo_opts'][ SNT_FEED_OPENS_OPT ] = array();
$base = snt_nsm_reading( true );
$flood = array();
for ( $i = 0; $i < 400; $i++ ) {
	$flood = snt_feed_opens_record( $flood, 100 + $i, 'ua', $t );
}
$GLOBALS['fo_opts'][ SNT_FEED_OPENS_OPT ] = $flood;
$with = snt_nsm_reading( true );
ok( 1 === $base['value'] && $base['value'] === $with['value'] && $base['series'] === $with['series'] && $base['trend'] === $with['trend'], 'the north star value, series and trend are unchanged by 400 feed opens' );
ok( 400 === $with['layers']['inputs']['feed_opens']['value'] && '7d' === $with['layers']['inputs']['feed_opens']['window'], 'feed opens show as their own input, 7-day window' );
ok( 0 === $base['layers']['inputs']['feed_opens']['value'] && array_key_exists( 'rss_readers', $with['layers']['inputs'] ) && array_key_exists( 'feed_clicks', $with['layers']['inputs'] ), 'beside rss_readers and feed_clicks, zero when none' );

// The REST route: the callback returns a response and core serves the request;
// rest_pre_serve_request swaps the JSON body for the GIF. Nothing exits inside
// the callback, so rest_post_dispatch and the rest of serve_request() still run.
echo "\nThe pixel route\n";
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $v ) { return $v; }
function get_post_type( $p ) { return 'post'; }
function get_post_status( $p ) { return 'publish'; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['fo_opts'][ $k ] = $v; return true; }
function wp_get_nocache_headers() { return array( 'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT', 'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private', 'Last-Modified' => false ); }
class WP_REST_Response {
	public $data; public $status; public $headers;
	public function __construct( $data = null, $status = 200, $headers = array() ) { $this->data = $data; $this->status = $status; $this->headers = $headers; }
	public function get_status() { return $this->status; }
	public function get_headers() { return $this->headers; }
}
class FO_Request {
	public $route; public $method; public $p;
	public function __construct( $route, $method = 'GET', $p = 7 ) { $this->route = $route; $this->method = $method; $this->p = $p; }
	public function get_param( $k ) { return 'p' === $k ? $this->p : null; }
	public function get_route() { return $this->route; }
	public function get_method() { return $this->method; }
}
class FO_Server {
	public $sent = array();
	public function send_header( $k, $v ) { $this->sent[ $k ] = $v; }
}
$gif   = base64_decode( SNT_FEED_OPENS_GIF );
$route = '/signal-noise/v1/feed-open';
$_SERVER['HTTP_USER_AGENT'] = 'NetNewsWire/6.1';
$GLOBALS['fo_opts'][ SNT_FEED_OPENS_OPT ] = array();
$req = new FO_Request( $route );
ob_start();
$res  = snt_feed_open_serve( $req );
$body = ob_get_clean();
ok( $res instanceof WP_REST_Response && 200 === $res->get_status() && '' === $body, 'the callback returns a 200 response: it prints nothing and does not end the request' );
ok( 1 === snt_feed_opens_window( $GLOBALS['fo_opts'][ SNT_FEED_OPENS_OPT ], 1, time() )['total'], 'and the open is still recorded' );
$h = $res instanceof WP_REST_Response ? $res->get_headers() : array();
ok( 'no-store, private, max-age=0' === ( $h['Cache-Control'] ?? '' ) && 'Wed, 11 Jan 1984 05:00:00 GMT' === ( $h['Expires'] ?? '' ) && ! array_key_exists( 'Last-Modified', $h ), 'the response carries the same no-store Cache-Control and 1984 Expires, and no empty Last-Modified line' );

$srv = new FO_Server();
ob_start();
$served = function_exists( 'snt_feed_open_pre_serve' ) ? snt_feed_open_pre_serve( false, $res, $req, $srv ) : null;
$body   = ob_get_clean();
ok( true === $served && $gif === $body && 42 === strlen( $body ), 'a GET is served the same 42 GIF bytes, and core is told the body went out' );
ok( 'image/gif' === ( $srv->sent['Content-Type'] ?? '' ) && 'no-store, private, max-age=0' === ( $srv->sent['Cache-Control'] ?? '' ), 'as image/gif, no-store' );
ob_start();
$served = function_exists( 'snt_feed_open_pre_serve' ) ? snt_feed_open_pre_serve( false, $res, new FO_Request( '/signal-noise/v1/Feed-Open' ), new FO_Server() ) : null;
ok( true === $served && $gif === ob_get_clean(), 'core matches a route in any case, and so does the GIF' );
ob_start();
$served = function_exists( 'snt_feed_open_pre_serve' ) ? snt_feed_open_pre_serve( false, $res, new FO_Request( $route, 'HEAD' ), new FO_Server() ) : null;
ok( true === $served && '' === ob_get_clean(), 'a HEAD gets the headers and no body' );
$srv = new FO_Server();
ob_start();
$served = function_exists( 'snt_feed_open_pre_serve' ) ? snt_feed_open_pre_serve( false, new WP_REST_Response( array( 'code' => 'rest_missing_callback_param' ), 400 ), $req, $srv ) : null;
ok( false === $served && '' === ob_get_clean() && array() === $srv->sent, 'a missing p stays core\'s JSON 400' );
ob_start();
$served = function_exists( 'snt_feed_open_pre_serve' ) ? snt_feed_open_pre_serve( false, new WP_REST_Response( array(), 200 ), new FO_Request( '/wp/v2/posts' ), $srv ) : null;
ok( false === $served && '' === ob_get_clean() && array() === $srv->sent, 'every other route is left to core' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
