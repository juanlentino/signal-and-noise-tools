<?php
/**
 * CLI fixture for inc/tag-consolidation.php — detection + merge + history.
 * Standalone, global-stub style. Run: php tests/tag-consolidation.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

// --- WP stubs (term + option seams) -----------------------------------------
$GLOBALS['__terms']   = array(); // term_id => (object){term_id,name,slug,count,taxonomy}
$GLOBALS['__objects'] = array(); // term_id => [post_id,...]
$GLOBALS['__opts']    = array();
$GLOBALS['__setcalls']= array(); // [ [post_id, term_ids, tax, append] ]
$GLOBALS['__deleted'] = array(); // term_ids deleted

function get_terms( $args = array() ) {
	if ( ( $args['taxonomy'] ?? '' ) !== 'post_tag' ) { return array(); }
	return array_values( $GLOBALS['__terms'] );
}
function get_term( $id, $tax = '' ) { return $GLOBALS['__terms'][ (int) $id ] ?? null; }
function get_objects_in_term( $id, $tax ) { return $GLOBALS['__objects'][ (int) $id ] ?? array(); }
function wp_set_object_terms( $obj, $terms, $tax, $append = false ) { $GLOBALS['__setcalls'][] = array( $obj, $terms, $tax, $append ); return $terms; }
function wp_delete_term( $id, $tax, $args = array() ) { $GLOBALS['__deleted'][] = (int) $id; unset( $GLOBALS['__terms'][ (int) $id ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_current_user_id() { return 1; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public $msg; public function __construct( $c = '', $m = '' ) { $this->msg = $m; } public function get_error_message() { return $this->msg; } }
function __( $s, $d = null ) { return $s; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $s ) ) ); }
function remove_accents( $s ) { return strtr( (string) $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U' ) ); }
// Real impl lives in inc/tag-consolidation-redirects.php; capturing stub keeps the
// logic file testable standalone without loading the redirect module.
function sn_tag_redirects_record( $old, $canon ) { $m = $GLOBALS['__opts']['sn_tag_redirects'] ?? array(); foreach ( (array) $old as $s ) { $m[ $s ] = $canon; } $GLOBALS['__opts']['sn_tag_redirects'] = $m; }

require __DIR__ . '/../inc/tag-consolidation.php';

// --- normalize_key -----------------------------------------------------------
ok( sn_tag_normalize_key( 'AI-Generated Music' ) === sn_tag_normalize_key( 'AI Generated Music' ),
	'normalize: hyphen vs space collapse to the same key' );
ok( sn_tag_normalize_key( 'AI  Generated   Music' ) === sn_tag_normalize_key( 'ai generated music' ),
	'normalize: case + whitespace collapse' );
ok( sn_tag_normalize_key( 'Música' ) === sn_tag_normalize_key( 'Musica' ),
	'normalize: diacritics folded' );
ok( sn_tag_normalize_key( 'AI Generated Music' ) !== sn_tag_normalize_key( 'Jazz' ),
	'normalize: distinct names stay distinct' );

// --- find_duplicate_clusters -------------------------------------------------
$GLOBALS['__terms'] = array(
	10 => (object) array( 'term_id' => 10, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5, 'taxonomy' => 'post_tag' ),
	11 => (object) array( 'term_id' => 11, 'name' => 'AI Generated Music', 'slug' => 'ai-generated-music-2', 'count' => 2, 'taxonomy' => 'post_tag' ),
	12 => (object) array( 'term_id' => 12, 'name' => 'Music', 'slug' => 'music', 'count' => 9, 'taxonomy' => 'post_tag' ),
	13 => (object) array( 'term_id' => 13, 'name' => 'Muisc', 'slug' => 'muisc', 'count' => 1, 'taxonomy' => 'post_tag' ),
	14 => (object) array( 'term_id' => 14, 'name' => 'Jazz', 'slug' => 'jazz', 'count' => 4, 'taxonomy' => 'post_tag' ),
);
$clusters = sn_tag_find_duplicate_clusters();
ok( count( $clusters ) === 2, 'clusters: exactly two clusters (AI-music exact-dupe + music/muisc typo)' );
$ai = null; $mu = null;
foreach ( $clusters as $c ) {
	$ids = array_map( function ( $t ) { return $t['term_id']; }, $c['terms'] );
	if ( in_array( 10, $ids, true ) ) { $ai = $c; }
	if ( in_array( 12, $ids, true ) ) { $mu = $c; }
}
ok( $ai !== null && count( $ai['terms'] ) === 2 && $ai['suggested'] === 10,
	'clusters: AI-music dupe groups 10+11, suggests the higher-count term (10)' );
ok( $mu !== null && in_array( 13, array_map( function ( $t ) { return $t['term_id']; }, $mu['terms'] ), true ) && $mu['suggested'] === 12,
	'clusters: typo "Muisc" clusters with "Music", suggests "Music" (count 9)' );
$all_ids = array();
foreach ( $clusters as $c ) { foreach ( $c['terms'] as $t ) { $all_ids[] = $t['term_id']; } }
ok( ! in_array( 14, $all_ids, true ), 'clusters: singleton "Jazz" excluded' );

// --- merge_preview (no mutation) --------------------------------------------
$GLOBALS['__objects'] = array( 10 => array( 100, 101 ), 11 => array( 101, 102 ) );
$pv = sn_tag_merge_preview( array( 10, 11 ), 12 );
ok( $pv['posts_affected'] === 3 && $pv['into']['id'] === 12 && count( $pv['from'] ) === 2,
	'preview: counts 3 distinct affected posts, no mutation' );
ok( empty( $GLOBALS['__setcalls'] ) && empty( $GLOBALS['__deleted'] ), 'preview: mutated nothing' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
