<?php
/**
 * Tests: inc/rights-evidence-compose.php + -ledger.php + -dry-run.php +
 * rights-evidence.php + the two abilities (17.0.0; schema 2 in 19.10.0).
 * Run: php tests/rights-evidence.php
 * SN_RE_PRINT=1 also prints the September dry-run payloads and the August v2
 * drafts composed from the fixtures below.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__k'] = array( 'opt' => array(), 'actions' => array(), 'abilities' => array(), 'scheduled' => array(), 'worker' => 'https://prov.example/', 'secret' => 's3', 'mr' => true, 'fetch' => array(), 'index' => null, 'posts' => array(), 'post_reply' => null, 'sensor' => array( 'version' => '1.25.4' ), 'http' => array(), 'ledger' => array(), 'blocks' => array() );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__k']['actions'][ $t ][] = $c; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__k']['opt'] ) ? $GLOBALS['__k']['opt'][ $k ] : $d; }
function add_option( $k, $v, $d = '', $a = null ) { if ( ! array_key_exists( $k, $GLOBALS['__k']['opt'] ) ) { $GLOBALS['__k']['opt'][ $k ] = $v; } return true; }
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
const SNT_MR_RIGHTS_EXCLUDE = array( 'dev', 'ops' );
function snt_mr_fetch( $days = 30, $view = 'aggregate', array $filter = array() ) { $GLOBALS['__k']['fetch'][] = array( $days, $view, (string) ( $filter['family'] ?? '' ) ); $GLOBALS['__k']['filters'][] = $filter; return $GLOBALS['__k'][ 'rows_' . $view ] ?? array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ); }
function snt_mr_sensor_info() { return $GLOBALS['__k']['sensor']; }
function snt_mr_ai_training_families() { return array( 'openai', 'anthropic', 'google-ai', 'mistral' ); }
function sn_prov_integrity_ledger_base() { return 'https://raw.example/ledger/main/'; }
// One seam for every read: the ledger index, ledger files by path, and the explorer (height -> hash -> block).
function sn_prov_integrity_http_fetch( $url ) {
	$GLOBALS['__k']['http'][] = $url;
	$j = static fn( $v ) => array( 'code' => 200, 'body' => json_encode( $v ) );
	if ( str_ends_with( $url, '/index.json' ) ) {
		$GLOBALS['__k']['index_url'] = $url;
		return null === $GLOBALS['__k']['index'] ? array( 'code' => 0, 'body' => '' ) : $j( $GLOBALS['__k']['index'] );
	}
	if ( str_starts_with( $url, 'https://blockstream.info/api/block-height/' ) ) {
		$h = (int) substr( $url, strlen( 'https://blockstream.info/api/block-height/' ) );
		return isset( $GLOBALS['__k']['blocks'][ $h ] ) ? array( 'code' => 200, 'body' => str_pad( dechex( $h ), 64, '0', STR_PAD_LEFT ) ) : array( 'code' => 0, 'body' => '' );
	}
	if ( str_starts_with( $url, 'https://blockstream.info/api/block/' ) ) {
		$h = (int) hexdec( substr( $url, strlen( 'https://blockstream.info/api/block/' ) ) );
		return isset( $GLOBALS['__k']['blocks'][ $h ] ) ? $j( array( 'height' => $h, 'timestamp' => strtotime( $GLOBALS['__k']['blocks'][ $h ] ) ) ) : array( 'code' => 404, 'body' => '' );
	}
	$path = substr( $url, strlen( sn_prov_integrity_ledger_base() ) );
	return isset( $GLOBALS['__k']['ledger'][ $path ] ) ? $j( $GLOBALS['__k']['ledger'][ $path ] ) : array( 'code' => 404, 'body' => '' );
}
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
require __DIR__ . '/../inc/rights-evidence-ledger.php';
require __DIR__ . '/../inc/rights-evidence-dry-run.php';
require __DIR__ . '/../inc/rights-evidence.php';
require __DIR__ . '/../inc/abilities-rights-evidence.php';
foreach ( $GLOBALS['__k']['actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

/** Re-canonicalize the way the worker does: decode, sort EVERY object's keys, re-encode. */
function recanon( $json ) {
	$sort = static function ( $v ) use ( &$sort ) {
		if ( $v instanceof stdClass ) { $a = get_object_vars( $v ); ksort( $a, SORT_STRING ); $o = new stdClass(); foreach ( $a as $k => $x ) { $o->$k = $sort( $x ); } return $o; }
		return is_array( $v ) ? array_map( $sort, $v ) : $v;
	};
	return json_encode( $sort( json_decode( $json ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
}

// The fixture ledger: tdm-policy v1..v3 (v3 anchored after August, the v8 case), license-xml v1 and a pending v2.
$GLOBALS['__k']['blocks'] = array( 100 => '2026-07-01T00:00:00Z', 150 => '2026-07-20T00:00:00Z', 200 => '2026-08-15T12:00:00Z', 300 => '2026-09-17T00:00:00Z' );
$sig = static fn( $h, $b ) => array( 'content_hash' => $h, 'signature' => 's', 'ots' => null === $b ? array( 'status' => 'pending' ) : array( 'status' => 'confirmed', 'bitcoin_block' => $b ) );
$GLOBALS['__k']['ledger'] = array(
	'rights-signals/tdm-policy/v1.json'  => $sig( 't1', 100 ),
	'rights-signals/tdm-policy/v2.json'  => $sig( 't2', 200 ),
	'rights-signals/tdm-policy/v3.json'  => $sig( 't3', 300 ),
	'rights-signals/license-xml/v1.json' => $sig( 'l1', 150 ),
	'rights-signals/license-xml/v2.json' => $sig( 'l2', null ),
);
$index = array( 'rights_signals' => array(
	array( 'slug' => 'tdm-policy', 'version' => 3, 'content_hash' => 't3', 'ots_status' => 'confirmed', 'bitcoin_block' => 300 ),
	array( 'slug' => 'license-xml', 'version' => 2, 'content_hash' => 'l2', 'ots_status' => 'pending' ),
	'junk',
	array( 'slug' => '../etc', 'version' => 1 ),
) );

// A: the id and the month.
$u1 = sn_rights_evidence_uuid( 'openai', '2026-08', 'https://x.test/' );
ok( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u1 ), 'A1 the id is a UUIDv5 (version nibble 5, RFC variant), the shape the worker admits' );
ok( $u1 === sn_rights_evidence_uuid( 'openai', '2026-08', 'https://x.test' ) && $u1 !== sn_rights_evidence_uuid( 'openai', '2026-09', 'https://x.test/' ) && $u1 !== sn_rights_evidence_uuid( 'anthropic', '2026-08', 'https://x.test/' ), 'A2 deterministic over family + month + site, a trailing slash ignored; another month or family is another id' );
$m = sn_rights_evidence_month( strtotime( '2026-09-19T14:00:00Z' ) );
ok( array( 'month' => '2026-08', 'start' => '2026-08-01', 'end' => '2026-08-31' ) === $m, 'A3 the last complete month, UTC, with its edges' );
ok( '2026-12' === sn_rights_evidence_month( strtotime( '2027-01-01T00:00:00Z' ) )['month'] && '2026-02-28' === sn_rights_evidence_month( strtotime( '2026-03-15T00:00:00Z' ) )['end'], 'A4 the year boundary and February' );
$sep = array( 'month' => '2026-09', 'start' => '2026-09-01', 'end' => '2026-09-30' );

// B: the reservation in force (F1): history from the ledger, pure selection by anchor time.
$hist = sn_rights_evidence_signal_history( $index );
ok( array( 'license-xml', 'tdm-policy' ) === array_keys( $hist ) && 3 === count( $hist['tdm-policy'] ) && '' === $hist['license-xml'][1]['anchored_at'] && null === $hist['license-xml'][1]['block'] && '2026-08-15T12:00:00Z' === $hist['tdm-policy'][1]['anchored_at'] && 200 === $hist['tdm-policy'][1]['block'], 'B1 history walks v1..current per slug from the ledger, block time from the explorer; a pending version has no anchor; junk and a path-unsafe slug are dropped' );
$GLOBALS['__k']['http'] = array();
$hist2 = sn_rights_evidence_signal_history( $index );
ok( $hist2 === $hist && array( 'https://raw.example/ledger/main/rights-signals/license-xml/v2.json' ) === $GLOBALS['__k']['http'], 'B2 confirmed versions and block times are cached for good: the second walk reads only the pending version' );
$GLOBALS['__k']['opt'] = array();
$keep = $GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json']; unset( $GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json'] );
ok( null === sn_rights_evidence_signal_history( $index ), 'B3 a missing version file is null: a reservation with a hole is refused' );
$GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json'] = $keep;
$GLOBALS['__k']['opt'] = array(); unset( $GLOBALS['__k']['blocks'][200] );
ok( null === sn_rights_evidence_signal_history( $index ) && ! isset( $GLOBALS['__k']['opt']['sn_rights_evidence_chain']['versions']['tdm-policy'][2] ), 'B4 a confirmed block whose time the explorer cannot give is null, and nothing is cached for it' );
$GLOBALS['__k']['blocks'][200] = '2026-08-15T12:00:00Z'; $GLOBALS['__k']['opt'] = array();
$res = sn_rights_evidence_reservation( $hist, $m );
$expect_aug = array(
	'license-xml' => array( array( 'block' => 150, 'content_hash' => 'l1', 'valid_from' => '2026-07-20T00:00:00Z', 'valid_to' => null, 'version' => 1 ) ),
	'tdm-policy'  => array(
		array( 'block' => 100, 'content_hash' => 't1', 'valid_from' => '2026-07-01T00:00:00Z', 'valid_to' => '2026-08-15T12:00:00Z', 'version' => 1 ),
		array( 'block' => 200, 'content_hash' => 't2', 'valid_from' => '2026-08-15T12:00:00Z', 'valid_to' => '2026-09-17T00:00:00Z', 'version' => 2 ),
	),
);
ok( array( 'start' => '2026-08-01T00:00:00Z', 'end' => '2026-08-31T23:59:59Z' ) === $res['window'] && $expect_aug === (array) $res['signals'], 'B5 August lists every version in force at any point of the month (tdm-policy v1 and v2), each with valid_from/valid_to; v3, anchored in September, is NOT claimed for August' );
$res_sep = sn_rights_evidence_reservation( $hist, $sep );
ok( array( 2, 3 ) === array_column( ( (array) $res_sep['signals'] )['tdm-policy'], 'version' ) && null === ( (array) $res_sep['signals'] )['tdm-policy'][1]['valid_to'], 'B6 September: v2 until v3\'s anchor, v3 open-ended' );
ok( null === ( (array) $res['signals'] )['license-xml'][0]['valid_to'], 'B7 a version not yet in a block is not claimed and leaves the one before it open-ended' );
ok( null === sn_rights_evidence_reservation( $hist, array( 'month' => '2026-06', 'start' => '2026-06-01', 'end' => '2026-06-30' ) ) && null === sn_rights_evidence_reservation( array(), $m ), 'B8 nothing anchored by the month\'s end is null, never an empty reservation' );

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
$rd = function ( $family, $at, $path, $hits = 1, $purpose = 'train' ) { return compact( 'family', 'path', 'hits', 'purpose' ) + array( 'observed_at' => $at, 'vendor' => 'v', 'user_agent' => 'UA', 'accept' => '*/*' ); };
$rights = array( 'ok' => true, 'truncated' => false, 'rows' => array(
	$rd( 'openai', '2026-08-20T10:00:00Z', '/license.xml' ),
	$rd( 'openai', '2026-08-03T09:00:00Z', '/.well-known/tdmrep.json', 2 ),
	$rd( 'openai', '2026-08-21T10:00:00Z', '/license.xml', 3, 'search' ),
	$rd( 'openai', '2026-08-22T10:00:00Z', '/tdm-policy', 1, 'unknown' ),
	$rd( 'openai', '2026-08-23T10:00:00Z', '/license.xml', 5, 'ops' ),
	$rd( 'openai', '2026-09-01T00:00:00Z', '/license.xml' ),
	$rd( 'anthropic', '2026-08-10T00:00:00Z', '/tdm-policy' ),
) );
$now = strtotime( '2026-09-19T14:00:00Z' );
$sensor = array( 'version' => '1.25.4', 'taxonomy' => '1.4' );
$p = sn_rights_evidence_compose( 'openai', $m, $aggregate, $rights, $res, $sensor, 'https://x.test/', $now );
ok( 2 === $p['schema'] && 'rights-evidence' === $p['kind'] && 'openai' === $p['family'] && '2026-08' === $p['month'] && 'https://x.test' === $p['site'] && '2026-09-19T14:00:00+00:00' === $p['composed_at'], 'C1 schema 2, kind, family, month, site, composed_at' );
ok( 50 === $p['crawling']['reads'] && 41 === $p['crawling']['train'] && array( '2026-08-03' => array( 'reads' => 41, 'train' => 41 ), '2026-08-20' => array( 'reads' => 9, 'train' => 0 ) ) === (array) $p['crawling']['by_day'] && '{"search":{"html":9},"train":{"html":40,"rights":1}}' === json_encode( $p['crawling']['by_surface'] ) && true === $p['crawling']['complete'], 'C2 crawling: per day with the training share; by_surface keyed by purpose; rows outside the month and other families excluded' );
$rr = $p['rights_reads'];
ok( 3 === $rr['reads'] && '{"train":3}' === json_encode( $rr['by_purpose'] ) && '{"train":{"/.well-known/tdmrep.json":2,"/license.xml":1}}' === json_encode( $rr['by_path'], JSON_UNESCAPED_SLASHES ) && '2026-08-03T09:00:00Z' === $rr['first'] && '2026-08-20T10:00:00Z' === $rr['last'] && true === $rr['complete'], 'C3 rights_reads is the training claim only: purpose train, by_purpose and by_path keyed by purpose' );
$rt = $p['retrieval_reads'];
ok( 4 === $rt['reads'] && '{"search":3,"unknown":1}' === json_encode( $rt['by_purpose'] ) && '{"search":{"/license.xml":3},"unknown":{"/tdm-policy":1}}' === json_encode( $rt['by_path'], JSON_UNESCAPED_SLASHES ) && '2026-08-21T10:00:00Z' === $rt['first'] && '2026-08-22T10:00:00Z' === $rt['last'], 'C3b retrieval_reads carries search and the unlabelled, same shape; ops rows (our own probes) are in neither' );
ok( $res === $p['reservation'] && ! isset( $p['reservation']['as_of'] ) && array( 'version' => '1.25.4', 'taxonomy' => '1.4' ) === $p['sensor'], 'C4 the reservation rides verbatim (window + signals, no as_of); the sensor names itself' );
$c = sn_prov_canonical_json( $p );
ok( false === strpos( $c, 'UA' ) && false === strpos( $c, 'user_agent' ) && false === strpos( $c, 'accept' ), 'C5 no user-agent string or Accept header reaches the record' );
ok( '{"composed_at"' === substr( $c, 0, 14 ) && str_contains( $c, '"by_day":{"2026-08-03":{"reads":41,"train":41},"2026-08-20":{"reads":9,"train":0}}' ), 'C6 canonical bytes: keys sorted, maps as objects' );
ok( recanon( $c ) === $c && str_contains( $c, '{"block":100,"content_hash":"t1","valid_from":"2026-07-01T00:00:00Z","valid_to":"2026-08-15T12:00:00Z","version":1}' ), 'C6b the bytes survive the worker\'s re-canonicalization: every key sorted INSIDE objects too (sn_prov_canonical_json does not sort there)' );
$empty = sn_rights_evidence_compose( 'google-ai', $m, $aggregate, $rights, $res, $sensor, 'https://x.test', $now );
ok( 0 === $empty['crawling']['reads'] && '{}' === json_encode( $empty['crawling']['by_day'] ) && '{}' === json_encode( $empty['crawling']['by_surface'] ) && '{}' === json_encode( $empty['rights_reads']['by_path'] ) && '{}' === json_encode( $empty['retrieval_reads']['by_purpose'] ) && '' === $empty['rights_reads']['first'], 'C7 a family with nothing composes empty OBJECTS, never lists' );
$trunc_agg = array( 'ok' => true, 'truncated' => true, 'rows' => array( $agg( 'openai', '2026-08-03', 'html', 'train', 1 ), $agg( 'openai', '2026-08-30', 'html', 'train', 1 ) ) );
$trunc_rts = array( 'ok' => true, 'truncated' => true, 'rows' => array( $rd( 'openai', '2026-08-05T00:00:00Z', '/license.xml' ) ) );
$t = sn_rights_evidence_compose( 'openai', $m, $trunc_agg, $trunc_rts, $res, $sensor, 'https://x.test', $now );
ok( false === $t['crawling']['complete'] && false === $t['rights_reads']['complete'] && false === $t['retrieval_reads']['complete'], 'C8 truncated reads whose edge falls inside the month say complete:false' );
$t2 = sn_rights_evidence_compose( 'openai', $m, array( 'ok' => true, 'truncated' => true, 'rows' => array( $agg( 'openai', '2026-08-31', 'html', 'train', 1 ), $agg( 'openai', '2026-09-03', 'html', 'train', 1 ) ) ), array( 'ok' => true, 'truncated' => true, 'rows' => array( $rd( 'openai', '2026-08-01T00:00:00Z', '/license.xml' ) ) ), $res, $sensor, 'https://x.test', $now );
ok( true === $t2['crawling']['complete'] && true === $t2['rights_reads']['complete'], 'C9 truncated reads whose edge falls outside the month still cover it' );
ok( array( 'anthropic', 'openai' ) === sn_rights_evidence_families( $aggregate, $m ), 'C10 families: AI-training families seen in the month, sorted' );
ok( null === sn_rights_evidence_compose( 'openai', $m, $aggregate, $rights, $res, array( 'version' => '1.25.4', 'taxonomy' => '' ), 'https://x.test', $now ) && null === sn_rights_evidence_compose( 'openai', $m, $aggregate, $rights, $res, array(), 'https://x.test', $now ), 'C11 F6: no taxonomy, no record (null, never shipped blank)' );
ok( '1.4' === sn_rights_evidence_taxonomy( $aggregate ) && '1.3.1' === sn_rights_evidence_taxonomy( array( 'taxonomy_version' => '1.3.1' ) + $aggregate ) && '' === sn_rights_evidence_taxonomy( array( 'rows' => array( array( 'taxonomy_version' => '' ) ) ) ), 'C12 F6: the envelope taxonomy first, else the first row that names one, else empty' );

