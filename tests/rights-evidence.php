<?php
/**
 * Tests: inc/rights-evidence-compose.php + inc/rights-evidence.php + the two abilities (17.0.0).
 * Run: php tests/rights-evidence.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__k'] = array( 'opt' => array(), 'actions' => array(), 'abilities' => array(), 'scheduled' => array(), 'worker' => 'https://prov.example/', 'secret' => 's3', 'mr' => true, 'fetch' => array(), 'index' => null, 'posts' => array(), 'post_reply' => null, 'sensor' => array( 'version' => '1.25.4' ) );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__k']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__k']['opt'] ) ? $GLOBALS['__k']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__k']['opt'][ $k ] = $v; return true; }
$GLOBALS['__k']['transients'] = array();
function get_transient( $k ) { return $GLOBALS['__k']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__k']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__k']['transients'][ $k ] ); return true; }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function wp_next_scheduled( $h ) { return $GLOBALS['__k']['scheduled'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__k']['scheduled'][ $h ] = $r; return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__k']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function sn_prov_worker_url() { return $GLOBALS['__k']['worker']; }
function sn_prov_hmac_secret() { return $GLOBALS['__k']['secret']; }
function sn_prov_url_allowed( $u ) { return str_starts_with( $u, 'https://' ); }
function snt_mr_config() { return $GLOBALS['__k']['mr'] ? array( 'url' => 'https://s', 'token' => 't' ) : null; }
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) { $GLOBALS['__k']['fetch'][] = array( $days, $view ); return $GLOBALS['__k'][ 'rows_' . $view ] ?? array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ); }
function snt_mr_sensor_info() { return $GLOBALS['__k']['sensor']; }
function snt_mr_ai_training_families() { return array( 'openai', 'anthropic', 'google-ai' ); }
function sn_prov_integrity_ledger_base() { return 'https://raw.example/ledger/main/'; }
function sn_prov_integrity_http_fetch( $url ) { $GLOBALS['__k']['index_url'] = $url; return null === $GLOBALS['__k']['index'] ? array( 'code' => 0, 'body' => '' ) : array( 'code' => 200, 'body' => json_encode( $GLOBALS['__k']['index'] ) ); }
function wp_remote_post( $url, $args ) { $GLOBALS['__k']['posts'][] = array( 'url' => $url, 'args' => $args ); $r = $GLOBALS['__k']['post_reply']; return is_callable( $r ) ? $r( $url, $args ) : $r; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
// provenance-core.php registers hooks and meta at load; give it the seams it names.
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $h, $v ) { return $v; }
function register_post_meta( $t, $k, $a ) { return true; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function get_post_meta( $id, $k = '', $s = false ) { return ''; }
function update_post_meta( $id, $k, $v ) { return true; }

require __DIR__ . '/../inc/provenance-core.php';
require __DIR__ . '/../inc/rights-evidence-compose.php';
require __DIR__ . '/../inc/rights-evidence.php';
require __DIR__ . '/../inc/abilities-rights-evidence.php';
foreach ( $GLOBALS['__k']['actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

// A: the id and the month.
$u1 = sn_rights_evidence_uuid( 'openai', '2026-08', 'https://x.test/' );
ok( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u1 ), 'A1 the id is a UUIDv5 (version nibble 5, RFC variant), the shape the worker admits' );
ok( $u1 === sn_rights_evidence_uuid( 'openai', '2026-08', 'https://x.test' ) && $u1 !== sn_rights_evidence_uuid( 'openai', '2026-09', 'https://x.test/' ) && $u1 !== sn_rights_evidence_uuid( 'anthropic', '2026-08', 'https://x.test/' ), 'A2 deterministic over family + month + site, a trailing slash ignored; another month or family is another id' );
$m = sn_rights_evidence_month( strtotime( '2026-09-19T14:00:00Z' ) );
ok( array( 'month' => '2026-08', 'start' => '2026-08-01', 'end' => '2026-08-31' ) === $m, 'A3 the last complete month, UTC, with its edges' );
ok( '2026-12' === sn_rights_evidence_month( strtotime( '2027-01-01T00:00:00Z' ) )['month'] && '2026-02-28' === sn_rights_evidence_month( strtotime( '2026-03-15T00:00:00Z' ) )['end'], 'A4 the year boundary and February' );

// B: the reservation from the ledger index.
$index = array( 'rights_signals' => array(
	array( 'slug' => 'tdmrep-json', 'url' => 'u', 'version' => 1, 'content_hash' => 'h1', 'ots_status' => 'confirmed', 'bitcoin_block' => 959348 ),
	array( 'slug' => 'license-xml', 'version' => 2, 'content_hash' => 'h2', 'ots_status' => 'pending' ),
	'junk',
) );
$res = sn_rights_evidence_reservation( $index );
ok( 2 === count( $res ) && 'license-xml' === $res[0]['slug'] && null === $res[0]['bitcoin_block'] && 959348 === $res[1]['bitcoin_block'] && 'h1' === $res[1]['content_hash'], 'B1 one row per signal, by slug, hash and anchor carried, a missing block is null, junk dropped' );
ok( null === sn_rights_evidence_reservation( array() ) && null === sn_rights_evidence_reservation( array( 'rights_signals' => array() ) ), 'B2 an index with no signals is null, never an empty reservation' );

// C: compose, pure.
$agg = function ( $family, $day, $surface, $purpose, $hits ) { return compact( 'family', 'day', 'surface', 'purpose', 'hits' ) + array( 'taxonomy_version' => '1.4' ); };
$aggregate = array( 'ok' => true, 'truncated' => false, 'rows' => array(
	$agg( 'openai', '2026-08-03', 'html', 'train', 40 ),
	$agg( 'openai', '2026-08-03', 'rights', 'train', 1 ),
	$agg( 'openai', '2026-08-20', 'html', 'search', 9 ),
	$agg( 'openai', '2026-09-02', 'html', 'train', 7 ),   // outside the month
	$agg( 'openai', '2026-07-31', 'html', 'train', 5 ),   // outside the month
	$agg( 'anthropic', '2026-08-10', 'html', 'train', 3 ),
	$agg( 'google', '2026-08-10', 'html', 'search', 300 ), // not an AI-training family
) );
$rd = function ( $family, $at, $path, $hits = 1 ) { return compact( 'family', 'path', 'hits' ) + array( 'observed_at' => $at, 'vendor' => 'v', 'purpose' => 'train', 'user_agent' => 'UA', 'accept' => '*/*' ); };
$rights = array( 'ok' => true, 'truncated' => false, 'rows' => array(
	$rd( 'openai', '2026-08-20T10:00:00Z', '/license.xml' ),
	$rd( 'openai', '2026-08-03T09:00:00Z', '/.well-known/tdmrep.json', 2 ),
	$rd( 'openai', '2026-09-01T00:00:00Z', '/license.xml' ),
	$rd( 'anthropic', '2026-08-10T00:00:00Z', '/tdm-policy' ),
) );
$now = strtotime( '2026-09-19T14:00:00Z' );
$p = sn_rights_evidence_compose( 'openai', $m, $aggregate, $rights, $res, array( 'version' => '1.25.4', 'taxonomy' => '1.4' ), 'https://x.test/', $now );
ok( 'rights-evidence' === $p['kind'] && 'openai' === $p['family'] && '2026-08' === $p['month'] && 'https://x.test' === $p['site'] && '2026-09-19T14:00:00+00:00' === $p['composed_at'], 'C1 kind, family, month, site, composed_at' );
ok( 50 === $p['crawling']['reads'] && 41 === $p['crawling']['train'] && array( '2026-08-03' => array( 'reads' => 41, 'train' => 41 ), '2026-08-20' => array( 'reads' => 9, 'train' => 0 ) ) === (array) $p['crawling']['by_day'] && array( 'html' => 49, 'rights' => 1 ) === (array) $p['crawling']['by_surface'] && true === $p['crawling']['complete'], 'C2 crawling: the family, the month, per day with the training share, per surface; rows outside the month and other families excluded' );
ok( 3 === $p['rights_reads']['reads'] && array( '/.well-known/tdmrep.json' => 2, '/license.xml' => 1 ) === (array) $p['rights_reads']['by_path'] && '2026-08-03T09:00:00Z' === $p['rights_reads']['first'] && '2026-08-20T10:00:00Z' === $p['rights_reads']['last'] && true === $p['rights_reads']['complete'], 'C3 rights reads: hits summed per path, first and last in time order, the September read excluded' );
ok( $res === $p['reservation']['signals'] && '2026-09-19T14:00:00+00:00' === $p['reservation']['as_of'] && array( 'version' => '1.25.4', 'taxonomy' => '1.4' ) === $p['sensor'], 'C4 the reservation rides verbatim with as_of; the sensor names itself' );
$c = sn_prov_canonical_json( $p );
ok( false === strpos( $c, 'UA' ) && false === strpos( $c, 'user_agent' ) && false === strpos( $c, 'accept' ), 'C5 no user-agent string or Accept header reaches the record' );
ok( '{"composed_at"' === substr( $c, 0, 14 ) && str_contains( $c, '"by_path":{"/.well-known/tdmrep.json":2,"/license.xml":1}' ) && str_contains( $c, '"by_day":{"2026-08-03":{"reads":41,"train":41},"2026-08-20":{"reads":9,"train":0}}' ), 'C6 canonical bytes: keys sorted at every level, maps as objects with sorted keys (the worker re-canonicalizes and compares bytes)' );
$empty = sn_rights_evidence_compose( 'google-ai', $m, $aggregate, $rights, $res, array(), 'https://x.test', $now );
ok( 0 === $empty['crawling']['reads'] && '{}' === json_encode( $empty['crawling']['by_day'] ) && '{}' === json_encode( $empty['rights_reads']['by_path'] ) && '' === $empty['rights_reads']['first'], 'C7 a family with nothing composes empty OBJECTS, never lists' );
$trunc_agg = array( 'ok' => true, 'truncated' => true, 'rows' => array( $agg( 'openai', '2026-08-03', 'html', 'train', 1 ), $agg( 'openai', '2026-08-30', 'html', 'train', 1 ) ) );
$trunc_rts = array( 'ok' => true, 'truncated' => true, 'rows' => array( $rd( 'openai', '2026-08-05T00:00:00Z', '/license.xml' ) ) );
$t = sn_rights_evidence_compose( 'openai', $m, $trunc_agg, $trunc_rts, $res, array(), 'https://x.test', $now );
ok( false === $t['crawling']['complete'] && false === $t['rights_reads']['complete'], 'C8 truncated reads whose edge falls inside the month say complete:false (aggregate cut at the newest day, stream at the oldest)' );
$t2 = sn_rights_evidence_compose( 'openai', $m, array( 'ok' => true, 'truncated' => true, 'rows' => array( $agg( 'openai', '2026-08-31', 'html', 'train', 1 ), $agg( 'openai', '2026-09-03', 'html', 'train', 1 ) ) ), array( 'ok' => true, 'truncated' => true, 'rows' => array( $rd( 'openai', '2026-08-01T00:00:00Z', '/license.xml' ) ) ), $res, array(), 'https://x.test', $now );
ok( true === $t2['crawling']['complete'] && true === $t2['rights_reads']['complete'], 'C9 truncated reads whose edge falls outside the month still cover it' );
ok( array( 'anthropic', 'openai' ) === sn_rights_evidence_families( $aggregate, $m ), 'C10 families: AI-training families seen in the month, sorted; google (search) and the September-only rows do not count' );

