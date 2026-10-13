<?php
/**
 * Standalone test: a note save that changed nothing another page lists purges
 * that note's cache tag and the lists' tag, not the theme's whole tag
 * (2026-10-13). Tags, not URLs: a URL purge misses every ?utm_source= copy.
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
function is_sticky( $id = 0 ) { return $GLOBALS['__sticky']; }
function wp_get_object_terms( $id, $tax, $args = array() ) { return $GLOBALS['__terms']; }
// Core's shapes, reduced: script/style bodies go, then tags; shortcodes go;
// excerpt_remove_blocks drops a block an excerpt never shows (here wp:html).
function wp_strip_all_tags( $text, $remove_breaks = false ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) ) ); }
function strip_shortcodes( $content ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $content ); }
function excerpt_remove_blocks( $content ) { return preg_replace( '/<!-- wp:html -->.*?<!-- \/wp:html -->/s', '', (string) $content ); }
function snt_purge_ledger_add( array $row ) { $GLOBALS['__ledger'][] = $row; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $at, $hook, $args = array() ) { $GLOBALS['__sched'][] = array( $hook, $args ); return true; }
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
// The plugin's own reading time (inc/reading-time.php, always loaded): whole
// minutes at 200 words, so a short addition stays inside the same minute.
function sn_calculate_reading_time( $post ) { return max( 1, (int) ceil( str_word_count( strip_tags( (string) $post->post_content ) ) / 200 ) ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-purge-verify.php';
require_once __DIR__ . '/../inc/cloudflare-purge.php';
$GLOBALS['__opts'] = array( SN_CF_TOKEN_OPT => 'T', SN_CF_ZONE_OPT => 'Z' );
$after_insert = end( $GLOBALS['__hooked']['wp_after_insert_post'] );
$capture      = $GLOBALS['__hooked']['pre_post_update'][0] ?? null;

$body = 'Opening words of a note. ' . str_repeat( 'filler ', 80 ) . 'closing line.';
$base = array( 'post_content' => $body );

/**
 * One save: the stored post before, the post after, and what else changed.
 *
 * @return array{tags:array,files:array,ledger:array,probe:bool}
 */
function save( array $after = array(), array $before = array(), array $opt = array() ) {
	global $after_insert, $capture, $base;
	$opt += array( 'tb' => array( 3, 5 ), 'ta' => array( 3, 5 ), 'capture' => true, 'replies' => array( array( 200, true ) ), 'thumb' => array( 0, 0 ), 'sticky' => array( false, false ) );
	$GLOBALS['__http'] = array(); $GLOBALS['__ledger'] = array(); $GLOBALS['__sched'] = array(); $GLOBALS['__replies'] = $opt['replies'];
	$old = new WP_Post( $before + $base );
	$new = new WP_Post( $after + $base );
	$GLOBALS['__db'] = $old; $GLOBALS['__terms'] = $opt['tb']; $GLOBALS['__thumb'] = $opt['thumb'][0]; $GLOBALS['__sticky'] = $opt['sticky'][0];
	if ( $opt['capture'] && is_callable( $capture ) ) { call_user_func( $capture, 7 ); }
	$GLOBALS['__db'] = $new; $GLOBALS['__terms'] = $opt['ta']; $GLOBALS['__thumb'] = $opt['thumb'][1]; $GLOBALS['__sticky'] = $opt['sticky'][1];
	call_user_func( $after_insert, 7, $new, true, $old );
	$tags = array(); $files = array();
	foreach ( $GLOBALS['__http'] as $b ) {
		$tags  = array_merge( $tags, (array) ( $b['tags'] ?? array() ) );
		$files = array_merge( $files, (array) ( $b['files'] ?? array() ) );
	}
	return array( 'tags' => $tags, 'files' => $files, 'ledger' => $GLOBALS['__ledger'], 'probe' => in_array( array( SN_CF_PROBE_HOOK, array( 7 ) ), $GLOBALS['__sched'], true ) );
}
$whole = static fn( $r ) => array( 'sn-render' ) === $r['tags'];

echo "Group: a theme that tags every page alike keeps the whole tag\n";
ok( 'sn_cf_listed_capture' === $capture, 'the "before" is captured on pre_post_update' );
ok( $whole( save() ) && ! ( function_exists( 'sn_cf_note_tags_supported' ) && sn_cf_note_tags_supported() ), 'no per-note tag from the theme: an unchanged save still purges the whole tag' );

// The theme after 2026.10.1 tags each note and the lists apart.
define( 'SN_EDGE_CACHE_LIST_TAG', 'sn-lists' );
if ( ! function_exists( 'sn_edge_cache_note_tag' ) ) {
	function sn_edge_cache_note_tag( $id ) { return 'sn-note-' . (int) $id; }
}