// D: the run.
$GLOBALS['__k']['rows_aggregate'] = $aggregate; $GLOBALS['__k']['rows_rights'] = $rights; $GLOBALS['__k']['index'] = $index;
$pending_reply = static function ( $url, $args ) { $b = json_decode( $args['body'], true ); return array( 'code' => 202, 'body' => json_encode( array( 'ok' => true, 'ots_status' => 'pending', 'ledger_path' => 'rights-evidence/' . $b['note_uid'] . '/v1.json' ) ) ); };
$GLOBALS['__k']['post_reply'] = $pending_reply;
$GLOBALS['__k']['fetch'] = array(); $GLOBALS['__k']['filters'] = array();
$r = sn_rights_evidence_run( $now );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['composed'] && 2 === $r['posted'] && 0 === $r['anchored'], 'D1 the first pass composes and posts one record per family seen: ' . json_encode( $r ) );
ok( 2 === count( $GLOBALS['__k']['posts'] ) && 'https://prov.example/' === $GLOBALS['__k']['posts'][0]['url'], 'D2 two POSTs to the worker' );
$body = json_decode( $GLOBALS['__k']['posts'][0]['args']['body'], true );
ok( 'rights-evidence' === $body['kind'] && 1 === $body['version'] && hash( 'sha256', $body['canonical'] ) === $body['content_hash'] && sn_rights_evidence_uuid( 'anthropic', '2026-08', 'https://x.test/' ) === $body['note_uid'] && recanon( $body['canonical'] ) === $body['canonical'] && 2 === json_decode( $body['canonical'], true )['schema'], 'D3 the body: kind, version 1, the hash of the canonical bytes (schema 2, worker-canonical), the deterministic id (anthropic first, sorted)' );
ok( 'sha256=' . hash_hmac( 'sha256', $GLOBALS['__k']['posts'][0]['args']['body'], 's3' ) === $GLOBALS['__k']['posts'][0]['args']['headers']['X-SN-Signature'] && 0 === $GLOBALS['__k']['posts'][0]['args']['redirection'], 'D4 HMAC over the exact body with the site secret; no redirects' );
$d = sn_rights_evidence_data();
ok( 'pending' === $d['2026-08']['openai']['status'] && str_starts_with( $d['2026-08']['openai']['ledger_path'], 'rights-evidence/' ) && ! isset( $d['2026-08']['openai']['canonical'] ), 'D5 stored: status from the worker, the ledger path, the bytes dropped once the ledger has them' );
ok( array( array( 51, 'aggregate', '' ), array( 51, 'rights', 'anthropic' ), array( 51, 'rights', 'openai' ) ) === $GLOBALS['__k']['fetch'] && array( 'dev', 'ops' ) === $GLOBALS['__k']['filters'][1]['exclude_purpose'], 'D6 the window reaches back past the first of the month (51 days); the aggregate read ONCE, the rights stream once PER FAMILY, filtered to that family with ops,dev excluded' );
ok( str_ends_with( $GLOBALS['__k']['index_url'], '/index.json' ), 'D7 the reservation starts from the ledger index' );
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['fetch'] = array();
$r = sn_rights_evidence_run( $now + DAY_IN_SECONDS );
ok( $r['ok'] && 0 === $r['composed'] && 0 === $r['posted'] && 2 === $r['anchored'] && array() === $GLOBALS['__k']['posts'] && array( array( 51 + 1, 'aggregate', '' ) ) === $GLOBALS['__k']['fetch'], 'D8 the next day: both on the ledger, nothing composed or posted, no rights read' );