// D: the run.
$GLOBALS['__k']['rows_aggregate'] = $aggregate; $GLOBALS['__k']['rows_rights'] = $rights; $GLOBALS['__k']['index'] = $index;
$GLOBALS['__k']['post_reply'] = static function ( $url, $args ) { $b = json_decode( $args['body'], true ); return array( 'code' => 202, 'body' => json_encode( array( 'ok' => true, 'ots_status' => 'pending', 'ledger_path' => 'rights-evidence/' . $b['note_uid'] . '/v1.json' ) ) ); };
$r = sn_rights_evidence_run( $now );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['composed'] && 2 === $r['posted'] && 0 === $r['anchored'], 'D1 the first pass composes and posts one record per family seen: ' . json_encode( $r ) );
ok( 2 === count( $GLOBALS['__k']['posts'] ) && 'https://prov.example/' === $GLOBALS['__k']['posts'][0]['url'], 'D2 two POSTs to the worker' );
$body = json_decode( $GLOBALS['__k']['posts'][0]['args']['body'], true );
ok( 'rights-evidence' === $body['kind'] && 1 === $body['version'] && hash( 'sha256', $body['canonical'] ) === $body['content_hash'] && sn_rights_evidence_uuid( 'anthropic', '2026-08', 'https://x.test/' ) === $body['note_uid'], 'D3 the body: kind, version 1, the hash of the canonical bytes, the deterministic id (anthropic first, sorted)' );
ok( 'sha256=' . hash_hmac( 'sha256', $GLOBALS['__k']['posts'][0]['args']['body'], 's3' ) === $GLOBALS['__k']['posts'][0]['args']['headers']['X-SN-Signature'] && 0 === $GLOBALS['__k']['posts'][0]['args']['redirection'], 'D4 HMAC over the exact body with the site secret; no redirects' );
$d = sn_rights_evidence_data();
ok( 'pending' === $d['2026-08']['openai']['status'] && str_starts_with( $d['2026-08']['openai']['ledger_path'], 'rights-evidence/' ) && ! isset( $d['2026-08']['openai']['canonical'] ), 'D5 stored: status from the worker, the ledger path, the bytes dropped once the ledger has them' );
ok( array( array( 51, 'aggregate' ), array( 51, 'rights' ) ) === $GLOBALS['__k']['fetch'], 'D6 the window reaches back past the first of the month (Aug 1 to Sep 19 14:00 is 49.6 days: 51); the aggregate and the rights stream each read ONCE for the pass' );
ok( str_ends_with( $GLOBALS['__k']['index_url'], '/index.json' ), 'D7 the reservation comes from the ledger index' );
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['fetch'] = array();
$r = sn_rights_evidence_run( $now + DAY_IN_SECONDS );
ok( $r['ok'] && 0 === $r['composed'] && 0 === $r['posted'] && 2 === $r['anchored'] && array() === $GLOBALS['__k']['posts'], 'D8 the next day: both on the ledger, nothing composed or posted' );

