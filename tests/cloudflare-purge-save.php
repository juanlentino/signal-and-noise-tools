<?php
/**
 * Standalone test: a note save that changed nothing another page lists purges
 * that note by URL, not the theme's whole cache tag (2026-10-13).
 * Run: php tests/cloudflare-purge-save.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_EDGE_CACHE_TAG', 'sn-render' );

$GLOBALS['__hooked'] = array();
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) { $GLOBALS['__hooked'][ $hook ][] = $cb; return true; }
function add_filter( $tag, $cb = null, $priority = 10, $args = 1 ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 7; public $post_type = 'post'; public $post_status = 'publish'; public $post_parent = 0;
		public $post_title = 'A note'; public $post_name = 'a-note'; public $post_date = '2026-10-01 09:00:00';
		public $post_excerpt = ''; public $post_password = ''; public $post_content = '';
		public function __construct( $f = array() ) { foreach ( $f as $k => $v ) { $this->$k = $v; } }
	}
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function get_permalink( $p ) { return 'https://example.test/notes/' . ( is_object( $p ) ? $p->post_name : 'x' ) . '/'; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_post( $id ) { return $GLOBALS['__db']; }
function get_post_meta( $id, $key = '', $single = false ) { return '_thumbnail_id' === $key ? $GLOBALS['__thumb'] : ''; }
// fields=ids answers term ids; otherwise term objects, as core does.
function wp_get_object_terms( $id, $tax, $args = array() ) {
	if ( 'ids' === ( $args['fields'] ?? '' ) ) { return $GLOBALS['__terms']; }
	return array_map( static fn( $t ) => (object) array( 'term_id' => $t, 'slug' => 'tag-' . $t ), $GLOBALS['__terms'] );
}
function get_term_link( $term, $tax = '' ) { return 'https://example.test/notes/tags/' . $term->slug . '/'; }
function snt_purge_ledger_add( array $row ) { $GLOBALS['__ledger'][] = $row; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $at, $hook, $args = array() ) { return true; }
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = json_decode( (string) $args['body'], true );
	$r = count( $GLOBALS['__replies'] ) > 1 ? array_shift( $GLOBALS['__replies'] ) : $GLOBALS['__replies'][0];
	return array( 'response' => array( 'code' => $r[0] ), 'body' => json_encode( array( 'success' => $r[1] ) ) );
}
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
// The theme's list (cache-headers.php), plus padding for the two-chunk case.
function sn_edge_cache_feed_paths() {
	$p = array( '/?feed=json' );
	foreach ( array( '/feed/', '/notes/feed/' ) as $b ) { foreach ( array( '', 'rss2/', 'rss/', 'rdf/', 'atom/', 'json/' ) as $f ) { $p[] = $b . $f; } }
	for ( $i = 0; $i < ( $GLOBALS['__pad'] ?? 0 ); $i++ ) { $p[] = '/pad-' . $i . '/'; }
	return $p;
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-purge.php';
$GLOBALS['__opts'] = array( SN_CF_TOKEN_OPT => 'T', SN_CF_ZONE_OPT => 'Z' );
$after_insert = end( $GLOBALS['__hooked']['wp_after_insert_post'] );
$capture      = $GLOBALS['__hooked']['pre_post_update'][0] ?? null;
ok( 'sn_cf_listed_capture' === $capture, 'the "before" is captured on pre_post_update' );

$body = 'Opening words of a note. ' . str_repeat( 'filler ', 80 ) . 'closing line.';
$base = array( 'post_content' => $body );

/**
 * One save: the stored post before, the post after, terms before and after.
 *
 * @return array{tag:bool,files:array,ledger:array}
 */