// E: a failed post keeps the bytes and re-sends them byte-identical.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 502, 'body' => json_encode( array( 'ok' => false, 'error' => 'calendar unreachable' ) ) );
$r = sn_rights_evidence_run( $now );
$d = sn_rights_evidence_data();
ok( ! $r['ok'] && 2 === $r['composed'] && 0 === $r['posted'] && 2 === $r['failed'] && 'unanchored' === $d['2026-08']['openai']['status'] && '502 calendar unreachable' === $d['2026-08']['openai']['error'] && '' !== $d['2026-08']['openai']['canonical'], 'E1 a 502 leaves the record composed and unanchored, the bytes kept, the error named' );
$kept = $d['2026-08']['openai']['canonical'];
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = null; $GLOBALS['__k']['http'] = array(); // the index unreachable now: the retry must not need it
$GLOBALS['__k']['post_reply'] = static function ( $url, $args ) { return array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'existing' => true, 'ots_status' => 'confirmed', 'ledger_path' => 'p.json' ) ) ); };
$r = sn_rights_evidence_run( $now + 2 * DAY_IN_SECONDS );
ok( $r['ok'] && 0 === $r['composed'] && 2 === $r['posted'] && $kept === json_decode( $GLOBALS['__k']['posts'][1]['args']['body'], true )['canonical'] && 'confirmed' === sn_rights_evidence_data()['2026-08']['openai']['status'] && array() === $GLOBALS['__k']['http'], 'E2 the retry re-sends the stored bytes verbatim (no recompose, no ledger read) and takes the worker\'s status' );