// E: a failed post keeps the bytes and re-sends them byte-identical.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 502, 'body' => json_encode( array( 'ok' => false, 'error' => 'calendar unreachable' ) ) );
$r = sn_rights_evidence_run( $now );
$d = sn_rights_evidence_data();
ok( ! $r['ok'] && 2 === $r['composed'] && 0 === $r['posted'] && 2 === $r['failed'] && 'unanchored' === $d['2026-08']['openai']['status'] && '502 calendar unreachable' === $d['2026-08']['openai']['error'] && '' !== $d['2026-08']['openai']['canonical'], 'E1 a 502 leaves the record composed and unanchored, the bytes kept, the error named' );
$kept = $d['2026-08']['openai']['canonical'];
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = null; // the index unreachable now: the retry must not need it
$GLOBALS['__k']['post_reply'] = static function ( $url, $args ) { return array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'existing' => true, 'ots_status' => 'confirmed', 'ledger_path' => 'p.json' ) ) ); };
$r = sn_rights_evidence_run( $now + 2 * DAY_IN_SECONDS );
ok( $r['ok'] && 0 === $r['composed'] && 2 === $r['posted'] && $kept === json_decode( $GLOBALS['__k']['posts'][1]['args']['body'], true )['canonical'] && 'confirmed' === sn_rights_evidence_data()['2026-08']['openai']['status'], 'E2 the retry re-sends the stored bytes verbatim (no recompose, no ledger read) and takes the worker\'s status' );

