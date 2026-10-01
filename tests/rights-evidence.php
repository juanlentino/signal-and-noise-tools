<?php
/**
 * Tests: inc/rights-evidence-compose.php + -ledger.php + -dry-run.php +
 * rights-evidence.php + the two abilities (17.0.0; schema 2 in Unreleased).
 * Run: php tests/rights-evidence.php
 * SN_RE_PRINT=1 also prints the September dry-run payloads and the August v2
 * drafts composed from the fixtures below.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
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
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__k']['transients'][ $k ] = $v; if ( 'sn_rights_evidence_lock' === $k && isset( $GLOBALS['__k']['on_lock'] ) ) { ( $GLOBALS['__k']['on_lock'] )(); } return true; } // on_lock: a concurrent writer landing once the pass holds its lock
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
function sn_prov_integrity_ledger_base() { return $GLOBALS['__k']['base'] ?? 'https://raw.example/ledger/main/'; }
// One seam for every read: the ledger index, ledger files by path, and the explorer (height -> hash -> block).
function sn_prov_integrity_http_fetch( $url ) {
	$GLOBALS['__k']['http'][] = $url;
	if ( isset( $GLOBALS['__k']['on_fetch'] ) ) { ( $GLOBALS['__k']['on_fetch'] )( $url ); } // a concurrent writer, landing mid-read
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
require __DIR__ . '/../inc/rights-evidence-review.php';
require __DIR__ . '/../inc/rights-evidence.php';
require __DIR__ . '/../inc/rights-evidence-post-now.php';
require __DIR__ . '/../inc/rights-evidence-retractions.php';
require __DIR__ . '/../inc/rights-evidence-retract.php';
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
	'rights-signals/tdm-policy/v1.json'  => $sig( '628b49d96dcde97a430dd4f597705899e09a968f793491e4b704cae33a40dc02', 100 ),
	'rights-signals/tdm-policy/v2.json'  => $sig( 'c44474038d459e40e4714afefa7bf8dae9f9834b22f5e8ec1dd434ecb62b512e', 200 ),
	'rights-signals/tdm-policy/v3.json'  => $sig( 'cece8a9cecfb6c7e7ee4f3346d5e2544138bfb6e33bec6042a17333a4d3180b0', 300 ),
	'rights-signals/license-xml/v1.json' => $sig( '2804bad6fe94a55f18b2b37e300919a5fd517b95aa81e95db574c0ba069a3740', 150 ),
	'rights-signals/license-xml/v2.json' => $sig( '8a1cee436cbac1489a1883c9d886fcfc46f302c55ed4106ae31729e4f4eb9041', null ),
);
$index = array( 'rights_signals' => array(
	array( 'slug' => 'tdm-policy', 'version' => 3, 'content_hash' => 'cece8a9cecfb6c7e7ee4f3346d5e2544138bfb6e33bec6042a17333a4d3180b0', 'ots_status' => 'confirmed', 'bitcoin_block' => 300 ),
	array( 'slug' => 'license-xml', 'version' => 2, 'content_hash' => '8a1cee436cbac1489a1883c9d886fcfc46f302c55ed4106ae31729e4f4eb9041', 'ots_status' => 'pending' ),
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
ok( array( 'license-xml', 'tdm-policy' ) === array_keys( $hist ) && 3 === count( $hist['tdm-policy'] ) && '' === $hist['license-xml'][1]['anchored_at'] && null === $hist['license-xml'][1]['block'] && '2026-08-15T12:00:00Z' === $hist['tdm-policy'][1]['anchored_at'] && 200 === $hist['tdm-policy'][1]['block'], 'B1 history walks v1..current per slug from the ledger, block time from the explorer; a pending version has no anchor' );
$GLOBALS['__k']['http'] = array();
$hist2 = sn_rights_evidence_signal_history( $index );
ok( $hist2 === $hist && array( 'https://raw.example/ledger/main/rights-signals/license-xml/v2.json' ) === $GLOBALS['__k']['http'], 'B2 confirmed versions and block times are cached for good: the second walk reads only the pending version' );
$GLOBALS['__k']['base'] = 'https://raw.example/other-ledger/main/'; $GLOBALS['__k']['http'] = array();
$hist3 = sn_rights_evidence_signal_history( $index );
ok( $hist3 === $hist && in_array( 'https://raw.example/other-ledger/main/rights-signals/tdm-policy/v1.json', $GLOBALS['__k']['http'], true ) && 5 === count( $GLOBALS['__k']['http'] ), 'B2b the permanent version cache is per ledger: another owner/repo re-reads every version, never reuses the first ledger\'s' );
unset( $GLOBALS['__k']['base'] );
$GLOBALS['__k']['opt'] = array();
$keep = $GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json']; unset( $GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json'] );
ok( null === sn_rights_evidence_signal_history( $index ), 'B3 a missing version file is null: a reservation with a hole is refused' );
$GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json'] = $keep;
$GLOBALS['__k']['opt'] = array(); unset( $GLOBALS['__k']['blocks'][200] );
ok( null === sn_rights_evidence_signal_history( $index ) && ! isset( $GLOBALS['__k']['opt']['sn_rights_evidence_chain']['versions']['https://raw.example/ledger/main/']['tdm-policy'][2] ), 'B4 a confirmed block whose time the explorer cannot give is null, and nothing is cached for it' );
$GLOBALS['__k']['blocks'][200] = '2026-08-15T12:00:00Z'; $GLOBALS['__k']['opt'] = array();
$keep = $GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json']; $GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json']['content_hash'] = 'not-a-hash';
ok( null === sn_rights_evidence_signal_history( $index ), 'B4b a version file with no sha256 content_hash is null: a version cannot be attested in force without its hash' );
$GLOBALS['__k']['ledger']['rights-signals/tdm-policy/v2.json'] = $keep; $GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['http'] = array();
$huge = $index; $huge['rights_signals'][0]['version'] = SN_RIGHTS_EVIDENCE_MAX_VERSIONS + 1;
ok( null === sn_rights_evidence_signal_history( $huge ) && array() === $GLOBALS['__k']['http'], 'B4c an index claiming more versions than the ceiling is refused before any read' );
foreach ( array( 'junk', array( 'slug' => '../etc', 'version' => 1 ), array( 'slug' => 'ai-txt' ), array( 'slug' => 'ai-txt', 'version' => 0 ) ) as $i => $row ) {
	$bad = $index; $bad['rights_signals'][] = $row; $GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['http'] = array();
	ok( null === sn_rights_evidence_signal_history( $bad ) && array() === $GLOBALS['__k']['http'], 'B4d a malformed rights-signal row (junk, path-unsafe slug, no version, version 0; case ' . $i . ') refuses the whole history before any read: a reservation missing a signal is half an evidence' );
}
$dup = $index; $dup['rights_signals'][] = array( 'slug' => 'tdm-policy', 'version' => 1 ); $GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['http'] = array();
ok( null === sn_rights_evidence_signal_history( $dup ) && array() === $GLOBALS['__k']['http'], 'B4e a slug listed twice in rights_signals refuses the history before any read: a later row never overwrites an earlier one' );
$GLOBALS['__k']['opt'] = array();
$res = sn_rights_evidence_reservation( $hist, $m );
$expect_aug = array(
	'license-xml' => array( array( 'block' => 150, 'content_hash' => '2804bad6fe94a55f18b2b37e300919a5fd517b95aa81e95db574c0ba069a3740', 'valid_from' => '2026-07-20T00:00:00Z', 'valid_to' => null, 'version' => 1 ) ),
	'tdm-policy'  => array(
		array( 'block' => 100, 'content_hash' => '628b49d96dcde97a430dd4f597705899e09a968f793491e4b704cae33a40dc02', 'valid_from' => '2026-07-01T00:00:00Z', 'valid_to' => '2026-08-15T12:00:00Z', 'version' => 1 ),
		array( 'block' => 200, 'content_hash' => 'c44474038d459e40e4714afefa7bf8dae9f9834b22f5e8ec1dd434ecb62b512e', 'valid_from' => '2026-08-15T12:00:00Z', 'valid_to' => '2026-09-17T00:00:00Z', 'version' => 2 ),
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
	$rd( 'openai', '2026-08-24T10:00:00Z', '/license.xml', 2, '' ),
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
ok( 3 === $rt['reads'] && '{"search":3}' === json_encode( $rt['by_purpose'] ) && '{"search":{"/license.xml":3}}' === json_encode( $rt['by_path'], JSON_UNESCAPED_SLASHES ) && '2026-08-21T10:00:00Z' === $rt['first'] && '2026-08-21T10:00:00Z' === $rt['last'], 'C3b retrieval_reads carries search (a recorded non-training purpose), same shape; ops rows (our own probes) are in no block' );
$ul = $p['unlabelled_reads'];
ok( 3 === $ul['reads'] && '{"unlabelled":3}' === json_encode( $ul['by_purpose'] ) && '{"unlabelled":{"/license.xml":2,"/tdm-policy":1}}' === json_encode( $ul['by_path'], JSON_UNESCAPED_SLASHES ) && '2026-08-22T10:00:00Z' === $ul['first'] && '2026-08-24T10:00:00Z' === $ul['last'] && true === $ul['complete'] && 3 === $rr['reads'] && 3 === $rt['reads'], 'C3c rows with no recorded purpose (\'\' and the normalizer\'s unknown) land in unlabelled_reads and in neither rights_reads nor retrieval_reads' );
$ua = sn_rights_evidence_compose( 'openai', $m, array( 'ok' => true, 'rows' => array( $agg( 'openai', '2026-08-05', 'html', '', 4 ), $agg( 'openai', '2026-08-05', 'feed', 'unknown', 1 ), $agg( 'openai', '2026-08-05', 'html', 'train', 2 ) ) ), $rights, $res, $sensor, 'https://x.test', $now );
ok( '{"train":{"html":2},"unlabelled":{"feed":1,"html":4}}' === json_encode( $ua['crawling']['by_surface'] ) && 2 === $ua['crawling']['train'], 'C3d crawling.by_surface keeps purpose keys; an empty or unknown purpose is the explicit unlabelled key, never counted as training' );
ok( $res === $p['reservation'] && ! isset( $p['reservation']['as_of'] ) && array( 'version' => '1.25.4', 'taxonomy' => '1.4' ) === $p['sensor'], 'C4 the reservation rides verbatim (window + signals, no as_of); the sensor names itself' );
$c = sn_prov_canonical_json( $p );
ok( false === strpos( $c, 'UA' ) && false === strpos( $c, 'user_agent' ) && false === strpos( $c, 'accept' ), 'C5 no user-agent string or Accept header reaches the record' );
ok( '{"composed_at"' === substr( $c, 0, 14 ) && str_contains( $c, '"by_day":{"2026-08-03":{"reads":41,"train":41},"2026-08-20":{"reads":9,"train":0}}' ), 'C6 canonical bytes: keys sorted, maps as objects' );
ok( recanon( $c ) === $c && str_contains( $c, '{"block":100,"content_hash":"628b49d96dcde97a430dd4f597705899e09a968f793491e4b704cae33a40dc02","valid_from":"2026-07-01T00:00:00Z","valid_to":"2026-08-15T12:00:00Z","version":1}' ), 'C6b the bytes survive the worker\'s re-canonicalization: every key sorted INSIDE objects too (sn_prov_canonical_json does not sort there)' );
$empty = sn_rights_evidence_compose( 'google-ai', $m, $aggregate, $rights, $res, $sensor, 'https://x.test', $now );
ok( 0 === $empty['crawling']['reads'] && '{}' === json_encode( $empty['crawling']['by_day'] ) && '{}' === json_encode( $empty['crawling']['by_surface'] ) && '{}' === json_encode( $empty['rights_reads']['by_path'] ) && '{}' === json_encode( $empty['retrieval_reads']['by_purpose'] ) && '{}' === json_encode( $empty['unlabelled_reads']['by_path'] ) && '' === $empty['rights_reads']['first'], 'C7 a family with nothing composes empty OBJECTS, never lists' );
$trunc_agg = array( 'ok' => true, 'truncated' => true, 'rows' => array( $agg( 'openai', '2026-08-03', 'html', 'train', 1 ), $agg( 'openai', '2026-08-30', 'html', 'train', 1 ) ) );
$trunc_rts = array( 'ok' => true, 'truncated' => true, 'rows' => array( $rd( 'openai', '2026-08-05T00:00:00Z', '/license.xml' ) ) );
$t = sn_rights_evidence_compose( 'openai', $m, $trunc_agg, $trunc_rts, $res, $sensor, 'https://x.test', $now );
ok( false === $t['crawling']['complete'] && false === $t['rights_reads']['complete'] && false === $t['retrieval_reads']['complete'], 'C8 truncated reads whose edge falls inside the month say complete:false' );
$t2 = sn_rights_evidence_compose( 'openai', $m, array( 'ok' => true, 'truncated' => true, 'rows' => array( $agg( 'openai', '2026-08-31', 'html', 'train', 1 ), $agg( 'openai', '2026-09-03', 'html', 'train', 1 ) ) ), array( 'ok' => true, 'truncated' => true, 'rows' => array( $rd( 'openai', '2026-08-01T00:00:00Z', '/license.xml' ) ) ), $res, $sensor, 'https://x.test', $now );
ok( true === $t2['crawling']['complete'] && true === $t2['rights_reads']['complete'], 'C9 truncated reads whose edge falls outside the month still cover it' );
ok( array( 'anthropic', 'openai' ) === sn_rights_evidence_families( $aggregate, $m ), 'C10 families: AI-training families seen in the month, sorted' );
ok( null === sn_rights_evidence_compose( 'openai', $m, $aggregate, $rights, $res, array( 'version' => '1.25.4', 'taxonomy' => '' ), 'https://x.test', $now ) && null === sn_rights_evidence_compose( 'openai', $m, $aggregate, $rights, $res, array(), 'https://x.test', $now ), 'C11 F6: no taxonomy, no record (null, never shipped blank)' );
ok( '1.4' === sn_rights_evidence_taxonomy( $aggregate ) && '1.3.1' === sn_rights_evidence_taxonomy( array( 'taxonomy_version' => '1.3.1' ) + $aggregate ) && '' === sn_rights_evidence_taxonomy( array( 'rows' => array( array( 'taxonomy_version' => '' ) ) ) ), 'C12 F6: the envelope taxonomy first, else the first row that names one, else empty' );

// C13-C18: the identity block (schema 2, owner rulings 2026-10-01; ledger checker PR #35).
$vb = static fn( $day, $purpose, $hits, $vbot ) => array( 'family' => 'openai', 'day' => $day, 'surface' => 'html', 'purpose' => $purpose, 'hits' => $hits, 'taxonomy_version' => '1.4', 'agent' => 'gptbot', 'network' => '' === $vbot ? 'Hetzner Online GmbH' : 'Microsoft Corporation', 'verified_bot' => $vbot );
$sep_agg = array( 'ok' => true, 'truncated' => false, 'rows' => array(
	$vb( '2026-09-02', 'train', 10, '' ),             // before the start: unverifiable
	$vb( '2026-09-27', 'train', 5, 'AI Crawler' ),    // the start day itself: unverifiable, even verified
	$vb( '2026-09-28', 'train', 4, 'AI Crawler' ),    // first whole day: verified
	$vb( '2026-09-28', 'train', 6, '' ),              // first whole day, no category: unverified
	$vb( '2026-09-29', 'search', 3, 'Search Engine Crawler' ),
	$vb( '2026-09-30', 'search', 2, '' ),
) );
$ps  = sn_rights_evidence_compose( 'openai', $sep, $sep_agg, $rights, $res_sep, $sensor, 'https://x.test/', $now );
$idn = $ps['identity'];
ok( 'claimed user agent' === $idn['basis'] && array( 'source' => 'cloudflare verified bot category', 'since' => '2026-09-27T15:49:59Z' ) === $idn['verification'] && 'claimed user agent; the rights stream records no verification' === $idn['rights_files'] && SN_RIGHTS_EVIDENCE_VERIFIED_SINCE === $idn['verification']['since'], 'C13 the identity block carries the exact strings the ledger checker requires' );
ok( array( 'verified' => 7, 'unverified' => 8, 'unverifiable' => 15 ) === $idn['crawling']['reads'] && array( 'verified' => 4, 'unverified' => 6, 'unverifiable' => 15 ) === $idn['crawling']['train'] && 30 === $ps['crawling']['reads'] && 25 === $ps['crawling']['train'], 'C14 each triple sums to its crawling count (reads 30, train 25) and no train component exceeds its reads component' );
ok( 15 === $idn['crawling']['train']['unverifiable'], 'C15 the partial start day (2026-09-27, verification began 15:49:59 UTC) counts as unverifiable, even a row Cloudflare verified' );
ok( 4 === $idn['crawling']['train']['verified'] && 6 === $idn['crawling']['train']['unverified'], 'C16 after the start a row with a verified-bot category is verified, a row with \'\' is unverified' );
$ida = $p['identity']['crawling'];
ok( array( 'verified' => 0, 'unverified' => 0, 'unverifiable' => 50 ) === $ida['reads'] && array( 'verified' => 0, 'unverified' => 0, 'unverifiable' => 41 ) === $ida['train'], 'C17 a window ending before verification began (August) is all unverifiable' );
$oct = array( 'month' => '2026-10', 'start' => '2026-10-01', 'end' => '2026-10-31' );
$ido = sn_rights_evidence_compose( 'openai', $oct, array( 'ok' => true, 'rows' => array( $vb( '2026-10-01', 'train', 2, 'AI Crawler' ), $vb( '2026-10-02', 'train', 3, '' ) ) ), $rights, $res_sep, $sensor, 'https://x.test', $now )['identity']['crawling'];
ok( array( 'verified' => 2, 'unverified' => 3, 'unverifiable' => 0 ) === $ido['reads'] && $ido['reads'] === $ido['train'], 'C18 a window starting after verification began (October) has nothing unverifiable' );
if ( getenv( 'SN_RE_SEPT_JSON' ) ) {
	file_put_contents( getenv( 'SN_RE_SEPT_JSON' ), json_encode( array( 'uid' => sn_rights_evidence_uuid( 'openai', '2026-09', 'https://x.test/' ), 'record' => array( 'payload' => json_decode( sn_prov_canonical_json( $ps ) ) ), 'august' => array( 'uid' => sn_rights_evidence_uuid( 'openai', '2026-08', 'https://x.test/' ), 'payload' => json_decode( sn_prov_canonical_json( $p ) ) ) ), JSON_UNESCAPED_SLASHES ) );
}

// D: the run. The first pass composes and stores for review; a pass at or after review_until posts (Unreleased).
$W = SN_RIGHTS_EVIDENCE_REVIEW;
$GLOBALS['__k']['rows_aggregate'] = $aggregate; $GLOBALS['__k']['rows_rights'] = $rights; $GLOBALS['__k']['index'] = $index;
$pending_reply = static function ( $url, $args ) { $b = json_decode( $args['body'], true ); return array( 'code' => 202, 'body' => json_encode( array( 'ok' => true, 'ots_status' => 'pending', 'ledger_path' => 'rights-evidence/' . $b['note_uid'] . '/v1.json' ) ) ); };
$GLOBALS['__k']['post_reply'] = $pending_reply;
$GLOBALS['__k']['fetch'] = array(); $GLOBALS['__k']['filters'] = array();
$r = sn_rights_evidence_run( $now );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['composed'] && 0 === $r['posted'] && 2 === $r['in_review'] && 0 === $r['anchored'] && array() === $GLOBALS['__k']['posts'], 'D1 the first pass composes one record per family seen, stores it and posts nothing: ' . json_encode( $r ) );
$d = sn_rights_evidence_data();
ok( 'composed' === $d['2026-08']['openai']['status'] && $now + 3 * DAY_IN_SECONDS === $d['2026-08']['openai']['review_until'] && array( 'reads' => 50, 'train' => 41 ) === $d['2026-08']['openai']['summary'] && '' !== $d['2026-08']['openai']['canonical'], 'D1b stored composed with review_until = compose time + 72 h and the summary (reads, train) the jump rule compares' );
ok( array( array( 51, 'aggregate', '' ), array( 51, 'rights', 'anthropic' ), array( 51, 'rights', 'openai' ) ) === $GLOBALS['__k']['fetch'] && array( 'dev', 'ops' ) === $GLOBALS['__k']['filters'][1]['exclude_purpose'], 'D6 the window reaches back past the first of the month (51 days); the aggregate read ONCE, the rights stream once PER FAMILY, filtered to that family with ops,dev excluded' );
ok( str_ends_with( $GLOBALS['__k']['index_url'], '/index.json' ), 'D7 the reservation starts from the ledger index' );
$S = SN_RIGHTS_EVIDENCE_REVIEW_SLACK;
$r = sn_rights_evidence_run( $now + $W - $S - 1 );
ok( $r['ok'] && 0 === $r['composed'] && 0 === $r['posted'] && 2 === $r['in_review'] && array() === $GLOBALS['__k']['posts'], 'D1c one second before the slack hour a pass posts nothing and composes nothing again' );
$r = sn_rights_evidence_run( $now + $W - 142 );
ok( $r['ok'] && 2 === $r['posted'] && 0 === $r['composed'] && 0 === $r['in_review'], 'D1d (changed) a daily pass a few minutes before review_until posts the stored bytes, so the window is three days, not four: ' . json_encode( $r ) );
ok( 2 === count( $GLOBALS['__k']['posts'] ) && 'https://prov.example/' === $GLOBALS['__k']['posts'][0]['url'], 'D2 two POSTs to the worker' );
$body = json_decode( $GLOBALS['__k']['posts'][0]['args']['body'], true );
ok( 'rights-evidence' === $body['kind'] && 1 === $body['version'] && hash( 'sha256', $body['canonical'] ) === $body['content_hash'] && sn_rights_evidence_uuid( 'anthropic', '2026-08', 'https://x.test/' ) === $body['note_uid'] && recanon( $body['canonical'] ) === $body['canonical'] && 2 === json_decode( $body['canonical'], true )['schema'] && $d['2026-08']['anthropic']['canonical'] === $body['canonical'], 'D3 the body: kind, version 1, the hash of the canonical bytes (schema 2, worker-canonical), the deterministic id (anthropic first, sorted); the bytes stored at compose, verbatim' );
ok( 'sha256=' . hash_hmac( 'sha256', $GLOBALS['__k']['posts'][0]['args']['body'], 's3' ) === $GLOBALS['__k']['posts'][0]['args']['headers']['X-SN-Signature'] && 0 === $GLOBALS['__k']['posts'][0]['args']['redirection'], 'D4 HMAC over the exact body with the site secret; no redirects' );
$d = sn_rights_evidence_data();
ok( 'pending' === $d['2026-08']['openai']['status'] && str_starts_with( $d['2026-08']['openai']['ledger_path'], 'rights-evidence/' ) && ! isset( $d['2026-08']['openai']['canonical'] ) && array( 'reads' => 50, 'train' => 41 ) === $d['2026-08']['openai']['summary'], 'D5 stored: status from the worker, the ledger path, the bytes dropped once the ledger has them, the summary kept' );
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['fetch'] = array();
$r = sn_rights_evidence_run( $now + $W + DAY_IN_SECONDS );
ok( $r['ok'] && 0 === $r['composed'] && 0 === $r['posted'] && 2 === $r['anchored'] && array() === $GLOBALS['__k']['posts'] && array( array( 51 + 4, 'aggregate', '' ) ) === $GLOBALS['__k']['fetch'], 'D8 the next day: both on the ledger, nothing composed or posted, no rights read' );

// E: a failed post keeps the bytes and re-sends them byte-identical.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 502, 'body' => json_encode( array( 'ok' => false, 'error' => 'calendar unreachable' ) ) );
sn_rights_evidence_run( $now );
$r = sn_rights_evidence_run( $now + $W );
$d = sn_rights_evidence_data();
ok( ! $r['ok'] && 0 === $r['composed'] && 0 === $r['posted'] && 2 === $r['failed'] && 'unanchored' === $d['2026-08']['openai']['status'] && '502 calendar unreachable' === $d['2026-08']['openai']['error'] && '' !== $d['2026-08']['openai']['canonical'] && ! in_array( '2026-08', sn_rights_evidence_held( false ), true ), 'E1 a 502 leaves the record unanchored, the bytes kept, the error named, and holds nothing (a transport failure is retried)' );
$kept = $d['2026-08']['openai']['canonical'];
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = null; $GLOBALS['__k']['http'] = array(); // the index unreachable now: the retry must not need it
$GLOBALS['__k']['post_reply'] = static function ( $url, $args ) { return array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'existing' => true, 'ots_status' => 'confirmed', 'ledger_path' => 'p.json' ) ) ); };
$r = sn_rights_evidence_run( $now + $W + 2 * DAY_IN_SECONDS );
ok( $r['ok'] && 0 === $r['composed'] && 2 === $r['posted'] && $kept === json_decode( $GLOBALS['__k']['posts'][1]['args']['body'], true )['canonical'] && 'confirmed' === sn_rights_evidence_data()['2026-08']['openai']['status'] && array() === $GLOBALS['__k']['http'], 'E2 the retry re-sends the stored bytes verbatim (no recompose, no ledger read) and takes the worker\'s status' );

// F: refusals.
$GLOBALS['__k']['opt'] = array(); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['index'] = null; $GLOBALS['__k']['post_reply'] = array( 'code' => 202, 'body' => '{}' );
$r = sn_rights_evidence_run( $now );
ok( ! $r['ok'] && str_contains( $r['error'], 'ledger index' ) && 0 === $r['composed'] && array() === $GLOBALS['__k']['posts'], 'F1 no reservation, no record: nothing composed, nothing posted' );
ok( in_array( '2026-08', sn_rights_evidence_held( false ), true ) && array( $r['error'] ) === sn_rights_evidence_hold_reasons()['2026-08'] && array( '2026-08' ) === sn_rights_evidence_backlog(), 'F7 auto-hold (a): a ledger-walk error holds the month with the error as its reason, and queues it' );
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
sn_rights_evidence_run( $now );
$r = sn_rights_evidence_run( $now + $W );
$e = sn_rights_evidence_data()['2026-08']['openai'];
ok( ! $r['ok'] && 2 === $r['failed'] && 'conflict' === $e['status'] && 'rights-evidence/' . $e['uuid'] . '/v1.json' === $e['ledger_path'] && ! isset( $e['canonical'] ), 'E3 a 409 is conflict with the deterministic ledger path and no bytes kept' );
$GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $now + $W + DAY_IN_SECONDS );
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
ok( ! $r['ok'] && 'held: 2026-08' === $r['error'] && 2 === $r['composed'] && array() === $GLOBALS['__k']['posts'] && 'composed' === sn_rights_evidence_data()['2026-08']['openai']['status'], 'H1 (changed for the review window) a held month still composes and stores its bytes, so View shows what would post; it posts nothing and says so' );
ok( ! isset( $GLOBALS['__k']['transients']['sn_rights_evidence_lock'] ), 'H2 (changed: a held pass now composes, under the lock) the lock is released after a held pass' );
$r = sn_rights_evidence_run( $now + $W + DAY_IN_SECONDS );
ok( 'held: 2026-08' === $r['error'] && 0 === $r['posted'] && array() === $GLOBALS['__k']['posts'] && '' !== sn_rights_evidence_data()['2026-08']['openai']['canonical'], 'H1b held never posts: past review_until the stored bytes stay home' );
$live = sn_rights_evidence_month( time() )['month']; // the ability runs on the real clock
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array( '2026-08', $live );
$r = snt_ability_rights_evidence_now();
ok( 'held: ' . $live === $r['error'] && array() === $GLOBALS['__k']['posts'], 'H3 rights-evidence-now is held too (it runs the same function)' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ) ); $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $now );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['composed'] && 0 === $r['posted'] && 2 === $r['in_review'], 'H4 (changed: the normal path composes first) an unheld month takes the normal path, into its review window' );
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
// A stored entry past its window, as a pass before this release left it (no review_until: due).
$due = static fn( $fam ) => array( 'uuid' => sn_rights_evidence_uuid( $fam, '2026-08', 'https://x.test/' ), 'content_hash' => 'h', 'canonical' => '{"' . $fam . '":1}', 'status' => 'unanchored', 'ledger_path' => '', 'at' => 1, 'error' => '502' );
$aug_due = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'anthropic' => $due( 'anthropic' ), 'openai' => $due( 'openai' ) ) ) );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), 'sn_rights_evidence_backlog' => array( '2026-08' ) ) + $aug_due; $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 502, 'body' => json_encode( array( 'error' => 'x' ) ) );
sn_rights_evidence_run( $oct5 );
ok( array( '2026-08' ) === sn_rights_evidence_backlog() && 2 === count( $GLOBALS['__k']['posts'] ), 'I6 a backlog month whose post failed stays queued for the next pass' );
$GLOBALS['__k']['post_reply'] = $pending_reply;
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), 'sn_rights_evidence_backlog' => array( '2026-08' ) ); $GLOBALS['__k']['rows_rights'] = array( 'ok' => false, 'rows' => array(), 'error' => 'http_503' );
$r = sn_rights_evidence_run( $oct5 );
ok( '2026-08' === $r['month'] && ! $r['ok'] && array() === sn_rights_evidence_unposted( '2026-08' ) && array( '2026-08' ) === sn_rights_evidence_backlog(), 'I7 a backlog month that could not be composed (nothing stored, so nothing unposted) stays queued: only a clean pass dequeues' );
$GLOBALS['__k']['rows_rights'] = $rights;

$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-11' ) );
ok( array( '2026-11' ) === snt_ability_rights_evidence()['hold'], 'H8 the read echoes the stored hold' );
$GLOBALS['__k']['opt'] = array();
ok( array( '2026-09' ) === snt_ability_rights_evidence()['hold'] && ! array_key_exists( 'sn_rights_evidence_hold', $GLOBALS['__k']['opt'] ), 'H9 absent option: the read reports the effective 2026-09 hold without storing it' );
$GLOBALS['__k']['opt'] = array();
ok( '{}' === json_encode( snt_ability_rights_evidence()['months'] ), 'G4 no records yet: months encodes as {} at the door, never []' );

// J: F5, stored records refreshed from the ledger, held or not.
$re = static fn( $st, $n ) => array( 'uuid' => 'u' . $n, 'content_hash' => 'h' . $n, 'status' => $st, 'ledger_path' => 'rights-evidence/u' . $n . '/v1.json', 'at' => 1, 'error' => '' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => array( 'openai' => $re( 'pending', 1 ), 'anthropic' => array( 'block' => 970002 ) + $re( 'confirmed', 2 ), 'mistral' => $re( 'conflict', 3 ), 'cohere' => $re( 'pending', 4 ) ) ) );
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

// J6: the capped refresh rotates, so every non-final record is re-read in turn (review follow-up 3).
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => $many ) );
$seen = array();
foreach ( array( 1, 2 ) as $pass_no ) {
	$GLOBALS['__k']['http'] = array();
	sn_rights_evidence_refresh();
	$seen = array_merge( $seen, array_filter( $GLOBALS['__k']['http'], static fn( $u ) => str_contains( $u, 'rights-evidence/' ) ) );
}
ok( 15 === count( array_unique( $seen ) ) && SN_RIGHTS_EVIDENCE_REFRESH_CAP * 2 === count( $seen ), 'J6 two capped passes over 15 pending records read all 15: the cap rotates from where the last pass stopped, never the same oldest twelve' );

// J4/J5: a confirmation without a numeric block is not final (review follow-up 1).
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => array( 'openai' => $re( 'pending', 41 ), 'anthropic' => $re( 'confirmed', 42 ) ) ) );
$GLOBALS['__k']['ledger']['rights-evidence/u41/v1.json'] = array( 'payload' => array(), 'ots' => array( 'status' => 'confirmed' ) );
$GLOBALS['__k']['ledger']['rights-evidence/u42/v1.json'] = array( 'payload' => array(), 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 970042 ) );
sn_rights_evidence_refresh();
$GLOBALS['__k']['http'] = array();
sn_rights_evidence_refresh();
$d = sn_rights_evidence_data()['2026-07'];
ok( 'pending' === $d['openai']['status'] && ! isset( $d['openai']['block'] ) && in_array( 'https://raw.example/ledger/main/rights-evidence/u41/v1.json', $GLOBALS['__k']['http'], true ), 'J4 a ledger file saying confirmed with no numeric block is not taken: the record stays non-final and the next pass re-reads it' );
ok( 'confirmed' === $d['anthropic']['status'] && 970042 === $d['anthropic']['block'], 'J5 a record stored confirmed with no block (a worker reply carries none) is re-read until the ledger gives its block' );

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
ok( $r['ok'] && 1 === $r['posted'] && 2 === $r['in_review'] && array( '2026-08' ) === sn_rights_evidence_backlog() && 1 === (int) ( get_option( 'sn_rights_evidence_backlog_fails' )['2026-08'] ?? 0 ), 'K4a (changed: K3 composed openai and anthropic into their window) cohere posts; a clean pass with families in review neither dequeues the month nor adds to its failure count (1, from K3)' );
ok( null === sn_rights_evidence_backlog_target( $oct5 + DAY_IN_SECONDS, array() ), 'K4b item 5: a backlog month whose unposted entries are all in their window is not a target; the pass goes to the current month meanwhile' );
$r = sn_rights_evidence_run( $oct5 + 2 * DAY_IN_SECONDS );
ok( '2026-09' === $r['month'] && array( '2026-08' ) === sn_rights_evidence_backlog() && 1 === (int) ( get_option( 'sn_rights_evidence_backlog_fails' )['2026-08'] ?? 0 ), 'K4c item 5: during the window the month stays queued and its failure count does not move' );
$r = sn_rights_evidence_run( $oct5 + $W );
ok( $r['ok'] && '2026-08' === $r['month'] && 2 === $r['posted'] && array() === sn_rights_evidence_backlog() && array() === sn_rights_evidence_unposted( '2026-08' ), 'K4 once its window passes the month posts, nothing is unposted, and it leaves the backlog' );

// K5-K7: a failing backlog month cannot starve the current one (review follow-up 6).
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ), 'sn_rights_evidence_backlog' => array( '2026-08' ) ); $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $oct5 );
ok( '2026-08' === $r['month'] && $r['ok'] && array( '2026-08', '2026-09' ) === sn_rights_evidence_backlog(), 'K5 (changed: the worked backlog month now enters its window and stays queued) a held current month is queued even on a pass that works a backlog month: queued before the target is chosen' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), 'sn_rights_evidence_backlog' => array( '2026-08' ) ) + $aug_due;
$GLOBALS['__k']['post_reply'] = array( 'code' => 502, 'body' => json_encode( array( 'error' => 'x' ) ) );
$months = array();
for ( $i = 0; $i < 9; $i++ ) { $months[] = sn_rights_evidence_run( $oct5 + $i * DAY_IN_SECONDS )['month']; }
ok( array_fill( 0, SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP, '2026-08' ) === array_slice( $months, 0, SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP ) && '2026-09' === $months[ SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP ], 'K6 after ' . SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP . ' failing passes on one backlog month, the next pass works the current month: ' . implode( ',', $months ) );
ok( '2026-08' === $months[ SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP + 1 ] && array( '2026-08' ) === sn_rights_evidence_backlog(), 'K7 the backlog month stays queued and takes the pass after that one' );
$GLOBALS['__k']['opt']['sn_rights_evidence_backlog_fails'] = array( '2026-08' => SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP ); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['transients']['sn_rights_evidence_lock'] = 1;
$r = sn_rights_evidence_run( $oct5 + 9 * DAY_IN_SECONDS );
unset( $GLOBALS['__k']['transients']['sn_rights_evidence_lock'] );
ok( 'a pass is already running' === $r['error'] && array( '2026-08' => SN_RIGHTS_EVIDENCE_BACKLOG_FAIL_CAP ) === get_option( 'sn_rights_evidence_backlog_fails' ) && array() === $GLOBALS['__k']['posts'] && array( '2026-08' ) === sn_rights_evidence_backlog(), 'K7b a pass that finds the lock held leaves the failure counter at the cap (not consumed), posts nothing and dequeues nothing' );
$r = sn_rights_evidence_run( $oct5 + 9 * DAY_IN_SECONDS );
ok( '2026-09' === $r['month'] && array() === get_option( 'sn_rights_evidence_backlog_fails' ), 'K7c the next pass that takes the lock consumes the counter: it works the current month and resets the count' );
$GLOBALS['__k']['post_reply'] = $pending_reply; $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $oct5 + 9 * DAY_IN_SECONDS );
ok( $r['ok'] && '2026-08' === $r['month'] && array() === sn_rights_evidence_backlog() && array() === (array) get_option( 'sn_rights_evidence_backlog_fails', array() ), 'K8 a clean pass dequeues the month and clears its failure count' );

// R: the review window, the automatic holds, the worker's refusal and Post now (Unreleased).
ok( sn_rights_evidence_train_jump( 50, 151 ) && sn_rights_evidence_train_jump( 151, 50 ) && ! sn_rights_evidence_train_jump( 50, 150 ) && ! sn_rights_evidence_train_jump( 150, 50 ) && ! sn_rights_evidence_train_jump( 49, 200 ) && ! sn_rights_evidence_train_jump( 200, 49 ) && sn_rights_evidence_train_jump( 300, 99 ), 'R1 the jump rule: more than 3x either way with both at 50 or more; exactly 3x is not a jump, a count under 50 never is' );
$posted_prev = static fn( $train, $extra = array() ) => array_merge( array( 'uuid' => 'p', 'content_hash' => 'h', 'status' => 'confirmed', 'block' => 1, 'ledger_path' => 'rights-evidence/p/v1.json', 'at' => 1, 'error' => '', 'summary' => array( 'reads' => $train, 'train' => $train ) ), $extra );
$big = $aggregate; $big['rows'][] = $agg( 'openai', '2026-08-04', 'html', 'train', 200 ); // openai train 241
$GLOBALS['__k']['rows_aggregate'] = $big; $GLOBALS['__k']['post_reply'] = $pending_reply; $GLOBALS['__k']['transients'] = array(); $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => array( 'openai' => $posted_prev( 60 ), 'anthropic' => $posted_prev( 60 ) ) ) );
$r = sn_rights_evidence_run( $now );
$why = sn_rights_evidence_hold_reasons()['2026-08'] ?? array();
ok( 2 === $r['composed'] && 'held: 2026-08' === $r['error'] && in_array( '2026-08', sn_rights_evidence_held( false ), true ) && array( 'openai crawling.train moved from 60 in 2026-07 to 241, more than 3x' ) === $why && array( '2026-08' ) === sn_rights_evidence_backlog(), 'R2 auto-hold (b): openai\'s train 241 against July\'s posted 60 holds the month with the reason; anthropic (3, under the floor) adds none: ' . json_encode( $why ) );
$r = sn_rights_evidence_run( $now + $W );
ok( 0 === $r['posted'] && array() === $GLOBALS['__k']['posts'], 'R2b the jump-held month does not post when its window ends' );
foreach ( array( 'no summary' => array( 'summary' => null ), 'a conflict' => array( 'status' => 'conflict' ), 'never posted' => array( 'ledger_path' => '' ) ) as $case => $extra ) {
	$prev = array_filter( $posted_prev( 60, $extra ), static fn( $v ) => null !== $v );
	$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => array( 'openai' => $prev ) ) );
	sn_rights_evidence_run( $now );
	ok( array() === sn_rights_evidence_held( false ) && array() === sn_rights_evidence_hold_reasons(), "R2c the jump rule needs last month's POSTED summary: none against $case" );
}
$GLOBALS['__k']['rows_aggregate'] = $aggregate;

// R3: the worker refuses with 422: refused, bytes dropped, month held with the divergences, never re-sent.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() ); $GLOBALS['__k']['posts'] = array();
sn_rights_evidence_run( $now );
$GLOBALS['__k']['post_reply'] = array( 'code' => 422, 'body' => json_encode( array( 'ok' => false, 'error' => 'rights-evidence refused', 'divergences' => array( array( 'reservation', 'tdm-policy v3 not in force in 2026-08' ), array( 'identity', 'triple does not sum' ) ) ) ) );
$r = sn_rights_evidence_run( $now + $W );
$e = sn_rights_evidence_data()['2026-08']['anthropic'];
ok( ! $r['ok'] && 1 === $r['refused'] && 0 === $r['posted'] && 1 === count( $GLOBALS['__k']['posts'] ) && 'refused' === $e['status'] && ! isset( $e['canonical'] ) && '422 rights-evidence refused' === $e['error'] && array( array( 'reservation', 'tdm-policy v3 not in force in 2026-08' ), array( 'identity', 'triple does not sum' ) ) === $e['divergences'], 'R3 a 422 is refused: the bytes dropped, the divergences kept, and the month held before the next family is sent (one POST)' );
ok( array( 'the worker refused anthropic: reservation: tdm-policy v3 not in force in 2026-08; identity: triple does not sum' ) === sn_rights_evidence_hold_reasons()['2026-08'] && 'composed' === sn_rights_evidence_data()['2026-08']['openai']['status'], 'R3b the hold reason names the family and every divergence; the family not yet sent keeps its bytes, held' );
$GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $now + $W + DAY_IN_SECONDS );
$e = sn_rights_evidence_data()['2026-08']['anthropic'];
ok( array() === $GLOBALS['__k']['posts'] && 1 === $r['composed'] && 'composed' === $e['status'] && '' !== $e['canonical'] && $now + $W + DAY_IN_SECONDS + $W === $e['review_until'], 'R3c the next pass sends nothing (held) and recomposes the refused family into a fresh window: refused bytes are never re-sent' );
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array(); $GLOBALS['__k']['post_reply'] = array( 'code' => 0, 'body' => '' );
$r = sn_rights_evidence_run( $now + 3 * $W );
ok( 2 === $r['failed'] && 'unanchored' === sn_rights_evidence_data()['2026-08']['openai']['status'] && array() === sn_rights_evidence_held( false ), 'R3d a transport failure is not a refusal: unanchored, bytes kept, nothing held' );

// R4: Post now, a composed and unheld month, whatever its window.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() ); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['post_reply'] = $pending_reply;
sn_rights_evidence_run( $now );
ok( array( '2026-08' => $now + $W ) === sn_rights_evidence_pending() && sn_rights_evidence_in_review( '2026-08', $now ) && ! sn_rights_evidence_in_review( '2026-08', $now + $W ), 'R4 pending lists the composed, unposted, unheld month with its review_until; in review until then' );
$GLOBALS['__k']['transients']['sn_rights_evidence_lock'] = 1;
ok( 'busy' === sn_rights_evidence_post_now( '2026-08', $now + 60 )['result'] && array() === $GLOBALS['__k']['posts'], 'R4b Post now while a pass runs: busy, nothing posted' );
$GLOBALS['__k']['transients'] = array();
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array( '2026-08' );
ok( 'ineligible' === sn_rights_evidence_post_now( '2026-08', $now + 60 )['result'] && 'ineligible' === sn_rights_evidence_post_now( '2026-07', $now + 60 )['result'] && array() === $GLOBALS['__k']['posts'] && array() === sn_rights_evidence_pending(), 'R4c Post now refuses a held month and a month with nothing composed' );
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array();
$p = sn_rights_evidence_post_now( '2026-08', $now + 60 );
ok( 'posted' === $p['result'] && 2 === $p['posted'] && 2 === count( $GLOBALS['__k']['posts'] ) && 'pending' === sn_rights_evidence_data()['2026-08']['openai']['status'] && array() === sn_rights_evidence_pending() && array() === $GLOBALS['__k']['transients'], 'R4d Post now sends both families inside the window, releases the lock, and the month leaves pending' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() ); $GLOBALS['__k']['posts'] = array();
sn_rights_evidence_run( $now );
$GLOBALS['__k']['post_reply'] = array( 'code' => 422, 'body' => json_encode( array( 'ok' => false, 'error' => 'rights-evidence refused', 'divergences' => array( array( 'crawling', 'by_day does not sum' ) ) ) ) );
$p = sn_rights_evidence_post_now( '2026-08', $now + 60 );
ok( 'partial' === $p['result'] && 1 === $p['refused'] && 1 === count( $GLOBALS['__k']['posts'] ) && in_array( '2026-08', sn_rights_evidence_held( false ), true ), 'R4e a refusal during Post now holds the month and stops there' );
$GLOBALS['__k']['post_reply'] = $pending_reply;

// R5: the read reports the window, the reasons and the refusal.
$o = snt_ability_rights_evidence();
ok( array( '2026-08' => array( 'the worker refused anthropic: crawling: by_day does not sum' ) ) === (array) $o['hold_reasons'] && 'refused' === ( (array) $o['months'] )['2026-08']['anthropic']['status'] && isset( ( (array) $o['months'] )['2026-08']['anthropic']['divergences'] ) && $now + $W === ( (array) $o['months'] )['2026-08']['openai']['review_until'] && '{}' === json_encode( $o['in_review'] ), 'R5 the read carries hold_reasons, refused entries with their divergences, review_until per record, and in_review ({} when nothing waits)' );
$GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array();
ok( array( '2026-08' => gmdate( 'c', $now + $W ) ) === (array) snt_ability_rights_evidence()['in_review'] && '{}' === json_encode( snt_ability_rights_evidence()['hold_reasons'] ), 'R5b in_review names each composed, unheld month and when it may post; a reason for a month no longer held is not reported (as on the twins and in the watch)' );
$an = $GLOBALS['__k']['abilities']['signal-noise/rights-evidence-now'];
ok( str_contains( $an['description'], 'only what is composed, past its review window and not held' ) && isset( $an['output_schema']['properties']['in_review'], $an['output_schema']['properties']['refused'] ), 'R6 rights-evidence-now says it posts only past the window, and reports in_review and refused' );

// R7: a month composed late is not left to Post now alone: the next month's first pass queues it, and it posts once its window passes.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() ); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['transients'] = array();
$sep30 = strtotime( '2026-09-30T12:00:00Z' );
$r = sn_rights_evidence_run( $sep30 );
ok( '2026-08' === $r['month'] && 2 === $r['composed'] && strtotime( '2026-10-03T12:00:00Z' ) === sn_rights_evidence_data()['2026-08']['openai']['review_until'] && array() === sn_rights_evidence_backlog(), 'R7 August composed on 2026-09-30, its window ending after the turnover; not yet queued (it is the current month)' );
$r = sn_rights_evidence_run( strtotime( '2026-10-01T06:00:00Z' ) );
ok( '2026-09' === $r['month'] && array( '2026-08' ) === sn_rights_evidence_backlog() && array() === $GLOBALS['__k']['posts'], 'R7b the first pass of October queues August (stranded, unposted) and, August being in its window, works September' );
$r = sn_rights_evidence_run( strtotime( '2026-10-03T12:00:00Z' ) );
ok( '2026-08' === $r['month'] && 2 === $r['posted'] && array( '2026-08' ) !== sn_rights_evidence_backlog() && ! in_array( '2026-08', sn_rights_evidence_backlog(), true ), 'R7c once its window passes August posts from the backlog and leaves it, with no Post now' );
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array(
	'2026-06' => array( 'openai' => array( 'status' => 'refused', 'ledger_path' => '' ) ),
	'2026-07' => array( 'openai' => array( 'status' => 'pending', 'ledger_path' => 'p.json' ) ),
	'2026-08' => array( 'openai' => array( 'status' => 'composed', 'canonical' => '{}', 'ledger_path' => '' ) ),
	'2026-09' => array( 'openai' => array( 'status' => 'unanchored', 'canonical' => '{}', 'ledger_path' => '' ) ),
) );
ok( array( '2026-06', '2026-09' ) === sn_rights_evidence_stranded( '2026-08' ), 'R7d stranded: a refused entry awaiting recompose and unposted bytes count; a posted month and the current month do not' );

// R8: codex review of #1817.
$shape = array( 'ok' => false, 'error' => 'rights-evidence refused', 'divergences' => array( array( 'reservation', 'x' ) ) );
ok( sn_rights_evidence_is_refusal( $shape ) && ! sn_rights_evidence_is_refusal( array( 'error' => 'unprocessable' ) ) && ! sn_rights_evidence_is_refusal( array( 'ok' => true ) + $shape ) && ! sn_rights_evidence_is_refusal( array( 'error' => 'rights-evidence refused ' ) + $shape ) && ! sn_rights_evidence_is_refusal( array( 'divergences' => array() ) + $shape ) && ! sn_rights_evidence_is_refusal( array( 'divergences' => array( array( 'a' ) ) ) + $shape ) && ! sn_rights_evidence_is_refusal( array( 'divergences' => array( array( 'a', 3 ) ) ) + $shape ) && ! sn_rights_evidence_is_refusal( array( 'divergences' => array( 'a' => array( 'b', 'c' ) ) ) + $shape ) && ! sn_rights_evidence_is_refusal( array( 'divergences' => 'x' ) + $shape ), 'R8a a refusal is exactly the worker\'s shape: ok false, error "rights-evidence refused", a non-empty list of [string, string]' );
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() ) + $aug_due; $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['transients'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 422, 'body' => json_encode( array( 'error' => 'unprocessable entity' ) ) );
$r = sn_rights_evidence_run( $now );
$e = sn_rights_evidence_data()['2026-08']['openai'];
ok( 2 === $r['failed'] && 0 === $r['refused'] && 'unanchored' === $e['status'] && '{"openai":1}' === $e['canonical'] && array() === sn_rights_evidence_held( false ) && array() === sn_rights_evidence_hold_reasons(), 'R8b any other 422 is a transport-class failure: unanchored, bytes kept, nothing held' );
$GLOBALS['__k']['post_reply'] = $pending_reply; $GLOBALS['__k']['posts'] = array();
$r = sn_rights_evidence_run( $now + DAY_IN_SECONDS );
ok( 2 === $r['posted'] && '{"openai":1}' === json_decode( $GLOBALS['__k']['posts'][1]['args']['body'], true )['canonical'], 'R8c and it is retried byte-identical' );
// A held backlog month with a refused family is still recomposed, and still never posts.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ), 'sn_rights_evidence_backlog' => array( '2026-08' ), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $due( 'openai' ), 'anthropic' => array( 'uuid' => 'ua', 'status' => 'refused', 'ledger_path' => '', 'at' => 1, 'error' => '422 rights-evidence refused', 'divergences' => array( array( 'a', 'b' ) ) ) ) ) );
$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['fetch'] = array();
$r = sn_rights_evidence_run( $oct5 );
$d = sn_rights_evidence_data()['2026-08'];
ok( '2026-08' === $r['month'] && 1 === $r['composed'] && 'composed' === $d['anthropic']['status'] && '' !== $d['anthropic']['canonical'] && array() === $GLOBALS['__k']['posts'] && 'held: 2026-08' === $r['error'] && array() === (array) get_option( 'sn_rights_evidence_backlog_fails', array() ), 'R8d a held backlog month whose family was refused is worked to recompose it; nothing posts and the held pass is not counted a failure' );
$r = sn_rights_evidence_run( $oct5 + DAY_IN_SECONDS );
ok( '2026-09' === $r['month'] && array() === $GLOBALS['__k']['posts'] && in_array( '2026-08', sn_rights_evidence_backlog(), true ), 'R8e once recomposed the held month is no longer a target; it stays queued for its lift' );
// The hold is read once per pass: a Lift landing while the pass holds its lock cannot make it post.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-08' ) ) + $aug_due; $GLOBALS['__k']['posts'] = array();
$GLOBALS['__k']['on_lock'] = static function () { $GLOBALS['__k']['opt']['sn_rights_evidence_hold'] = array(); };
$r = sn_rights_evidence_run( $now );
unset( $GLOBALS['__k']['on_lock'] );
ok( array() === $GLOBALS['__k']['posts'] && 'held: 2026-08' === $r['error'] && '{"openai":1}' === sn_rights_evidence_data()['2026-08']['openai']['canonical'], 'R8f a Lift during a running pass does not let that pass post a month it treated as held' );
$r = sn_rights_evidence_run( $now + DAY_IN_SECONDS );
ok( 2 === $r['posted'], 'R8g the next pass, starting unheld, posts' );
// A reason is cut at 300 bytes without splitting a character.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array() );
sn_rights_evidence_hold_month( '2026-08', str_repeat( 'a', 299 ) . 'é and more' );
$cut = sn_rights_evidence_hold_reasons()['2026-08'][0];
ok( str_repeat( 'a', 299 ) === $cut && mb_check_encoding( $cut, 'UTF-8' ) && strlen( $cut ) <= SN_RIGHTS_EVIDENCE_REASON_MAX, 'R8h a reason cut at 300 bytes drops a character that would straddle the boundary: valid UTF-8, never more than 300 bytes' );
// Families in different windows: the month reports the earliest.
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array(), SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => array( 'review_until' => 500 ) + $due( 'openai' ), 'anthropic' => array( 'review_until' => 900 ) + $due( 'anthropic' ) ) ) );
ok( array( '2026-08' => 500 ) === sn_rights_evidence_pending(), 'R8i a month whose families have different windows reports the earliest review_until, when the first may post' );

// L: the dry run composes from live reads and reaches no POST (behaviour AND source).
$GLOBALS['__k']['opt'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ) ); $GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['transients'] = array(); $GLOBALS['__k']['fetch'] = array();
$dry = sn_rights_evidence_dry_run( '2026-09', $oct5 );
$sp  = json_decode( $dry['payloads']['openai'] ?? '{}', true );
ok( $dry['ok'] && array( 'openai' ) === array_keys( $dry['payloads'] ) && 2 === $sp['schema'] && '2026-09' === $sp['month'] && array( 2, 3 ) === array_column( $sp['reservation']['signals']['tdm-policy'], 'version' ) && recanon( $dry['payloads']['openai'] ) === $dry['payloads']['openai'], 'L1 the dry run composes a held month per family (canonical, schema 2, September\'s versions in force)' );
ok( 'claimed user agent' === ( $sp['identity']['basis'] ?? '' ) && array( 'verified' => 0, 'unverified' => 0, 'unverifiable' => 7 ) == ( $sp['identity']['crawling']['train'] ?? null ), 'L1b the dry run (what the View download streams) carries the identity block' );
ok( array() === $GLOBALS['__k']['posts'] && ! array_key_exists( SN_RIGHTS_EVIDENCE_OPTION, $GLOBALS['__k']['opt'] ) && array() === $GLOBALS['__k']['transients'] && array( '2026-09' ) === sn_rights_evidence_held( false ), 'L2 and posts nothing, stores no record, takes no lock, lifts no hold' );
$src = file_get_contents( __DIR__ . '/../inc/rights-evidence-dry-run.php' );
ok( ! preg_match( '/wp_remote_post|wp_safe_remote_post|sn_rights_evidence_post|sn_rights_evidence_send|sn_rights_evidence_run|SN_RIGHTS_EVIDENCE_OPTION/', $src ), 'L3 structurally: the dry-run file names no POST, no send, no pass and no record option' );
ok( ! sn_rights_evidence_dry_run( '2026-13' )['ok'] && 'month must be YYYY-MM' === sn_rights_evidence_dry_run( '2026-9' )['error'], 'L4 a malformed month is refused' );

$GLOBALS['__k']['fetch'] = array();
$win = array_map( static fn( $ym ) => sn_rights_evidence_dry_run( $ym, $oct5 )['error'], array( '2026-10', '2027-01', '2026-07', '2026-06' ) );
ok( 'month must be complete: the current month and future months cannot be composed' === $win[0] && $win[0] === $win[1] && 'month is past the sensor\'s 90-day window' === $win[2] && $win[2] === $win[3] && array() === $GLOBALS['__k']['fetch'], 'L5 the current month, a future month, and months starting past the 90-day window are refused with a clear error before any sensor read' );

// M: D1 (retraction + erratum): the erratum data per v1 record, never posted.
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $re( 'confirmed', 7 ) ) ) ); $GLOBALS['__k']['posts'] = array();
$er = sn_rights_evidence_erratum( '2026-08', $oct5 );
$ep = json_decode( $er['erratum']['openai'] ?? '{}', true );
ok( $er['ok'] && array( 'openai' ) === array_keys( $er['erratum'] ) && array( 'content_hash' => 'h7', 'ledger_path' => 'rights-evidence/u7/v1.json', 'version' => 1 ) === $ep['corrects'] && SN_RIGHTS_EVIDENCE_ERRATUM_REASON === $ep['reason'] && isset( $ep['reservation']['window'], $ep['reservation']['signals'] ) && '2026-08' === $ep['month'] && ! isset( $ep['supersedes'] ) && ! isset( $ep['schema'] ), 'M1 erratum data per family with a v1 on the ledger: the record corrected, the reason, the reservation in force; not a record (no schema, no supersedes)' );
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $re( 'conflict', 7 ) ) ) );
ok( array() === sn_rights_evidence_erratum( '2026-08', $oct5 )['erratum'], 'M1b a 409 conflict kept the ledger\'s own bytes: no erratum is drafted against it' );
// M3: the erratum is driven by the posted records, not by today's aggregate (review follow-up 8).
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-07' => array( 'openai' => $re( 'confirmed', 7 ), 'cohere' => $re( 'pending', 8 ), 'mistral' => array( 'status' => 'retracted', 'retraction_path' => 'r' ) + $re( 'confirmed', 9 ), 'anthropic' => $re( 'conflict', 10 ), 'google-ai' => array( 'canonical' => '{}', 'ledger_path' => '' ) + $re( 'unanchored', 11 ) ) ) );
$GLOBALS['__k']['fetch'] = array(); $GLOBALS['__k']['http'] = array();
$er3 = sn_rights_evidence_erratum( '2026-07', $oct5 );
$e3  = array_map( static fn( $c ) => json_decode( $c, true ), $er3['erratum'] );
ok( $er3['ok'] && array( 'cohere', 'mistral', 'openai' ) === array_keys( $e3 ) && 'rights-evidence/u8/v1.json' === $e3['cohere']['corrects']['ledger_path'] && $e3['cohere']['reservation'] === $e3['openai']['reservation'] && isset( $e3['openai']['reservation']['signals'] ), 'M3 every stored v1 with a ledger path gets an erratum (a family the aggregate no longer lists, a retracted one), one reservation for all; a conflict and an unposted entry get none' );
ok( array() === $GLOBALS['__k']['fetch'] && 1 === count( array_filter( $GLOBALS['__k']['http'], static fn( $u ) => str_ends_with( $u, '/index.json' ) ) ), 'M4 the erratum reads no sensor (a month past the 90-day window still gets one) and the ledger index once' );
ok( array() === $GLOBALS['__k']['posts'] && false === strpos( SN_RIGHTS_EVIDENCE_ERRATUM_REASON, "\u{2014}" ), 'M2 the erratum posts nothing' );

// N: the retraction, one signed POST /retract per eligible record (Unreleased).
$t0   = strtotime( '2026-09-30T12:00:00Z' );
$conf = array_merge( $re( 'confirmed', 7 ), array( 'uuid' => 'uuid-7', 'block' => 970007 ) ); // ledger_path not derivable from uuid: byte-for-byte is visible.
$seed = static function ( array $openai ) use ( $re ) {
	$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array(
		'2026-07' => array( 'openai' => $re( 'confirmed', 1 ) ), // no approved text for July
		'2026-08' => array( 'openai' => $openai, 'mistral' => $re( 'confirmed', 9 ) ), // no approved text for mistral
	) );
	$GLOBALS['__k']['posts'] = array(); $GLOBALS['__k']['transients'] = array();
};
$ok_reply = array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'created' => true, 'path' => 'retractions/uuid-7/v1.json', 'content_hash' => str_repeat( 'a', 64 ), 'retracted' => 'rights-evidence/u7/v1.json' ) ) );
$txt      = SN_RIGHTS_EVIDENCE_RETRACTIONS['2026-08']['openai'];
ok( '63322851e7ee5618921a6738bf2f5bb24e626a8de8d03c7cbce3fe70f7954ef3' === hash( 'sha256', json_encode( SN_RIGHTS_EVIDENCE_RETRACTIONS ) ) && array( 'openai', 'anthropic', 'google-ai', 'commoncrawl' ) === array_keys( SN_RIGHTS_EVIDENCE_RETRACTIONS['2026-08'] ) && array( '2026-08' ) === array_keys( SN_RIGHTS_EVIDENCE_RETRACTIONS ) && false === strpos( json_encode( SN_RIGHTS_EVIDENCE_RETRACTIONS, JSON_UNESCAPED_UNICODE ), "\u{2014}" ), 'N0 the approved text is pinned byte for byte (sha256 of the map), four August families, no em dash' );
ok( 0 === strpos( $txt['what_was_wrong'], 'Two errors. The reservation named the versions current' ) && 'The reservation in force for August 2026 was license-xml v2, robots-txt v5, tdm-policy v8, tdmrep-json v1 and webmcp-bridge v5.' === SN_RIGHTS_EVIDENCE_RETRACTIONS['2026-08']['google-ai']['claimed'] && null === sn_rights_evidence_retraction_text( '2026-08', 'mistral' ), 'N0b exact strings, and no text means null' );

$seed( $conf );
ok( array( array( '2026-08', 'openai' ) ) === array_map( static fn( $r ) => array( $r['month'], $r['family'] ), sn_rights_evidence_retractable() ), 'N1 only a confirmed record with a ledger path and approved text is retractable' );
foreach ( array( 'composed', 'unanchored', 'pending', 'conflict', 'retracted', '' ) as $st ) {
	$seed( array_merge( $conf, array( 'status' => $st ) ) );
	$r = sn_rights_evidence_retract( '2026-08', 'openai', $t0 );
	ok( 'ineligible' === $r['result'] && array() === $GLOBALS['__k']['posts'], "N2 status '$st': no post" );
}
$seed( array_merge( $conf, array( 'ledger_path' => '' ) ) );
ok( 'ineligible' === sn_rights_evidence_retract( '2026-08', 'openai', $t0 )['result'] && array() === $GLOBALS['__k']['posts'], 'N2b confirmed with no ledger path: no post' );
$seed( $conf );
foreach ( array( array( '2026-07', 'openai' ), array( '2026-08', 'mistral' ), array( '2026-08', 'nobody' ), array( '2026-09', 'openai' ) ) as $mf ) {
	ok( 'ineligible' === sn_rights_evidence_retract( $mf[0], $mf[1], $t0 )['result'] && array() === $GLOBALS['__k']['posts'], 'N2c no text or no record (' . implode( ' ', $mf ) . '): no post' );
}

$seed( $conf );
$GLOBALS['__k']['post_reply'] = $ok_reply;
$before = sn_rights_evidence_data()['2026-08']['openai'];
$r      = sn_rights_evidence_retract( '2026-08', 'openai', $t0 );
$p      = $GLOBALS['__k']['posts'][0] ?? array( 'url' => '', 'args' => array( 'body' => '', 'headers' => array(), 'redirection' => null ) );
$b      = (array) json_decode( $p['args']['body'], true );
ok( 1 === count( $GLOBALS['__k']['posts'] ) && 'https://prov.example/retract' === $p['url'], 'N3 one POST, to the worker\'s /retract' );
ok( array( 'note_uid', 'version', 'retracted_path', 'what_was_wrong', 'claimed', 'root_cause', 'what_changed', 'retracted_at' ) === array_keys( $b ), 'N4 the body carries exactly the worker\'s fields, in its order: ' . implode( ',', array_keys( $b ) ) );
ok( 'uuid-7' === ( $b['note_uid'] ?? '' ) && 1 === ( $b['version'] ?? 0 ) && 'rights-evidence/u7/v1.json' === ( $b['retracted_path'] ?? '' ) && $txt['claimed'] === ( $b['claimed'] ?? '' ) && $txt['what_was_wrong'] === ( $b['what_was_wrong'] ?? '' ) && $txt['root_cause'] === ( $b['root_cause'] ?? '' ) && $txt['what_changed'] === ( $b['what_changed'] ?? '' ) && '2026-09-30T12:00:00+00:00' === ( $b['retracted_at'] ?? '' ), 'N5 note_uid is the record uuid, retracted_path the stored ledger_path byte for byte, the text the approved text, retracted_at gmdate(c)' );
ok( 'sha256=' . hash_hmac( 'sha256', $p['args']['body'], 's3' ) === ( $p['args']['headers']['X-SN-Signature'] ?? '' ) && 0 === $p['args']['redirection'], 'N6 HMAC over the exact body with the site secret; no redirects' );
$after = sn_rights_evidence_data()['2026-08']['openai'];
ok( 'retracted' === $r['result'] && 'retracted' === $after['status'] && 'retractions/uuid-7/v1.json' === ( $after['retraction_path'] ?? '' ) && str_repeat( 'a', 64 ) === ( $after['retraction_hash'] ?? '' ) && array_diff_key( $before, array( 'status' => 1 ) ) === array_diff_key( $after, array( 'status' => 1, 'retraction_path' => 1, 'retraction_hash' => 1 ) ), 'N7 200: status retracted, retraction path and hash stored, every other field kept' );
ok( array() === $GLOBALS['__k']['transients'] && array() === sn_rights_evidence_retractable(), 'N8 the lock is released, and a retracted record is no longer retractable' );

$seed( $conf );
$GLOBALS['__k']['post_reply'] = array( 'code' => 409, 'body' => json_encode( array( 'ok' => false, 'error' => 'already retracted' ) ) );
$snap = $GLOBALS['__k']['opt'];
$r    = sn_rights_evidence_retract( '2026-08', 'openai', $t0 );
ok( 'refused' === $r['result'] && 'already retracted' === $r['error'] && $snap === $GLOBALS['__k']['opt'] && array() === $GLOBALS['__k']['transients'], 'N9 409: refused with the worker\'s error, the stored record untouched' );
foreach ( array( 'transport' => new WP_Error( 'x', 'timed out' ), '502' => array( 'code' => 502, 'body' => '{"error":"bad gateway"}' ), '200 without ok' => array( 'code' => 200, 'body' => '{"ok":false}' ) ) as $k => $reply ) {
	$seed( $conf );
	$GLOBALS['__k']['post_reply'] = $reply;
	$snap = $GLOBALS['__k']['opt'];
	$r    = sn_rights_evidence_retract( '2026-08', 'openai', $t0 );
	ok( 'failed' === $r['result'] && $snap === $GLOBALS['__k']['opt'], "N10 $k: failed, untouched ({$r['error']})" );
}
// A lost 200: the 409 says already retracted; the ledger is read (GET only) and adopted only when it names this record.
$retr_doc = array( 'payload' => array( 'retracted_path' => 'rights-evidence/u7/v1.json', 'note_uid' => 'uuid-7' ), 'content_hash' => str_repeat( 'b', 64 ), 'ots' => array( 'status' => 'pending' ) );
$seed( $conf );
$GLOBALS['__k']['post_reply'] = array( 'code' => 409, 'body' => json_encode( array( 'ok' => false, 'error' => 'subject already retracted' ) ) );
$GLOBALS['__k']['ledger']['retractions/uuid-7/v1.json'] = $retr_doc; $GLOBALS['__k']['http'] = array();
$r     = sn_rights_evidence_retract( '2026-08', 'openai', $t0 );
$after = sn_rights_evidence_data()['2026-08']['openai'];
ok( 'retracted' === $r['result'] && 'retracted' === $after['status'] && 'retractions/uuid-7/v1.json' === ( $after['retraction_path'] ?? '' ) && str_repeat( 'b', 64 ) === ( $after['retraction_hash'] ?? '' ) && array( 'https://raw.example/ledger/main/retractions/uuid-7/v1.json' ) === $GLOBALS['__k']['http'] && 1 === count( $GLOBALS['__k']['posts'] ), 'N10b 409 already retracted + the ledger\'s retraction names this record: adopted with its path and hash, one POST, one ledger GET' );
$seed( $conf );
$GLOBALS['__k']['ledger']['retractions/uuid-7/v1.json'] = array_merge( $retr_doc, array( 'payload' => array( 'retracted_path' => 'rights-evidence/other/v1.json' ) ) );
$snap = $GLOBALS['__k']['opt'];
ok( 'refused' === sn_rights_evidence_retract( '2026-08', 'openai', $t0 )['result'] && $snap === $GLOBALS['__k']['opt'], 'N10c the ledger\'s retraction names another record: refused, untouched' );
$seed( $conf );
unset( $GLOBALS['__k']['ledger']['retractions/uuid-7/v1.json'] );
$snap = $GLOBALS['__k']['opt'];
ok( 'refused' === sn_rights_evidence_retract( '2026-08', 'openai', $t0 )['result'] && $snap === $GLOBALS['__k']['opt'], 'N10d already retracted but no retraction file on the ledger: refused, untouched' );
$seed( $conf );
$GLOBALS['__k']['ledger']['retractions/uuid-7/v1.json'] = $retr_doc; $GLOBALS['__k']['http'] = array();
$GLOBALS['__k']['post_reply'] = array( 'code' => 409, 'body' => json_encode( array( 'ok' => false, 'error' => 'subject absent' ) ) );
$snap = $GLOBALS['__k']['opt'];
ok( 'refused' === sn_rights_evidence_retract( '2026-08', 'openai', $t0 )['result'] && $snap === $GLOBALS['__k']['opt'] && array() === $GLOBALS['__k']['http'], 'N10e any other 409: untouched, the ledger not even read' );
unset( $GLOBALS['__k']['ledger']['retractions/uuid-7/v1.json'] );

$seed( $conf );
$GLOBALS['__k']['transients']['sn_rights_evidence_lock'] = 1;
ok( 'busy' === sn_rights_evidence_retract( '2026-08', 'openai', $t0 )['result'] && array() === $GLOBALS['__k']['posts'], 'N11 a pass holds the lock: nothing posted' );
$GLOBALS['__k']['transients'] = array();

// The F5 refresh treats retracted as final: the v1 file still says confirmed.
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => array_merge( $conf, array( 'status' => 'retracted', 'retraction_path' => 'retractions/uuid-7/v1.json', 'retraction_hash' => 'h' ) ) ) ) );
$GLOBALS['__k']['ledger']['rights-evidence/u7/v1.json'] = array( 'payload' => array(), 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 970007 ) );
$GLOBALS['__k']['http'] = array();
sn_rights_evidence_refresh();
ok( 'retracted' === sn_rights_evidence_data()['2026-08']['openai']['status'] && ! array_filter( $GLOBALS['__k']['http'], static fn( $u ) => str_contains( $u, 'rights-evidence/u7/' ) ), 'N12 the refresh never re-reads a retracted record nor flips it back to confirmed' );
// The refresh never overwrites a concurrent change: a retraction (and a pass's
// own final verdict) land while the refresh is reading the ledger.
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $conf, 'anthropic' => $re( 'pending', 2 ), 'cohere' => $re( 'pending', 3 ) ) ) );
$GLOBALS['__k']['ledger']['rights-evidence/u2/v1.json'] = array( 'payload' => array(), 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 970002 ) );
$GLOBALS['__k']['ledger']['rights-evidence/u3/v1.json'] = array( 'payload' => array(), 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 970003 ) );
$GLOBALS['__k']['on_fetch'] = static function ( $url ) {
	if ( str_ends_with( $url, 'rights-evidence/u3/v1.json' ) ) {
		$GLOBALS['__k']['opt'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['openai'] = array_merge( $GLOBALS['__k']['opt'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['openai'], array( 'status' => 'retracted', 'retraction_path' => 'retractions/uuid-7/v1.json' ) );
		$GLOBALS['__k']['opt'][ SN_RIGHTS_EVIDENCE_OPTION ]['2026-08']['anthropic']['status'] = 'conflict';
	}
};
$n    = sn_rights_evidence_refresh();
unset( $GLOBALS['__k']['on_fetch'] );
$d    = sn_rights_evidence_data()['2026-08'];
ok( 'retracted' === $d['openai']['status'] && 'retractions/uuid-7/v1.json' === $d['openai']['retraction_path'], 'N12b a retraction landing between the refresh\'s read and its write survives' );
ok( 'conflict' === $d['anthropic']['status'] && ! isset( $d['anthropic']['block'] ) && 'confirmed' === $d['cohere']['status'] && 970003 === $d['cohere']['block'] && 1 === $n, 'N12c an entry that turned final meanwhile is not touched; a still-pending one takes the ledger\'s status and block' );

$ab = $GLOBALS['__k']['abilities']['signal-noise/rights-evidence'];
$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => array_merge( $conf, array( 'status' => 'retracted', 'retraction_path' => 'retractions/uuid-7/v1.json', 'retraction_hash' => 'h' ) ) ) ) );
$ar = call_user_func( $ab['execute_callback'] );
$am = json_decode( json_encode( $ar['months'] ), true );
ok( 'retracted' === $am['2026-08']['openai']['status'] && 'retractions/uuid-7/v1.json' === $am['2026-08']['openai']['retraction_path'] && str_contains( $ab['description'], 'retracted' ) && str_contains( $ar['note'], 'retracted (' ), 'N13 the read ability reports status retracted and the retraction path' );
$src = (string) file_get_contents( __DIR__ . '/../inc/rights-evidence-retractions.php' );
ok( ! preg_match( '/wp_remote|sn_rights_evidence_(signed_)?post|update_option|get_option/', $src ), 'N14 structurally: the text file is pure data' );

if ( getenv( 'SN_RE_PRINT' ) ) {
	$GLOBALS['__k']['opt'] = array( SN_RIGHTS_EVIDENCE_OPTION => array( '2026-08' => array( 'openai' => $re( 'confirmed', 7 ), 'anthropic' => $re( 'confirmed', 8 ) ) ) );
	foreach ( sn_rights_evidence_dry_run( '2026-09', $oct5 )['payloads'] as $f => $c ) { echo "# dry-run 2026-09 $f\n" . json_encode( json_decode( $c ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; }
	foreach ( sn_rights_evidence_erratum( '2026-08', $oct5 )['erratum'] as $f => $c ) { echo "# erratum 2026-08 $f\n" . json_encode( json_decode( $c ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; }
}

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
