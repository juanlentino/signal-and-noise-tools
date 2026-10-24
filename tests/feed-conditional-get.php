<?php
/**
 * Tests: a feed's conditional GET is core's, and every answer is counted.
 *
 * Wave 2, item 11. Core's WP::send_headers() sends a feed's Last-Modified and
 * ETag and answers its 304 (class-wp.php), and that exit comes before
 * template_redirect, so the RSS tracker counts on the wp_headers filter core
 * applies just before it. The plugin's own feed validator (a second ETag, and
 * a 304 on a mismatched ETag with a fresh If-Modified-Since, which RFC 9110
 * 13.2.2 forbids) is gone; the last render change reaches core through the
 * get_lastpostmodified filter.
 *
 * Runs core's REAL send_headers() (class-wp.php beside SNT_WP_HTML_API, in a
 * namespace so header() is recorded) and get_lastpostmodified() (post.php).
 * A 304 exits, so each request is a child process that reports from shutdown.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
date_default_timezone_set( 'UTC' );

$fc_inc = getenv( 'SNT_WP_HTML_API' ) ? dirname( (string) getenv( 'SNT_WP_HTML_API' ) ) : '';

if ( 'child' === ( $argv[1] ?? '' ) ) {
	$req = json_decode( (string) $argv[2], true );
	$GLOBALS['__h'] = array();
	$GLOBALS['__hdr'] = array();
	$GLOBALS['__tracked'] = 0;
	$GLOBALS['__tr'] = false;
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__h'][ $tag ][ $prio ][] = array( $cb, $args ); return true; }
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
	function remove_action() {}
	function apply_filters( $tag, $value, ...$args ) {
		$hooks = $GLOBALS['__h'][ $tag ] ?? array();
		ksort( $hooks );
		foreach ( $hooks as $rows ) {
			foreach ( $rows as $row ) { $value = call_user_func_array( $row[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $row[1] ) ) ); }
		}
		return $value;
	}
	function do_action( $tag ) { apply_filters( $tag, null ); }
	function do_action_ref_array( $tag, $args ) { apply_filters( $tag, ...$args ); }
	$GLOBALS['__opts'] = '' === $req['since'] ? array() : array( 'sn_rss_tracker_304_since' => $req['since'] );
	function get_option( $k, $d = false ) {
		$o = array( 'html_type' => 'text/html', 'blog_charset' => 'UTF-8', 'sn_render_changed_gmt' => $GLOBALS['req']['render_changed'] ) + $GLOBALS['__opts'];
		return $o[ $k ] ?? $d;
	}
	function add_option( $k, $v = '', $dep = '', $autoload = null ) {
		if ( array_key_exists( $k, $GLOBALS['__opts'] ) ) { return false; }
		$GLOBALS['__opts'][ $k ] = $v;
		return true;
	}
	function home_url( $p = '' ) { return 'https://example.com' . $p; }
	function wp_unslash( $v ) { return $v; }
	function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
	function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
	function is_feed( $f = '' ) { return true; }
	function is_singular( $t = '' ) { return false; }
	function is_user_logged_in() { return false; }
	function get_queried_object() { return null; }
	function feed_content_type( $t = '' ) { return 'application/rss+xml'; }
	function get_default_feed() { return 'rss2'; }
	function mysql2date( $format, $date, $translate = true ) { return $date ? gmdate( $format, strtotime( $date . ' UTC' ) ) : false; }
	function _get_last_post_time( $tz, $field, $type = 'any' ) { return $GLOBALS['req']['db'][ $field ]; }
	function get_lastpostdate( $tz = 'server', $type = 'any' ) { return $GLOBALS['req']['db']['date']; }
	function get_feed_build_date( $format ) { return (string) strtotime( $GLOBALS['req']['db']['modified'] . ' UTC' ); }
	$GLOBALS['req'] = $req;
	$GLOBALS['wpdb'] = new class() {
		public $prefix = 'wp_';
		public $last_error = '';
		public function insert( $table, $row ) { $GLOBALS['__tracked']++; return 1; }
	};
	$post = (string) file_get_contents( "$fc_inc/post.php" );
	preg_match( '/^function get_lastpostmodified\(.*?^\}/ms', $post, $m );
	eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- core's own function, lifted.
	eval( 'namespace snwp; function header( $h, $r = true, $c = 0 ) { $GLOBALS["__hdr"][] = $h; } function status_header( $c ) { \http_response_code( $c ); } function headers_sent() { return false; } function header_remove( $n = null ) {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	$wp_src = str_replace( '#[AllowDynamicProperties]', '#[\AllowDynamicProperties]', (string) file_get_contents( "$fc_inc/class-wp.php" ) );
	eval( 'namespace snwp; ' . preg_replace( '/^<\?php/', '', $wp_src, 1 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- core's WP class, namespaced.

	require __DIR__ . '/../inc/rss-feed-tracker.php';
	require __DIR__ . '/../inc/seo.php';
	add_action( 'template_redirect', static function () { $GLOBALS['__tr'] = true; }, 1000 );

	$_SERVER['REQUEST_URI']     = '/feed/';
	$_SERVER['HTTP_USER_AGENT'] = 'Feedly/1.0 (+http://www.feedly.com/fetcher.html; 3 subscribers)';
	foreach ( array( 'ims' => 'HTTP_IF_MODIFIED_SINCE', 'inm' => 'HTTP_IF_NONE_MATCH' ) as $k => $key ) {
		if ( '' !== $req[ $k ] ) { $_SERVER[ $key ] = $req[ $k ]; }
	}
	http_response_code( 200 );
	register_shutdown_function( static function () {
		$e     = error_get_last();
		$fatal = $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ? $e['message'] : '';
		echo 'FCJSON:' . json_encode( array( 'status' => http_response_code(), 'tracked' => $GLOBALS['__tracked'], 'tr' => $GLOBALS['__tr'], 'hdr' => $GLOBALS['__hdr'], 'fatal' => $fatal, 'since' => $GLOBALS['__opts']['sn_rss_tracker_304_since'] ?? null ) ) . "\n";
	} );
	$wp             = new snwp\WP();
	$wp->query_vars = array( 'feed' => 'feed' );
	$wp->send_headers(); // A 304 exits in here, as it does in WordPress.
	do_action( 'template_redirect' );
	exit;
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( '' === $fc_inc || ! is_readable( "$fc_inc/class-wp.php" ) || ! is_readable( "$fc_inc/post.php" ) ) {
	if ( getenv( 'CI' ) ) {
		ok( false, 'core class-wp.php and post.php beside SNT_WP_HTML_API (tools/fetch-wp-test-core.sh)' );
	} else {
		echo "SKIP - no class-wp.php / post.php beside SNT_WP_HTML_API\n";
		ok( true, 'skipped locally without core' );
	}
	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}

/** One feed request through core, in a child process. */
function fc_request( $label, array $req ) {
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' child ' . escapeshellarg( (string) json_encode( $req ) ) . ' 2>&1' );
	if ( ! preg_match( '/^FCJSON:(.*)$/m', $out, $m ) ) {
		ok( false, "$label: the request ran (output: " . trim( substr( $out, -400 ) ) . ')' );
		return array( 'status' => 0, 'tracked' => -1, 'tr' => null, 'hdr' => array() );
	}
	$r = json_decode( $m[1], true );
	ok( '' === $r['fatal'], "$label: ran without a fatal " . $r['fatal'] );
	echo "  $label: HTTP {$r['status']}, counted {$r['tracked']}, " . implode( ' | ', $r['hdr'] ) . "\n";
	return $r;
}
function fc_has( array $r, $line ) { return in_array( $line, $r['hdr'], true ); }
function fc_count( array $r, $name ) { return count( array_filter( $r['hdr'], static function ( $h ) use ( $name ) { return 0 === stripos( $h, $name . ':' ); } ) ); }