// F: refusals.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = null; $GLOBALS['__k']['post_reply'] = array( 'code' => 202, 'body' => '{}' );
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && str_contains( $r['error'], 'ledger index' ) && 0 === $r['composed'] && array() === $GLOBALS['__k']['posts'], 'F1 no reservation, no record: nothing composed, nothing posted' );
$GLOBALS['__k']['index'] = $index; $GLOBALS['__k']['rows_rights'] = array( 'ok' => false, 'rows' => array(), 'error' => 'http_503' );
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && 'sensor rights stream: http_503' === $r['error'] && array() === $GLOBALS['__k']['posts'] && array() === sn_rights_evidence_data(), 'F2 a failed rights read stops the pass before any record' );
$GLOBALS['__k']['rows_rights'] = $rights; $GLOBALS['__k']['secret'] = '';
ok( 'not-ready' === sn_rights_evidence_run( $now )['error'] && ! sn_rights_evidence_is_ready(), 'F3 no secret, not ready' );
$GLOBALS['__k']['secret'] = 's3'; $GLOBALS['__k']['mr'] = false;
ok( 'not-ready' === sn_rights_evidence_run( $now )['error'], 'F4 no sensor, not ready' );
$GLOBALS['__k']['mr'] = true;
$GLOBALS['__k']['rows_aggregate'] = array( 'ok' => false, 'rows' => array(), 'error' => 'network' );
ok( 'sensor: network' === sn_rights_evidence_run( $now )['error'], 'F5 a failed aggregate read is named' );
$GLOBALS['__k']['rows_aggregate'] = array( 'ok' => true, 'rows' => array( array( 'taxonomy_version' => '' ) + $aggregate['rows'][0] ) );
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && str_contains( $r['error'], 'taxonomy' ) && array() === $GLOBALS['__k']['posts'] && array() === sn_rights_evidence_data(), 'F6 a sensor that names no taxonomy composes nothing and posts nothing' );
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
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() ); $GLOBALS['__k']['post_reply'] = $pending_reply;
snt_ability_rights_evidence_now();
$o = snt_ability_rights_evidence();
$lm = sn_rights_evidence_month( time() )['month'];
ok( $o['ok'] && $o['ready'] && 'https://raw.example/ledger/main/' === $o['ledger_base'] && ! isset( ( (array) $o['months'] )[ $lm ]['openai']['canonical'] ), 'G2 the read hands out the ledger with the base URL and never the bytes' );
foreach ( $GLOBALS['__k']['actions']['init'] as $cb ) { $cb(); }
ok( 'daily' === ( $GLOBALS['__k']['scheduled'][ SN_RIGHTS_EVIDENCE_HOOK ] ?? '' ), 'G3 daily when ready' );

