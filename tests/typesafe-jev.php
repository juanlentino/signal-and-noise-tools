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
class WP_Error { public $m; public $d; function __construct( $c = '', $m = '', $d = null ) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
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
function delete_option( $k ) { unset( $GLOBALS['__j']['opt'][ $k ] ); return true; }
// 16.5.2: the connector owns the key. The stub reads the same test switch the
// pre-16.5.2 tests flipped, so every group below keeps its shape.
require __DIR__ . '/stubs/jev-connector.php';

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
ok( true === $r['ok'] && 'jev-latest' === $c['model'] && array( 'title' => 'x' ) === $c['state'] && isset( $c['questions']['q'] ), 'the request goes through JevConnector\\ask() with the model, state and questions (16.5.3: the connector owns the transport)' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":{"message":"invalid key ts-secret"}}' );
$r = sn_jev_ask( 's', array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) ) );
ok( false === $r['ok'] && 401 === $r['code'] && 'TypeSafe returned HTTP 401.' === $r['error'], 'a 401 carries the connector\'s status and message (16.5.3: the connector never puts the key in a message)' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"model":"jev-latest"}' );
ok( 'unparsed' === sn_jev_ask( 's', array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) ) )['error'], 'a 200 without answers is not ok: unparsed' );

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
ok( array_key_exists( 'previous', $d['notes'][8] ) && null === $d['notes'][8]['previous'], '16.8.1: the first pass stores previous as null' );
$GLOBALS['__j']['answer'] = $ok200( array( 'title_query' => array( 'type' => 'score', 'score' => 1.7, 'confidence' => 0.8 ), 'description_says' => array( 'type' => 'score', 'score' => 2.0, 'confidence' => 0.9 ), 'opening_names' => array( 'type' => 'noul', 'noul' => 0.5 ) ) );
sn_jev_sync();
ok( 0.2 === sn_jev_data()['notes'][8]['previous']['title']['score'] && 1.7 === sn_jev_data()['notes'][8]['verdict']['title']['score'], '16.8.1: the second pass keeps the first verdict as previous' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 529 ), 'body' => '{"message":"overloaded"}' );
$r = sn_jev_sync();
$d = sn_jev_data();
ok( false === $r['ok'] && 2 === $r['failed'] && 1.7 === $d['notes'][8]['verdict']['title']['score'] && false !== strpos( $d['notes'][8]['error'], 'HTTP 529' ) && false !== strpos( $d['last_error'], 'HTTP 529' ), 'a failed request keeps the previous verdict with the error beside it' );
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