function save( array $after = array(), array $before = array(), array $tb = array( 3, 5 ), array $ta = array( 3, 5 ), $do_capture = true, array $replies = array( array( 200, true ) ) ) {
	global $after_insert, $capture, $base;
	$GLOBALS['__http'] = array(); $GLOBALS['__ledger'] = array(); $GLOBALS['__replies'] = $replies; $GLOBALS['__thumb'] = 0;
	$old = new WP_Post( $before + $base );
	$new = new WP_Post( $after + $base );
	$GLOBALS['__db'] = $old; $GLOBALS['__terms'] = $tb;
	if ( $do_capture && is_callable( $capture ) ) { call_user_func( $capture, 7 ); }
	$GLOBALS['__db'] = $new; $GLOBALS['__terms'] = $ta;
	call_user_func( $after_insert, 7, $new, true, $old );
	$tag = false; $files = array();
	foreach ( $GLOBALS['__http'] as $b ) {
		$tag   = $tag || isset( $b['tags'] );
		$files = array_merge( $files, (array) ( $b['files'] ?? array() ) );
	}
	return array( 'tag' => $tag, 'files' => $files, 'ledger' => $GLOBALS['__ledger'] );
}

echo "Group: a save that changed no list purges the note by URL\n";
$r = save( array( 'post_content' => str_replace( 'closing line.', 'closing lines.', $body ) ) );
ok( ! $r['tag'], 'a body edit past the opening, same length and links, sends no tag purge' );
foreach ( array( '/notes/a-note/', '/', '/notes/', '/notes/feed/', '/?feed=json', '/llms.txt', '/notes/tags/', '/notes/tags/tag-3/', '/notes/tags/tag-5/', '/wp-sitemap.xml' ) as $p ) {
	ok( in_array( 'https://example.test' . $p, $r['files'], true ), "it purges $p by URL" );
}
ok( ! in_array( 'https://example.test/notes/a-note.md', $r['files'], true ), 'not the .md copy: the Worker stores none at the edge' );
ok( array( 'save', 'note', true ) === array( $r['ledger'][0]['trigger'] ?? '', $r['ledger'][0]['scope'] ?? '', $r['ledger'][0]['edge'] ?? null ) && 1 === count( $r['ledger'] ), 'one ledger row, scope note, edge confirmed' );
ok( ! save()['tag'], 'a save that changed nothing at all is narrow too' );

echo "\nGroup: a change another page lists keeps the tag purge\n";
$changed = array(
	'the title'                   => array( 'post_title' => 'A renamed note' ),
	'the slug'                    => array( 'post_name' => 'renamed' ),
	'the publish date'            => array( 'post_date' => '2026-10-02 09:00:00' ),
	'the excerpt'                 => array( 'post_excerpt' => 'Now with a summary.' ),
	'the password'                => array( 'post_password' => 'x' ),
	'the opening words'           => array( 'post_content' => str_replace( 'Opening words', 'First words', $body ) ),
	'the reading length'          => array( 'post_content' => $body . ' ' . str_repeat( 'more ', 300 ) ),
	'a link to another note'      => array( 'post_content' => str_replace( 'closing line.', 'see <a href="/notes/other/">this</a>', $body ) ),
);
foreach ( $changed as $what => $after ) {
	$r = save( $after );
	ok( $r['tag'] && 'tag' === ( $r['ledger'][0]['scope'] ?? '' ), "a change to $what sends the tag purge" );
}
ok( save( array(), array(), array( 3, 5 ), array( 3 ) )['tag'], 'a removed tag sends the tag purge' );

echo "\nGroup: when in doubt, the tag\n";
ok( save( array(), array(), array( 3, 5 ), array( 3, 5 ), false )['tag'], 'no "before" captured in this request (a scheduled post going live): tag' );
ok( save( array(), array( 'post_status' => 'draft' ) )['tag'], 'a first publish: tag' );
$r = save( array( 'post_status' => 'draft' ) );
ok( $r['tag'], 'leaving publish: tag' );
ok( save( array( 'post_type' => 'page' ), array( 'post_type' => 'page' ) )['tag'], 'a page (never captured): tag' );
ok( ! isset( $GLOBALS['sn_cf_listed_before'][7] ), 'the capture is consumed by the save that used it' );

echo "\nGroup: the ledger row tells a confirmed purge from a queued one\n";
$GLOBALS['__pad'] = 20;
$r = save( array(), array(), array( 3, 5 ), array( 3, 5 ), true, array( array( 429, false ), array( 200, true ) ) );
$GLOBALS['__pad'] = 0;
ok( ! $r['tag'] && count( $GLOBALS['__http'] ) >= 2 && false === ( $r['ledger'][0]['edge'] ?? null ), 'a throttled first chunk and a confirmed last one: the row says edge false, not the last chunk\'s answer' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
