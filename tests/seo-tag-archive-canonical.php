<?php
/**
 * Tests for the v13.14.0 tag-archive branch of sn_seo_meta_for_current_view().
 *
 * Tag archives had NO branch there, so they fell through every conditional and
 * the canonical emitter — which reads only $url — printed nothing. Measured on
 * the live site 2026-08-27: 23 indexable tag archives with no canonical, and
 * /tag/provenance/ vs /tag/provenance/page/2/ serving DIFFERENT notes under an
 * identical <title> with no canonical on either — the duplicate-content shape
 * the /notes/ branch fixed for itself in v5.1.0 and tags never got.
 *
 * Loads the REAL inc/seo.php with the same stub set as
 * tests/seo-notes-paged-canonical.php; only WordPress is faked.
 *
 * @since plugin v13.14.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

// ── View state, driven per case ─────────────────────────────────────
$GLOBALS['V'] = array( 'tag' => false, 'front' => false, 'page' => '', 'paged' => 1, 'term' => null, 'link' => '' );

function get_query_var( $k, $d = '' ) { return 'paged' === $k ? (int) $GLOBALS['V']['paged'] : $d; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function is_front_page() { return (bool) $GLOBALS['V']['front']; }
function is_page( $s = '' ) { return $GLOBALS['V']['page'] === $s; }
function is_home() { return false; }
function is_singular( $t = '' ) { return false; }
function is_tag() { return (bool) $GLOBALS['V']['tag']; }
function is_404() { return false; }
function get_queried_object() { return $GLOBALS['V']['term']; }
function term_description( $t = null ) { return is_object( $t ) && isset( $t->description ) ? $t->description : ''; }
function get_term_link( $t ) { return $GLOBALS['V']['link']; }
class SN_Tag_WP_Error {}
function is_wp_error( $x ) { return $x instanceof SN_Tag_WP_Error; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function sn_setting( $p, $d = null ) { return $d; }
function add_query_arg( $k, $v, $url ) {
	$sep = ( strpos( $url, '?' ) === false ) ? '?' : '&';
	return $url . $sep . $k . '=' . $v;
}
function add_action() {}
function add_filter() {}
function remove_action() {}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	function apply_filters_deprecated( $tag, $args, $version = '', $replacement = '', $message = '' ) { return apply_filters( $tag, ...$args ); }
}

require __DIR__ . '/../inc/seo.php';

function mk_term( $id, $slug, $name, $desc = '' ) {
	$t = new stdClass();
	$t->term_id = $id;
	$t->slug    = $slug;
	$t->name    = $name;
	$t->description = $desc;
	return $t;
}
function view_tag( $term, $link, $paged = 1 ) {
	$GLOBALS['V'] = array( 'tag' => true, 'front' => false, 'page' => '', 'paged' => $paged, 'term' => $term, 'link' => $link );
	return sn_seo_meta_for_current_view();
}

// ── 1. The defect this branch exists to fix ─────────────────────────
list( $t1, $d1, $u1 ) = view_tag( mk_term( 7, 'provenance', 'Provenance' ), 'https://example.com/tag/provenance/' );
ok( 'https://example.com/tag/provenance/' === $u1, 'page 1 canonicals to the tag URL (was: nothing emitted at all)' );

// ── 2. Paged archives self-canonical to the URL that actually serves ─
list( , , $u2 ) = view_tag( mk_term( 7, 'provenance', 'Provenance' ), 'https://example.com/tag/provenance/', 2 );
ok( 'https://example.com/tag/provenance/page/2/' === $u2, 'page 2 self-canonicals to the pretty /page/2/ URL' );
ok( false === strpos( $u2, 'paged=' ), 'no ?paged= query form leaks into a pretty-permalink canonical' );

// ── 3. Title deliberately left empty ────────────────────────────────
// document_title_parts returns WP's own parts untouched when this is '', and
// WP already renders the correct "Notes — <Tag> — Juan Lentino". A title here
// would REPLACE that string, so the branch supplies only what is missing.
ok( '' === $t1, 'title stays empty so the working WP-core <title> is not replaced' );

// ── 4. Description is the term's own; absence is never fabricated ───
ok( '' === $d1, 'a tag with no description emits none (never invented)' );
list( , $d4 ) = view_tag( mk_term( 8, 'ai-music', 'AI music', '  Where synthetic performance meets attribution.  ' ), 'https://example.com/tag/ai-music/' );
ok( 'Where synthetic performance meets attribution.' === $d4, 'a written description is used, trimmed' );
list( , $d5 ) = view_tag( mk_term( 9, 'x', 'X', '<p>Markup <em>stripped</em>.</p>' ), 'https://example.com/tag/x/' );
ok( 'Markup stripped.' === $d5, 'markup in a term description is stripped for meta' );

// ── 5. Failure degrades to today's behavior, never to a wrong canonical ─
list( , , $u6 ) = view_tag( null, 'https://example.com/tag/x/' );
ok( '' === $u6, 'no queried term → no canonical rather than a guessed one' );
list( , , $u7 ) = view_tag( mk_term( 7, 'p', 'P' ), new SN_Tag_WP_Error() );
ok( '' === $u7, 'a WP_Error from get_term_link yields no canonical' );

// ── 6. The branch is scoped: other views are untouched ──────────────
$GLOBALS['V'] = array( 'tag' => false, 'front' => true, 'page' => '', 'paged' => 1, 'term' => null, 'link' => '' );
list( , , $u8 ) = sn_seo_meta_for_current_view();
ok( 'https://example.com/' === $u8, 'front page still canonicals to home' );
$GLOBALS['V'] = array( 'tag' => false, 'front' => false, 'page' => 'notes', 'paged' => 3, 'term' => null, 'link' => '' );
list( , , $u9 ) = sn_seo_meta_for_current_view();
ok( 'https://example.com/notes/?paged=3' === $u9, "the /notes/ branch keeps its OWN ?paged= form (it is a Page, not a pretty archive)" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
