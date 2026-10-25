<?php
/**
 * Tests: the robots meta and the canonical ride core (wave 2, items 9 and 10).
 *
 * Core prints both: wp_robots() on wp_head:1 and rel_canonical() on wp_head:10.
 * The plugin feeds them through the wp_robots and get_canonical_url filters
 * and prints its own canonical only where core prints none (archives, tag
 * archives, /notes/tags/). This renders the head of every page kind with
 * core's REAL functions, lifted from robots-template.php and link-template.php
 * beside SNT_WP_HTML_API (the core-fingerprint.php pattern), and pins one
 * robots meta and exactly one canonical per kind (none on search, 404 and
 * MailPoet). Each row prints, so the suite doubles as the before/after table.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$inc  = getenv( 'SNT_WP_HTML_API' ) ? dirname( (string) getenv( 'SNT_WP_HTML_API' ) ) : '';
$core = array();
foreach ( array( 'robots-template.php' => array( 'wp_robots', 'wp_robots_noindex', 'wp_robots_noindex_embeds', 'wp_robots_noindex_search', 'wp_robots_no_robots', 'wp_robots_max_image_preview_large' ), 'link-template.php' => array( 'wp_get_canonical_url', 'rel_canonical' ) ) as $file => $names ) {
	$src = ( '' !== $inc && is_readable( "$inc/$file" ) ) ? (string) file_get_contents( "$inc/$file" ) : '';
	foreach ( $names as $name ) {
		$core[ $name ] = preg_match( '/^function ' . $name . '\(.*?^\}/ms', $src, $m ) ? $m[0] : '';
	}
}
if ( in_array( '', $core, true ) ) {
	if ( getenv( 'CI' ) ) {
		ok( false, 'core robots-template.php and link-template.php beside SNT_WP_HTML_API (tools/fetch-wp-test-core.sh)' );
	} else {
		echo "SKIP - no robots-template.php / link-template.php beside SNT_WP_HTML_API\n";
		ok( true, 'skipped locally without core' );
	}
	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
eval( implode( "\n", $core ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- core's own functions, lifted.

// ── A priority-ordered hook registry, faithful enough that core's and the
// plugin's registrations and removals behave as they do in WordPress.
$GLOBALS['__h'] = array();
$GLOBALS['__doing'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__h'][ $tag ][ $prio ][] = array( $cb, $args ); return true; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
function remove_filter( $tag, $cb, $prio = 10 ) {
	foreach ( $GLOBALS['__h'][ $tag ][ $prio ] ?? array() as $i => $row ) {
		if ( $row[0] === $cb ) { unset( $GLOBALS['__h'][ $tag ][ $prio ][ $i ] ); return true; }
	}
	return false;
}
function remove_action( $tag, $cb, $prio = 10 ) { return remove_filter( $tag, $cb, $prio ); }
function sh_has_hook( $tag, $cb ) {
	foreach ( $GLOBALS['__h'][ $tag ] ?? array() as $prio => $rows ) {
		foreach ( $rows as $row ) { if ( $row[0] === $cb ) { return $prio; } }
	}
	return false;
}
function sh_run( $tag, $value, $args, $keep = null ) {
	$hooks = $GLOBALS['__h'][ $tag ] ?? array();
	ksort( $hooks );
	$GLOBALS['__doing'][] = $tag;
	foreach ( $hooks as $rows ) {
		foreach ( $rows as $row ) {
			if ( null !== $keep && ! $keep( $row[0] ) ) { continue; }
			$out = call_user_func_array( $row[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $row[1] ) ) );
			$value = null === $keep ? $out : $value;
		}
	}
	array_pop( $GLOBALS['__doing'] );
	return $value;
}
function apply_filters( $tag, $value, ...$args ) { return sh_run( $tag, $value, $args ); }
function apply_filters_deprecated( $tag, $args ) { return $args[0]; }
function do_action( $tag, ...$args ) { sh_run( $tag, null, $args, static function () { return true; } ); }
function doing_action( $tag = null ) { return in_array( $tag, $GLOBALS['__doing'], true ); }

// ── The view. One array says which page kind this request is.
$GLOBALS['V'] = array();
function sh_v( $k, $d = null ) { return $GLOBALS['V'][ $k ] ?? $d; }
function is_singular( $t = '' ) { return (bool) sh_v( 'id' ) && ( '' === $t || in_array( sh_v( 'type' ), (array) $t, true ) ); }
function is_page( $s = '' ) { return is_singular( 'page' ) && ( '' === $s || $s === sh_v( 'slug' ) ); }
function is_front_page() { return (bool) sh_v( 'front' ); }
function is_home() { return (bool) sh_v( 'home' ); }
function is_tag() { return null !== sh_v( 'term' ); }
function is_search() { return (bool) sh_v( 'search' ); }
function is_embed() { return false; }
function is_404() { return (bool) sh_v( 'is404' ); }
function is_feed() { return false; }
function get_queried_object() { return sh_v( 'term' ) ?? ( sh_v( 'id' ) ? get_post( sh_v( 'id' ) ) : null ); }
function get_queried_object_id() { return (int) sh_v( 'id', 0 ); }
function get_post( $p = null ) {
	$id = is_object( $p ) ? (int) $p->ID : (int) $p;
	return ( $id && $id === (int) sh_v( 'id' ) ) ? (object) array( 'ID' => $id, 'post_type' => sh_v( 'type' ), 'post_name' => sh_v( 'slug', '' ), 'post_status' => 'publish', 'post_content' => '', 'post_excerpt' => '', 'post_title' => 'T' ) : null;
}
function get_post_status( $p = null ) { return 'publish'; }
function get_post_type( $p = null ) { $p = get_post( $p ); return $p ? $p->post_type : false; }
function get_permalink( $p = 0 ) { return (string) sh_v( 'permalink', '' ); }
function get_post_meta( $id, $k = '', $s = false ) { return sh_v( 'meta', array() )[ $k ] ?? ''; }
function get_query_var( $k, $d = '' ) { return sh_v( 'qv', array() )[ $k ] ?? $d; }
function get_option( $k, $d = false ) {
	$o = array( 'blog_public' => sh_v( 'blog_public', '1' ), 'permalink_structure' => '/%postname%/', 'page_on_front' => 2 );
	return $o[ $k ] ?? $d;
}
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function user_trailingslashit( $s, $t = '' ) { return trailingslashit( $s ); }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function get_comments_pagenum_link( $p = 1, $m = 0 ) { return ''; }
function get_term_link( $t, $tax = '' ) { return $t->link; }
function term_description( $t = 0, $d = null ) { return ''; }
function is_wp_error( $x ) { return false; }
function esc_url( $u ) { return (string) $u; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sn_setting( $k, $d = null ) { return $d; }
function get_the_title( $p = null ) { return 'Title'; }
function get_bloginfo( $k = '' ) { return 'Site'; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sn_notes_is_tags_request() { return (bool) sh_v( 'tags_req' ); }
function sn_sitemap_tag_is_hub( $c ) { return (int) $c >= 3; }
function sn_post_settings_get_canonical_url( $id ) { return (string) ( sh_v( 'meta', array() )['_sn_canonical_url'] ?? '' ); }

// Core's own registrations (default-filters.php), before any plugin loads.
foreach ( array( 'wp_robots_noindex', 'wp_robots_noindex_embeds', 'wp_robots_noindex_search', 'wp_robots_max_image_preview_large' ) as $cb ) {
	add_filter( 'wp_robots', $cb );
}
add_action( 'wp_head', 'wp_robots', 1 );
add_action( 'wp_head', 'rel_canonical' );

require __DIR__ . '/../inc/seo.php';

// The theme's notes-search noindex rides the plugin seam (theme inc/page-notes-template.php).
add_filter( 'sn_seo_robots_directives', static function ( $d ) {
	return '' === sh_v( 's', '' ) ? $d : array_merge( (array) $d, array( 'noindex', 'follow' ) );
} );

do_action( 'init' );

/** The head of one view: core's printers plus the plugin's head emitters. */
function sh_head( array $view ) {
	$GLOBALS['V'] = $view;
	ob_start();
	sh_run( 'wp_head', null, array(), static function ( $cb ) {
		if ( is_string( $cb ) && in_array( $cb, array( 'wp_robots', 'rel_canonical' ), true ) ) {
			return true;
		}
		return $cb instanceof Closure || is_string( $cb ) ? 'seo-head-parts.php' === basename( (string) ( new ReflectionFunction( $cb ) )->getFileName() ) : false;
	} );
	$html = (string) ob_get_clean();
	preg_match_all( '/<meta name=["\']robots["\'] content=["\']([^"\']*)["\']/', $html, $r );
	preg_match_all( '/<link rel=["\']canonical["\'] href=["\']([^"\']*)["\']/', $html, $c );
	return array( 'robots' => $r[1], 'canon' => $c[1], 'dirs' => isset( $r[1][0] ) ? preg_split( '/\s*,\s*/', $r[1][0] ) : array() );
}

