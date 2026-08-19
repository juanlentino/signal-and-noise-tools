<?php
/**
 * Standalone tests for the sn_posts consolidated ability (v10.26.0):
 * signal-noise/sn-posts. Absorbs list-posts + get-post-content; neither is
 * touched (new alongside old — verified against the live registrations by
 * tests/mcp-capabilities.php separately).
 *
 * Stub-fidelity notes (mirrors tests/abilities-corpus.php's own harness,
 * since this file reuses inc/corpus-inspect.php's real primitives):
 *   - get_posts() filters the fixture registry by post_type + post_status
 *     and applies posts_per_page — models the transport's transform.
 *   - WP_Post-shaped fixtures are stdClass with the real field names.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data( $code = '' ) { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

function tf_post( $id, $status, $post_type, $date, $modified, $extra = array() ) {
	$p = new stdClass();
	$p->ID            = $id;
	$p->post_title    = $extra['title'] ?? "Post $id";
	$p->post_name     = $extra['slug'] ?? "post-$id";
	$p->post_status   = $status;
	$p->post_type     = $post_type;
	$p->post_date     = $date;
	$p->post_modified = $modified;
	$p->post_content  = $extra['content'] ?? "Body of post $id.";
	$p->post_excerpt  = '';
	return $p;
}

$GLOBALS['__posts'] = array();
for ( $n = 1; $n <= 22; $n++ ) {
	$GLOBALS['__posts'][ $n ] = tf_post( $n, 'publish', 'post', sprintf( '2026-06-%02d 10:00:00', $n ), sprintf( '2026-07-%02d 10:00:00', $n ) );
}
$GLOBALS['__posts'][23] = tf_post( 23, 'draft', 'post', '2026-06-23 10:00:00', '2026-07-25 10:00:00', array( 'title' => 'Late draft' ) ); // newest-modified of all
$GLOBALS['__posts'][24] = tf_post( 24, 'publish', 'page', '2026-06-24 10:00:00', '2026-06-24 10:00:00', array( 'title' => 'A page', 'content' => 'Page body.' ) );
$GLOBALS['__posts'][25] = tf_post( 25, 'trash', 'post', '2026-06-25 10:00:00', '2026-06-25 10:00:00' );

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
		$o->public = in_array( $t, array( 'post', 'page' ), true );
		return $o;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num = 55, $more = '&hellip;' ) { return (string) $text; }
}

require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/abilities-sn-posts.php';

// ─── Telemetry wiring assert: pull in the real telemetry classifier + a wpdb
//     stand-in so one test proves a schema violation records outcome
//     schema_error through the REAL Layer B pipeline, not a re-implemented
//     assumption about it. ───────────────────────────────────────────────
class SN_Test_Wpdb_Posts {
	public $prefix = 'wp_'; public $insert_calls = array();
	public function get_charset_collate() { return 'utf8mb4'; }
	public function insert( $table, $data, $format = null ) { $this->insert_calls[] = array( 'table' => $table, 'data' => $data ); return 1; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Posts();
$wpdb            = $GLOBALS['wpdb'];
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { return true; } }
if ( ! function_exists( 'dbDelta' ) ) { function dbDelta( $sql ) {} }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $a, $b ) { return $b; } } // never fire the prune gate in this fixture.
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
require __DIR__ . '/../inc/mcp/mcp-telemetry.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_posts (consolidated) — plugin v10.26.0\n\n";

// ─── Cursor codec ──────────────────────────────────────────────────────
ok( 0 === snt_sn_posts_decode_cursor( null ), 'decode: null cursor -> offset 0' );
ok( 0 === snt_sn_posts_decode_cursor( '' ), 'decode: empty cursor -> offset 0' );
$enc = snt_sn_posts_encode_cursor( 5 );
ok( 5 === snt_sn_posts_decode_cursor( $enc ), 'encode/decode round-trips an offset' );
ok( null === snt_sn_posts_decode_cursor( 'not-valid-base64!!!' ), 'decode: malformed cursor -> null (signals reject)' );
ok( null === snt_sn_posts_decode_cursor( base64_encode( 'not-a-number' ) ), 'decode: base64-valid but non-numeric payload -> null' );

// ─── scope.kind validation ──────────────────────────────────────────────
$bad = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'nonsense' ) ) );
ok( is_wp_error( $bad ) && 'snt_posts_bad_scope' === $bad->get_error_code(), 'unknown scope.kind is rejected' );
ok( 422 === ( $bad->get_error_data()['status'] ?? null ), 'unknown scope.kind carries a 422 status' );

$bad_cursor = snt_ability_sn_posts( array( 'cursor' => '***' ) );
ok( is_wp_error( $bad_cursor ) && 'snt_posts_bad_cursor' === $bad_cursor->get_error_code(), 'malformed cursor is rejected (422)' );

// ─── scope.kind = 'all' (default), pagination ───────────────────────────
$page1 = snt_ability_sn_posts( array( 'max' => 5 ) );
ok( is_array( $page1 ) && true === $page1['ok'], 'default scope (all, post_type=post) returns an ok envelope' );
ok( 5 === $page1['count'], 'page size honors max=5' );
ok( true === $page1['has_more'], 'has_more is true when the walk exceeds one page' );
ok( is_string( $page1['cursor'] ), 'a non-empty next page carries a cursor string' );
ok( ! isset( $page1['posts'][0]['content'] ), 'include_content defaults false — no content field' );

$page2 = snt_ability_sn_posts( array( 'max' => 5, 'cursor' => $page1['cursor'] ) );
ok( 5 === $page2['count'], 'second page also returns 5' );
$ids_p1 = array_column( $page1['posts'], 'post_id' );
$ids_p2 = array_column( $page2['posts'], 'post_id' );
ok( empty( array_intersect( $ids_p1, $ids_p2 ) ), 'two pages never overlap' );
// Deterministic ordering: date DESC + ID DESC tie-break -> post 23 (draft,
// latest post_date is actually post 22's 06-22... wait: post 23's post_date
// is 06-23, the LATEST post_date of all 23 posts) sorts first.
ok( 23 === $page1['posts'][0]['post_id'], 'deterministic order: post 23 (latest post_date, incl. draft status) sorts first' );

// Malformed but "beyond range" cursor is a NATURAL empty page, not an error.
$beyond = snt_ability_sn_posts( array( 'cursor' => snt_sn_posts_encode_cursor( 9999 ) ) );
ok( is_array( $beyond ) && 0 === $beyond['count'] && false === $beyond['has_more'], 'an in-range-but-past-the-end cursor returns an empty page, not an error' );

// max clamps (never rejects) above the ceiling.
$clamped = snt_ability_sn_posts( array( 'max' => 500 ) );
ok( 23 === $clamped['count'] && false === $clamped['has_more'], 'max above the 100 ceiling clamps down; the whole 23-post "post" corpus fits one page' );

// ─── scope.kind = 'post_type' ────────────────────────────────────────────
$missing_type = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_type' ) ) );
ok( is_wp_error( $missing_type ) && 'snt_posts_bad_scope' === $missing_type->get_error_code(), 'post_type scope without a post_type is rejected' );

$pages_scope = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_type', 'post_type' => 'page' ), 'include_content' => true ) );
ok( 1 === $pages_scope['count'], 'post_type=page scope finds exactly the 1 page fixture' );
ok( 'Page body.' === ( $pages_scope['posts'][0]['content'] ?? '' ), 'include_content attaches the body when the scope is small enough' );

$bad_type = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_type', 'post_type' => 'revision' ) ) );
ok( is_wp_error( $bad_type ) && 'snt_corpus_unknown_post_type' === $bad_type->get_error_code(), 'internal post type is rejected via the REUSED snt_corpus_post_type_error()' );

// ─── scope.kind = 'modified_since' ──────────────────────────────────────
$since = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'modified_since', 'modified_since' => '2026-07-15 00:00:00' ) ) );
ok( is_array( $since ) && true === $since['ok'], 'modified_since scope returns an ok envelope' );
ok( 9 === $since['count'], 'modified_since=2026-07-15 matches posts 15..22 (8) plus post 23 (07-25) = 9' );
ok( 23 === $since['posts'][0]['post_id'], 'newest-modified sorts first (post 23, modified 07-25)' );
ok( 22 === $since['posts'][1]['post_id'], 'second-newest is post 22 (modified 07-22)' );

$bad_since = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'modified_since', 'modified_since' => 'not-a-date-at-all-xyz' ) ) );
ok( is_wp_error( $bad_since ) && 'snt_posts_bad_scope' === $bad_since->get_error_code(), 'unparseable modified_since is rejected (422)' );

// ─── scope.kind = 'post_ids' ─────────────────────────────────────────────
$by_ids = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array( 1, 2, 25, 999, 1 ) ) ) );
ok( is_array( $by_ids ) && true === $by_ids['ok'], 'post_ids scope returns an ok envelope' );
ok( 2 === $by_ids['count'], 'duplicate IDs collapse; 2 fetchable posts (trash + unknown excluded)' );
$missing = $by_ids['missing']; sort( $missing );
ok( $missing === array( 25, 999 ), 'trash and unknown IDs are reported missing, not silently dropped' );
ok( null === $by_ids['cursor'] && false === $by_ids['has_more'], 'post_ids scope is never paginated (its whole bounded set returns in one page)' );

$empty_ids = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_ids', 'post_ids' => array() ) ) );
ok( is_wp_error( $empty_ids ) && 'snt_posts_bad_scope' === $empty_ids->get_error_code(), 'post_ids scope with an empty array is rejected' );

// ─── include_content HARD cap: reject, never truncate ───────────────────
$cap_hit = snt_ability_sn_posts( array( 'include_content' => true ) ); // default scope resolves to 23 posts > 20
ok( is_wp_error( $cap_hit ) && 'snt_posts_content_cap_exceeded' === $cap_hit->get_error_code(), 'include_content:true over the 20-post cap is REJECTED' );
ok( 422 === ( $cap_hit->get_error_data()['status'] ?? null ), 'the content-cap rejection carries a 422 status' );

$many_ids = range( 1, 21 );
$cap_hit_ids = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_ids', 'post_ids' => $many_ids ), 'include_content' => true ) );
ok( is_wp_error( $cap_hit_ids ) && 'snt_posts_content_cap_exceeded' === $cap_hit_ids->get_error_code(), 'the 20-post cap also applies to an explicit post_ids scope (21 IDs)' );

$exactly_20 = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'post_ids', 'post_ids' => range( 1, 20 ) ), 'include_content' => true ) );
ok( ! is_wp_error( $exactly_20 ) && 20 === $exactly_20['count'], 'exactly 20 posts with include_content:true is accepted (the cap, not below it)' );

// Reject-never-truncate: confirm the rejection did NOT return a truncated 20-post page.
ok( is_wp_error( $cap_hit ), 'sanity re-confirm: the 23-post default scope with include_content is an outright error, never a silently truncated 20-row page' );

// ─── Ability registration ────────────────────────────────────────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$a = $GLOBALS['__abilities']['signal-noise/sn-posts'] ?? null;
ok( is_array( $a ), 'signal-noise/sn-posts is registered' );
ok( 'snt_ability_perm_read_corpus' === ( $a['permission_callback'] ?? '' ), 'sn-posts gates on edit_others_posts (corpus READ tier)' );
ok( true === ( $a['meta']['annotations']['readonly'] ?? false ) && false === ( $a['meta']['annotations']['destructive'] ?? true ) && true === ( $a['meta']['annotations']['idempotent'] ?? false ), 'sn-posts is annotated readonly + non-destructive + idempotent' );
ok( array( 'object', 'null' ) === ( $a['input_schema']['type'] ?? null ), 'sn-posts input schema is bodyless-GET-safe (no required fields)' );

// ─── Telemetry wiring: a REAL sn-posts schema violation, run through the
//     ACTUAL Layer B classifier (inc/mcp/mcp-telemetry.php's status-first
//     sn_mcp_telemetry_classify_wp_error()), records outcome schema_error —
//     not a hand-asserted stand-in for what the classifier would do. ───
$real_error = snt_ability_sn_posts( array( 'scope' => array( 'kind' => 'nonsense' ) ) );
ok( is_wp_error( $real_error ), 'sanity: the scenario really does produce a WP_Error' );
$classified = sn_mcp_telemetry_classify_wp_error( $real_error );
ok( 'schema_error' === $classified['outcome'], 'the REAL classifier scores this REAL sn-posts WP_Error (422, snt_posts_bad_scope) as schema_error' );
sn_mcp_telemetry_record( 'signal-noise__sn-posts', array( 'scope' => array( 'kind' => 'nonsense' ) ), 'read', $classified['outcome'], $classified['refusal_gate'], 3 );
ok( 1 === count( $wpdb->insert_calls ), 'telemetry wiring: one row recorded for the sn-posts schema-violation scenario' );
ok( 'schema_error' === $wpdb->insert_calls[0]['data']['outcome'], 'telemetry wiring: the inserted row carries outcome=schema_error end-to-end' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