echo "\nGroup E: check 30 (16.3.4: below level one is a finding; confidence is the caveat)\n";
$j = sn_health_jev_notes_judge( array(
	7 => array( 'title' => 'Fine', 'verdict' => array( 'title' => array( 'score' => 1.5, 'confidence' => 0.3, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.95, 'sure' => true ), 'opening' => array( 'noul' => 0.7 ) ), 'at' => 1, 'error' => '' ),
	8 => array( 'title' => 'Low and sure', 'verdict' => array( 'title' => array( 'score' => 0.58, 'confidence' => 0.7, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.2 ) ), 'at' => 1, 'error' => '' ),
	9 => array( 'title' => 'Low and unsure', 'verdict' => array( 'title' => array( 'score' => 0.66, 'confidence' => 0.02, 'sure' => false ), 'description' => array( 'score' => 0.4, 'confidence' => 0.2, 'sure' => false ), 'opening' => array( 'noul' => 0.9 ) ), 'at' => 1, 'error' => '' ),
	10 => array( 'title' => 'Exactly one', 'verdict' => array( 'title' => array( 'score' => 1.0, 'confidence' => 0.9, 'sure' => true ), 'description' => array( 'score' => 1.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 1, 'error' => '' ),
	11 => array( 'title' => 'Never judged', 'verdict' => null, 'at' => 0, 'error' => 'x' ),
	'junk',
) );
ok( 4 === $j['judged'] && array( 8, 9 ) === array_column( $j['findings'], 'subject_id' ), 'THE PIN: a position below level one is a finding whatever the confidence; level one itself and above are not (the 0.9 floor hid every reading on 16.3.3\'s pass: one of 69 cleared it)' );
// 16.8.1: an edge reading is taken twice.
$two = sn_health_jev_notes_judge( array(
	20 => array( 'title' => 'Flapped', 'verdict' => array( 'title' => array( 'score' => 0.98, 'confidence' => 0.4, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'previous' => array( 'title' => array( 'score' => 1.0, 'confidence' => 0.9, 'sure' => true ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 2, 'error' => '' ),
	21 => array( 'title' => 'Held', 'verdict' => array( 'title' => array( 'score' => 0.7, 'confidence' => 0.3, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'previous' => array( 'title' => array( 'score' => 0.8, 'confidence' => 0.2, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 2, 'error' => '' ),
	22 => array( 'title' => 'First read', 'verdict' => array( 'title' => array( 'score' => 0.6, 'confidence' => 0.6, 'sure' => false ), 'description' => array( 'score' => 2.0, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 1, 'error' => '' ),
	23 => array( 'title' => 'Description held, title flapped', 'verdict' => array( 'title' => array( 'score' => 0.9, 'confidence' => 0.6, 'sure' => false ), 'description' => array( 'score' => 0.5, 'confidence' => 0.9, 'sure' => false ), 'opening' => array( 'noul' => 0.5 ) ), 'previous' => array( 'title' => array( 'score' => 1.4, 'confidence' => 0.6, 'sure' => false ), 'description' => array( 'score' => 0.4, 'confidence' => 0.9, 'sure' => false ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 2, 'error' => '' ),
) );
ok( array( 21, 22, 23 ) === array_column( $two['findings'], 'subject_id' ), '16.8.1: a note below the line today but above it yesterday is not listed; below on both passes is; a first reading with no previous pass still counts' );
ok( false === strpos( $two['findings'][2]['note'], 'search title' ) && false !== strpos( $two['findings'][2]['note'], 'description below' ), '16.8.1: the two-pass rule is per field: the description that held is listed, the title that flapped is not' );
ok( false !== strpos( $j['findings'][0]['note'], 'rubric 0.58 of 2, confidence 0.70' ) && false === strpos( $j['findings'][0]['note'], 'unsure' ), 'confidence at or above 0.5: the note carries the numbers and no caveat' );
ok( false !== strpos( $j['findings'][1]['note'], 'confidence 0.02: Jev is unsure; read it yourself' ) && false !== strpos( $j['findings'][1]['note'], 'below "says what it is about"' ) && 1 === $j['unsure'], 'confidence under 0.5: the note says Jev is unsure; both fields can fire on one note; unsure counts findings, not readings' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = '';
ok( is_string( sn_health_check_jev_notes()['skipped'] ) && 0 === sn_health_check_jev_notes()['count'], 'no key: SKIPPED, never a pass' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'ts-secret'; unset( $GLOBALS['__j']['opt'][ SN_JEV_DATA_OPTION ] );
ok( false !== strpos( (string) sn_health_check_jev_notes()['skipped'], 'has not run' ), 'a key but no pass yet: skipped, says so' );
$GLOBALS['__j']['opt'][ SN_JEV_DATA_OPTION ] = array( 'synced_at' => 1, 'notes' => array( 8 => array( 'title' => 'A', 'verdict' => array( 'title' => array( 'score' => 0.3, 'confidence' => 0.4, 'sure' => false ), 'description' => array( 'score' => 2, 'confidence' => 0.9, 'sure' => true ), 'opening' => array( 'noul' => 0.5 ) ), 'at' => 1, 'error' => '' ) ) );
$c = sn_health_check_jev_notes();
ok( 1 === $c['count'] && null === $c['skipped'] && false !== strpos( $c['fix_hint'], '1 of them Jev is unsure about' ) && false !== strpos( $c['label'], 'below "names the subject"' ), 'a pass with one low unsure reading: ONE finding (routed to the human), the check RAN, the hint says Jev is unsure about it' );

echo "
Group F: the key is the connector's (16.5.2)
";
ok( ! isset( sn_keyring()['typesafe_api_key'] ), 'the keyring holds no TypeSafe row' );
ok( 'ts-secret' === sn_jev_key(), 'the key reads through JevConnector\Connector::get_api_key()' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'legacy-key'; unset( $GLOBALS['__j']['opt']['connectors_typesafe_api_key'] );
ok( 'moved' === sn_jev_migrate_legacy_key() && 'legacy-key' === $GLOBALS['__j']['opt']['connectors_typesafe_api_key'] && ! isset( $GLOBALS['__j']['opt']['sn_typesafe_api_key'] ), 'the legacy option moves into Core\'s connector option once and is deleted' );
ok( 'none' === sn_jev_migrate_legacy_key(), 'a second run is a no-op' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'stale'; $GLOBALS['__j']['opt']['connectors_typesafe_api_key'] = 'theirs';
ok( 'dropped' === sn_jev_migrate_legacy_key() && 'theirs' === $GLOBALS['__j']['opt']['connectors_typesafe_api_key'] && ! isset( $GLOBALS['__j']['opt']['sn_typesafe_api_key'] ), 'when the connector already holds a key the legacy one is dropped, never overwrites' );
ok( in_array( 'sn_jev_migrate_legacy_key', $GLOBALS['__j']['actions']['plugins_loaded'] ?? array(), true ), 'the migration hangs on plugins_loaded' );
$GLOBALS['__j']['opt']['sn_typesafe_api_key'] = 'ts-secret';

echo "\nGroup G: the ability\n";
foreach ( $GLOBALS['__j']['actions']['wp_abilities_api_init'] as $cb ) { $cb(); }
$ab = $GLOBALS['__j']['abilities']['signal-noise/jev-notes'] ?? null;
ok( is_array( $ab ) && array( 'object', 'null' ) === $ab['input_schema']['type'] && true === $ab['meta']['annotations']['readonly'], 'registers readonly with the [object,null] input union' );
$out = snt_ability_jev_notes();
ok( 'typesafe-jev' === $out['source'] && true === $out['synced'] && 1 === $out['judged'] && 1 === $out['unsure'] && 1 === count( $out['findings'] ) && 1 === count( $out['notes'] ) && 0.3 === $out['notes'][0]['title_score'] && 0.4 === $out['notes'][0]['title_confidence'], 'the ability reads the stored pass, names its source, and hands every note\'s readings out for tuning' );
unset( $GLOBALS['__j']['opt'][ SN_JEV_DATA_OPTION ] );
ok( false === snt_ability_jev_notes()['synced'] && true === snt_ability_jev_notes()['ready'], 'nothing stored: synced false, ready true' );
$ab = $GLOBALS['__j']['abilities']['signal-noise/jev-pass-now'] ?? null;
ok( is_array( $ab ) && false === $ab['meta']['annotations']['readonly'] && true === $ab['meta']['annotations']['idempotent'] && 'maintenance' === $ab['category'], 'jev-pass-now registers as a write, idempotent, maintenance' );
$GLOBALS['__j']['answer'] = static function ( $body ) use ( $ok200 ) { return $ok200( array( 'title_query' => array( 'type' => 'score', 'score' => 1.9, 'confidence' => 0.95 ), 'description_says' => array( 'type' => 'score', 'score' => 2.0, 'confidence' => 0.95 ), 'opening_names' => array( 'type' => 'noul', 'noul' => 0.9 ) ) ); };
$r = snt_ability_jev_pass_now();
ok( true === $r['ok'] && 2 === $r['judged'] && 0 === $r['failed'] && 2 === $r['usage']['requests'], 'the on-demand pass runs the same sync and returns its counts' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