$note  = array( 'id' => 10, 'type' => 'post', 'slug' => 'a-note', 'permalink' => 'https://example.com/notes/a-note/' );
$notes = array( 'id' => 30, 'type' => 'page', 'slug' => 'notes', 'permalink' => 'https://example.com/notes/' );
$about = array( 'id' => 20, 'type' => 'page', 'slug' => 'about', 'permalink' => 'https://example.com/about/' );
$views = array(
	'home'                  => array( array( 'id' => 2, 'type' => 'page', 'slug' => 'home', 'front' => true, 'permalink' => 'https://example.com/' ), 'https://example.com/', array(), array( 'noindex' ) ),
	'a note'                => array( $note, 'https://example.com/notes/a-note/', array(), array( 'noindex' ) ),
	'a note, nextpage 2'    => array( $note + array( 'qv' => array( 'page' => 2 ) ), 'https://example.com/notes/a-note/2/', array(), array() ),
	'a note, canonical set' => array( $note + array( 'meta' => array( '_sn_canonical_url' => 'https://example.org/original/' ) ), 'https://example.org/original/', array(), array() ),
	'a page'                => array( $about, 'https://example.com/about/', array(), array( 'noindex' ) ),
	'a page, not public'    => array( $about + array( 'blog_public' => '0' ), 'https://example.com/about/', array( 'noindex', 'nofollow' ), array( 'follow' ) ),
	'hub tag archive'       => array( array( 'term' => (object) array( 'term_id' => 5, 'count' => 5, 'link' => 'https://example.com/tag/c2pa/' ) ), 'https://example.com/tag/c2pa/', array(), array( 'noindex' ) ),
	'thin tag archive'      => array( array( 'term' => (object) array( 'term_id' => 6, 'count' => 2, 'link' => 'https://example.com/tag/thin/' ) ), 'https://example.com/tag/thin/', array( 'noindex' ), array() ),
	'/notes/page/2/ (Page)' => array( $notes + array( 'qv' => array( 'paged' => 2 ) ), 'https://example.com/notes/?paged=2', array(), array( 'noindex' ) ),
	'/notes/page/2/ (home)' => array( array( 'home' => true, 'qv' => array( 'paged' => 2 ) ), 'https://example.com/notes/?paged=2', array(), array( 'noindex' ) ),
	'/notes/tags/'          => array( array( 'tags_req' => true ), 'https://example.com/notes/tags/', array(), array( 'noindex' ) ),
	'search, /notes/?s='    => array( $notes + array( 's' => 'x' ), 'https://example.com/notes/', array( 'noindex', 'follow' ), array() ),
	'search, core ?s='      => array( array( 'search' => true ), '', array( 'noindex', 'follow' ), array() ),
	'search, not public'    => array( $notes + array( 's' => 'x', 'blog_public' => '0' ), 'https://example.com/notes/', array( 'noindex', 'nofollow' ), array( 'follow' ) ),
	'404'                   => array( array( 'is404' => true ), '', array(), array( 'noindex' ) ),
	'MailPoet page'         => array( array( 'id' => 40, 'type' => 'mailpoet_page', 'slug' => 'subscriptions', 'permalink' => 'https://example.com/?mailpoet_page=subscriptions' ), '', array( 'noindex' ), array() ),
);
echo "Group: one robots meta and exactly one canonical per page kind\n";
foreach ( $views as $label => list( $view, $canon, $has, $lacks ) ) {
	$h = sh_head( $view );
	echo "  | $label | " . ( $h['robots'][0] ?? '(none)' ) . ' | ' . ( implode( ' + ', $h['canon'] ) ?: '(none)' ) . " |\n";
	ok( 1 === count( $h['robots'] ), "$label: one robots meta" );
	ok( ( '' === $canon ? array() : array( $canon ) ) === $h['canon'], "$label: " . ( '' === $canon ? 'no canonical' : "exactly one canonical, $canon" ) );
	foreach ( array( 'max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1' ) as $d ) {
		ok( in_array( $d, $h['dirs'], true ), "$label: $d" );
	}
	foreach ( $has as $d ) { ok( in_array( $d, $h['dirs'], true ), "$label: $d" ); }
	foreach ( $lacks as $d ) { ok( ! in_array( $d, $h['dirs'], true ), "$label: no $d" ); }
}

echo "\nGroup: core prints, the plugin feeds it\n";
ok( 1 === sh_has_hook( 'wp_head', 'wp_robots' ), 'core wp_robots() stays on wp_head:1' );
ok( 10 === sh_has_hook( 'wp_head', 'rel_canonical' ), 'core rel_canonical() stays on wp_head:10' );
$GLOBALS['V'] = $about;
ob_start();
wp_robots(); // As core calls it from login_head, embed_head and wp_die: not in wp_head.
$off = (string) ob_get_clean();
ok( false === strpos( $off, 'max-snippet' ), 'outside wp_head (login, embed, wp_die) the plugin adds nothing to core robots' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