// H: the month hold (19.8.1). $now is 2026-09-19, so the month under test is 2026-08.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ) ); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['fetch'] = array();
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && 'held: 2026-08' === $r['error'] && 0 === $r['composed'] && array() === $GLOBALS['__k']['posts'] && array() === $GLOBALS['__k']['fetch'] && array() === sn_rights_evidence_data(), 'H1 a held month reads nothing from the sensor, composes nothing, posts nothing, and says so' );
ok( ! isset( $GLOBALS['__k']['transients']['sn_rights_evidence_lock'] ), 'H2 a held pass takes no lock' );
$live = sn_rights_evidence_month( time() )['month']; // the ability runs on the real clock
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array( '2026-08', $live );
$r = snt_ability_rights_evidence_now();
ok( 'held: ' . $live === $r['error'] && array() === $GLOBALS['__k']['posts'], 'H3 rights-evidence-now is held too (it runs the same function)' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ) ); $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $now );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['posted'], 'H4 an unheld month takes the normal path' );
$GLOBALS['__k']['opt'] = array();
ok( array( '2026-09' ) === sn_rights_evidence_held() && array( '2026-09' ) === $GLOBALS['__k']['opt']['sn_rights_evidence_hold'], 'H5 absent option: seeded with 2026-09 and stored' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() );
ok( array() === sn_rights_evidence_held() && array() === $GLOBALS['__k']['opt']['sn_rights_evidence_hold'], 'H6 an emptied list stays empty (the seed never overwrites)' );
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( strtotime( '2026-10-01T21:43:00Z' ) );
ok( 'held: 2026-09' === $r['error'] && '2026-09' === $r['month'] && array() === $GLOBALS['__k']['posts'], 'H7 the 2026-10-01 run on a fresh install is held by the seed alone' );

