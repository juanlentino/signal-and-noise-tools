<?php
/**
 * Tag fit (16.8.0): the pool, the state, a Score per attached tag and a
 * Noul per candidate, the judge's two lines, the pass over a fake
 * transport, check 31, the two abilities. Run: php tests/jev-tags.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__k'] = array( 'opt' => array(), 'posts' => array(), 'tags' => array(), 'terms' => array(), 'calls' => array(), 'answer' => null, 'actions' => array(), 'abilities' => array(), 'scheduled' => array(), 'key' => 'k' );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__k']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__k']['opt'] ) ? $GLOBALS['__k']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__k']['opt'][ $k ] = $v; return true; }
function get_post( $id ) { return $GLOBALS['__k']['posts'][ $id ] ?? null; }
function get_permalink( $id ) { return "https://x.test/notes/n$id/"; }
function admin_url( $p ) { return 'https://x.test/wp-admin/' . $p; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function strip_shortcodes( $s ) { return $s; }
function wp_next_scheduled( $h ) { return $GLOBALS['__k']['scheduled'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__k']['scheduled'][ $h ] = $r; return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__k']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) { return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => $skipped ); }
function get_terms( $a ) { return $GLOBALS['__k']['terms']; }
function wp_get_post_tags( $id, $a = array() ) { return $GLOBALS['__k']['tags'][ $id ] ?? array(); }
function sn_jev_note_ids() { return array_keys( $GLOBALS['__k']['posts'] ); }
function sn_jev_key() { return $GLOBALS['__k']['key']; }
function sn_jev_is_ready() { return '' !== sn_jev_key(); }
if ( ! defined( 'SN_JEV_NOT_READY' ) ) { define( 'SN_JEV_NOT_READY', 'Install the connector.' ); }
function sn_jev_ask( $state, array $questions, $feature = 'other' ) { $GLOBALS['__k']['calls'][] = compact( 'state', 'questions', 'feature' ); $a = $GLOBALS['__k']['answer']; return is_callable( $a ) ? $a( $state, $questions ) : $a; }
function term( $id, $name, $desc, $count = 1 ) { $t = new stdClass(); $t->term_id = $id; $t->name = $name; $t->description = $desc; $t->count = $count; return $t; }
function mkpost( $id, $title, $tags ) { $p = new stdClass(); $p->ID = $id; $p->post_type = 'post'; $p->post_status = 'publish'; $p->post_title = $title; $p->post_content = "<p>Body of $title.</p>"; $p->post_excerpt = "About $title."; $GLOBALS['__k']['posts'][ $id ] = $p; $GLOBALS['__k']['tags'][ $id ] = $tags; return $p; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/jev-tags.php';
require __DIR__ . '/../inc/health-check-jev-tags.php';
require __DIR__ . '/../inc/abilities-jev.php';
foreach ( $GLOBALS['__k']['actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

// A: pool, state, questions.
$GLOBALS['__k']['terms'] = array( term( 10, 'provenance', 'Why provenance beats detection.', 30 ), term( 20, 'royalties', 'Streaming royalties and who gets paid.', 12 ), term( 30, 'orphan', '', 0 ) );
$pool = sn_jev_tag_pool();
ok( array( 10, 20, 30 ) === array_keys( $pool ) && 'provenance' === $pool[10]['name'] && '' === $pool[30]['description'], 'A1 the pool keeps every tag with name, description and count, most used first' );
mkpost( 1, 'Signing masters', array( 10, 20 ) );
$st = sn_jev_tags_state( get_post( 1 ), array( 10, 20 ), $pool );
ok( 'Signing masters' === $st['note']['title'] && array( 't10', 't20' ) === array_keys( $st['tags'] ) && 'Why provenance beats detection.' === $st['tags']['t10']['description'], 'A2 state: the note and its attached tags keyed tID; no candidates since 16.9.2' );
$q = sn_jev_tags_questions( array( 10, 20 ) );
ok( array( 'a10', 'a20' ) === array_keys( $q ) && 'score' === $q['a10']['type'] && 3 === count( $q['a10']['criteria'] ) && str_contains( $q['a10']['instructions']['question'], 'touch what `tags.t10` names' ) && str_contains( $q['a10']['criteria'][0]['summary'], 'absent' ), 'A3 a Score per attached tag, the house question (touch, not argue), 0 is absent' );
ok( ! array_filter( $q, static fn( $qq ) => 'noul' === $qq['type'] ), 'A4 no Noul: Jev proposes no tags' );

// B: judge.
$ans = array( 'a10' => array( 'type' => 'score', 'score' => 1.8, 'confidence' => 0.9 ), 'a20' => array( 'type' => 'score', 'score' => 0.4, 'confidence' => 0.7 ), 'a99' => array( 'type' => 'score', 'score' => 0.1, 'confidence' => 0.9 ) );
$j = sn_jev_tags_judge( $ans, array( 10, 20, 99 ), $pool );
ok( 2 === count( $j ) && 0.4 === $j[1]['score'] && 0.7 === $j[1]['confidence'] && 'royalties' === $j[1]['name'], 'B1 one row per attached tag with score and confidence; an id not in the pool is dropped' );
ok( sn_jev_tag_is_misfit( array( 'score' => 0.49, 'confidence' => 0.7 ) ) && ! sn_jev_tag_is_misfit( array( 'score' => 0.5, 'confidence' => 0.9 ) ) && ! sn_jev_tag_is_misfit( array( 'score' => 0.1, 'confidence' => 0.69 ) ), 'B2 a misfit is under 0.5 AT confidence 0.7 or better; 0.5 is not under, 0.69 is a shrug' );

// C: the pass.
mkpost( 2, 'Untagged', array() );
mkpost( 3, 'Reach', array( 10, 20 ) );
$GLOBALS['__k']['answer'] = static function ( $state, $q ) { $a = array(); foreach ( $q as $k => $qq ) { $a[ $k ] = array( 'type' => 'score', 'score' => 'a20' === $k ? 0.3 : 1.9, 'confidence' => 'Reach' === $state['note']['title'] ? 0.9 : 0.5 ); } return array( 'ok' => true, 'code' => 200, 'answers' => $a, 'usage' => array( 'input_tokens' => 2500 ), 'error' => '' ); };
$r = sn_jev_tags_sync();
ok( $r['ok'] && 2 === $r['judged'] && 1 === $r['misfits'] && ! isset( $r['missing'] ) && 2 === count( $GLOBALS['__k']['calls'] ) && 'tags' === $GLOBALS['__k']['calls'][0]['feature'], 'C1 one request per note that carries tags, under the tags feature; the untagged note is skipped; one misfit (royalties on Reach at 0.9), the 0.5-confidence one on note 1 is not' );
ok( array( 'a10', 'a20' ) === array_keys( $GLOBALS['__k']['calls'][0]['questions'] ), 'C2 the questions are the attached tags only' );
$d = sn_jev_tags_data();
ok( 5000 === $d['usage']['input_tokens'] && 3 === $d['tags'] && 'royalties' === $d['notes'][1]['attached'][1]['name'] && ! isset( $d['notes'][1]['missing'] ) && ! isset( $d['notes'][2] ), 'C3 stored with tokens, the pool size, attached rows per note, nothing else' );
$GLOBALS['__k']['key'] = '';
ok( 'no-key' === sn_jev_tags_sync()['error'], 'C4 no key, no pass' );
$GLOBALS['__k']['key'] = 'k';
foreach ( $GLOBALS['__k']['actions']['init'] as $cb ) { $cb(); }
ok( 'weekly' === ( $GLOBALS['__k']['scheduled'][ SN_JEV_TAGS_HOOK ] ?? '' ), 'C5 weekly when ready' );

// D: check 31.
$c = sn_health_check_jev_tags();
ok( 1 === $c['count'] && null === $c['skipped'] && str_contains( $c['findings'][0]['note'], 'attached for reach (0.30 of 2, confidence 0.90)' ) && 3 === $c['findings'][0]['subject_id'], 'D1 a finding per note with a confident misfit, with the numbers; the shrug on note 1 is not one' );
ok( str_contains( $c['fix_hint'], 'DESCRIPTION' ) && str_contains( $c['fix_hint'], 'not prose' ) && ! str_contains( $c['fix_hint'], 'add one' ), 'D2 the hint says tags are not prose and the description is what Jev read; it proposes nothing' );
$GLOBALS['__k']['opt'] = array();
ok( is_string( sn_health_check_jev_tags()['skipped'] ) && 0 === sn_health_check_jev_tags()['count'], 'D3 no pass yet: skipped, never a pass' );
$GLOBALS['__k']['key'] = '';
ok( str_contains( (string) sn_health_check_jev_tags()['skipped'], 'connector' ), 'D4 no key: skipped, names the connector' );
$GLOBALS['__k']['key'] = 'k';

// E: abilities.
$ab = $GLOBALS['__k']['abilities'];
ok( false === $ab['signal-noise/jev-tags-now']['meta']['annotations']['readonly'] && true === $ab['signal-noise/jev-tags']['meta']['annotations']['readonly'], 'E1 one write, one read' );
ok( ! isset( $ab['signal-noise/jev-tags-now']['output_schema']['properties']['missing'] ) && ! isset( $ab['signal-noise/jev-tags']['output_schema']['properties']['umbrellas'] ) && str_contains( $ab['signal-noise/jev-tags-now']['description'], 'touch what the tag names' ), 'E1b the schemas carry no missing or umbrellas; jev-tags-now describes the house question' );
ok( false === snt_ability_jev_tags()['judged'], 'E2 no pass yet' );
sn_jev_tags_sync();
$o = snt_ability_jev_tags();
ok( $o['judged'] && 1 === $o['flagged'] && 'royalties' === $o['notes'][3]['misfits'][0]['name'] && 2 === count( $o['notes'][3]['attached'] ) && ! isset( $o['umbrellas'] ) && str_contains( $o['note'], 'does not propose' ), 'E3 jev-tags hands the flagged note out with its misfit and every attached score; no proposals' );

// F: rows, forget.
$stored = array( 'notes' => array(
	1 => array( 'title' => 'a', 'attached' => array( array( 'id' => 20, 'name' => 'royalties', 'score' => 0.4, 'confidence' => 0.5 ) ) ),
	2 => array( 'title' => 'b', 'attached' => array( array( 'id' => 10, 'name' => 'provenance', 'score' => 1.9, 'confidence' => 0.9 ), array( 'id' => 20, 'name' => 'royalties', 'score' => 0.2, 'confidence' => 0.8 ) ) ),
	3 => array( 'title' => 'c', 'attached' => array(), 'missing' => array( array( 'id' => 10, 'name' => 'provenance', 'noul' => 0.9 ) ) ),
) );
$rows = sn_jev_tags_rows( $stored );
ok( array( 2 ) === array_keys( $rows ) && array( 'title', 'remove' ) === array_keys( $rows[2] ) && 'royalties' === $rows[2]['remove'][0]['name'], 'F1 rows: only the confident misfit is a row; a 16.9.1 stored missing list is ignored; no add key' );
$GLOBALS['__k']['opt'][ SN_JEV_TAGS_OPTION ] = $stored + array( 'synced_at' => 1, 'tags' => 3 );
sn_jev_tags_forget( array( 2 => array( 20 ) ) );
ok( array() === sn_jev_tags_rows( sn_jev_tags_data() ) && 1 === count( sn_jev_tags_data()['notes'][2]['attached'] ), 'F2 a removed pair leaves the stored pass; the other attached row stays' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
