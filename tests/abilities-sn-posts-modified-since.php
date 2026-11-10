<?php
/**
 * sn-posts, scope.kind "modified_since": the page, its order and its cursor.
 *
 * The filter, the order and the page moved from PHP over the whole corpus to
 * core (WP_Query: date_query on post_modified_gmt, orderby modified + ID,
 * offset, found_posts). Group A pins the OUTPUT and passes against both
 * versions; group B pins that core was asked, and fails against the PHP walk.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function add_action( $tag, $cb, $p = 10, $a = 1 ) { return true; }
function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page' ), true ); }
function get_post_type_object( $t ) { return post_type_exists( $t ) ? (object) array( 'public' => true ) : null; }
function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $text, $num = 55, $more = '' ) { return (string) $text; }
function get_post_meta( $id, $key = '', $single = false ) { return ''; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }

/** Fixture: site zone UTC-4, so post_modified = post_modified_gmt minus 4h. */
function tf_post( $id, $status, $type, $date, $modified_gmt ) {
	return (object) array(
		'ID' => $id, 'post_title' => "Post $id", 'post_name' => "post-$id", 'post_status' => $status, 'post_type' => $type,
		'post_date' => $date, 'post_modified' => gmdate( 'Y-m-d H:i:s', strtotime( $modified_gmt . ' UTC' ) - 4 * 3600 ),
		'post_modified_gmt' => $modified_gmt, 'post_content' => "Body $id.", 'post_excerpt' => '',
	);
}
$GLOBALS['__posts'] = array(
	1 => tf_post( 1, 'publish', 'post', '2026-06-01 10:00:00', '2026-08-31 23:59:59' ), // one second before the cutoff
	2 => tf_post( 2, 'publish', 'post', '2026-06-09 10:00:00', '2026-09-01 00:00:00' ), // exactly on it: inclusive
	3 => tf_post( 3, 'draft', 'post', '2026-06-03 10:00:00', '2026-09-05 12:00:00' ),
	4 => tf_post( 4, 'publish', 'post', '2026-06-04 10:00:00', '2026-09-05 12:00:00' ), // ties with 3 and 7: ID DESC
	5 => tf_post( 5, 'future', 'post', '2026-12-01 10:00:00', '2026-09-03 08:00:00' ),
	6 => tf_post( 6, 'trash', 'post', '2026-06-06 10:00:00', '2026-09-09 09:00:00' ),  // never a corpus status
	7 => tf_post( 7, 'private', 'post', '2026-06-07 10:00:00', '2026-09-05 12:00:00' ),
	8 => tf_post( 8, 'publish', 'page', '2026-06-08 10:00:00', '2026-09-08 10:00:00' ), // another post type
	9 => tf_post( 9, 'pending', 'post', '2026-06-02 10:00:00', '2026-09-02 03:30:00' ), // 2026-09-01 23:30 site-local
);
// The pre-change code walks get_posts(); model it as the sibling suite does.
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['__posts'] as $p ) {
		if ( $p->post_type !== ( $args['post_type'] ?? 'post' ) ) { continue; }
		if ( ! in_array( $p->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) { continue; }
		$out[] = $p;
	}
	usort( $out, static function ( $a, $b ) { return strcmp( $b->post_date, $a->post_date ); } );
	return $out;
}
require __DIR__ . '/lib/wp-query-corpus-stub.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/abilities-sn-posts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function eq( $e, $a, $m ) { ok( $e === $a, $m . ( $e === $a ? '' : ' (expected ' . json_encode( $e ) . ', got ' . json_encode( $a ) . ')' ) ); }
function since( array $extra = array() ) {
	return snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'modified_since', 'modified_since' => '2026-09-01T00:00:00Z' ) ) + $extra );
}
function ids( $r ) { return array_column( $r['posts'], 'post_id' ); }

echo "A. Output pins (same before and after)\n";
$all = since();
eq( array( 7, 4, 3, 5, 9, 2 ), ids( $all ), 'newest modified first, ID DESC inside a tie, the cutoff instant included, trash and pages left out' );
eq( array( 6, null, false ), array( $all['count'], $all['cursor'], $all['has_more'] ), 'one page: count 6, no cursor, has_more false' );
eq( array( 'ok', 'posts', 'count', 'missing', 'filtered', 'cursor', 'has_more' ), array_keys( $all ), 'envelope keys and their order' );
eq( '2026-09-05 08:00:00', $all['posts'][0]['post_modified'], 'rows still carry the site-local post_modified' );

