<?php
/**
 * CLI fixture for inc/ai-tag-suggest.php — AI tag suggestion constrained to the
 * existing post_tag vocabulary. Run: php tests/ai-tag-suggest.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }
class WP_Error { public $c; public $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }

// AI seams.
$GLOBALS['__gate']      = null;   // null = available; WP_Error = not
$GLOBALS['__ai_out']    = '[]';
$GLOBALS['__ai_prompt'] = '';
function snt_ai_require_text_generation() { return $GLOBALS['__gate']; }
function snt_ai_generate_with_constraints( $prompt, $sys, $max = 256, $feat = 'generic' ) { $GLOBALS['__ai_prompt'] = $prompt; $GLOBALS['__ai_system'] = $sys; return $GLOBALS['__ai_out']; }
function snt_ai_extract_post_text( $id, $words = 400 ) { return $GLOBALS['__body'] ?? 'a post about jazz music'; }
function get_the_title( $id = 0 ) { return $GLOBALS['__title'] ?? 'Jazz piece'; }

// taxonomy seams.
$GLOBALS['__terms'] = array(
	(object) array( 'term_id' => 1, 'name' => 'Jazz', 'slug' => 'jazz', 'count' => 4 ),
	(object) array( 'term_id' => 2, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5 ),
);
function get_terms( $a = array() ) { return ( ( $a['taxonomy'] ?? '' ) === 'post_tag' ) ? $GLOBALS['__terms'] : array(); }
$GLOBALS['__assigned'] = array(); // term_ids the post already has
function has_term( $term, $tax, $post ) { return in_array( $term, $GLOBALS['__assigned'], true ); }

require __DIR__ . '/../inc/tag-consolidation.php'; // for sn_tag_normalize_key
require __DIR__ . '/../inc/ai-tag-suggest.php';

// match_to_vocab: keep only existing terms, drop hallucinations, case/format-insensitive
$m   = snt_ai_tag_match_to_vocab( array( 'jazz', 'AI Generated Music', 'NewInventedTag' ), $GLOBALS['__terms'] );
$ids = array_map( function ( $r ) { return $r['term_id']; }, $m );
ok( in_array( 1, $ids, true ) && in_array( 2, $ids, true ), 'match: "jazz" + "AI Generated Music" match existing terms (format-insensitive)' );
ok( ! in_array( 'NewInventedTag', array_map( function ( $r ) { return $r['name']; }, $m ), true ) && count( $m ) === 2, 'match: hallucinated tag dropped' );

// impl happy path
$GLOBALS['__ai_out'] = '["jazz","AI Generated Music"]';
$r = snt_ai_tag_suggest_impl( 7 );
ok( ! is_wp_error( $r ) && $r['ok'] === true, 'impl: ok' );
ok( count( $r['suggested'] ) === 2 && strpos( $GLOBALS['__ai_prompt'], 'Jazz' ) !== false, 'impl: 2 suggested + prompt carries the vocabulary' );
ok( strpos( $GLOBALS['__ai_system'], '3 to 4' ) !== false && stripos( $GLOBALS['__ai_system'], 'most relevant' ) !== false, 'impl: system instruction targets the 3-4 most relevant tags' );

// already-assigned excluded
$GLOBALS['__assigned'] = array( 1 ); // post already has Jazz
$r = snt_ai_tag_suggest_impl( 7 );
ok( count( $r['suggested'] ) === 1 && $r['suggested'][0]['term_id'] === 2, 'impl: already-assigned tag excluded' );
$GLOBALS['__assigned'] = array();

// gate: AI unavailable -> WP_Error
$GLOBALS['__gate'] = new WP_Error( 'x', 'no AI' );
ok( is_wp_error( snt_ai_tag_suggest_impl( 7 ) ), 'impl: WP_Error when AI unavailable' );
$GLOBALS['__gate'] = null;

// malformed/fenced JSON -> graceful
$GLOBALS['__ai_out'] = "Sure! ```json\n[\"jazz\"]\n``` hope that helps";
$r = snt_ai_tag_suggest_impl( 7 );
ok( ! is_wp_error( $r ) && count( $r['suggested'] ) === 1, 'impl: tolerates fenced JSON (extracts the array)' );
$GLOBALS['__ai_out'] = 'not json at all';
$r = snt_ai_tag_suggest_impl( 7 );
ok( ! is_wp_error( $r ) && $r['suggested'] === array(), 'impl: unparseable -> empty, not fatal' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
