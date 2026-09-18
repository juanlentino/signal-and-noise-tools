<?php
/**
 * TypeSafe Jev (16.3.0): the parser against the documented response, the
 * questions, a note's state, the verdict shape, the daily pass over a fake
 * transport, check 30's judge and its skips, the probe's verdicts, the ability.
 * Run: php tests/typesafe-jev.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_MR_DEFAULT_ENDPOINT', 'https://juanlentino.com/_sn/rights-signals/machine-readers' );
define( 'SN_SPOTIFY_TOKEN_KEY', 'sn_spotify_token' );
$GLOBALS['__j'] = array( 'opt' => array(), 'posts' => array(), 'calls' => array(), 'answer' => null, 'actions' => array(), 'abilities' => array(), 'scheduled' => array(), 'settings' => array() );
function __( $s, $d = null ) { return $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $t, $v ) { return $v; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__j']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__j']['opt'] ) ? $GLOBALS['__j']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__j']['opt'][ $k ] = $v; return true; }
function delete_transient( $k ) { return true; }
function sn_setting( $path, $d = null ) { return $GLOBALS['__j']['settings'][ $path ] ?? $d; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['__j']['meta'][ $id ][ $k ] ?? ''; }
function get_posts( $a ) { return array_keys( $GLOBALS['__j']['posts'] ); }
function get_post( $id ) { return $GLOBALS['__j']['posts'][ $id ] ?? null; }
function get_permalink( $id ) { return "https://x.test/notes/$id/"; }
function admin_url( $p ) { return 'https://x.test/wp-admin/' . $p; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]+\]/', '', $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['__j']['calls'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ), 'auth' => $args['headers']['Authorization'] ?? '' ); $a = $GLOBALS['__j']['answer']; return is_callable( $a ) ? $a( json_decode( $args['body'], true ) ) : $a; }
function wp_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_next_scheduled( $h ) { return $GLOBALS['__j']['scheduled'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__j']['scheduled'][ $h ] = $r; return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__j']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) { return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => $skipped ); }
function sn_post_settings_get_seo_title( $id ) { return $GLOBALS['__j']['meta'][ $id ]['_sn_seo_title'] ?? ''; }
function sn_seo_resolve_singular_description( $p ) { return (string) $p->post_excerpt; }
function sn_cf_monitor_verify( $z, $t = null ) { return null; }
function sn_uptime_status_api_get( $r ) { return null; }
function sn_spotify_token() { return ''; }

require dirname( __DIR__ ) . '/inc/keyring.php';
require dirname( __DIR__ ) . '/inc/keyring-verify.php';
require dirname( __DIR__ ) . '/inc/typesafe-client.php';
require dirname( __DIR__ ) . '/inc/jev-notes.php';
require dirname( __DIR__ ) . '/inc/health-check-jev-notes.php';
require dirname( __DIR__ ) . '/inc/abilities-jev.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$ok200 = static function ( $answers, $usage = array( 'input_tokens' => 312, 'output_tokens' => 48 ) ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'model' => 'jev-latest', 'answers' => $answers, 'usage' => $usage ) ) ); };

echo "Group A: the parser against the documented response\n";
$doc = json_decode( '{"model":"jev-latest","answers":{"urgency":{"type":"noul","noul":0.999}},"usage":{"input_tokens":312,"output_tokens":48}}', true );
$p = sn_jev_parse( $doc );
ok( 0.999 === $p['answers']['urgency']['noul'] && 312 === $p['usage']['input_tokens'], 'the quickstart response parses: noul as a float, usage as ints' );
$p = sn_jev_parse( array( 'answers' => array( 'q' => array( 'type' => 'score', 'score' => 2.6, 'probabilities' => array( 0.05, 0.3, 0.65 ), 'confidence' => 0.91 ), 'c' => array( 'type' => 'choice', 'choice' => 'refund', 'confidence' => 0.7 ) ) ) );
ok( 2.6 === $p['answers']['q']['score'] && 0.91 === $p['answers']['q']['confidence'] && array( 0.05, 0.3, 0.65 ) === $p['answers']['q']['probabilities'] && 'refund' === $p['answers']['c']['choice'], 'score and choice carry value, probabilities and confidence' );
ok( null === sn_jev_parse( array( 'model' => 'x' ) ) && null === sn_jev_parse( 'nope' ) && null === sn_jev_parse( array( 'answers' => array( 'q' => array( 'type' => 'score' ) ) ) ), 'no answers map, a non-array, or a score without its value: null, never a guess' );
ok( 0.0 === sn_jev_parse( array( 'answers' => array( 'q' => array( 'type' => 'score', 'score' => 1 ) ) ) )['answers']['q']['confidence'], 'a score without confidence reads 0.0, which never clears the floor' );
ok( 1.0 === sn_jev_parse( array( 'answers' => array( 'n' => array( 'type' => 'noul', 'noul' => 7 ) ) ) )['answers']['n']['noul'], 'a noul is clamped to 0..1' );
ok( true === sn_jev_is_sure( array( 'confidence' => 0.9 ) ) && false === sn_jev_is_sure( array( 'confidence' => 0.89 ) ) && false === sn_jev_is_sure( array() ), 'the floor is 0.9 inclusive; no confidence is not sure' );

echo "\nGroup B: the request\n";
ok( 'no-key' === sn_jev_ask( 's', array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) ) )['error'], 'no key: no request' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'ts-secret';
ok( 'no-questions' === sn_jev_ask( 's', array() )['error'] && array() === $GLOBALS['__j']['calls'], 'no questions: no request' );
$GLOBALS['__j']['answer'] = $ok200( array( 'q' => array( 'type' => 'noul', 'noul' => 0.2 ) ) );
$r = sn_jev_ask( array( 'title' => 'x' ), array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) ) );
$c = end( $GLOBALS['__j']['calls'] );
ok( true === $r['ok'] && 'https://api.typesafe.ai/v1/systemone' === $c['url'] && 'Bearer ts-secret' === $c['auth'] && 'jev-latest' === $c['body']['model'] && array( 'title' => 'x' ) === $c['body']['state'] && isset( $c['body']['questions']['q'] ), 'the documented request: endpoint, bearer, model, state, questions' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":{"message":"invalid key ts-secret"}}' );
$r = sn_jev_ask( 's', array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) ) );
ok( false === $r['ok'] && 401 === $r['code'] && 'invalid key [key]' === $r['error'], 'a 401 carries the message with the key redacted' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"model":"jev-latest"}' );
ok( 'http-200' === sn_jev_ask( 's', array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) ) )['error'], 'a 200 without answers is not ok' );

echo "\nGroup C: questions, state, verdict\n";
$q = sn_jev_note_questions();
ok( array( 'title_query', 'description_says', 'opening_names' ) === array_keys( $q ) && 'score' === $q['title_query']['type'] && 3 === count( $q['title_query']['criteria'] ) && 'noul' === $q['opening_names']['type'], 'three questions: two three-level scores and one noul, keyed by name' );
ok( ! preg_match( '/\b(count|how many|before|after|earlier|later)\b/i', json_encode( $q ) ), 'no question asks Jev to count or to order dates (it cannot)' );
$GLOBALS['__j']['posts'][7] = (object) array( 'ID' => 7, 'post_title' => 'Two kinds of provenance', 'post_excerpt' => 'Authorship and behaviour are two questions.', 'post_content' => '<!-- wp:paragraph --><p>Provenance answers two   questions. [sn_pillars] The first is who.</p><!-- /wp:paragraph -->' . str_repeat( ' more', 300 ) );
$GLOBALS['__j']['meta'][7]['_sn_seo_title'] = 'Two kinds of provenance: authorship vs behavioral provenance';
$st = sn_jev_note_state( get_post( 7 ) );
ok( 'Two kinds of provenance' === $st['title'] && 'Two kinds of provenance: authorship vs behavioral provenance' === $st['search_title'] && 'Authorship and behaviour are two questions.' === $st['description'] && 0 === strpos( $st['opening'], 'Provenance answers two questions. The first is who.' ) && strlen( $st['opening'] ) <= 600, 'four short fields: tags, comments and shortcodes stripped, whitespace folded, opening capped at 600' );
$GLOBALS['__j']['posts'][8] = (object) array( 'ID' => 8, 'post_title' => 'Bare', 'post_excerpt' => '', 'post_content' => '' );
ok( 'Bare' === sn_jev_note_state( get_post( 8 ) )['search_title'], 'no override: the search title is the title' );
ok( 'authorship vs behavioral provenance' === $st['query_part'], 'THE 16.3.3 PIN: query_part is the half after the colon when the front is the note\'s own title (the first pass rated the compound and hedged on 45 of 69)' );
ok( 'Why provenance records do not survive lossy encoding' === sn_jev_query_part( 'Why provenance records do not survive lossy encoding', 'The guarantee ends at the codec' ) && 'ISRC: the code and the file' === sn_jev_query_part( 'ISRC: the code and the file', 'Five layers, one system' ) && 'Bare' === sn_jev_query_part( 'Bare', 'Bare' ), 'a full override, a colon whose front is not the title, or no colon: the whole search title' );
ok( is_array( $q['title_query']['instructions'] ) && false !== strpos( $q['title_query']['instructions']['question'], '`query_part`' ) && isset( $q['title_query']['criteria'][0]['signals'] ) && isset( $q['opening_names']['criteria']['true']['what'] ), 'the questions are structured: the title question rates `query_part`, the levels carry signals, the noul carries what/examples' );
$v = sn_jev_verdict_shape( array( 'title_query' => array( 'score' => 1.2, 'confidence' => 0.95 ), 'description_says' => array( 'score' => 2.9, 'confidence' => 0.6 ), 'opening_names' => array( 'noul' => 0.8 ) ) );
ok( 1.2 === $v['title']['score'] && true === $v['title']['sure'] && false === $v['description']['sure'] && 0.8 === $v['opening']['noul'], 'the verdict keeps score, confidence and whether the floor was cleared' );
ok( false === sn_jev_verdict_shape( array() )['title']['sure'], 'a missing answer is never sure' );

echo "\nGroup D: the daily pass\n";
$GLOBALS['__j']['answer'] = static function ( $body ) use ( $ok200 ) { $aph = 'Bare' === $body['state']['search_title']; return $ok200( array( 'title_query' => array( 'type' => 'score', 'score' => $aph ? 0.2 : 1.8, 'confidence' => 0.95 ), 'description_says' => array( 'type' => 'score', 'score' => $aph ? 0.1 : 1.6, 'confidence' => 0.5 ), 'opening_names' => array( 'type' => 'noul', 'noul' => 0.7 ) ) ); };
$GLOBALS['__j']['calls'] = array();
$r = sn_jev_sync();
$d = sn_jev_data();
ok( true === $r['ok'] && 2 === $r['judged'] && 2 === count( $GLOBALS['__j']['calls'] ) && 2 === count( $d['notes'] ) && 624 === $d['usage']['input_tokens'] && 'jev-latest' === $d['model'], 'one request per note, every note, usage summed, one option' );
ok( true === $d['notes'][8]['verdict']['title']['sure'] && 0.2 === $d['notes'][8]['verdict']['title']['score'] && false === $d['notes'][8]['verdict']['description']['sure'], 'the stored verdict is the shaped answer' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 529 ), 'body' => '{"message":"overloaded"}' );
$r = sn_jev_sync();
$d = sn_jev_data();
ok( false === $r['ok'] && 2 === $r['failed'] && 0.2 === $d['notes'][8]['verdict']['title']['score'] && false !== strpos( $d['notes'][8]['error'], 'overloaded' ) && false !== strpos( $d['last_error'], 'overloaded' ), 'a failed request keeps the previous verdict with the error beside it' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"message":"nope"}' ); $GLOBALS['__j']['calls'] = array();
sn_jev_sync();
ok( 1 === count( $GLOBALS['__j']['calls'] ), 'a refused key stops the pass after one request' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = '';
ok( 'no-key' === sn_jev_sync()['error'] && ! sn_jev_is_ready(), 'no key: not ready, no pass' );
foreach ( $GLOBALS['__j']['actions']['init'] as $cb ) { $cb(); }
ok( ! isset( $GLOBALS['__j']['scheduled'][ SN_JEV_SYNC_HOOK ] ), 'no key: nothing scheduled' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'ts-secret';
foreach ( $GLOBALS['__j']['actions']['init'] as $cb ) { $cb(); }
ok( 'daily' === ( $GLOBALS['__j']['scheduled'][ SN_JEV_SYNC_HOOK ] ?? '' ), 'a key: the daily pass is scheduled once' );

echo "\nGroup E: check 30\n";
$j = sn_health_jev_notes_judge( array(
	7 => array( 'title' => 'Sure and fine', 'verdict' => array( 'title' => array( 'score' => 1.8, 'confidence' => 0.95, 'sure' => true ), 'description' => array( 'score' => 1.5, 'confidence' => 0.95, 'sure' => true ), 'opening' => array( 'noul' => 0.7 ) ), 'at' => 1, 'error' => '' ),
	8 => array( 'title' => 'Aphorism, sure', 'verdict' => array( 'title' => array( 'score' => 0.1, 'confidence' => 0.95, 'sure' => true ), 'description' => array( 'score' => 0.0, 'confidence' => 0.5, 'sure' => false ), 'opening' => array( 'noul' => 0.2 ) ), 'at' => 1, 'error' => '' ),
	9 => array( 'title' => 'Low but unsure', 'verdict' => array( 'title' => array( 'score' => 0.0, 'confidence' => 0.6, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.9 ) ), 'at' => 1, 'error' => '' ),
	10 => array( 'title' => 'Never judged', 'verdict' => null, 'at' => 0, 'error' => 'x' ),
	'junk',
) );
ok( 3 === $j['judged'] && 1 === count( $j['findings'] ) && 8 === $j['findings'][0]['subject_id'] && false !== strpos( $j['findings'][0]['note'], 'aphorism, not a query' ) && false !== strpos( $j['findings'][0]['note'], 'of 2' ) && false === strpos( $j['findings'][0]['note'], 'fragment' ), 'THE PIN: a finding needs a low position AND the floor; note 8 fires on the title only (its description is low but unsure); the note says "of 2"' );
ok( 0.5 === SN_JEV_TITLE_FINDING_MAX && array() === sn_health_jev_notes_judge( array( 1 => array( 'title' => 'Level one', 'verdict' => array( 'title' => array( 'score' => 1.0, 'confidence' => 0.99, 'sure' => true ), 'description' => array( 'score' => 1.0, 'confidence' => 0.99, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 1, 'error' => '' ) ) )['findings'], 'THE SCALE PIN (16.3.2): a score is 0..2, level one (names the subject, phrased differently) is NOT low; 16.3.0 drew the line at 1.5 and read 68 of 69 notes as unsure' );
ok( 2 === $j['unsure'], 'two readings below the floor are counted as unsure, never as findings (note 8 description, note 9 title)' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = '';
ok( is_string( sn_health_check_jev_notes()['skipped'] ) && 0 === sn_health_check_jev_notes()['count'], 'no key: SKIPPED, never a pass' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'ts-secret'; unset( $GLOBALS['__j']['opt'][ SN_JEV_DATA_OPTION ] );
ok( false !== strpos( (string) sn_health_check_jev_notes()['skipped'], 'has not run' ), 'a key but no pass yet: skipped, says so' );
$GLOBALS['__j']['opt'][ SN_JEV_DATA_OPTION ] = array( 'synced_at' => 1, 'notes' => array( 8 => array( 'title' => 'A', 'verdict' => array( 'title' => array( 'score' => 0.3, 'confidence' => 0.4, 'sure' => false ), 'description' => array( 'score' => 2, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 1, 'error' => '' ) ) );
$c = sn_health_check_jev_notes();
ok( 0 === $c['count'] && null === $c['skipped'] && false !== strpos( $c['fix_hint'], '1 reading(s) fell below' ), 'a pass with one unsure reading: zero findings, the check RAN, the hint says one fell below the floor' );

echo "\nGroup F: the probe\n";
$rows = sn_keyring();
ok( isset( $rows['typesafe_api_key'] ) && 'typesafe' === $rows['typesafe_api_key']['probe'] && 'secret' === $rows['typesafe_api_key']['kind'], 'the keyring row: issued, secret, probed as typesafe' );
$GLOBALS['__j']['answer'] = $ok200( array( 'probe' => array( 'type' => 'noul', 'noul' => 0.97 ) ) );
$v = sn_keyring_probe( 'typesafe_api_key', $rows['typesafe_api_key'] );
ok( 'ok' === $v['status'] && false !== strpos( $v['detail'], '0.97' ), 'Jev answers the probe: ok, with the probability' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":{"message":"Invalid API key"}}' );
ok( 'refused' === sn_keyring_probe( 'typesafe_api_key', $rows['typesafe_api_key'] )['status'], 'a 401 is refused' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 529 ), 'body' => '' );
ok( 'error' === sn_keyring_probe( 'typesafe_api_key', $rows['typesafe_api_key'] )['status'], 'a 529 is TypeSafe\'s side: error' );

echo "\nGroup G: the ability\n";
foreach ( $GLOBALS['__j']['actions']['wp_abilities_api_init'] as $cb ) { $cb(); }
$ab = $GLOBALS['__j']['abilities']['signal-noise/jev-notes'] ?? null;
ok( is_array( $ab ) && array( 'object', 'null' ) === $ab['input_schema']['type'] && true === $ab['meta']['annotations']['readonly'], 'registers readonly with the [object,null] input union' );
$out = snt_ability_jev_notes();
ok( 'typesafe-jev' === $out['source'] && true === $out['synced'] && 1 === $out['judged'] && 1 === $out['unsure'] && array() === $out['findings'] && 1 === count( $out['notes'] ) && 0.3 === $out['notes'][0]['title_score'] && 0.4 === $out['notes'][0]['title_confidence'], 'the ability reads the stored pass, names its source, and hands every note\'s readings out for tuning' );
unset( $GLOBALS['__j']['opt'][ SN_JEV_DATA_OPTION ] );
ok( false === snt_ability_jev_notes()['synced'] && true === snt_ability_jev_notes()['ready'], 'nothing stored: synced false, ready true' );
$ab = $GLOBALS['__j']['abilities']['signal-noise/jev-pass-now'] ?? null;
ok( is_array( $ab ) && false === $ab['meta']['annotations']['readonly'] && true === $ab['meta']['annotations']['idempotent'] && 'maintenance' === $ab['category'], 'jev-pass-now registers as a write, idempotent, maintenance' );
$GLOBALS['__j']['answer'] = static function ( $body ) use ( $ok200 ) { return $ok200( array( 'title_query' => array( 'type' => 'score', 'score' => 1.9, 'confidence' => 0.95 ), 'description_says' => array( 'type' => 'score', 'score' => 2.0, 'confidence' => 0.95 ), 'opening_names' => array( 'type' => 'noul', 'noul' => 0.9 ) ) ); };
$r = snt_ability_jev_pass_now();
ok( true === $r['ok'] && 2 === $r['judged'] && 0 === $r['failed'] && 2 === $r['usage']['requests'], 'the on-demand pass runs the same sync and returns its counts' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