// I: the backlog keeps a held month after the calendar moves on (review, #1806).
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ) ); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['transients'] = array();
sn_rights_evidence_run( $now );
ok( array( '2026-08' ) === sn_rights_evidence_backlog(), 'I1 a held month is queued in the backlog' );
$oct5 = strtotime( '2026-10-05T12:00:00Z' );
$r = sn_rights_evidence_run( $oct5 );
ok( '2026-09' === $r['month'] && array( '2026-08' ) === sn_rights_evidence_backlog(), 'I2 still held when the calendar moves on: the pass takes the new month, the held one stays queued' );
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array(); $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $oct5 + DAY_IN_SECONDS );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['posted'] && array() === sn_rights_evidence_backlog(), 'I3 lifted: the backlog month goes first, is posted, and leaves the backlog' );
$r = sn_rights_evidence_run( $oct5 + 2 * DAY_IN_SECONDS );
ok( '2026-09' === $r['month'], 'I4 then the pass returns to the last complete month' );
$GLOBALS['__k']['opt']['sn_rights_evidence_backlog'] = array( '2026-06' ); $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $oct5 );
ok( '2026-09' === $r['month'] && array( '2026-06' ) === sn_rights_evidence_backlog() && array( '2026-06' ) === snt_ability_rights_evidence()['backlog'], 'I5 a backlog month past the 90-day sensor window with nothing stored is skipped, stays listed and the read shows it' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), 'sn_rights_evidence_backlog' => array( '2026-08' ) ); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 502, 'body' => json_encode( array( 'error' => 'x' ) ) );
sn_rights_evidence_run( $oct5 );
ok( array( '2026-08' ) === sn_rights_evidence_backlog(), 'I6 a backlog month whose post failed stays queued for the next pass' );
$GLOBALS['__k']['post_reply'] = $pending_reply;

