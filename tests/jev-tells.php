<?php
/**
 * The anti-tell pass (16.7.0): paragraphs out of block markup, the regex
 * tells against real tells and real non-tells, the state and the four
 * questions per paragraph, the judge's line, the check's hash skip and
 * failure record, the hook's status filter, the corpus pass, the three
 * abilities, the panel's warnings. Run: php tests/jev-tells.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__t'] = array( 'opt' => array(), 'posts' => array(), 'meta' => array(), 'calls' => array(), 'answer' => null, 'actions' => array(), 'abilities' => array(), 'key' => 'k' );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__t']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__t']['opt'] ) ? $GLOBALS['__t']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__t']['opt'][ $k ] = $v; return true; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['__t']['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__t']['meta'][ $id ][ $k ] = $v; return true; }
function get_posts( $a ) { $o = array(); foreach ( $GLOBALS['__t']['posts'] as $id => $p ) { if ( 'publish' === $p->post_status ) { $o[] = $id; } } return $o; }
function get_post( $id ) { return $GLOBALS['__t']['posts'][ $id ] ?? null; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_textarea_field( $s ) { return $s; }
function register_post_meta( $t, $k, $a ) { $GLOBALS['__t']['registered'][ $k ] = $a; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__t']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
function sn_jev_key() { return $GLOBALS['__t']['key']; }
function sn_jev_is_ready() { return '' !== sn_jev_key(); }
function sn_jev_ask( $state, array $questions, $feature = 'other' ) {
	$GLOBALS['__t']['calls'][] = compact( 'state', 'questions', 'feature' );
	$a = $GLOBALS['__t']['answer'];
	return is_callable( $a ) ? $a( $state, $questions ) : $a;
}
function para( $s ) { return '<!-- wp:paragraph --><p>' . $s . '</p><!-- /wp:paragraph -->'; }
function mkpost( $id, $status, $title, $content ) { $p = new stdClass(); $p->ID = $id; $p->post_type = 'post'; $p->post_status = $status; $p->post_title = $title; $p->post_content = $content; $GLOBALS['__t']['posts'][ $id ] = $p; return $p; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
$yes = static function ( $keys, $on ) { return static function ( $state, $q ) use ( $keys, $on ) { $a = array(); foreach ( array_keys( $q ) as $k ) { $a[ $k ] = array( 'type' => 'noul', 'noul' => in_array( $k, $on, true ) ? 0.81 : 0.1 ); } return array( 'ok' => true, 'code' => 200, 'answers' => $a, 'usage' => array( 'input_tokens' => 4000 ), 'error' => '' ); }; };

require __DIR__ . '/../inc/jev-tells.php';
require __DIR__ . '/../inc/abilities-jev.php';
foreach ( $GLOBALS['__t']['actions']['init'] ?? array() as $cb ) { $cb(); }
foreach ( $GLOBALS['__t']['actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

// A: paragraphs.
$long = 'A signature is a claim that can be checked by anyone who holds the public key, and that is the whole of it.';
$html = '<!-- wp:heading --><h2>Head</h2><!-- /wp:heading -->' . para( $long ) . para( 'Short.' ) . '<!-- wp:list --><ul><li>x</li></ul><!-- /wp:list -->' . para( 'Second <em>long</em> paragraph with &amp; an entity and enough characters to clear the floor easily.' );
$ps = sn_jev_tells_paragraphs( $html );
ok( 2 === count( $ps ) && $long === $ps[0] && str_starts_with( $ps[1], 'Second long paragraph with & an entity' ), 'A1 paragraphs: <p> only, tags stripped, entities decoded, short ones dropped, headings and lists ignored' );
ok( array() === sn_jev_tells_paragraphs( '' ) && array() === sn_jev_tells_paragraphs( '<h2>only</h2>' ), 'A2 nothing to judge is an empty list' );
$many = ''; for ( $i = 0; $i < 30; $i++ ) { $many .= para( $long . ' ' . $i ); }
ok( SN_JEV_TELLS_MAX_PARAS === count( sn_jev_tells_paragraphs( $many ) ), 'A3 capped at ' . SN_JEV_TELLS_MAX_PARAS );

// B: the regex tells, positive and negative.
$tells = array(
	"The score is a number \xE2\x80\x94 nothing more \xE2\x80\x94 and a number can be tuned by whoever runs it.",
	'The platform quietly changed the rule, and the change was not just a policy shift but a reversal.',
	'It could perhaps be that the signer might have meant something else, which seems possible.',
	'The record was signed on Monday morning by the engineer. The claim was filed on Tuesday morning by the label. The payment cleared on Wednesday noon at the bank.',
);
$d = sn_jev_tells_deterministic( $tells );
$by = array_column( $d, 'count', 'tell' );
ok( 2 === ( $by['em_dash'] ?? 0 ), 'B1 two em dashes counted' );
ok( 1 === ( $by['quietly'] ?? 0 ) && 1 === ( $by['not_just'] ?? 0 ), 'B2 quietly and not-just-but counted once each' );
ok( 1 === ( $by['hedge_cluster'] ?? 0 ), 'B3 a sentence stacking could/perhaps/might/seems is one cluster' );
ok( 1 === ( $by['uniform_rhythm'] ?? 0 ), 'B4 three sentences within 15% of one length is one uniform paragraph' );
$clean = array(
	'The score is a number, and a number can be tuned by whoever runs it; a signature cannot.',
	'The label filed the claim. Two years later the distributor paid it, after the audit that the estate had asked for in the first month.',
	'ISRC, ISWC and IPI are the three identifiers a track carries, and only the first is assigned at upload.',
);
ok( array() === sn_jev_tells_deterministic( $clean ), 'B5 plain prose with a semicolon, varied sentence lengths and a real list of three: no regex tell' );

// C: state, questions, judge.
$st = sn_jev_tells_state( array( 'one', 'two' ) );
ok( array( 'p1' => 'one', 'p2' => 'two' ) === $st['paragraphs'] && 'p2' === $st['last'], 'C1 state keyed pN with the last named' );
$q = sn_jev_tells_questions( 2 );
ok( 7 === count( $q ) && isset( $q['p1_tricolon'], $q['p2_symmetric'], $q['closer'] ) && str_contains( $q['closer']['instructions'], 'paragraphs.p2' ) && isset( $q['p1_anaphora']['criteria']['true']['examples'] ), 'C2 three per paragraph plus the closer on the last, each with what and examples' );
ok( array() === sn_jev_tells_questions( 0 ), 'C3 no paragraphs, no questions' );
$ans = array( 'p1_tricolon' => array( 'type' => 'noul', 'noul' => 0.6 ), 'p1_anaphora' => array( 'type' => 'noul', 'noul' => 0.59 ), 'p2_symmetric' => array( 'type' => 'noul', 'noul' => 0.9 ), 'closer' => array( 'type' => 'noul', 'noul' => 0.7 ), 'junk' => array( 'type' => 'noul', 'noul' => 1 ), 'p9_tricolon' => array( 'type' => 'noul', 'noul' => 1 ) );
$rows = sn_jev_tells_judge( $ans, array( str_repeat( 'a', 100 ), 'second' ) );
ok( array( array( 1, 'tricolon', 0.6 ), array( 2, 'symmetric', 0.9 ), array( 2, 'closer', 0.7 ) ) === array_map( static function ( $r ) { return array( $r['p'], $r['tell'], $r['noul'] ); }, $rows ) && 80 === strlen( $rows[0]['excerpt'] ), 'C4 rows at or above 0.6 in paragraph order, 0.59 out, unknown keys out, excerpt capped at 80' );

// D: the check.
mkpost( 1, 'draft', 'Draft', para( $tells[0] ) . para( $clean[1] ) );
$GLOBALS['__t']['answer'] = $yes( array(), array( 'p2_symmetric', 'closer' ) );
$r1 = sn_jev_tells_check( 1 );
ok( '' === $r1['error'] && 2 === $r1['paragraphs'] && array( 'symmetric', 'closer' ) === array_column( $r1['rows'], 'tell' ) && 2 === $r1['deterministic'][0]['count'] && 4000 === $r1['input_tokens'] && 1 === count( $GLOBALS['__t']['calls'] ) && 'tells' === $GLOBALS['__t']['calls'][0]['feature'], 'D1 first check: one request under the tells feature, rows and regex counts stored' );
ok( is_array( json_decode( get_post_meta( 1, SN_JEV_TELLS_META, true ), true ) ), 'D2 stored as JSON meta' );
sn_jev_tells_check( 1 );
ok( 1 === count( $GLOBALS['__t']['calls'] ), 'D3 unchanged paragraphs: no second request' );
sn_jev_tells_check( 1, true );
ok( 2 === count( $GLOBALS['__t']['calls'] ), 'D4 force asks again' );
get_post( 1 )->post_content = para( $clean[0] ) . para( $clean[1] );
$GLOBALS['__t']['answer'] = array( 'ok' => false, 'code' => 529, 'answers' => array(), 'usage' => array(), 'error' => 'overloaded' );
$r3 = sn_jev_tells_check( 1 );
ok( array( 'symmetric', 'closer' ) === array_column( $r3['rows'], 'tell' ) && array() === $r3['deterministic'] && str_contains( $r3['error'], 'overloaded' ), 'D5 a failed request keeps the previous rows, recomputes the regex tells, records the error' );
mkpost( 2, 'draft', 'Empty', '<h2>Only a heading</h2>' );
ok( 0 === sn_jev_tells_check( 2 )['paragraphs'] && 3 === count( $GLOBALS['__t']['calls'] ), 'D6 no paragraphs: stored, no request' );
$GLOBALS['__t']['key'] = '';
ok( 'no-key' === sn_jev_tells_check( 1, true )['error'] && 3 === count( $GLOBALS['__t']['calls'] ), 'D7 no key: no request, the record says so' );
$GLOBALS['__t']['key'] = 'k';
ok( array( 'error' => 'not-a-note' ) === sn_jev_tells_check( 99 ), 'D8 unknown post' );

// E: the hook.
$hook = $GLOBALS['__t']['actions']['wp_after_insert_post'][0];
$GLOBALS['__t']['answer'] = $yes( array(), array() );
$n = count( $GLOBALS['__t']['calls'] );
mkpost( 3, 'publish', 'Live', para( $long ) ); $hook( 3, get_post( 3 ) );
ok( $n === count( $GLOBALS['__t']['calls'] ), 'E1 a published note is not judged on save' );
mkpost( 4, 'future', 'Scheduled', para( $long ) ); $hook( 4, get_post( 4 ) );
ok( $n + 1 === count( $GLOBALS['__t']['calls'] ), 'E2 a scheduled note is judged' );
ok( true === $GLOBALS['__t']['registered'][ SN_JEV_TELLS_META ]['show_in_rest'], 'E3 meta registered for the editor' );

// F: the corpus pass.
$GLOBALS['__t']['calls'] = array();
mkpost( 5, 'publish', 'Clean', para( $clean[0] ) . para( $clean[1] ) );
mkpost( 6, 'publish', 'Nothing', '<h2>x</h2>' );
$GLOBALS['__t']['answer'] = static function ( $state, $q ) { $a = array(); foreach ( array_keys( $q ) as $k ) { $a[ $k ] = array( 'type' => 'noul', 'noul' => 'Live' === substr( $state['paragraphs']['p1'], 0, 4 ) ? 0.1 : ( 'p1_anaphora' === $k ? 0.7 : 0.1 ) ); } return array( 'ok' => true, 'code' => 200, 'answers' => $a, 'usage' => array( 'input_tokens' => 3000 ), 'error' => '' ); };
$p = sn_jev_tells_pass();
$d = sn_jev_tells_data();
ok( $p['ok'] && 2 === $p['judged'] && 2 === count( $GLOBALS['__t']['calls'] ) && 6000 === $d['input_tokens'] && ! isset( $d['notes'][6] ), 'F1 one request per published note with paragraphs; the heading-only note is skipped' );
ok( 2 === $p['flagged'] && 'anaphora' === $d['notes'][5]['rows'][0]['tell'], 'F2 flagged counts notes with a row or a regex tell' );
$out = snt_ability_jev_tells();
ok( $out['judged'] && 2 === $out['flagged'] && isset( ( (array) $out['notes'] )[5] ), 'F3 jev-tells hands the flagged notes out' );
$GLOBALS['__t']['opt'] = array();
ok( false === snt_ability_jev_tells()['judged'], 'F4 no pass yet' );

// G: abilities and the panel.
$ab = $GLOBALS['__t']['abilities'];
ok( false === $ab['signal-noise/jev-tells-check']['meta']['annotations']['readonly'] && false === $ab['signal-noise/jev-tells-pass']['meta']['annotations']['readonly'] && true === $ab['signal-noise/jev-tells']['meta']['annotations']['readonly'], 'G1 two writes, one read' );
ok( 'post_id required' === snt_ability_jev_tells_check( array() )['error'], 'G2 tells-check needs a post' );
$js = file_get_contents( __DIR__ . '/../assets/pre-publish-gate.js' );
ok( str_contains( $js, 'tellWarnings' ) && str_contains( $js, '_sn_jev_tells' ) && str_contains( $js, 'r.noul >= 0.6' ) && str_contains( $js, 'Tells the playbook bans, counted' ) && str_contains( $js, 'tellWarnings( meta._sn_jev_tells )' ), 'G3 the panel reads the meta, warns at 0.6, lists the regex counts' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
