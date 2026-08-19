<?php
/**
 * Standalone tests for the corpus-inspection abilities (v10.6.0):
 * duplicate-body-scan, list-posts, get-post-content.
 *
 * Stub-fidelity notes (per the repo's stub-drift rules):
 *   - get_posts() FILTERS the fixture registry by post_type + post_status
 *     and applies posts_per_page — it models the transport's transform,
 *     not just the call.
 *   - wp_trim_words() models core: strips tags, normalizes whitespace to
 *     single spaces, appends $more only when it actually trimmed.
 *   - wp_get_post_terms() returns string[] for fields=names and a WP_Error
 *     for the configured failure post — the FAILURE shape is modeled too.
 *   - WP_Post-shaped fixtures are stdClass with the real field names.
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// ─── WP stubs (BEFORE the SUT loads) ─────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

// Fixture post factory — WP_Post field names, real shapes.
function tf_post( $id, $status, $content, $extra = array() ) {
	$p = new stdClass();
	$p->ID            = $id;
	$p->post_title    = $extra['title'] ?? "Post $id";
	$p->post_name     = $extra['slug'] ?? "post-$id";
	$p->post_status   = $status;
	$p->post_type     = $extra['post_type'] ?? 'post';
	$p->post_date     = $extra['date'] ?? '2026-07-0' . min( 9, $id ) . ' 10:00:00';
	$p->post_modified = $extra['modified'] ?? '2026-07-2' . min( 9, $id ) . ' 10:00:00';
	$p->post_content  = $content;
	$p->post_excerpt  = $extra['excerpt'] ?? '';
	return $p;
}

$BODY_A = "<!-- wp:paragraph -->\n<p>The signal argument, stated once.</p>\n<!-- /wp:paragraph -->";
$BODY_B = "<!-- wp:paragraph -->\n<p>A different note entirely — cuatro palabras más.</p>\n<!-- /wp:paragraph -->";

$GLOBALS['__posts'] = array(
	1  => tf_post( 1, 'publish', $BODY_A, array( 'title' => 'Original', 'slug' => 'original' ) ),
	2  => tf_post( 2, 'future', $BODY_A . "\n\n", array( 'title' => 'Scheduled dupe', 'slug' => 'scheduled-dupe' ) ), // trailing whitespace: must still group
	3  => tf_post( 3, 'draft', $BODY_B, array( 'excerpt' => 'Manual excerpt wins.' ) ),
	4  => tf_post( 4, 'draft', '' ),          // empty — never groups
	5  => tf_post( 5, 'pending', "  \n\t " ), // whitespace-only — never groups
	6  => tf_post( 6, 'publish', $BODY_A, array( 'post_type' => 'page', 'title' => 'Page twin' ) ), // other type: out of a 'post' scan
	7  => tf_post( 7, 'trash', $BODY_B ),     // trash: unreachable
	8  => tf_post( 8, 'inherit', $BODY_A, array( 'post_type' => 'revision' ) ), // internal type: unreachable
	9  => tf_post( 9, 'private', $BODY_B, array( 'title' => 'Private dupe of 3' ) ),
);

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$out = array();
		foreach ( $GLOBALS['__posts'] as $p ) {
			if ( $p->post_type !== ( $args['post_type'] ?? 'post' ) ) { continue; }
			if ( ! in_array( $p->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) { continue; }
			$out[] = $p;
		}
		$cap = (int) ( $args['posts_per_page'] ?? -1 );
		return $cap > 0 ? array_slice( $out, 0, $cap ) : $out;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'revision' ), true ); }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $t ) {
		if ( ! post_type_exists( $t ) ) { return null; }
		$o = new stdClass();
		$o->public = in_array( $t, array( 'post', 'page' ), true ); // revision registered but NOT public
		return $o;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $tax, $args = array() ) {
		if ( 9 === (int) $id ) { return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ); } // failure shape
		if ( 'category' === $tax ) { return array( 'Notes' ); }
		return 1 === (int) $id ? array( 'signal', 'writing' ) : array();
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
		return trim( strip_tags( $s ) );
	}
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num = 55, $more = '&hellip;' ) {
		$text  = wp_strip_all_tags( $text );
		$words = preg_split( '/[\s]+/', $text, $num + 1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) > $num ) {
			array_pop( $words );
			return implode( ' ', $words ) . $more;
		}
		return implode( ' ', $words );
	}
}

// ─── Load the SUT ────────────────────────────────────────────────────
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/abilities-corpus.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Corpus-inspection abilities — plugin v10.6.0\n\n";

// ─── Hash primitive ──────────────────────────────────────────────────
ok( snt_corpus_content_hash( $BODY_A ) === md5( $BODY_A ), 'hash is md5 of the body' );
ok( snt_corpus_content_hash( $BODY_A . "\n\n" ) === md5( $BODY_A ), 'trailing whitespace does not change the hash (trim)' );
ok( snt_corpus_content_hash( '' ) === '', 'empty body hashes to the empty sentinel' );
ok( snt_corpus_content_hash( "  \n\t " ) === '', 'whitespace-only body hashes to the empty sentinel' );

// ─── Word count + excerpt ────────────────────────────────────────────
ok( snt_corpus_word_count( $BODY_A ) === 5, 'word count strips block comments and tags (5 words)' );
ok( snt_corpus_word_count( '' ) === 0, 'empty content counts 0 words' );
ok( snt_corpus_word_count( "<!-- wp:x -->\n<p>más allá</p>\n<!-- /wp:x -->" ) === 2, 'unicode words counted correctly' );
ok( snt_corpus_excerpt( $GLOBALS['__posts'][3] ) === 'Manual excerpt wins.', 'manual excerpt wins when set' );
$fallback = snt_corpus_excerpt( $GLOBALS['__posts'][1] );
ok( false === strpos( $fallback, 'wp:' ) && false === strpos( $fallback, '<' ), 'fallback excerpt carries no block markup or tags' );
ok( false !== strpos( $fallback, 'signal argument' ), 'fallback excerpt carries the visible text' );

// ─── Duplicate body scan ─────────────────────────────────────────────
$scan = snt_corpus_duplicate_scan( 'post' );
ok( is_array( $scan ) && true === $scan['ok'], 'scan returns ok envelope' );
ok( 2 === $scan['group_count'] && 2 === count( $scan['groups'] ), 'exactly two duplicate groups (body A: 1+2, body B: 3+9)' );
$g = array( 'posts' => array() );
foreach ( $scan['groups'] as $grp ) { if ( md5( $BODY_A ) === $grp['content_hash'] ) { $g = $grp; } }
$g_ids = array_column( $g['posts'], 'post_id' );
sort( $g_ids );
ok( $g_ids === array( 1, 2 ), 'the group pairs the published original with the scheduled dupe' );
ok( ( $g['content_hash'] ?? '' ) === md5( $BODY_A ), 'group carries the shared content hash' );
$member = $g['posts'][0] ?? array();
ok( array_keys( $member ) === array( 'post_id', 'title', 'slug', 'status', 'post_date' ), 'group members carry exactly id/title/slug/status/date' );
$statuses_in_group = array_column( $g['posts'], 'status' );
sort( $statuses_in_group );
ok( $statuses_in_group === array( 'future', 'publish' ), 'the scan sees across statuses (future + publish in one group)' );
ok( 6 === $scan['posts_scanned'], 'scan walked the 6 non-trash post-type posts (trash, page, revision excluded)' );
ok( false === $scan['truncated'], 'scan reports untruncated below the cap' );
// Empty bodies never form a group even though posts 4 and 5 both hash empty.
foreach ( $scan['groups'] as $grp ) {
	ok( '' !== $grp['content_hash'], 'no group has the empty-content hash' );
}
$err = snt_corpus_duplicate_scan( 'revision' );
ok( is_wp_error( $err ) && 'snt_corpus_unknown_post_type' === $err->get_error_code(), 'internal post type is rejected even though registered' );
ok( is_wp_error( snt_corpus_duplicate_scan( 'nope' ) ), 'unknown post type is rejected' );

// ─── List posts ──────────────────────────────────────────────────────
$list = snt_corpus_list_posts( 'any', 'post' );
ok( true === $list['ok'] && 6 === $list['count'], 'list(any) returns all 6 corpus-status posts' );
$row = null;
foreach ( $list['posts'] as $r ) { if ( 1 === $r['post_id'] ) { $row = $r; } }
ok( is_array( $row ), 'the published original is in the listing' );
ok( array_keys( $row ) === array( 'post_id', 'title', 'slug', 'status', 'post_type', 'post_date', 'post_modified', 'categories', 'tags', 'word_count', 'content_hash', 'excerpt' ), 'row carries exactly the documented metadata fields, no body' );
ok( $row['content_hash'] === md5( $BODY_A ), 'row hash matches the duplicate-scan hash (same primitive)' );
ok( $row['tags'] === array( 'signal', 'writing' ) && $row['categories'] === array( 'Notes' ), 'terms resolved to names' );
ok( ! array_key_exists( 'content', $row ), 'listing never returns bodies' );
$drafts = snt_corpus_list_posts( 'draft', 'post' );
ok( 2 === $drafts['count'], 'status filter narrows to the 2 drafts' );
$priv_row = null;
foreach ( $list['posts'] as $r ) { if ( 9 === $r['post_id'] ) { $priv_row = $r; } }
ok( is_array( $priv_row ) && $priv_row['categories'] === array() && $priv_row['tags'] === array(), 'a WP_Error from the term lookup degrades to empty arrays, not a fatal' );
$bad = snt_corpus_list_posts( 'trash', 'post' );
ok( is_wp_error( $bad ) && 'snt_corpus_bad_status' === $bad->get_error_code(), 'trash is not a listable status' );
ok( is_wp_error( snt_corpus_list_posts( 'any', 'revision' ) ), 'listing rejects internal post types' );

// ─── Get post content ────────────────────────────────────────────────
$got = snt_corpus_get_post_content( array( 1, 2, 7, 8, 999, 1 ) );
ok( true === $got['ok'], 'content fetch returns ok envelope' );
ok( 2 === count( $got['posts'] ), 'duplicate input IDs collapse; 2 fetchable posts returned' );
$missing = $got['missing'];
sort( $missing );
ok( $missing === array( 7, 8, 999 ), 'trash, revision, and unknown IDs are reported missing — not silently dropped' );
ok( $got['posts'][0]['content'] === $BODY_A, 'full body is returned verbatim' );
ok( isset( $got['posts'][0]['content_hash'], $got['posts'][0]['excerpt'] ), 'content rows carry the metadata row too' );
ok( is_wp_error( snt_corpus_get_post_content( array() ) ), 'empty ID set is rejected' );
ok( is_wp_error( snt_corpus_get_post_content( range( 1, 21 ) ) ), '21 IDs exceed the cap and are rejected' );
ok( ! is_wp_error( snt_corpus_get_post_content( range( 1, 20 ) ) ), '20 IDs are exactly at the cap and accepted' );

// ─── Ability registration (capture wp_abilities_api_init) ───────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$expected = array( 'signal-noise/duplicate-body-scan', 'signal-noise/list-posts', 'signal-noise/get-post-content' );
foreach ( $expected as $slug ) {
	$a = $GLOBALS['__abilities'][ $slug ] ?? null;
	ok( is_array( $a ), "$slug is registered" );
	ok( 'snt_ability_perm_read_corpus' === ( $a['permission_callback'] ?? '' ), "$slug gates on edit_others_posts (corpus READ tier)" );
	ok( true === ( $a['meta']['annotations']['readonly'] ?? false ) && false === ( $a['meta']['annotations']['destructive'] ?? true ), "$slug is annotated readonly + non-destructive" );
	ok( 'tools' === ( $a['category'] ?? '' ), "$slug sits in the tools category" );
}
$gpc = $GLOBALS['__abilities']['signal-noise/get-post-content'];
ok( array( 'post_ids' ) === ( $gpc['input_schema']['required'] ?? array() ), 'get-post-content requires post_ids' );
ok( 20 === ( $gpc['input_schema']['properties']['post_ids']['maxItems'] ?? 0 ), 'get-post-content schema caps at 20 IDs' );

// Wrappers delegate to the live impls.
$w = snt_ability_corpus_duplicate_scan( array() );
ok( is_array( $w ) && 2 === $w['group_count'], 'duplicate-scan wrapper delegates (default post_type=post)' );
ok( is_wp_error( snt_ability_corpus_duplicate_scan( array( 'post_type' => 'revision' ) ) ), 'duplicate-scan wrapper surfaces the impl 422 for internal types' );
$w = snt_ability_corpus_list_posts( array( 'status' => 'future' ) );
ok( is_array( $w ) && 1 === $w['count'] && 'scheduled-dupe' === $w['posts'][0]['slug'], 'list-posts wrapper threads the status filter' );
$w = snt_ability_corpus_get_post_content( array( 'post_ids' => array( 2 ) ) );
ok( is_array( $w ) && 'Scheduled dupe' === ( $w['posts'][0]['title'] ?? '' ), 'get-post-content wrapper threads the ID set' );
ok( is_wp_error( snt_ability_corpus_get_post_content( null ) ), 'get-post-content wrapper rejects null input' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