echo "\nGroup: a save that changed no list purges the note's tag and the lists' tag\n";
$r = save( array( 'post_content' => str_replace( 'closing line.', 'closing lines.', $body ) ) );
ok( array( 'sn-note-7', 'sn-lists' ) === $r['tags'], 'one call carrying the note\'s tag and the lists\' tag, never the whole tag: every ?utm_source= copy and every tier goes with them' );
ok( array( 'https://example.test/wp-sitemap.xml' ) === $r['files'], 'the only URL sent is core\'s sitemap, which carries no tag' );
ok( array( 'save', 'note', true ) === array( $r['ledger'][0]['trigger'] ?? '', $r['ledger'][0]['scope'] ?? '', $r['ledger'][0]['edge'] ?? null ) && 1 === count( $r['ledger'] ), 'one ledger row, scope note, edge confirmed' );
ok( $r['probe'], 'the post-save probe is scheduled for the note' );
ok( ! $whole( save() ), 'a save that changed nothing at all is narrow too' );
ok( ! $whole( save( array( 'post_content' => $body . ' five more words here now' ) ) ), 'a sentence added at the end, inside the same reading minute, is narrow' );
ok( ! $whole( save( array( 'post_content' => $body . ' <a href="/notes/a-note.md">md</a>' ) ) ), 'a link to a .md copy is not a link to a note (the theme\'s cited-by boundary)' );

echo "\nGroup: a change another page lists keeps the whole tag\n";
$block   = '<!-- wp:html -->' . str_repeat( 'embed ', 70 ) . '<!-- /wp:html -->';
$sc      = '[caption ' . str_repeat( 'attr ', 70 ) . ']';
$script  = '<script>' . str_repeat( 'code ', 70 ) . '</script>';
$changed = array(
	'the title'              => array( array( 'post_title' => 'A renamed note' ), array() ),
	'the slug'               => array( array( 'post_name' => 'renamed' ), array() ),
	'the publish date'       => array( array( 'post_date' => '2026-10-02 09:00:00' ), array() ),
	'the excerpt'            => array( array( 'post_excerpt' => 'Now with a summary.' ), array() ),
	'an excerpt of a space'  => array( array( 'post_excerpt' => ' ' ), array() ),
	'the password'           => array( array( 'post_password' => 'x' ), array() ),
	'the opening words'      => array( array( 'post_content' => str_replace( 'Opening words', 'First words', $body ) ), array() ),
	'the reading minute'     => array( array( 'post_content' => $body . ' ' . str_repeat( 'more ', 300 ) ), array() ),
	'a link to another note' => array( array( 'post_content' => str_replace( 'closing line.', 'see <a href="/notes/other/">this</a>', $body ) ), array() ),
	// Past the raw opening, but the first words of the excerpt: the block before them is one an excerpt drops.
	'the opening behind a long shortcode' => array( array( 'post_content' => $sc . '<p>Real start here.</p>' ), array( 'post_content' => $sc . '<p>Real start there.</p>' ) ),
	'the opening behind a script' => array( array( 'post_content' => $script . '<p>Real start here.</p>' ), array( 'post_content' => $script . '<p>Real start there.</p>' ) ),
	'the excerpt\'s opening behind a dropped block' => array( array( 'post_content' => $block . '<p>Real start here.</p>' ), array( 'post_content' => $block . '<p>Real start there.</p>' ) ),
);
foreach ( $changed as $what => $c ) {
	$r = save( $c[0], $c[1] );
	ok( $whole( $r ) && 'tag' === ( $r['ledger'][0]['scope'] ?? '' ), "a change to $what sends the whole tag" );
}
ok( $whole( save( array(), array(), array( 'ta' => array( 3 ) ) ) ), 'a removed tag sends the whole tag' );
ok( $whole( save( array(), array(), array( 'ta' => array( 3, 5, 9 ) ) ) ), 'an added tag sends the whole tag' );
ok( $whole( save( array(), array(), array( 'thumb' => array( 0, 41 ) ) ) ), 'a new featured image sends the whole tag' );
ok( $whole( save( array(), array(), array( 'sticky' => array( false, true ) ) ) ), 'making it sticky sends the whole tag' );

echo "\nGroup: when in doubt, the whole tag\n";
ok( $whole( save( array(), array(), array( 'capture' => false ) ) ), 'no "before" captured in this request (a scheduled post going live)' );
ok( $whole( save( array(), array( 'post_status' => 'draft' ) ) ), 'a first publish' );
ok( $whole( save( array( 'post_status' => 'draft' ) ) ), 'leaving publish' );
ok( $whole( save( array( 'post_type' => 'page' ), array( 'post_type' => 'page' ) ) ), 'a page (never captured)' );
ok( ! isset( $GLOBALS['sn_cf_listed_before'][7] ), 'the capture is consumed by the save that used it' );
$r = save( array(), array(), array( 'replies' => array( array( 400, false ), array( 200, true ) ) ) );
ok( array( 'sn-note-7', 'sn-lists', 'sn-render' ) === $r['tags'] && 'tag' === ( $r['ledger'][0]['scope'] ?? '' ), 'Cloudflare refuses the note\'s tags outright: the whole tag goes instead' );
$r = save( array(), array(), array( 'replies' => array( array( 429, false ), array( 200, true ) ) ) );
ok( array( 'sn-note-7', 'sn-lists' ) === $r['tags'] && false === ( $r['ledger'][0]['edge'] ?? null ), 'a throttled note purge waits for its retry: the row says note, edge false' );

echo "\nGroup: the probe says it runs again\n";
ok( false === snt_cf_probe_status( array() )['retired'] && '' === snt_cf_probe_retired_note( array() ), 'with per-note tags the probe is not retired, and nothing says it is' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