$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-11' ) );
ok( array( '2026-11' ) === snt_ability_rights_evidence()['hold'], 'H8 the read echoes the stored hold' );
$GLOBALS['__k']['opt'] = array();
ok( array( '2026-09' ) === snt_ability_rights_evidence()['hold'] && ! array_key_exists( 'sn_rights_evidence_hold', $GLOBALS['__k']['opt'] ), 'H9 absent option: the read reports the effective 2026-09 hold without storing it' );
$GLOBALS['__k']['opt'] = array();
ok( '{}' === json_encode( snt_ability_rights_evidence()['months'] ), 'G4 no records yet: months encodes as {} at the door, never []' );

// J: F5, stored records refreshed from the ledger, held or not.
$re = static fn( $st, $n ) => array( 'uuid' => 'u' . $n, 'content_hash' => 'h' . $n, 'status' => $st, 'ledger_path' => 'rights-evidence/u' . $n . '/v1.json', 'at' => 1, 'error' => '' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => array( 'openai' => $re( 'pending', 1 ), 'anthropic' => $re( 'confirmed', 2 ), 'mistral' => $re( 'conflict', 3 ), 'cohere' => $re( 'pending', 4 ) ) ) );
$GLOBALS['__k']['ledger']['rights-evidence/u1/v1.json'] = array( 'payload' => array(), 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 970001 ) );
$GLOBALS['__k']['http'] = array(); $GLOBALS['__k']['secret'] = '';
$r = sn_rights_evidence_run( $now );
$d = sn_rights_evidence_data()['2026-07'];
$reads = array_values( array_filter( $GLOBALS['__k']['http'], static fn( $u ) => str_contains( $u, 'rights-evidence/' ) ) );
ok( 'not-ready' === $r['error'] && 'confirmed' === $d['openai']['status'] && 970001 === $d['openai']['block'] && 'pending' === $d['cohere']['status'] && 2 === count( $reads ), 'J1 a pass that is not even ready still re-reads the ledger for every pending record and takes its status and block; an unreadable record keeps its status' );
ok( ! array_filter( $reads, static fn( $u ) => str_contains( $u, '/u2/' ) || str_contains( $u, '/u3/' ) ), 'J2 confirmed and conflict records are final and never re-read' );
$GLOBALS['__k']['secret'] = 's3'; $GLOBALS['__k']['http'] = array();
$many = array(); for ( $i = 10; $i < 25; $i++ ) { $many[ 'f' . $i ] = $re( 'pending', $i ); }
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => $many ) );
sn_rights_evidence_run( $now );
ok( SN_RIGHTS_EVIDENCE_REFRESH_CAP === count( array_filter( $GLOBALS['__k']['http'], static fn( $u ) => str_contains( $u, 'rights-evidence/' ) ) ), 'J3 the refresh reads at most ' . SN_RIGHTS_EVIDENCE_REFRESH_CAP . ' records per pass (15 pending here)' );

// K: the backlog fixes (owner approved, Codex review).
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ) ); $GLOBALS['__k']['mr'] = false;
$r = sn_rights_evidence_run( $now );
ok( 'not-ready' === $r['error'] && array( '2026-08' ) === sn_rights_evidence_backlog(), 'K1 a held month is queued BEFORE the readiness check: an outage on the day it closes cannot drop it' );
$GLOBALS['__k']['mr'] = true;
$u = sn_rights_evidence_uuid( 'openai', '2026-06', 'https://x.test/' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), 'sn_rights_evidence_backlog' => array( '2026-06' ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-06' => array( 'openai' => array( 'uuid' => $u, 'content_hash' => hash( 'sha256', '{"x":1}' ), 'canonical' => '{"x":1}', 'status' => 'unanchored', 'ledger_path' => '', 'at' => 1, 'error' => '502' ) ) ) );
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['fetch'] = array();
$r = sn_rights_evidence_run( $oct5 );
ok( $r['ok'] && '2026-06' === $r['month'] && 1 === $r['posted'] && '{"x":1}' === json_decode( $GLOBALS['__k']['posts'][0]['args']['body'], true )['canonical'] && array() === $GLOBALS['__k']['fetch'] && array() === sn_rights_evidence_backlog(), 'K2 stored unposted bytes past the 90-day window are re-sent verbatim with no sensor read (the window guards composition only), then the month leaves the backlog' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), 'sn_rights_evidence_backlog' => array( '2026-08' ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'cohere' => array( 'uuid' => 'uc', 'content_hash' => 'hc', 'canonical' => '{"c":1}', 'status' => 'unanchored', 'ledger_path' => '', 'at' => 1, 'error' => '' ) ) ) );
$GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['post_reply'] = static function ( $url, $args ) use ( $pending_reply ) { $b = json_decode( $args['body'], true ); return 'uc' === $b['note_uid'] ? array( 'code' => 502, 'body' => '{"error":"x"}' ) : $pending_reply( $url, $args ); };
$r = sn_rights_evidence_run( $oct5 );
ok( ! $r['ok'] && in_array( 'uc', array_map( static fn( $p ) => json_decode( $p['args']['body'], true )['note_uid'], $GLOBALS['__k']['posts'] ), true ) && array( '2026-08' ) === sn_rights_evidence_backlog(), 'K3 a stored record of a family the sensor no longer lists (cohere) is retried anyway, and while it stays unposted the month stays queued' );
$GLOBALS['__k']['post_reply'] = $pending_reply; $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $oct5 + DAY_IN_SECONDS );
ok( $r['ok'] && 1 === $r['posted'] && 2 === $r['anchored'] && array() === sn_rights_evidence_backlog() && array() === sn_rights_evidence_unposted( '2026-08' ), 'K4 once nothing of the month is unposted, it leaves the backlog' );

