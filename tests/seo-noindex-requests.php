<?php
/**
 * Tests: the requests that stay reachable but out of the index.
 *
 * Search Console, 2026-10: MailPoet's /?mailpoet_page=subscriptions served 200,
 * indexable, with a self-canonical carrying the query string (the plugin's own
 * canonical: the singular branch of sn_seo_meta_for_current_view() printed
 * get_permalink() of the mailpoet_page post). And every feed was indexable.
 *
 * Pins: a MailPoet request gets noindex in the robots meta (through
 * sn_seo_robots_directives, since the plugin removes core's wp_robots), an
 * X-Robots-Tag: noindex header, and no canonical; a feed gets
 * X-Robots-Tag: noindex, follow; an ordinary page gets none of it.
 *
 * Loads the REAL inc/seo.php; only WordPress is faked.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['V'] = array( 'type' => '', 'feed' => false );
$GLOBALS['__actions'] = array();

function is_singular( $t = '' ) {
	$type = $GLOBALS['V']['type'];
	if ( '' === $type ) {
		return false;
	}
	return '' === $t || in_array( $type, (array) $t, true );
}
function is_feed( $f = '' ) { return (bool) $GLOBALS['V']['feed']; }
function get_queried_object() { return '' === $GLOBALS['V']['type'] ? null : (object) array( 'ID' => 9, 'post_type' => $GLOBALS['V']['type'] ); }
function get_permalink( $p = null ) { return 'mailpoet_page' === $GLOBALS['V']['type'] ? 'https://example.com/?mailpoet_page=subscriptions' : 'https://example.com/about/'; }
function get_post_meta( $id, $k = '', $s = false ) { return ''; }
function get_query_var( $k, $d = '' ) { return $d; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function is_front_page() { return false; }
function is_page( $s = '' ) { return false; }
function is_home() { return false; }
function is_tag() { return false; }
function is_404() { return false; }
function sn_setting( $p, $d = null ) { return $d; }
function get_the_title( $p = null ) { return 'Title'; }
function get_bloginfo( $k = '' ) { return 'Site'; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function apply_filters_deprecated( $tag, $args ) { return $args[0]; }
function add_action( $tag, $cb, $prio = 10 ) { $GLOBALS['__actions'][ $tag ][] = array( $cb, $prio ); }
function add_filter() {}
function remove_action() {}

require __DIR__ . '/../inc/seo.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function header_for_view() { return function_exists( 'sn_seo_robots_header' ) ? sn_seo_robots_header() : 'MISSING sn_seo_robots_header()'; }

echo "Group: MailPoet's subscription page\n";
$GLOBALS['V'] = array( 'type' => 'mailpoet_page', 'feed' => false );
ok( in_array( 'noindex', sn_seo_robots_directives(), true ), 'the robots meta says noindex' );
ok( 'X-Robots-Tag: noindex' === header_for_view(), 'the X-Robots-Tag header says noindex' );
list( , , $url ) = sn_seo_meta_for_current_view();
ok( '' === $url, 'no canonical (was the query-string self-canonical)' );

$GLOBALS['V'] = array( 'type' => '', 'feed' => false );
$_GET['mailpoet_page'] = 'subscriptions';
$GLOBALS['V'] = array( 'type' => 'page', 'feed' => false );
ok( ! in_array( 'noindex', sn_seo_robots_directives(), true ), 'any other URL with ?mailpoet_page= appended stays indexable (no outsider de-index)' );
ok( '' === header_for_view(), 'and gets no X-Robots-Tag' );
unset( $_GET['mailpoet_page'] );

echo "\nGroup: feeds\n";
$GLOBALS['V'] = array( 'type' => '', 'feed' => true );
ok( 'X-Robots-Tag: noindex, follow' === header_for_view(), 'a feed (/feed/, /notes/feed/, tag, page and /feed/json/ alike: is_feed()) gets noindex, follow' );

echo "\nGroup: ordinary pages are untouched\n";
$GLOBALS['V'] = array( 'type' => 'page', 'feed' => false );
ok( ! in_array( 'noindex', sn_seo_robots_directives(), true ), 'an ordinary page is not noindex' );
ok( '' === header_for_view(), 'an ordinary page gets no X-Robots-Tag' );
list( , , $url ) = sn_seo_meta_for_current_view();
ok( 'https://example.com/about/' === $url, 'an ordinary page keeps its canonical' );

echo "\nGroup: wiring\n";
$hooked = false;
foreach ( $GLOBALS['__actions']['template_redirect'] ?? array() as $h ) {
	if ( 'sn_seo_send_robots_header' === $h[0] && $h[1] < 10 ) {
		$hooked = true;
	}
}
ok( $hooked, 'the header is sent at template_redirect, before the feed 304 short-circuit at priority 10' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
