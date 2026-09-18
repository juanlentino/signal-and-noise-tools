<?php
/**
 * The collision gate (16.4.0): the corpus, the state, one Noul per note,
 * the judge's line and cap, the check's hash skip and its failure record,
 * the hook's status filter, the lane map's pairs, the three abilities, and
 * the client-side warnings. Run: php tests/jev-collision.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$GLOBALS['__c'] = array( 'opt' => array(), 'posts' => array(), 'meta' => array(), 'calls' => array(), 'answer' => null, 'actions' => array(), 'abilities' => array(), 'key' => 'k' );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__c']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__c']['opt'] ) ? $GLOBALS['__c']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__c']['opt'][ $k ] = $v; return true; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['__c']['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__c']['meta'][ $id ][ $k ] = $v; return true; }
function get_posts( $a ) { $o = array(); foreach ( $GLOBALS['__c']['posts'] as $id => $p ) { if ( 'publish' === $p->post_status ) { $o[] = $id; } } return $o; }
function get_post( $id ) { return $GLOBALS['__c']['posts'][ $id ] ?? null; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function strip_shortcodes( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_textarea_field( $s ) { return $s; }
function register_post_meta( $t, $k, $a ) { $GLOBALS['__c']['registered'][ $k ] = $a; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__c']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
function sn_jev_key() { return $GLOBALS['__c']['key']; }
function sn_jev_is_ready() { return '' !== sn_jev_key(); }
function sn_jev_ask( $state, array $questions, $key = null ) {
	$GLOBALS['__c']['calls'][] = array( 'state' => $state, 'questions' => $questions );
	$a = $GLOBALS['__c']['answer'];
	return is_callable( $a ) ? $a( $state, $questions ) : $a;
}
function mkpost( $id, $status, $title, $content = 'Body.', $excerpt = 'Desc.' ) {
	$p = new stdClass(); $p->ID = $id; $p->post_type = 'post'; $p->post_status = $status; $p->post_title = $title; $p->post_content = $content; $p->post_excerpt = $excerpt;
	$GLOBALS['__c']['posts'][ $id ] = $p; return $p;
}
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/jev-collision.php';
require __DIR__ . '/../inc/abilities-jev.php';
foreach ( $GLOBALS['__c']['actions']['init'] ?? array() as $cb ) { $cb(); }
foreach ( $GLOBALS['__c']['actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

// A: corpus is the published notes minus the one being judged; text is flattened.
mkpost( 1, 'publish', 'Two kinds  of <b>provenance</b>', 'x', 'A &amp; B' );
mkpost( 2, 'publish', 'Falsifiability is the line' );
mkpost( 3, 'draft', 'A new draft', str_repeat( 'word ', 400 ) );
$corpus = sn_jev_collision_corpus( 3 );
ok( array_keys( $corpus ) === array( 1, 2 ), 'A1 corpus is the published notes except the draft' );
ok( 'Two kinds of provenance' === $corpus[1]['title'] && 'A & B' === $corpus[1]['description'], 'A2 title and description flattened and decoded' );
ok( ! isset( sn_jev_collision_corpus( 1 )[1] ), 'A3 a published note is excluded from its own corpus' );

// B: state carries the draft's opening (capped) and the corpus keyed by nID.
$state = sn_jev_collision_state( get_post( 3 ), $corpus );
ok( 'A new draft' === $state['draft']['title'] && strlen( $state['draft']['opening'] ) <= SN_JEV_COLLISION_OPENING, 'B1 draft opening is capped at ' . SN_JEV_COLLISION_OPENING );
ok( isset( $state['notes']['n1']['title'], $state['notes']['n2']['description'] ), 'B2 notes keyed nID with title and description' );

// C: one Noul per note, the question names the draft and the note, with what/examples on both sides.
$q = sn_jev_collision_questions( $corpus );
ok( array_keys( $q ) === array( 'n1', 'n2' ), 'C1 one question per note keyed nID' );
ok( 'noul' === $q['n1']['type'] && str_contains( $q['n1']['instructions'], 'notes.n1' ) && str_contains( $q['n1']['instructions'], 'same central argument' ), 'C2 the question asks about the same central argument against notes.nID' );
ok( isset( $q['n1']['criteria']['true']['what'], $q['n1']['criteria']['true']['examples'], $q['n1']['criteria']['false']['what'] ), 'C3 both sides carry what and examples' );

// D: judge sorts desc, counts at or above the line, keeps five.
$big = array(); for ( $i = 1; $i <= 8; $i++ ) { $big[ $i ] = array( 'title' => "N$i", 'description' => '' ); }
$answers = array(); foreach ( $big as $i => $_ ) { $answers[ "n$i" ] = array( 'type' => 'noul', 'noul' => $i / 10 ); }
$j = sn_jev_collision_judge( $answers, $big );
ok( count( $j['rows'] ) === SN_JEV_COLLISION_KEEP && 8 === $j['rows'][0]['id'] && 'N8' === $j['rows'][0]['title'] && 0.8 === $j['rows'][0]['noul'], 'D1 top five sorted desc with title' );
ok( 4 === $j['collisions'], 'D2 collisions counts rows at or above 0.5 (0.5,0.6,0.7,0.8)' );
ok( sn_jev_collision_judge( array(), $big ) === array( 'rows' => array(), 'collisions' => 0 ), 'D3 no answers, no rows' );

// E: the check stores the record, skips on an unchanged hash, refreshes on force, keeps prev on failure.
$GLOBALS['__c']['answer'] = array( 'ok' => true, 'code' => 200, 'answers' => array( 'n1' => array( 'type' => 'noul', 'noul' => 0.72 ), 'n2' => array( 'type' => 'noul', 'noul' => 0.1 ) ), 'usage' => array( 'input_tokens' => 900 ), 'error' => '' );
$r1 = sn_jev_collision_check( 3 );
ok( 1 === $r1['collisions'] && 2 === $r1['against'] && '' === $r1['error'] && 900 === $r1['input_tokens'] && 1 === count( $GLOBALS['__c']['calls'] ), 'E1 first check asks once and stores one collision' );
$stored = json_decode( get_post_meta( 3, SN_JEV_COLLISION_META, true ), true );
ok( 0.72 === $stored['rows'][0]['noul'] && 1 === $stored['rows'][0]['id'], 'E2 record is JSON in post meta with the row' );
sn_jev_collision_check( 3 );
ok( 1 === count( $GLOBALS['__c']['calls'] ), 'E3 unchanged draft: no second request' );
sn_jev_collision_check( 3, true );
ok( 2 === count( $GLOBALS['__c']['calls'] ), 'E4 force asks again' );
get_post( 3 )->post_title = 'A changed draft';
sn_jev_collision_check( 3 );
ok( 3 === count( $GLOBALS['__c']['calls'] ), 'E5 a changed draft asks again' );
$GLOBALS['__c']['answer'] = array( 'ok' => false, 'code' => 500, 'answers' => array(), 'usage' => array(), 'error' => 'boom' );
get_post( 3 )->post_title = 'A changed draft again';
$r4 = sn_jev_collision_check( 3 );
ok( 1 === $r4['collisions'] && str_contains( $r4['error'], 'boom' ), 'E6 a failed request keeps the previous rows and records the error' );
ok( sn_jev_collision_check( 99 ) === array( 'error' => 'not-a-note' ), 'E7 unknown post is not-a-note' );
$GLOBALS['__c']['key'] = '';
ok( sn_jev_collision_check( 3 ) === array( 'error' => 'no-key' ), 'E8 no key, no request' );
$GLOBALS['__c']['key'] = 'k';

// F: the hook judges draft/pending/future posts only.
$hook = $GLOBALS['__c']['actions']['wp_after_insert_post'][0];
$GLOBALS['__c']['answer'] = array( 'ok' => true, 'code' => 200, 'answers' => array(), 'usage' => array(), 'error' => '' );
$n = count( $GLOBALS['__c']['calls'] );
$hook( 1, get_post( 1 ) );
ok( $n === count( $GLOBALS['__c']['calls'] ), 'F1 a published note is not judged on save' );
mkpost( 4, 'pending', 'Pending one' ); $hook( 4, get_post( 4 ) );
ok( $n + 1 === count( $GLOBALS['__c']['calls'] ), 'F2 a pending note is judged' );
$page = mkpost( 5, 'draft', 'A page' ); $page->post_type = 'page'; $hook( 5, $page );
ok( $n + 1 === count( $GLOBALS['__c']['calls'] ), 'F3 a page is not judged' );

// G: registered meta shows in REST as a string.
ok( isset( $GLOBALS['__c']['registered'][ SN_JEV_COLLISION_META ] ) && true === $GLOBALS['__c']['registered'][ SN_JEV_COLLISION_META ]['show_in_rest'] && 'string' === $GLOBALS['__c']['registered'][ SN_JEV_COLLISION_META ]['type'], 'G1 meta registered for the editor' );

// H: the lane map dedupes unordered pairs keeping the higher reading, sorts, stores.
$GLOBALS['__c']['posts'] = array(); $GLOBALS['__c']['calls'] = array();
mkpost( 1, 'publish', 'One' ); mkpost( 2, 'publish', 'Two' ); mkpost( 3, 'publish', 'Three' );
$GLOBALS['__c']['answer'] = function ( $state, $q ) {
	$id = (int) preg_replace( '/\D/', '', $state['draft']['title'] === 'One' ? '1' : ( $state['draft']['title'] === 'Two' ? '2' : '3' ) );
	$map = array( 1 => array( 'n2' => 0.6, 'n3' => 0.1 ), 2 => array( 'n1' => 0.9, 'n3' => 0.5 ), 3 => array( 'n1' => 0.2, 'n2' => 0.4 ) );
	$ans = array(); foreach ( $map[ $id ] as $k => $v ) { $ans[ $k ] = array( 'type' => 'noul', 'noul' => $v ); }
	return array( 'ok' => true, 'code' => 200, 'answers' => $ans, 'usage' => array( 'input_tokens' => 10 ), 'error' => '' );
};
$m = sn_jev_lane_map();
ok( $m['ok'] && 3 === $m['judged'] && 0 === $m['failed'] && 2 === $m['pairs'] && 3 === count( $GLOBALS['__c']['calls'] ), 'H1 one request per note, two pairs at or above the line' );
$lanes = sn_jev_lanes();
ok( 0.9 === $lanes['pairs'][0]['noul'] && 1 === $lanes['pairs'][0]['a'] && 2 === $lanes['pairs'][0]['b'] && 'One' === $lanes['pairs'][0]['a_title'], 'H2 pair 1-2 keeps the higher of 0.6 and 0.9, sorted first' );
ok( 0.5 === $lanes['pairs'][1]['noul'] && 2 === $lanes['pairs'][1]['a'] && 3 === $lanes['pairs'][1]['b'] && 30 === $lanes['input_tokens'], 'H3 pair 2-3 at exactly 0.5 counts; tokens summed' );
$GLOBALS['__c']['answer'] = array( 'ok' => false, 'code' => 401, 'answers' => array(), 'usage' => array(), 'error' => 'unauthorized' );
$GLOBALS['__c']['calls'] = array();
$m2 = sn_jev_lane_map();
ok( ! $m2['ok'] && 1 === $m2['failed'] && 1 === count( $GLOBALS['__c']['calls'] ) && 'unauthorized' === $m2['error'], 'H4 a 401 stops the map after one request' );

// I: the three abilities.
$ab = $GLOBALS['__c']['abilities'];
ok( isset( $ab['signal-noise/jev-collision-check'], $ab['signal-noise/jev-lane-map'], $ab['signal-noise/jev-lanes'] ), 'I1 three abilities registered' );
ok( false === $ab['signal-noise/jev-collision-check']['meta']['annotations']['readonly'] && false === $ab['signal-noise/jev-lane-map']['meta']['annotations']['readonly'] && true === $ab['signal-noise/jev-lanes']['meta']['annotations']['readonly'], 'I2 two writes, one read' );
ok( ! snt_ability_jev_collision_check( array() )['ok'] && 'post_id required' === snt_ability_jev_collision_check( array() )['error'], 'I3 collision-check requires post_id' );
$l = snt_ability_jev_lanes();
ok( $l['ok'] && $l['mapped'] && 0 === count( $l['pairs'] ) && 1 === $l['failed'], 'I4 jev-lanes hands the stored map out (the failed one from H4: zero pairs, failed=1)' );
$GLOBALS['__c']['opt'] = array();
ok( false === snt_ability_jev_lanes()['mapped'], 'I5 no map yet reads as mapped=false' );

// J: the client-side warnings, from the JS source.
$js = file_get_contents( __DIR__ . '/../assets/pre-publish-gate.js' );
ok( str_contains( $js, 'collisionWarnings' ) && str_contains( $js, '_sn_jev_collision' ) && str_contains( $js, 'Notes are never edited after publication' ), 'J1 the gate reads the meta and names the irreversibility' );
ok( str_contains( $js, 'r.noul >= 0.6' ) && ! str_contains( $js, 'r.noul >= 0.5' ), 'J2 the panel warns at 0.6 (16.4.1); the server keeps 0.5 for the count and the map' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