// L: the dry run composes from live reads and reaches no POST (behaviour AND source).
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ) ); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['transients'] = array(); $GLOBALS['__k']['fetch'] = array();
$dry = sn_rights_evidence_dry_run( '2026-09', $oct5 );
$sp  = json_decode( $dry['payloads']['openai'] ?? '{}', true );
ok( $dry['ok'] && array( 'openai' ) === array_keys( $dry['payloads'] ) && 2 === $sp['schema'] && '2026-09' === $sp['month'] && array( 2, 3 ) === array_column( $sp['reservation']['signals']['tdm-policy'], 'version' ) && recanon( $dry['payloads']['openai'] ) === $dry['payloads']['openai'], 'L1 the dry run composes a held month per family (canonical, schema 2, September\'s versions in force)' );
ok( array() === $GLOBALS['__k']['posts'] && ! array_key_exists( SN_RIGHTS_EVIDENCE_OPTION, $GLOBALS['__k']['opt'] ) && array() === $GLOBALS['__k']['transients'] && array( '2026-09' ) === sn_rights_evidence_held( false ), 'L2 and posts nothing, stores no record, takes no lock, lifts no hold' );
$src = file_get_contents( __DIR__ . '/../inc/rights-evidence-dry-run.php' );
ok( ! preg_match( '/wp_remote_post|wp_safe_remote_post|sn_rights_evidence_post|sn_rights_evidence_send|sn_rights_evidence_run|SN_RIGHTS_EVIDENCE_OPTION/', $src ), 'L3 structurally: the dry-run file names no POST, no send, no pass and no record option' );
ok( ! sn_rights_evidence_dry_run( '2026-13' )['ok'] && 'month must be YYYY-MM' === sn_rights_evidence_dry_run( '2026-9' )['error'], 'L4 a malformed month is refused' );

// M: D1, the v2 drafts supersede the posted v1 records, and are never posted.
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $re( 'confirmed', 7 ) ) ) ); $GLOBALS['__k']['posts'] = array();
$v2 = sn_rights_evidence_v2_drafts( '2026-08', $oct5 );
$dp = json_decode( $v2['drafts']['openai'] ?? '{}', true );
ok( $v2['ok'] && array( 'openai' ) === array_keys( $v2['drafts'] ) && array( 'content_hash' => 'h7', 'ledger_path' => 'rights-evidence/u7/v1.json', 'version' => 1 ) === $dp['supersedes'] && SN_RIGHTS_EVIDENCE_V2_REASON === $dp['reason'] && 2 === $dp['schema'] && recanon( $v2['drafts']['openai'] ) === $v2['drafts']['openai'], 'M1 a draft per family with a v1 on the ledger: supersedes {version, ledger_path, content_hash}, a reason, schema 2; anthropic has no v1 and gets none' );
ok( array() === $GLOBALS['__k']['posts'] && false === strpos( SN_RIGHTS_EVIDENCE_V2_REASON, "\u{2014}" ), 'M2 drafting posts nothing' );

if ( getenv( 'SN_RE_PRINT' ) ) {
	$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $re( 'confirmed', 7 ), 'anthropic' => $re( 'confirmed', 8 ) ) ) );
	foreach ( sn_rights_evidence_dry_run( '2026-09', $oct5 )['payloads'] as $f => $c ) { echo "# dry-run 2026-09 $f\n" . json_encode( json_decode( $c ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; }
	foreach ( sn_rights_evidence_v2_drafts( '2026-08', $oct5 )['drafts'] as $f => $c ) { echo "# v2 draft 2026-08 $f\n" . json_encode( json_decode( $c ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; }
}

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