$now  = time();
$db   = array( 'modified' => gmdate( 'Y-m-d H:i:s', $now - 2 * 86400 ), 'date' => gmdate( 'Y-m-d H:i:s', $now - 3 * 86400 ) );
$lm   = gmdate( 'D, d M Y H:i:s', $now - 2 * 86400 ) . ' GMT';
$etag = '"' . md5( $lm ) . '"';
$base = array( 'db' => $db, 'render_changed' => 0, 'ims' => '', 'inm' => '', 'since' => '' );

echo "Group: a reader revalidating an unchanged feed\n";
$r = fc_request( 'matching validators', array( 'ims' => $lm, 'inm' => $etag ) + $base );
ok( 304 === $r['status'], 'core answers 304' );
ok( 1 === $r['tracked'], 'the 304 is counted, once (it was lost when the tracker ran on template_redirect)' );
ok( false === $r['tr'], 'template_redirect never ran: the count came before core exited' );
ok( fc_has( $r, 'X-Robots-Tag: noindex, follow' ), 'the 304 carries the feed X-Robots-Tag' );

echo "\nGroup: the change in counting is dated, once\n";
ok( gmdate( 'Y-m-d' ) === $r['since'], 'the first counted request records the UTC day 304s began to count: ' . var_export( $r['since'], true ) );
$r = fc_request( 'a later request', array( 'ims' => $lm, 'inm' => $etag, 'since' => '2026-10-24' ) + $base );
ok( '2026-10-24' === $r['since'], 'a later request keeps the first day' );

echo "\nGroup: a full fetch\n";
$r = fc_request( 'no validators', $base );
ok( 200 === $r['status'] && true === $r['tr'], 'a 200, and the request goes on to the template' );
ok( 1 === $r['tracked'], 'counted exactly once (no second count on template_redirect)' );
ok( 1 === fc_count( $r, 'ETag' ) && fc_has( $r, "ETag: $etag" ), 'one ETag, core computed' );
ok( 1 === fc_count( $r, 'Last-Modified' ) && fc_has( $r, "Last-Modified: $lm" ), 'one Last-Modified, the newest post' );
ok( fc_has( $r, 'X-Robots-Tag: noindex, follow' ), 'the X-Robots-Tag goes out with core headers' );

echo "\nGroup: RFC 9110 13.2.2, If-None-Match wins over If-Modified-Since\n";
$r = fc_request( 'stale ETag, fresh date', array( 'ims' => gmdate( 'D, d M Y H:i:s', $now ) . ' GMT', 'inm' => '"stale"' ) + $base );
ok( 200 === $r['status'], 'a mismatched ETag gets the feed, not a 304 (the plugin validator answered 304 here)' );
ok( 1 === $r['tracked'], 'counted once' );

echo "\nGroup: a deploy after the newest post moves the validator\n";
$changed = $now - 3600;
$r = fc_request( 'validators from before the deploy', array( 'render_changed' => $changed, 'ims' => $lm, 'inm' => $etag ) + $base );
ok( 200 === $r['status'], 'the old validators get the new body, not a 304' );
ok( fc_has( $r, 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $changed ) . ' GMT' ), 'Last-Modified is the render change, through get_lastpostmodified' );
$r = fc_request( 'a deploy before the newest post', array( 'render_changed' => $now - 4 * 86400 ) + $base );
ok( fc_has( $r, "Last-Modified: $lm" ), 'an older render change leaves the post date' );
$r = fc_request( 'a render change dated ahead', array( 'render_changed' => $now + 86400 ) + $base );
ok( ! fc_has( $r, 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $now + 86400 ) . ' GMT' ), 'never a Last-Modified in the future' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
