<?php
/**
 * The link graph reads RENDERED bodies (inc/ml-link-graph-render.php): links a
 * dynamic block or a shortcode prints count, a render that throws falls back
 * to the raw body for that post, the result is cached per post, nothing goes
 * over HTTP while rendering, and the front page is a source.
 *
 * Run: php tests/ml-link-graph-render.php
 * Needs core's HTML API: SNT_WP_HTML_API=<wp>/wp-includes/html-api (CI fetches it).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'SNT_VERSION', '2026.10.55' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$api = '';
foreach ( array( rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' ), dirname( __DIR__, 4 ) . '/wp-includes/html-api' ) as $dir ) {
	if ( '' !== $dir && is_file( $dir . '/class-wp-html-tag-processor.php' ) ) { $api = $dir; break; }
}
if ( '' !== $api ) {
	if ( is_file( dirname( $api ) . '/class-wp-token-map.php' ) ) { require_once dirname( $api ) . '/class-wp-token-map.php'; }
	foreach ( array( 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-attribute-token.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $f ) {
		require_once "$api/$f";
	}
}
if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	echo "FAIL: core's HTML API is missing (set SNT_WP_HTML_API)\n\nResult: 0 passed, 1 failed.\n";
	exit( 1 );
}

// ── stubs ──
class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } }
$GLOBALS['T'] = array(); $GLOBALS['F'] = array(); $GLOBALS['RENDERED'] = array(); $GLOBALS['HTTP_SEEN'] = array();
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_stylesheet() { return 'signal-and-noise'; }
function wp_get_theme() { return new class() { public function get( $k ) { return $GLOBALS['THEME_V'] ?? '2026.10.9'; } }; }
function get_transient( $k ) { return $GLOBALS['T'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['T'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T'][ $k ] ); return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['F'][ $h ][] = $cb; return true; }
function remove_filter( $h, $cb, $p = 10 ) { foreach ( $GLOBALS['F'][ $h ] ?? array() as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['F'][ $h ][ $i ] ); } } return true; }
function apply_filters( $h, $v, ...$a ) { foreach ( $GLOBALS['F'][ $h ] ?? array() as $cb ) { $v = $cb( $v, ...$a ); } return $v; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['A'][ $h ][] = $cb; }
function do_action( $h ) { foreach ( $GLOBALS['A'][ $h ] ?? array() as $cb ) { $cb(); } }
class SntDeps { public $queue = array(); }
$GLOBALS['STYLES'] = new SntDeps(); $GLOBALS['SCRIPTS'] = new SntDeps();
$GLOBALS['STYLES']->queue = array( 'admin-css' );
function wp_styles() { return $GLOBALS['STYLES']; }
function wp_scripts() { return $GLOBALS['SCRIPTS']; }
function wp_enqueue_style( $h ) { $GLOBALS['STYLES']->queue[] = $h; }
function wp_enqueue_script( $h ) { $GLOBALS['SCRIPTS']->queue[] = $h; }
function setup_postdata( $p ) { $GLOBALS['SETUP'] = $p; return true; }
function do_blocks( $c ) {
	$id = (int) ( $GLOBALS['post']->ID ?? 0 );
	$GLOBALS['RENDERED'][] = $id;
	if ( false !== strpos( $c, '<!-- wp:boom /-->' ) ) { echo '<a href="/leak/">'; throw new RuntimeException( 'block needs a request' ); }
	// A dynamic block that lists children, and needs the post it renders in.
	return str_replace( '<!-- wp:signal-noise/children /-->', '<ul><li><a href="/maturity/roadmap/">Roadmap</a></li><li><a href="/maturity/self-' . $id . '/">self</a></li></ul>', $c );
}
function do_shortcode( $c ) {
	if ( false !== strpos( $c, '[sn_maturity_index]' ) ) { wp_enqueue_style( 'sn-maturity-index-front' ); wp_enqueue_script( 'sn-front-js' ); }
	if ( false !== strpos( $c, '[http]' ) ) { $GLOBALS['HTTP_SEEN'][] = apply_filters( 'pre_http_request', false, array(), 'https://api.example/' ); }
	return str_replace( array( '[sn_maturity_index]', '[http]' ), array( '<a class="card" href="https://example.test/maturity/ai-maturity/">AI</a>', '' ), $c );
}
function get_permalink( $p ) { return 'https://example.test' . $p->url; }
function sn_path_join_key( $p ) { return '/' . trim( (string) parse_url( (string) $p, PHP_URL_PATH ), '/' ); }
function mk( $id, $type, $url, $content, $mod = '2026-10-01 00:00:00' ) {
	return (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_name' => basename( $url ), 'post_title' => "t$id", 'post_content' => $content, 'post_modified_gmt' => $mod, 'url' => $url );
}
$GLOBALS['POSTS'] = array(
	'post' => array(
		mk( 1, 'post', '/notes/a/', '<p>body <a href="/notes/b/">b</a></p>' ),
		mk( 2, 'post', '/notes/b/', '<!-- wp:boom /--><a href="/maturity/">raw link survives</a>' ),
		mk( 5, 'post', '/notes/c/', '[http]<a href="/notes/a/">a</a>' ),
	),
	'page' => array(
		mk( 3, 'page', '/maturity/', '<!-- wp:signal-noise/children /-->[sn_maturity_index]' ),
		mk( 4, 'page', '/maturity/roadmap/', '<p>none</p>' ),
		mk( 6, 'page', '/maturity/ai-maturity/', '<p>none</p>' ),
		mk( 7, 'page', '/', '<a href="/maturity/roadmap/">from home</a>' ),
	),
);
function snt_corpus_fetch_posts( $status, $type ) { return $GLOBALS['POSTS'][ $type ]; }

require __DIR__ . '/../inc/ml-link-isolation.php';

echo "link graph over rendered bodies\n\n";
$GLOBALS['post'] = 'before';
$level = ob_get_level();
$ib    = snt_ml_inbound_by_path();

echo "Group: links a block or shortcode renders count\n";
ok( array( '/maturity' ) === ( $ib['/maturity/roadmap']['linked_from_paths'] ?? null ) || in_array( '/maturity', (array) ( $ib['/maturity/roadmap']['linked_from_paths'] ?? array() ), true ), 'a child linked only by the hub\'s dynamic block counts the hub' );
ok( 1 === ( $ib['/maturity/ai-maturity']['inbound'] ?? null ), 'a link a shortcode prints counts (the maturity index cards)' );
ok( in_array( 3, $GLOBALS['RENDERED'], true ) && ! isset( $ib['/maturity/self-3'] ), 'the block rendered with its own post set up (a path that is not a node is not one)' );

echo "\nGroup: a render that fails falls back to the raw body, for that post only\n";
ok( in_array( '/notes/b', (array) ( $ib['/maturity']['linked_from_paths'] ?? array() ), true ), 'the raw link in a body whose render threw still counts' );
ok( ! isset( $ib['/leak'] ) && $level === ob_get_level(), 'nothing half-rendered counted and no output buffer left open' );
ok( 'before' === $GLOBALS['post'], 'the global post is restored after rendering' );
ok( 1 === ( $ib['/notes/b']['inbound'] ?? null ), 'other posts still render normally beside the failure' );

echo "\nGroup: no HTTP while rendering\n";
ok( 1 === count( $GLOBALS['HTTP_SEEN'] ) && $GLOBALS['HTTP_SEEN'][0] instanceof WP_Error, 'a request a shortcode would make is short-circuited by pre_http_request' );
ok( false === apply_filters( 'pre_http_request', false, array(), 'x' ), 'the short-circuit is removed once rendering ends' );

echo "\nGroup: front-end assets a render enqueues do not leak\n";
ok( array( 'admin-css' ) === $GLOBALS['STYLES']->queue && array() === $GLOBALS['SCRIPTS']->queue, 'the style and script queues are restored after rendering (a maturity shortcode\'s stylesheet never prints in admin)' );

echo "\nGroup: the front page is a source, never a row\n";
ok( in_array( '/', (array) ( $ib['/maturity/roadmap']['linked_from_paths'] ?? array() ), true ), 'a link in the home page body counts, named "/"' );
ok( ! isset( $ib['/'] ), 'the front page is not a target row' );

echo "\nGroup: cached per post\n";
$n = count( $GLOBALS['RENDERED'] );
snt_ml_inbound_by_path();
ok( array( 2 ) === array_slice( $GLOBALS['RENDERED'], $n ), 'a second build renders only the post whose render failed (a failure is retried, never cached)' );
$stats = snt_ml_render_stats();
ok( 0 === $stats['rendered'] && 6 === $stats['cached'] && 1 === $stats['failed'] && is_int( $stats['ms'] ) && false === $stats['budget_hit'] && 0 === $stats['remaining'], 'stats name what rendered, what came from the cache, what failed, the time and the budget: ' . json_encode( $stats ) );
$n = count( $GLOBALS['RENDERED'] );
$GLOBALS['POSTS']['page'][0]->post_modified_gmt = '2026-10-21 00:00:00';
snt_ml_inbound_by_path();
ok( array( 2, 3 ) === array_slice( $GLOBALS['RENDERED'], $n ) && 1 === snt_ml_render_stats()['rendered'], 'an edited post re-renders, beside the retried failure only' );
$GLOBALS['THEME_V'] = '2026.10.10';
$n = count( $GLOBALS['RENDERED'] );
snt_ml_inbound_by_path();
ok( count( $GLOBALS['RENDERED'] ) - $n >= 6, 'a theme update re-renders the corpus' );
do_action( 'upgrader_process_complete' );
ok( false === get_transient( SNT_ML_RENDER_CACHE ), 'an update clears the cache' );

echo "\nGroup: a time budget; what rendered is kept, the next call continues\n";
$GLOBALS['T'] = array();
$all   = array_merge( $GLOBALS['POSTS']['post'], $GLOBALS['POSTS']['page'] );
$tick  = 0.0;
$clock = static function () use ( &$tick ) { $tick += 1.0; return $tick; }; // each read is one second later.
$n     = count( $GLOBALS['RENDERED'] );
$part  = snt_ml_rendered_links( $all, 'example.test', 3.5, $clock );
$st    = snt_ml_render_stats();
$done  = count( $GLOBALS['RENDERED'] ) - $n;
ok( true === $st['budget_hit'] && $done > 0 && $done < 7 && $st['remaining'] === 7 - $done, 'the deadline stops rendering mid-corpus and says how many are left: ' . json_encode( $st ) );
ok( count( array_filter( $part, 'is_null' ) ) === 7 - $done + ( in_array( 2, array_slice( $GLOBALS['RENDERED'], $n ), true ) ? 1 : 0 ), 'posts past the deadline read null, so the graph uses their raw bodies' );
ok( $done - ( in_array( 2, array_slice( $GLOBALS['RENDERED'], $n ), true ) ? 1 : 0 ) === count( get_transient( SNT_ML_RENDER_CACHE )['posts'] ?? array() ), 'what rendered before the deadline is persisted' );
$first = array_slice( $GLOBALS['RENDERED'], $n );
$n     = count( $GLOBALS['RENDERED'] );
snt_ml_rendered_links( $all, 'example.test' );
$second = array_slice( $GLOBALS['RENDERED'], $n );
ok( array() === array_intersect( array_diff( $first, array( 2 ) ), $second ) && 7 === count( array_unique( array_merge( $first, $second ) ) ) && false === snt_ml_render_stats()['budget_hit'], 'the next call renders only the rest: ' . implode( ',', $first ) . ' then ' . implode( ',', $second ) );
ok( 20 === SNT_ML_RENDER_PERSIST_EVERY && 15.0 === (float) SNT_ML_RENDER_BUDGET, 'persisted every 20 renders, 15 seconds by default' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