$p1 = since( array( 'max' => 4 ) );
eq( array( 7, 4, 3, 5 ), ids( $p1 ), 'page 1 of max 4' );
eq( array( base64_encode( '4' ), true ), array( $p1['cursor'], $p1['has_more'] ), 'page 1 hands back cursor base64("4")' );
$p2 = since( array( 'max' => 4, 'cursor' => $p1['cursor'] ) );
eq( array( 9, 2 ), ids( $p2 ), 'page 2 picks up at the cursor with no overlap' );
eq( array( null, false ), array( $p2['cursor'], $p2['has_more'] ), 'the last page ends the walk' );
$past = since( array( 'max' => 4, 'cursor' => base64_encode( '40' ) ) );
eq( array( array(), 0, null, false ), array( $past['posts'], $past['count'], $past['cursor'], $past['has_more'] ), 'a cursor past the end is an empty last page' );

$st = since( array( 'status' => array( 'publish', 'future' ), 'max' => 2 ) );
eq( array( 4, 5 ), ids( $st ), 'the status filter runs before the page is cut' );
eq( array( base64_encode( '2' ), true ), array( $st['cursor'], $st['has_more'] ), '...and before the count: one more publish row is left' );
eq( array( 2 ), ids( since( array( 'status' => array( 'publish', 'future' ), 'max' => 2, 'cursor' => $st['cursor'] ) ) ), '...which the next page returns' );

$page = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'modified_since', 'modified_since' => '2026-09-01', 'post_type' => 'page' ) ) );
eq( array( 8 ), ids( $page ), 'scope.post_type narrows the walk' );
eq( array(), ids( snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'modified_since', 'modified_since' => '2027-01-01' ) ) ) ), 'a cutoff after every post is an empty ok page' );

for ( $n = 100; $n < 120; $n++ ) { $GLOBALS['__posts'][ $n ] = tf_post( $n, 'publish', 'post', '2026-07-01 10:00:00', '2026-10-01 00:00:00' ); }
$cap = since( array( 'include_content' => true, 'max' => 5 ) );
ok( is_wp_error( $cap ) && 'snt_posts_content_cap_exceeded' === $cap->get_error_code(), 'include_content over 20 matches is refused, never truncated' );
ok( is_wp_error( $cap ) && false !== strpos( $cap->message, '(26)' ), '...and the refusal names the full match count, not the page size' );
$cap_past = since( array( 'include_content' => true, 'max' => 5, 'cursor' => base64_encode( '40' ) ) );
ok( is_wp_error( $cap_past ) && 'snt_posts_content_cap_exceeded' === $cap_past->get_error_code(), '...even from a cursor past the end' );
$ok20 = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'modified_since', 'modified_since' => '2026-10-01' ), 'include_content' => true ) );
ok( is_array( $ok20 ) && 20 === $ok20['count'] && 'Body 119.' === $ok20['posts'][0]['content'], 'exactly 20 matches return with content' );

echo "\nB. Core is asked (fails against the PHP walk)\n";
$GLOBALS['__wp_query_args'] = array();
since( array( 'max' => 4, 'cursor' => base64_encode( '4' ), 'status' => array( 'draft' ) ) );
$q = $GLOBALS['__wp_query_args'][0] ?? array();
eq( array( array( 'column' => 'post_modified_gmt', 'after' => array( 'year' => 2026, 'month' => 9, 'day' => 1, 'hour' => 0, 'minute' => 0, 'second' => 0 ), 'inclusive' => true ) ), $q['date_query'] ?? null, 'date_query on post_modified_gmt, the UTC cutoff as parts (no site-zone parse), inclusive' );
eq( array( 'modified' => 'DESC', 'ID' => 'DESC' ), $q['orderby'] ?? null, 'core orders: modified DESC, ID DESC' );
eq( array( 4, 4, array( 'draft' ) ), array( $q['posts_per_page'] ?? null, $q['offset'] ?? null, $q['post_status'] ?? null ), 'core pages and filters status' );
ok( ! empty( $q['suppress_filters'] ) && ! empty( $q['ignore_sticky_posts'] ) && empty( $q['no_found_rows'] ), 'the get_posts() guards are kept, and found_posts is on' );
ok( function_exists( 'snt_corpus_gmt_date_parts' ) && array( 'year' => 2026, 'month' => 3, 'day' => 8, 'hour' => 7, 'minute' => 30, 'second' => 5 ) === snt_corpus_gmt_date_parts( gmmktime( 7, 30, 5, 3, 8, 2026 ) ), 'the parts helper reads the instant in UTC' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
