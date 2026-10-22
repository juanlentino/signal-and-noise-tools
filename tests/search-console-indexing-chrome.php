<?php
/**
 * The indexing guard reads the site chrome (inc/search-console-indexing-chrome.php):
 * the header and footer template parts, rendered through core, count as links
 * from every page; notes stay judged on body links; a Spanish twin of a
 * chrome-linked page counts; the cache follows the parts; nothing goes over HTTP.
 *
 * Run: php tests/search-console-indexing-chrome.php
 * Needs core's HTML API: SNT_WP_HTML_API=<wp>/wp-includes/html-api (CI fetches it).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

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

// ── WordPress stubs: one theme, two parts, a transient store, a hook table ──
$GLOBALS['T']     = array(); // transients.
$GLOBALS['H']     = array(); // hook => callbacks.
$GLOBALS['HTTP']  = 0;       // any HTTP call is a failure.
$GLOBALS['RENDS'] = 0;       // do_blocks() calls (one per part).
$GLOBALS['PARTS'] = array(
	'header' => '<!-- wp:navigation --><a href="/">Home</a><a href="/about">About</a><a href="https://example.test/resume/?x=1">Resume</a><a href="https://elsewhere.test/about/">Out</a><a href="mailto:a@b.c">Mail</a><!-- /wp:navigation -->',
	'footer' => '<nav><a href="/colophon/">Colophon</a><a href="#top">Top</a></nav>',
);
$GLOBALS['THROW'] = false;
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_stylesheet() { return 'signal-and-noise'; }
function wp_get_theme() { return new class() { public function get( $k ) { return 'Version' === $k ? '2026.10.9' : ''; } }; }
function get_block_template( $id, $type ) {
	$slug = substr( $id, strpos( $id, '//' ) + 2 );
	return isset( $GLOBALS['PARTS'][ $slug ] ) ? (object) array( 'content' => $GLOBALS['PARTS'][ $slug ] ) : null;
}
function do_blocks( $c ) { ++$GLOBALS['RENDS']; if ( $GLOBALS['THROW'] ) { echo '<a href="/leak">'; throw new RuntimeException( 'cron' ); } return (string) $c; }
function parse_blocks( $c ) {
	if ( isset( $GLOBALS['PARSED'][ $c ] ) ) { return $GLOBALS['PARSED'][ $c ]; }
	return array( array( 'blockName' => 'core/navigation', 'attrs' => array(), 'innerBlocks' => array(
		array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'url' => '/services' ), 'innerBlocks' => array() ),
	) ), array( 'blockName' => 'signal-noise/meta-nav', 'attrs' => array(), 'innerBlocks' => array() ) );
}
function render_block( $b ) { return 'signal-noise/meta-nav' === $b['blockName'] ? '<a href="/now">Now</a>' : ''; }
function esc_url( $u ) { return (string) $u; }
function get_transient( $k ) { return $GLOBALS['T'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['T'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T'][ $k ] ); return true; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['H'][ $h ][] = $cb; }
function do_action( $h ) { foreach ( $GLOBALS['H'][ $h ] ?? array() as $cb ) { $cb(); } }
function wp_remote_get() { ++$GLOBALS['HTTP']; return array(); }
function wp_remote_request() { ++$GLOBALS['HTTP']; return array(); }
function wp_safe_remote_get() { ++$GLOBALS['HTTP']; return array(); }

require __DIR__ . '/../inc/ml-link-isolation.php';
require __DIR__ . '/../inc/search-console-indexing.php';

echo "indexing guard: the site chrome\n\n";

echo "Group: hrefs from rendered chrome (core's HTML API)\n";
$paths = snt_ml_paths_from_html( $GLOBALS['PARTS']['header'] . $GLOBALS['PARTS']['footer'], 'example.test' );
ok( array( '/about', '/colophon', '/resume' ) === $paths, 'internal paths only, normalized, sorted, unique (root, another host, mailto and a fragment are not links to a page): ' . implode( ',', $paths ) );
ok( array( '/about' ) === snt_ml_paths_from_html( '<A HREF=\'/About/\'>x</A><a title="href=/no">y</a><a href="/about#x">z</a>', 'example.test' ), 'attribute case, quoting and a fragment do not fool it, and a href inside another attribute is not a link' );

echo "\nGroup: the live read renders the parts once, then caches\n";
$live = sn_indexing_site_links()['chrome'];
ok( array( '/about', '/colophon', '/resume' ) === $live && 2 === $GLOBALS['RENDS'], 'the header and footer render through core in one pass: ' . implode( ',', $live ) );
sn_indexing_site_links()['chrome'];
ok( 2 === $GLOBALS['RENDS'], 'a second read is served from the cache, no render' );
$GLOBALS['PARTS']['footer'] .= '<a href="/now/">Now</a>';
ok( in_array( '/now', sn_indexing_site_links()['chrome'], true ) && 4 === $GLOBALS['RENDS'], 'an edited part changes the cache key, so the set follows the parts' );
foreach ( array( 'switch_theme', 'upgrader_process_complete', 'customize_save_after', 'save_post_wp_template_part', 'save_post_wp_navigation' ) as $hook ) {
	sn_indexing_site_links()['chrome'];
	do_action( $hook );
	ok( false === get_transient( SN_INDEXING_CHROME_CACHE ), "$hook clears the cache" );
}
ok( sn_indexing_chrome_key( 'a', '1', array( 'x' ) ) !== sn_indexing_chrome_key( 'a', '2', array( 'x' ) ) && sn_indexing_chrome_key( 'a', '1', array( 'x' ) ) !== sn_indexing_chrome_key( 'b', '1', array( 'x' ) ), 'the key moves with the theme stylesheet and version too' );

echo "\nGroup: when rendering fails (cron), the block markup is the fallback\n";
$GLOBALS['T'] = array();
$GLOBALS['THROW'] = true;
$level = ob_get_level();
$fb = sn_indexing_site_links()['chrome'];
ok( array( '/now', '/services' ) === $fb, 'navigation-link url attrs and the meta-nav block\'s own render: ' . implode( ',', $fb ) );
ok( $level === ob_get_level() && ! in_array( '/leak', $fb, true ), 'a render that throws leaves no output buffer open and nothing half-rendered counted' );
$GLOBALS['THROW'] = false;
$GLOBALS['T'] = array();
$saved = $GLOBALS['PARTS'];
$GLOBALS['PARTS'] = array();
ok( array() === sn_indexing_site_links()['chrome'] && false === get_transient( SN_INDEXING_CHROME_CACHE ), 'no parts, no set, and an empty set is never cached' );
$GLOBALS['PARTS'] = $saved;

echo "\nGroup: the publish check counts the chrome\n";
$now = 1792000000;
$sub = static function ( $id, $type, $path, $extra = array() ) use ( $now ) {
	return array_merge( array( 'post_id' => $id, 'path' => $path, 'url' => 'https://example.test' . $path . '/', 'type' => $type, 'published' => $now - 30 * DAY_IN_SECONDS, 'indexable' => true, 'canonical' => '', 'core' => 'page' === $type, 'en_path' => '' ), $extra );
};
$entries = array( '/notes/fine' => array( 'indexed' => true ) );
$inbound = array(
	'/about'      => array( 'inbound' => 0, 'linked_from' => array(), 'linked_from_paths' => array() ),
	'/colophon'   => array( 'inbound' => 0, 'linked_from' => array(), 'linked_from_paths' => array() ),
	'/notes/lone' => array( 'inbound' => 0, 'linked_from' => array(), 'linked_from_paths' => array() ),
	'/sobre-mi'   => array( 'inbound' => 1, 'linked_from' => array( 7 ), 'linked_from_paths' => array( '/notes/fine' ) ),
);
$chrome   = array( '/about', '/colophon', '/resume' );
$subjects = array(
	$sub( 1, 'page', '/about' ),
	$sub( 2, 'page', '/Colophon' ),
	$sub( 3, 'post', '/notes/lone' ),
	$sub( 4, 'page', '/sobre-mi', array( 'en_path' => '/about' ) ),
	$sub( 5, 'page', '/workflow' ),
	$sub( 6, 'post', '/about', array() ),
	$sub( 7, 'page', '/resume', array( 'canonical' => 'https://other.test/' ) ),
);
$pc = sn_indexing_publish_check( $subjects, $entries, $inbound, $now, array( 'chrome' => $chrome ) );
$by = array();
foreach ( $pc['failures'] as $f ) { $by[ $f['post_type'] . ':' . $f['path'] ] = $f; }
ok( ! isset( $by['page:/about'] ) && ! isset( $by['page:/Colophon'] ), 'a chrome-linked page with no body links passes the inbound rule (path spelling does not matter)' );
ok( isset( $by['post:/notes/lone'] ) && array( 'inbound' ) === $by['post:/notes/lone']['reasons'] && 'body' === $by['post:/notes/lone']['inbound_source'], 'a note with no body links still fails, judged on body links only' );
ok( ! isset( $by['page:/sobre-mi'] ), 'the Spanish twin of a chrome-linked page counts (the lang-chrome swap links it from every Spanish page)' );
ok( isset( $by['page:/workflow'] ) && 'body' === $by['page:/workflow']['inbound_source'], 'a page the chrome does not link is still judged on body links' );
ok( isset( $by['post:/about'] ), 'a note never borrows the chrome, even at a chrome path' );
ok( isset( $by['page:/resume'] ) && array( 'canonical' ) === $by['page:/resume']['reasons'] && 'chrome' === $by['page:/resume']['inbound_source'], 'a chrome page can still fail on canonical, and the row says its inbound came from the chrome' );
ok( array( '/about', '/colophon', '/resume', '/sobre-mi' ) === $pc['chrome_paths'], 'the check names the chrome set it used, Spanish twins included, so a render that found nothing is visible: ' . implode( ',', $pc['chrome_paths'] ) );
$pc0 = sn_indexing_publish_check( $subjects, $entries, $inbound, $now );
ok( array() === $pc0['chrome_paths'] && 7 === count( $pc0['failures'] ), 'without a chrome set the check is the body-only check it was' );

echo "\nGroup: request and stuck rows carry a chrome flag, not a fake count\n";
$GLOBALS['P'] = array( 1 => '/about/', 4 => '/sobre-mi/', 5 => '/workflow/' );
function get_post( $id ) { return isset( $GLOBALS['P'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' ) : null; }
function get_post_meta( $id, $k, $s = false ) { return ''; }
function get_permalink( $p ) { return 'https://example.test' . ( $GLOBALS['P'][ is_object( $p ) ? (int) $p->ID : (int) $p ] ?? '/' ); }
$ent = array(
	'/about'    => array( 'indexed' => false, 'coverage_state' => 'Crawled - currently not indexed', 'url' => 'https://example.test/about/', 'kind' => 'post', 'post_id' => 1 ),
	'/sobre-mi' => array( 'indexed' => false, 'coverage_state' => 'Discovered - currently not indexed', 'url' => 'https://example.test/sobre-mi/', 'kind' => 'post', 'post_id' => 4 ),
	'/workflow' => array( 'indexed' => false, 'coverage_state' => 'Discovered - currently not indexed', 'url' => 'https://example.test/workflow/', 'kind' => 'post', 'post_id' => 5 ),
);
if ( function_exists( 'snt_gsc_index_stuck' ) ) {
	$r    = sn_indexing_build( $ent, array( $subjects[0], $subjects[3], $subjects[4] ), $inbound, $now, '', array( 'chrome' => $chrome ) );
	$rows = array_column( $r['request_list'], null, 'path' );
	ok( true === ( $rows['/about']['chrome'] ?? null ) && true === ( $rows['/sobre-mi']['chrome'] ?? null ) && false === ( $rows['/workflow']['chrome'] ?? null ), 'chrome is true for a chrome page and its Spanish twin, false otherwise' );
	ok( 0 === $rows['/about']['inbound_indexed'], 'inbound_indexed stays the body count; the chrome is never folded into it' );
} else {
	ok( false, 'snt_gsc_index_stuck() is loaded with the indexing guard' );
}

echo "\nGroup: template links outside the post content (single, page, front page)\n";
$b = static function ( $name, $html = '', $inner = array(), $attrs = array() ) { return array( 'blockName' => $name, 'attrs' => $attrs, 'innerHTML' => $html, 'innerBlocks' => $inner ); };
$GLOBALS['PARTS']['single']       = 'SINGLE';
$GLOBALS['PARTS']['page']         = 'PAGE';
$GLOBALS['PARTS']['front-page']   = 'FRONT';
$GLOBALS['PARTS']['post-closing'] = 'CLOSING';
$GLOBALS['PARSED']['SINGLE']  = array( $b( 'core/group', '<div></div>', array(
	$b( 'core/template-part', '', array(), array( 'slug' => 'header' ) ),
	$b( 'core/post-content', '', array( $b( 'core/paragraph', '<p><a href="/in-the-body/">body</a></p>' ) ) ),
	$b( 'core/paragraph', '<p><a href="/provenance/over-detection/">Start with the pillar</a></p>' ),
	$b( 'signal-noise/related-notes' ),
	$b( 'core/template-part', '', array(), array( 'slug' => 'post-closing' ) ),
) ) );
$GLOBALS['PARSED']['CLOSING'] = array( $b( 'core/paragraph', '<p><a href="/notes/">All notes</a></p>' ) );
$GLOBALS['PARSED']['PAGE']    = array( $b( 'core/post-content' ), $b( 'core/paragraph', '<p><a href="/workflow/">Workflow</a></p>' ) );
$GLOBALS['PARSED']['FRONT']   = array( $b( 'core/post-content' ), $b( 'core/html', '<a href="/provenance">Read essay</a>' ) );
$GLOBALS['T'] = array();
$rends = $GLOBALS['RENDS'];
$site  = sn_indexing_site_links();
ok( array( '/notes', '/provenance/over-detection' ) === $site['template']['post'], 'single: links outside core/post-content, through its own template parts; never the body, the header part, or a per-note dynamic block: ' . implode( ',', $site['template']['post'] ) );
ok( array( '/workflow' ) === $site['template']['page'] && array( '/provenance' ) === $site['home'], 'the page and front-page templates read the same way' );
ok( 2 === $GLOBALS['RENDS'] - $rends, 'only the header and footer render; templates are read from their block markup (a per-note block rendered without its note would count links that are not on every note)' );
$GLOBALS['PARSED']['SINGLE2'] = $GLOBALS['PARSED']['SINGLE'];
$GLOBALS['PARSED']['SINGLE2'][0]['innerBlocks'][2]['innerHTML'] .= '<a href="/now/">x</a>';
$GLOBALS['PARTS']['single'] = 'SINGLE2';
ok( in_array( '/now', sn_indexing_site_links()['template']['post'], true ), 'an edited template changes the cache key' );

$tent = array(
	'/notes/x' => array( 'indexed' => true ),
	'/notes/y' => array( 'indexed' => true ),
	'/notes/z' => array( 'indexed' => false ),
	'/'        => array( 'indexed' => true ),
	'/other'   => array( 'indexed' => true ),
);
$tsub = array(
	$sub( 30, 'post', '/notes/x' ), $sub( 31, 'post', '/notes/y' ), $sub( 32, 'post', '/notes/z' ), $sub( 33, 'page', '/other' ),
	$sub( 34, 'page', '/provenance/over-detection' ), $sub( 35, 'page', '/provenance' ), $sub( 36, 'page', '/workflow' ),
);
$none = array( 'inbound' => 0, 'linked_from' => array(), 'linked_from_paths' => array() );
$tin  = array(
	'/notes/x' => $none, '/notes/y' => $none, '/notes/z' => $none, '/provenance/over-detection' => $none, '/workflow' => $none,
	'/provenance' => array( 'inbound' => 1, 'linked_from' => array( 30 ), 'linked_from_paths' => array( '/notes/x' ) ),
);
$tsite = array( 'chrome' => array(), 'template' => array( 'post' => array( '/provenance/over-detection' ), 'page' => array( '/workflow' ) ), 'home' => array( '/provenance' ) );
$tpc   = sn_indexing_publish_check( $tsub, $tent, $tin, $now, $tsite );
$tby   = array_column( $tpc['failures'], null, 'path' );
ok( ! isset( $tby['/provenance/over-detection'] ), 'a page every note\'s template links passes: two indexed notes link it' );
ok( ! isset( $tby['/provenance'] ), 'one body link from an indexed note plus the indexed home page\'s template is two' );
ok( isset( $tby['/workflow'] ) && 'template' === $tby['/workflow']['inbound_source'] && array( 'page' ) === $tby['/workflow']['template'], 'the page template links /workflow from one other indexed page: still thin, and the row says the template was counted' );
$solo = sn_indexing_publish_check( array( $sub( 30, 'post', '/notes/x' ), $sub( 34, 'page', '/provenance/over-detection' ) ), array( '/notes/x' => array( 'indexed' => true ) ), array( '/provenance/over-detection' => array( 'inbound' => 1, 'linked_from' => array( 30 ), 'linked_from_paths' => array( '/notes/x' ) ) ), $now, array( 'template' => array( 'post' => array( '/provenance/over-detection' ) ) ) );
$sby = array_column( $solo['failures'], null, 'path' );
ok( 1 === ( $sby['/provenance/over-detection']['inbound_indexed'] ?? null ) && in_array( 'inbound', $sby['/provenance/over-detection']['reasons'], true ), 'a body link and a template link from the one indexed note are one source, never two' );
ok( array( 'post' => array( '/provenance/over-detection' ), 'page' => array( '/workflow' ), 'home' => array( '/provenance' ) ) === $tpc['template_paths'], 'the check names the template sets it used' );
$GLOBALS['P'][34] = '/provenance/over-detection/';
$tr = sn_indexing_build( array( '/provenance/over-detection' => array( 'indexed' => false, 'coverage_state' => 'Discovered - currently not indexed', 'url' => 'https://example.test/provenance/over-detection/', 'kind' => 'post', 'post_id' => 34 ) ), $tsub, $tin, $now, '', $tsite );
ok( array( 'post' ) === ( $tr['request_list'][0]['template'] ?? null ) && false === $tr['request_list'][0]['chrome'], 'a request row names the templates that link it' );

ok( 0 === $GLOBALS['HTTP'], 'nothing here fetched the site over HTTP' );
ok( false === strpos( (string) file_get_contents( __DIR__ . '/../inc/search-console-indexing-chrome.php' ), 'wp_remote_' ), 'the chrome reader has no HTTP call in its source' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
