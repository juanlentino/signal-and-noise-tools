<?php
/**
 * Search Console on the read door — measurement weave Phase 1 (v13.57.0).
 *
 * Pins the three payload rules the file header names: the FLOOR flag rides
 * the wire, NULL is never a zero row, and the cross-exam states its grain.
 * Then the registration + door wiring: three slugs, readonly, manage_options,
 * on the read allowlist, routed by sn-status sections.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; } }
$GLOBALS['__test_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
$GLOBALS['__registered'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
function snt_ability_perm_manage_options() { return true; }
function snt_sn_site_facts_dispatch( $slug, $args ) { return array( 'dispatched' => $slug ); }

function get_option( $k, $d = null ) { return $d; }
require_once __DIR__ . '/../inc/search-console-store.php';    // SNT_GSC_PAGE_ROW_LIMIT — the floor the payload quotes.
require_once __DIR__ . '/../inc/search-console-derive.php';   // the thresholds the payloads quote.
require_once __DIR__ . '/../inc/abilities-search-console.php';
require_once __DIR__ . '/../inc/abilities-sn-status.php';
require_once __DIR__ . '/../inc/mcp/mcp-capabilities.php';

echo "Search Console on the read door — v13.57.0\n\n";

// ─── search_performance ───
$never = snt_search_performance_impl( null, null );
ok( false === $never['synced'] && null === $never['totals'] && array() === $never['pages'], 'never synced: synced:false with NULL totals and no rows — not a zero' );

$data = array(
	'property'  => 'sc-domain:example.test',
	'window'    => array( 'start' => '2026-08-01', 'end' => '2026-08-28' ),
	'synced_at' => 1700000000,
	'pages'     => array(
		'/notes/low'   => array( 'clicks' => 1, 'impressions' => 5, 'ctr' => 0.2, 'position' => 12.0 ),
		'/notes/top'   => array( 'clicks' => 40, 'impressions' => 900, 'ctr' => 0.0444, 'position' => 3.2 ),
		'/notes/near'  => array( 'clicks' => 2, 'impressions' => 60, 'ctr' => 0.0333, 'position' => 8.0 ),
		'/notes/deep'  => array( 'clicks' => 0, 'impressions' => 30, 'ctr' => 0.0, 'position' => 20.1 ),
	),
	'queries'   => array( array( 'key' => 'signal noise', 'impressions' => 100 ) ),
);
$tot = array( 'clicks' => 43, 'impressions' => 995, 'days' => 28, 'capped' => true );
$p   = snt_search_performance_impl( $data, $tot );
ok( true === $p['synced'] && '/notes/top' === $p['pages'][0]['path'], 'rows are most-shown first' );
ok( true === $p['totals']['capped'] && false !== strpos( $p['note'], 'FLOOR' ), 'THE FLOOR: capped rides the wire and the note says floor' );
$pu = snt_search_performance_impl( $data, array_merge( $tot, array( 'capped' => false ) ) );
ok( false === $pu['totals']['capped'] && false === strpos( $pu['note'], 'FLOOR' ), 'uncapped: no floor language' );
$opp = array_map( static fn( $r ) => $r['path'], $p['opportunities'] );
ok( array( '/notes/near' ) === $opp, 'opportunities: position 8-20 with 10+ impressions — /near (8.0) in; /low (5 imp) out; /deep (20.1) out; /top (3.2) out' );
ok( 1 === count( $p['queries'] ) && 'sc-domain:example.test' === $p['property'], 'queries and property pass through' );

// ─── search_drift ───
$acc = snt_search_drift_impl( null );
ok( 'accruing' === $acc['state'] && array() === $acc['drifting'], 'NULL from the derive is state:accruing — not "no drift"' );
$zero = snt_search_drift_impl( array() );
ok( 'measured' === $zero['state'] && array() === $zero['drifting'], '[] from the derive is state:measured with nothing drifting — the real good zero' );
$d = snt_search_drift_impl( array( '/notes/a' => array( 'from' => 4.0, 'to' => 11.5, 'drift' => 7.5, 'impressions' => 40 ) ) );
ok( 'measured' === $d['state'] && '/notes/a' === $d['drifting'][0]['path'] && 7.5 === $d['drifting'][0]['drift'], 'drift rows carry path/from/to/drift/impressions' );

// v13.88.2 — ACCRUING MUST SAY HOW FAR OFF IT IS.
// On the day the drift watch came due, a bare `accruing` was indistinguishable
// from "stuck and will never flip", and settling that meant reading the derive
// source. Third instrument in one day reporting a verdict without the evidence
// to interpret it.
$acc2 = snt_search_drift_impl( null, array( 'snapshots' => 9, 'span_days' => 6.2, 'needed_days' => 7 ) );
ok( 6.2 === $acc2['progress']['span_days'] && 9 === $acc2['progress']['snapshots'] && 7 === $acc2['progress']['needed_days'],
	'accruing carries progress: span, snapshots and the threshold it needs' );
ok( false !== strpos( $acc2['note'], '6.2 of 7 days' ) && false !== strpos( $acc2['note'], '9 snapshots' ),
	'and the NOTE names them, so the readout answers "how close" without a second call' );
ok( false !== strpos( $acc2['note'], 'not "no drift"' ),
	'while keeping the sentence that stops accruing being read as a clean bill' );

// A DIFFERENT progress must produce a different note, or the assertion above
// could pass against a hardcoded string.
$acc3 = snt_search_drift_impl( null, array( 'snapshots' => 2, 'span_days' => 1.0, 'needed_days' => 7 ) );
ok( $acc3['note'] !== $acc2['note'] && false !== strpos( $acc3['note'], '1.0 of 7 days' ),
	'VACUITY GUARD: the note is built from the values, not printed from a constant' );

// The PRODUCTION path must actually pass progress. Without this the payload
// still carries a `progress` key — full of zeros — and every assertion above
// would keep passing while the readout said nothing.
$src = (string) file_get_contents( __DIR__ . '/../inc/abilities-search-console.php' );
ok( false !== strpos( $src, 'snt_gsc_drift_progress()' ),
	'the ability entrypoint passes real progress, not the empty default' );

// ─── search_crossexam ───
$x = snt_search_crossexam_impl( array( 'ok' => true, 'verdict' => 'agree', 'gsc' => array(), 'ledger' => array() ), 'Both agree.' );
ok( 'window' === $x['grain'] && false !== stripos( $x['caveat'], 'NOT a per-page join' ), 'THE GRAIN: window, and the caveat is IN THE PAYLOAD' );
ok( 'Both agree.' === $x['reading'] && 'agree' === $x['verdict'], 'reading + verdict pass through' );
$xf = snt_search_crossexam_impl( array( 'ok' => false, 'reason' => 'no_gsc' ), '' );
ok( false === $xf['ok'] && 'no_gsc' === $xf['reason'] && 'window' === $xf['grain'], 'an instrument that did not answer stays ok:false with its reason — never zero' );

// ─── registration ───
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$slugs = array( 'signal-noise/search-performance', 'signal-noise/search-drift', 'signal-noise/search-crossexam' );
foreach ( $slugs as $slug ) {
	$r = $GLOBALS['__registered'][ $slug ] ?? null;
	ok( is_array( $r ), "$slug registers on wp_abilities_api_init" );
	ok( 'snt_ability_perm_manage_options' === ( $r['permission_callback'] ?? '' ), "$slug is manage_options" );
	ok( true === ( $r['meta']['annotations']['readonly'] ?? null ) && true === ( $r['meta']['show_in_rest'] ?? null ), "$slug is readonly + REST-visible" );
	ok( function_exists( (string) ( $r['execute_callback'] ?? '' ) ), "$slug execute callback exists" );
	ok( is_array( $r['output_schema']['properties'] ?? null ) && count( $r['output_schema']['properties'] ) >= 4, "$slug declares an output_schema" );
}
ok( isset( $GLOBALS['__registered']['signal-noise/search-performance']['output_schema']['properties']['totals']['properties']['capped'] ), 'the schema DECLARES totals.capped — the floor flag is contractual' );

// ─── doors ───
$read = sn_mcp_allowlist();
ok( array() === array_diff( $slugs, $read ), 'all three are on the read-door allowlist' );
$map = snt_sn_status_map();
ok( 'signal-noise/search-performance' === ( $map['search_performance'] ?? null ) && 'signal-noise/search-drift' === ( $map['search_drift'] ?? null ) && 'signal-noise/search-crossexam' === ( $map['search_crossexam'] ?? null ), 'sn-status routes the three search_* sections to them' );
$out = snt_ability_sn_status( array( 'sections' => array( 'search_performance', 'search_drift', 'search_crossexam' ) ) );
ok( is_array( $out ) && 3 === count( $out['sections'] ) && 'signal-noise/search-drift' === $out['sections']['search_drift']['dispatched'], 'the sections dispatch through the shared dispatcher' );

// ─── the execute callbacks over an EMPTY store (loaded, never synced) ───
ok( false === snt_ability_search_performance( null )['synced'], 'search-performance over a never-synced store answers synced:false — no fabricated window' );
ok( 'accruing' === snt_ability_search_drift( null )['state'], 'search-drift over an empty history answers state:accruing' );
ok( is_wp_error( snt_ability_search_crossexam( null ) ), 'search-crossexam without the cross-exam module loaded is a WP_Error, never a verdict' );


// ─── v13.63.1: empty maps serialize as [] and the schema must admit it ───
// The never-synced answer was refused by the door's own output validator on
// first live read: by_coverage_state and entries were declared 'object', and a
// keyless PHP array is JSON []. Pin the encoding fact AND the declaration.
function snt_gsc_coverage_data() { return null; }
function snt_gsc_coverage_summary( $d ) { return array( 'synced' => false, 'inspected' => 0, 'indexed' => 0, 'not_indexed' => 0, 'unknown' => 0, 'errors' => 0, 'by_coverage_state' => array(), 'not_indexed_paths' => array(), 'canonical_mismatch' => array() ); }
$never_cov = snt_ability_search_coverage( null );
$enc = json_decode( json_encode( $never_cov ), true );
ok( '[]' === json_encode( $never_cov['entries'] ) && '[]' === json_encode( $never_cov['by_coverage_state'] ), 'FACT: the never-synced maps encode as JSON [] (not {})' );
$cov_schema = $GLOBALS['__registered']['signal-noise/search-coverage']['output_schema']['properties'];
foreach ( array( 'by_coverage_state', 'entries' ) as $k ) {
	$t = (array) ( $cov_schema[ $k ]['type'] ?? array() );
	ok( in_array( 'object', $t, true ) && in_array( 'array', $t, true ), "schema: $k admits BOTH object and array — the empty map cannot be refused" );
}
ok( isset( $GLOBALS['__registered']['signal-noise/search-coverage'] ) && 'snt_ability_search_coverage' === $GLOBALS['__registered']['signal-noise/search-coverage']['execute_callback'], 'search-coverage registers from the same table' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