// F: refusals.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = null; $GLOBALS['__k']['post_reply'] = array( 'code' => 202, 'body' => '{}' );
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && str_contains( $r['error'], 'ledger index' ) && 0 === $r['composed'] && array() === $GLOBALS['__k']['posts'], 'F1 no reservation, no record: nothing composed, nothing posted' );
$GLOBALS['__k']['index'] = $index; $GLOBALS['__k']['rows_rights'] = array( 'ok' => false, 'rows' => array(), 'error' => 'http_503' );
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && 'sensor rights stream: http_503' === $r['error'] && array() === $GLOBALS['__k']['posts'], 'F2 a failed rights read stops the pass before any record' );
$GLOBALS['__k']['rows_rights'] = $rights; $GLOBALS['__k']['secret'] = '';
ok( 'not-ready' === sn_rights_evidence_run( $now )['error'] && ! sn_rights_evidence_is_ready(), 'F3 no secret, not ready' );
$GLOBALS['__k']['secret'] = 's3'; $GLOBALS['__k']['mr'] = false;
ok( 'not-ready' === sn_rights_evidence_run( $now )['error'], 'F4 no sensor, not ready' );
$GLOBALS['__k']['mr'] = true;
$GLOBALS['__k']['rows_aggregate'] = array( 'ok' => false, 'rows' => array(), 'error' => 'network' );
ok( 'sensor: network' === sn_rights_evidence_run( $now )['error'], 'F5 a failed aggregate read is named' );
$GLOBALS['__k']['rows_aggregate'] = $aggregate;

