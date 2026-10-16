<?php
/**
 * 2026.10 live-site audit, plugin findings P1-1, P1-2, P2-5.
 *
 * P1-1: a singular's Last-Modified is the later of its content date and the
 * last render change, and a computed page (/stats, the maturity pages) sends
 * none, so Cloudflare cannot renew a stale copy with a 304.
 * P1-2: the feed build date counts publish dates, so a scheduled note going
 * live moves lastBuildDate, Last-Modified and the ETag.
 * P2-5: /notes/tags/ and /tag/* get Open Graph and CollectionPage JSON-LD.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "PASS: $m\n";
	} else {
		$fail++;
		echo "FAIL: $m\n";
	}
}

$GLOBALS['__hooks'] = array();
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {
	$GLOBALS['__hooks'][ $hook ][] = array( $cb, $priority, $args );
	return true;
}
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) {
	return add_action( $hook, $cb, $priority, $args );
}
function apply_filters( $t, $v, ...$a ) { return $v; }
function remove_action() {}

$GLOBALS['__v'] = array( 'tags' => false, 'tag' => false );
function sn_notes_is_tags_request() { return $GLOBALS['__v']['tags']; }
function is_tag() { return $GLOBALS['__v']['tag']; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_page( $s = '' ) { return false; }
function is_singular( $t = '' ) { return false; }
function is_feed() { return false; }
function is_wp_error( $x ) { return false; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function sn_setting( $k, $d = '' ) { return $d; }
function get_bloginfo( $k ) { return 'Juan Lentino'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_count_terms( $a ) { return 23; }
function wp_get_document_title() { return $GLOBALS['__v']['tags'] ? 'Tags | Juan Lentino' : 'Notes | AI music | Juan Lentino'; }
function get_query_var( $k, $d = '' ) { return $d; }
function term_description( $t ) { return '<p>Notes on music made with AI.</p>'; }
function get_term_link( $t ) { return 'https://x.test/tag/ai-music/'; }
function single_term_title( $p = '', $d = true ) { return 'AI &#038; music'; } // texturized, as WordPress returns it
function get_queried_object() {
	if ( ! $GLOBALS['__v']['tag'] ) {
		return null;
	}
	$t           = new stdClass();
	$t->term_id  = 9;
	$t->name     = 'AI & music';
	$t->taxonomy = 'post_tag';
	return $t;
}
function get_posts( $a ) { return array(); }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $autoload; return true; }
function has_shortcode( $content, $tag ) { return false !== strpos( (string) $content, '[' . $tag ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }

require __DIR__ . '/../inc/seo.php';
require __DIR__ . '/../inc/seo-schema.php';
require __DIR__ . '/../inc/maturity-layout.php';

// The module and its four parts files (split 2026-10-14, house line cap).
require_once __DIR__ . '/lib/inc-population.php';
$seo_src    = snt_test_seo_src();
$schema_src = snt_test_seo_schema_src(); // the module and its three parts files (split 2026-10-16)

echo "P1-1: the singular validator moves with every render change\n";
ok( function_exists( 'sn_seo_singular_validator_gmt' ), 'the validator rule is a named function' );
if ( function_exists( 'sn_seo_singular_validator_gmt' ) ) {
	ok( 5000 === sn_seo_singular_validator_gmt( 1000, 5000, false ), 'a deploy after the edit: Last-Modified is the deploy time, not the post date' );
	ok( 7000 === sn_seo_singular_validator_gmt( 7000, 5000, false ), 'an edit after the deploy: the edit time' );
	ok( 1000 === sn_seo_singular_validator_gmt( 1000, 0, false ), 'no render change on record: the content date, as before' );
	ok( 0 === sn_seo_singular_validator_gmt( 1000, 5000, true ), 'a computed page sends no Last-Modified' );
	ok( 0 === sn_seo_singular_validator_gmt( 0, 5000, false ), 'no content date: no validator' );
	ok( sn_seo_is_computed_content( '<!-- wp:shortcode -->[sn_public_stats]<!-- /wp:shortcode -->' ), '/stats ([sn_public_stats]) is computed' );
	ok( sn_seo_is_computed_content( '[sn_maturity_index]' ) && sn_seo_is_computed_content( '[sn_analytics_maturity]' ) && sn_seo_is_computed_content( '[sn_maturity_roadmap]' ), 'the maturity hub, a family page and the roadmap are computed' );
	ok( ! sn_seo_is_computed_content( '<p>An about page.</p>' ), 'an ordinary page is not' );
}
ok( false !== strpos( $seo_src, '$modified_gmt = sn_seo_singular_validator_gmt( sn_seo_singular_last_modified_gmt( $post ), sn_seo_render_changed_gmt(), sn_seo_is_computed_content( (string) $post->post_content ) );' ), 'the handler sends the validator that rule picks' );
ok( 1 === substr_count( $seo_src, "header( 'Last-Modified: ' . \$modified_http );" ), 'one Last-Modified header, after that rule' );

echo "\nP1-1 review: the render time is recorded BEFORE the flush runs\n";
$before = null;
foreach ( $GLOBALS['__hooks']['sn_before_cache_flush'] ?? array() as $h ) {
	if ( 'sn_seo_record_render_change' === $h[0] ) {
		$before = $h;
	}
}
ok( null !== $before && 1 === $before[1], 'recorded on sn_before_cache_flush at priority 1 (the theme fires it before any purge leg and before the re-warm)' );
$after = false;
foreach ( $GLOBALS['__hooks']['sn_after_full_cache_flush'] ?? array() as $h ) {
	$after = $after || 'sn_seo_record_render_change' === $h[0];
}
ok( ! $after, 'not recorded after the flush, when the re-warmed pages are already cached' );
$GLOBALS['__opt']['sn_last_purge_report'] = array( 'time' => 4000 );
if ( function_exists( 'sn_seo_record_render_change' ) ) {
	$t0 = time();
	sn_seo_record_render_change();
	ok( (int) ( $GLOBALS['__opt']['sn_render_changed_gmt'] ?? 0 ) >= $t0 && true === ( $GLOBALS['__autoload']['sn_render_changed_gmt'] ?? null ), 'the hook stores the time, autoloaded' );
	ok( (int) $GLOBALS['__opt']['sn_render_changed_gmt'] === sn_seo_render_changed_gmt(), 'the validator reads that time, not the purge report' );
} else {
	ok( false, 'the hook stores the time, autoloaded' );
	ok( false, 'the validator reads that time, not the purge report' );
}
unset( $GLOBALS['__opt']['sn_render_changed_gmt'] );
ok( 4000 === sn_seo_render_changed_gmt(), 'before the first recorded flush, the purge report still answers' );
$GLOBALS['__opt'] = array();

echo "\nP1-2: a scheduled note going live moves the feed build date\n";
$scheduled = (object) array( 'post_date_gmt' => '2026-10-11 16:32:49', 'post_modified_gmt' => '2026-10-01 21:49:11' );
$older     = (object) array( 'post_date_gmt' => '2026-10-06 12:00:00', 'post_modified_gmt' => '2026-10-06 17:17:04' );
ok( function_exists( 'sn_seo_feed_build_gmt' ), 'the build-date rule is a named function' );
if ( function_exists( 'sn_seo_feed_build_gmt' ) ) {
	ok( strtotime( '2026-10-11 16:32:49 UTC' ) === sn_seo_feed_build_gmt( array( $scheduled, $older ) ), 'modified Oct 1, published Oct 11: the feed is built Oct 11, not Oct 6' );
	ok( strtotime( '2026-10-06 17:17:04 UTC' ) === sn_seo_feed_build_gmt( array( $older ) ), 'an edit after publishing still counts' );
	ok( 0 === sn_seo_feed_build_gmt( array( (object) array( 'post_date_gmt' => '0000-00-00 00:00:00', 'post_modified_gmt' => '' ) ) ), 'no usable date: 0, and core keeps its own' );
	$future = (object) array( 'post_date_gmt' => '2099-01-01 00:00:00', 'post_modified_gmt' => '2026-10-01 00:00:00' );
	ok( 1000 === sn_seo_feed_build_gmt( array( $future ), 1000 ), 'a future post_date (published early, keeping its scheduled date) never dates the feed after now' );

	global $wp_query;
	$wp_query        = new stdClass();
	$wp_query->posts = array( $scheduled, $older );
	ok( 'Sun, 11 Oct 2026 16:32:49 +0000' === sn_seo_feed_build_date_filter( 'Tue, 06 Oct 2026 17:17:04 +0000', 'r' ), 'lastBuildDate (format r) reads Oct 11' );
	ok( (string) strtotime( '2026-10-11 16:32:49 UTC' ) === (string) sn_seo_feed_build_date_filter( '1791307024', 'U' ), 'the conditional-GET handler (format U) gets Oct 11, so Last-Modified and the ETag move' );
	// 2026-10-13: an update now invalidates (marks the edge stale), and the edge
	// revalidates with the stored validators: a deploy must move them too.
	$GLOBALS['__opt']['sn_render_changed_gmt'] = strtotime( '2026-10-12 09:00:00 UTC' );
	ok( (string) strtotime( '2026-10-12 09:00:00 UTC' ) === (string) sn_seo_feed_build_date_filter( '1', 'U' ), 'a deploy after the newest note moves the feed Last-Modified and ETag, so a revalidation gets the new body, not a 304' );
	$GLOBALS['__opt']['sn_render_changed_gmt'] = strtotime( '2026-10-02 09:00:00 UTC' );
	ok( (string) strtotime( '2026-10-11 16:32:49 UTC' ) === (string) sn_seo_feed_build_date_filter( '1', 'U' ), 'a deploy before the newest note: the note date, as before' );
	$GLOBALS['__opt'] = array();
	$wp_query = null;
	ok( 'kept' === sn_seo_feed_build_date_filter( 'kept', 'r' ), 'no query: core value kept' );
}
$registered = false;
foreach ( $GLOBALS['__hooks']['get_feed_build_date'] ?? array() as $h ) {
	$registered = $registered || ( 'sn_seo_feed_build_date_filter' === $h[0] && 2 === $h[2] );
}
ok( $registered, 'the filter is registered on get_feed_build_date with both arguments' );

echo "\nP2-5: tag views get Open Graph and JSON-LD\n";
$og = null;
foreach ( $GLOBALS['__hooks']['wp_head'] ?? array() as $h ) {
	if ( 3 === $h[1] ) {
		$og = $h[0];
	}
}
foreach ( array( 'tags' => 'https://x.test/notes/tags/', 'tag' => 'https://x.test/tag/ai-music/' ) as $view => $url ) {
	$GLOBALS['__v'] = array( 'tags' => 'tags' === $view, 'tag' => 'tag' === $view );
	ob_start();
	if ( $og ) {
		$og();
	}
	$html = (string) ob_get_clean();
	ok( false !== strpos( $html, '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' ), "$view: og:title is the page's own title" );
	ok( false !== strpos( $html, '<meta property="og:type" content="website">' ), "$view: og:type website" );
	ok( false !== strpos( $html, '<meta property="og:url" content="' . $url . '">' ), "$view: og:url is the canonical" );
	ok( false !== strpos( $html, '<meta property="og:description"' ), "$view: og:description" );
	$cp = sn_schema_collection_page();
	ok( is_array( $cp ) && 'CollectionPage' === $cp['@type'] && $url === $cp['@id'] && $url === $cp['url'], "$view: a CollectionPage at the canonical" );
	$bc = sn_schema_breadcrumb_list();
	ok( is_array( $bc ) && $url . '#breadcrumb' === $bc['@id'] && is_array( $cp ) && $cp['breadcrumb']['@id'] === $bc['@id'], "$view: the breadcrumb the CollectionPage points at exists" );
	ok( is_array( $bc ) && ( 'tag' === $view ? 'AI & music' : 'Tags' ) === $bc['itemListElement'][2]['name'], "$view: the breadcrumb names the page by its raw name (no texturized &#038;)" );
	ok( is_array( $bc ) && 3 === count( $bc['itemListElement'] ) && 'https://x.test/notes/' === $bc['itemListElement'][1]['item'] && $url === $bc['itemListElement'][2]['item'], "$view: Home, Notes, then this page" );
}
$GLOBALS['__v'] = array( 'tags' => false, 'tag' => false );
ok( function_exists( 'sn_seo_head_view_wanted' ) && ! sn_seo_head_view_wanted(), 'off these views (and off front, home, singular) nothing new is emitted' );
ok( null === sn_schema_collection_page() && null === sn_schema_breadcrumb_list(), 'no CollectionPage or breadcrumb off the views' );
ok( 2 === substr_count( $seo_src . $schema_src, 'if ( ! sn_seo_head_view_wanted() ) {' ), 'the Open Graph and the JSON-LD emitters share one gate' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
