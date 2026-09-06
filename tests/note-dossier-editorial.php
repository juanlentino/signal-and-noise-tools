<?php
/**
 * Standalone test: inc/note-dossier-editorial.php — tags, reading time,
 * word count, the excerpt served to agents, and related notes from the
 * plugin's own kernel, never the theme's backfilling query.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_READING_TIME_META_KEY', '_sn_reading_time_minutes' );
define( 'SN_READING_TIME_DEFAULT_WPM', 225 );
define( 'SNT_ML_RELATED_META', '_snt_ml_related' );
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function apply_filters( $h, $v ) { return 'sn_reading_time_wpm' === $h ? ( $GLOBALS['__wpm'] ?? $v ) : $v; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ (int) $id ][ $key ] ?? ''; }
function get_the_title( $p = 0 ) { return 'Note ' . (int) $p; }
function wp_get_post_terms( $id, $tax, $args = array() ) { $GLOBALS['__terms_reads'][] = (int) $id; return $GLOBALS['__tags']; }
function is_object_in_taxonomy( $object_type, $taxonomy ) { return 'post_tag' === $taxonomy && 'post' === $object_type; }
function sn_prov_subject_kind( $post ) { return 'page' === (string) $post->post_type ? 'page' : 'note'; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public $code; public function __construct( $c = '' ) { $this->code = $c; } }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public $post_content = ''; public $post_excerpt = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array(
	7 => new WP_Post( array( 'ID' => 7, 'post_content' => '<!-- wp:paragraph --><p>one two three four</p><!-- /wp:paragraph -->' ) ),
	9 => new WP_Post( array( 'ID' => 9, 'post_type' => 'page', 'post_content' => '<p>one two three four</p>' ) ),
);
$GLOBALS['__terms_reads'] = array();
function snt_corpus_word_count( $c ) { return 4; }
function snt_corpus_excerpt( $p ) { return 'one two three four'; }
function snt_ml_related_for_post( $id, $limit ) { return $GLOBALS['__related']; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-editorial.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function by_heading( $blocks, $h ) { foreach ( $blocks as $b ) { if ( $b['heading'] === $h ) { return $b; } } return null; }
function tile( $block, $label ) { foreach ( $block['tiles'] as $t ) { if ( $t['label'] === $label ) { return $t; } } return null; }
echo "note dossier -- editorial\n\n";

$GLOBALS['__meta']    = array( 7 => array( '_sn_reading_time_minutes' => '3' ) );
$GLOBALS['__tags']    = array( 'provenance', 'signatures' );
$GLOBALS['__related'] = array( array( 'post_id' => 11, 'score' => 0.42 ), array( 'post_id' => 12, 'score' => 0.31 ) );
$b = sn_note_dossier_editorial( 7 );
$e = by_heading( $b, 'Editorial' );
ok( 'stats' === $e['kind'] && '3 min' === tile( $e, 'Reading time' )['value'] && '4' === tile( $e, 'Words' )['value'] && '2' === tile( $e, 'Tags' )['value'], 'reading time from the meta, the word count, the tag count' );
$GLOBALS['__wpm'] = 180;
ok( false !== strpos( tile( by_heading( sn_note_dossier_editorial( 7 ), 'Editorial' ), 'Reading time' )['note'], 'at 180 words' ), 'the pace named is the FILTERED sn_reading_time_wpm, not the default' );
unset( $GLOBALS['__wpm'] );
ok( false !== strpos( tile( $e, 'Words' )['note'], 'whitespace' ), 'the word count names its counter, because reading time uses another' );
ok( 'provenance, signatures' === by_heading( $b, 'Tags' )['text'], 'tags listed by name' );
ok( 'one two three four' === by_heading( $b, 'Excerpt served to agents' )['text'] && false !== strpos( by_heading( $b, 'Excerpt served to agents' )['source'], 'sn-posts' ), 'the excerpt is the one agents get, and says which' );
$r = by_heading( $b, 'Related notes' );
ok( 'table' === $r['kind'] && 2 === count( $r['rows'] ) && 'Note 11' === $r['rows'][0]['title'] && '0.42' === $r['rows'][0]['score'], 'related notes from the kernel with their scores' );

echo "\nthe absences\n";
$GLOBALS['__meta'] = array();
$GLOBALS['__tags'] = array();
$GLOBALS['__related'] = array();
$GLOBALS['__meta'][7]['_snt_ml_related'] = array();
$b = sn_note_dossier_editorial( 7 );
ok( '—' === tile( by_heading( $b, 'Editorial' ), 'Reading time' )['value'] && false !== strpos( tile( by_heading( $b, 'Editorial' ), 'Reading time' )['note'], 'not computed' ), 'no cached reading time: not computed yet, and the getter is NOT called (it writes meta)' );
ok( false !== strpos( by_heading( $b, 'Tags' )['text'], 'Untagged' ), 'no tags says untagged' );
ok( 'text' === by_heading( $b, 'Related notes' )['kind'] && false !== strpos( by_heading( $b, 'Related notes' )['text'], 'None' ), 'the kernel answered "none": said so' );
unset( $GLOBALS['__meta'][7]['_snt_ml_related'] );
$b = sn_note_dossier_editorial( 7 );
ok( false !== strpos( by_heading( $b, 'Related notes' )['text'], 'Not in the kernel' ) && false === strpos( by_heading( $b, 'Related notes' )['text'], 'None' ), 'the same empty answer for a note the kernel never indexed is named as unindexed, never as "none related"' );
ok( 'the post' === by_heading( $b, 'Tags' )['source'], 'the Tags block names its source' );
$GLOBALS['__related'] = null;
$b = sn_note_dossier_editorial( 7 );
ok( null === by_heading( $b, 'Related notes' ), 'the kernel not built: the block is omitted, not faked' );
$GLOBALS['__tags'] = new WP_Error( 'x' );
$b = sn_note_dossier_editorial( 7 );
ok( false !== strpos( by_heading( $b, 'Tags' )['text'], 'could not be read' ) && '—' === tile( by_heading( $b, 'Editorial' ), 'Tags' )['value'], 'a taxonomy error is named, never "untagged"' );
ok( array() === sn_note_dossier_editorial( 999 ), 'no post, no blocks' );

echo "\na signed page: the two blocks that cannot be measured are OMITTED, not answered\n";
$GLOBALS['__tags']        = array( 'provenance' );
$GLOBALS['__related']     = array( array( 'post_id' => 11, 'score' => 0.42 ) );
$GLOBALS['__terms_reads'] = array();
$b = sn_note_dossier_editorial( 9 );
ok( null === by_heading( $b, 'Tags' ), 'a page has no Tags block: pages are not in post_tag, so "Untagged." would have read as a measurement' );
ok( null === tile( by_heading( $b, 'Editorial' ), 'Tags' ) && 2 === count( by_heading( $b, 'Editorial' )['tiles'] ), '   ...and no Tags tile either: reading time and words only' );
ok( array() === $GLOBALS['__terms_reads'], '   ...the taxonomy was never read for a page -- the type is asked BEFORE the query, so an empty answer can never be mistaken for none' );
ok( null === by_heading( $b, 'Related notes' ), 'a page has no Related block: the kernel indexes notes, so its empty answer would have promised an index that is not coming' );
ok( 'one two three four' === by_heading( $b, 'Excerpt served to agents' )['text'], 'what a page CAN be measured for is still measured' );
$GLOBALS['__terms_reads'] = array();
$b = sn_note_dossier_editorial( 7 );
ok( array( 7 ) === $GLOBALS['__terms_reads'] && null !== by_heading( $b, 'Tags' ) && null !== by_heading( $b, 'Related notes' ), 'negative control: a NOTE still reads its tags and still gets both blocks' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