// E3: a 409 is terminal: the ledger's record stands, the path is derived from the id, nothing is retried.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = $index;
$GLOBALS['__k']['post_reply'] = array( 'code' => 409, 'body' => json_encode( array( 'error' => 'record path already exists with different immutable bytes' ) ) );
$r = sn_rights_evidence_run( $now );
$e = sn_rights_evidence_data()['2026-08']['openai'];
ok( ! $r['ok'] && 2 === $r['failed'] && 'conflict' === $e['status'] && 'rights-evidence/' . $e['uuid'] . '/v1.json' === $e['ledger_path'] && ! isset( $e['canonical'] ), 'E3 a 409 is conflict with the deterministic ledger path and no bytes kept' );
$GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $now + DAY_IN_SECONDS );
ok( $r['ok'] && 2 === $r['anchored'] && array() === $GLOBALS['__k']['posts'], 'E4 the next day a conflict counts as on the ledger and is not re-sent' );
// E5: the lock.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['transients']['sn_rights_evidence_lock'] = 1;
ok( 'a pass is already running' === sn_rights_evidence_run( $now )['error'] && array() === $GLOBALS['__k']['posts'], 'E5 a running pass refuses a second one' );
$GLOBALS['__k']['transients'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 202, 'body' => json_encode( array( 'ok' => true, 'ots_status' => 'pending', 'ledger_path' => 'x.json' ) ) );
sn_rights_evidence_run( $now );
ok( ! isset( $GLOBALS['__k']['transients']['sn_rights_evidence_lock'] ), 'E6 the lock is released after the pass' );
$GLOBALS['__k']['rows_aggregate'] = array( 'ok' => false, 'rows' => array(), 'error' => 'network' );
sn_rights_evidence_run( $now );
ok( ! isset( $GLOBALS['__k']['transients']['sn_rights_evidence_lock'] ), 'E7 and after a sensor refusal' );
$GLOBALS['__k']['rows_aggregate'] = $aggregate;

// G: the abilities and the schedule.
$ab = $GLOBALS['__k']['abilities'];
ok( true === $ab['signal-noise/rights-evidence']['meta']['annotations']['readonly'] && false === $ab['signal-noise/rights-evidence-now']['meta']['annotations']['readonly'] && true === $ab['signal-noise/rights-evidence-now']['meta']['annotations']['open_world_hint'], 'G1 one read, one write that says it publishes' );
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['post_reply'] = static function ( $url, $args ) { return array( 'code' => 202, 'body' => json_encode( array( 'ok' => true, 'ots_status' => 'pending', 'ledger_path' => 'x.json' ) ) ); };
snt_ability_rights_evidence_now();
$o = snt_ability_rights_evidence();
ok( $o['ok'] && $o['ready'] && 'https://raw.example/ledger/main/' === $o['ledger_base'] && isset( ( (array) $o['months'] )['2026-08']['openai']['uuid'] ) && ! isset( ( (array) $o['months'] )['2026-08']['openai']['canonical'] ), 'G2 the read hands out the ledger with the base URL and never the bytes' );
foreach ( $GLOBALS['__k']['actions']['init'] as $cb ) { $cb(); }
ok( 'daily' === ( $GLOBALS['__k']['scheduled'][ SN_RIGHTS_EVIDENCE_HOOK ] ?? '' ), 'G3 daily when ready' );

$GLOBALS['__k']['opt'] = array();
ok( '{}' === json_encode( snt_ability_rights_evidence()['months'] ), 'G4 no records yet: months encodes as {} at the door, never []' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
