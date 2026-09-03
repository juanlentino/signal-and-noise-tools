<?php
/**
 * Standalone tests for inc/search-console-derive.php — wiring items 2 + 3.
 *
 * Drift: the three-state contract (accruing/zero/rows), the pre-committed
 * thresholds, span selection (longest qualifying span, not just adjacent
 * days), and the new-page exclusion. Topic interest: the by-PAGE join (no
 * query text anywhere near a model), impression-weighted position, and the
 * stated residual — a scan is its exclusions.
 *
 * Run: php tests/search-console-derive.php
 *
 * @since plugin v13.11.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── stubs before the module binds ──
$GLOBALS['__history'] = array();
function snt_gsc_history() { return $GLOBALS['__history']; }
$GLOBALS['__gsc'] = null;
function snt_gsc_data() { return $GLOBALS['__gsc']; }
$GLOBALS['__topics'] = null;
function snt_ml_topics_get() { return $GLOBALS['__topics']; }
$GLOBALS['__permalinks'] = array();
function get_permalink( $id ) { return $GLOBALS['__permalinks'][ $id ] ?? ''; }
function snt_gsc_url_to_path( $url ) { $p = parse_url( (string) $url, PHP_URL_PATH ); return is_string( $p ) ? $p : ''; }

require_once __DIR__ . '/../inc/search-console-derive.php';

function snap( $end, $pages ) { return array( 'end' => $end, 'pages' => $pages ); }

echo "Group: drift — the three states\n";
ok( null === snt_gsc_position_drift(), 'no history -> null (unknown, not zero)' );
$GLOBALS['__history'] = array( '2026-08-24' => snap( '2026-08-24', array( '/a/' => array( 'position' => 5.0, 'impressions' => 100 ) ) ) );
ok( null === snt_gsc_position_drift(), 'one snapshot -> null' );
$GLOBALS['__history'] = array(
	'2026-08-22' => snap( '2026-08-22', array( '/a/' => array( 'position' => 5.0, 'impressions' => 100 ) ) ),
	'2026-08-24' => snap( '2026-08-24', array( '/a/' => array( 'position' => 30.0, 'impressions' => 100 ) ) ),
);
ok( null === snt_gsc_position_drift(), 'two snapshots only 2 days apart -> null — a week is the pre-committed minimum span, however dramatic the move' );
$GLOBALS['__history'] = array(
	'2026-08-14' => snap( '2026-08-14', array( '/steady/' => array( 'position' => 4.0, 'impressions' => 200 ) ) ),
	'2026-08-24' => snap( '2026-08-24', array( '/steady/' => array( 'position' => 5.5, 'impressions' => 210 ) ) ),
);
ok( array() === snt_gsc_position_drift(), 'history answers and nothing clears the +5 floor -> a REAL empty, distinct from null' );

echo "\nGroup: drift — thresholds and span selection\n";
$GLOBALS['__history'] = array(
	'2026-08-10' => snap( '2026-08-10', array(
		'/slides/' => array( 'position' => 6.0, 'impressions' => 150 ),
		'/thin/'   => array( 'position' => 2.0, 'impressions' => 300 ),
		'/improves/' => array( 'position' => 20.0, 'impressions' => 90 ),
	) ),
	'2026-08-17' => snap( '2026-08-17', array( '/slides/' => array( 'position' => 8.0, 'impressions' => 140 ) ) ),
	'2026-08-24' => snap( '2026-08-24', array(
		'/slides/' => array( 'position' => 13.5, 'impressions' => 130 ),
		'/thin/'   => array( 'position' => 40.0, 'impressions' => 4 ),
		'/improves/' => array( 'position' => 3.0, 'impressions' => 400 ),
		'/new/'    => array( 'position' => 50.0, 'impressions' => 60 ),
	) ),
);
$d = snt_gsc_position_drift();
ok( is_array( $d ) && isset( $d['/slides/'] ) && 7.5 === $d['/slides/']['drift'], 'drift measures newest against the LONGEST qualifying span (6.0 -> 13.5 over 14 days = +7.5), not the adjacent snapshot' );
ok( ! isset( $d['/thin/'] ), 'a page shown 4 times now is excluded however far it fell — movement without impressions is noise' );
ok( ! isset( $d['/improves/'] ), 'improvement (20 -> 3) never flags: drift is one-sided by design' );
ok( ! isset( $d['/new/'] ), 'a page absent from the old snapshot has no drift yet — new is not worse' );
ok( 1 === count( $d ), 'exactly the one real slide' );

echo "\nGroup: topic interest — the by-page join\n";
ok( null === snt_gsc_topic_interest(), 'no window and no topics -> null (unknown)' );
$GLOBALS['__gsc'] = array( 'pages' => array(
	'/notes/prov-1/' => array( 'impressions' => 300, 'clicks' => 9, 'position' => 4.0, 'ctr' => 0.03 ),
	'/notes/prov-2/' => array( 'impressions' => 100, 'clicks' => 1, 'position' => 12.0, 'ctr' => 0.01 ),
	'/notes/biz-1/'  => array( 'impressions' => 50,  'clicks' => 0, 'position' => 22.0, 'ctr' => 0.0 ),
	'/services/'     => array( 'impressions' => 400, 'clicks' => 11, 'position' => 3.0, 'ctr' => 0.0275 ),
), 'synced_at' => 1 );
ok( null === snt_gsc_topic_interest(), 'window without a topics artifact -> still null' );
$GLOBALS['__topics'] = array(
	array( 'members' => array( 11, 12 ), 'label' => 'provenance' ),
	array( 'members' => array( 21 ), 'label' => 'freelance' ),
	array( 'members' => array( 31 ), 'label' => 'unshown-topic' ),
);
$GLOBALS['__permalinks'] = array(
	11 => 'https://x.test/notes/prov-1/', 12 => 'https://x.test/notes/prov-2/',
	21 => 'https://x.test/notes/biz-1/', 31 => 'https://x.test/notes/quiet/',
);
$ti = snt_gsc_topic_interest();
ok( is_array( $ti ) && 3 === count( $ti['clusters'] ), 'every cluster reports, including the one Google never showed' );
$prov = $ti['clusters'][0];
ok( 'provenance' === $prov['label'] && 400 === $prov['impressions'] && 10 === $prov['clicks'], 'most-shown first; members sum (300+100 impressions, 9+1 clicks)' );
ok( 6.0 === $prov['position'], 'position is impression-weighted ((4*300 + 12*100)/400 = 6.0), never a mean of means' );
$quiet = null; foreach ( $ti['clusters'] as $c ) { if ( 'unshown-topic' === $c['label'] ) { $quiet = $c; } }
ok( is_array( $quiet ) && 0 === $quiet['impressions'] && 0.0 === $quiet['position'], 'the never-shown cluster carries a real zero, position 0 (the view renders it as a dash)' );
ok( 400 === $ti['outside']['impressions'] && 1 === $ti['outside']['paths'], 'the residual is STATED: /services/ is outside every cluster, not silently dropped' );


// ─── snt_gsc_drift_progress (v13.88.2) ───
// snt_gsc_position_drift() returns null when it cannot answer; this turns that
// null into "how far off". On 2026-09-03, the day the drift watch came due, a
// bare `accruing` was indistinguishable from "stuck and will never flip" and
// settling it meant reading the source.
$GLOBALS['__history'] = array();
$pr = snt_gsc_drift_progress();
ok( 0 === $pr['snapshots'] && 0.0 === $pr['span_days'], 'no history: zero snapshots, zero span' );
ok( SNT_GSC_DRIFT_MIN_SPAN_DAYS === $pr['needed_days'], 'the threshold comes from the CONSTANT, so a caller need not know it' );

$GLOBALS['__history'] = array( '2026-08-25' => array( 'end' => '2026-08-25', 'pages' => array() ) );
$pr = snt_gsc_drift_progress();
ok( 1 === $pr['snapshots'] && 0.0 === $pr['span_days'], 'ONE snapshot spans nothing — a span needs two points' );

// The widest available span is newest minus FIRST: the store ksorts on ISO
// window-end dates, and that is the span the drift read gets to use.
$GLOBALS['__history'] = array(
	'2026-08-25' => array( 'end' => '2026-08-25', 'pages' => array() ),
	'2026-08-28' => array( 'end' => '2026-08-28', 'pages' => array() ),
	'2026-08-31' => array( 'end' => '2026-08-31', 'pages' => array() ),
);
$pr = snt_gsc_drift_progress();
ok( 3 === $pr['snapshots'] && 6.0 === $pr['span_days'], 'span is newest minus OLDEST (6.0), not the gap between adjacent snapshots' );
ok( $pr['span_days'] < $pr['needed_days'], 'and 6.0 < 7 is exactly the state that reads as accruing' );

// The span must track the data, or the row above could pass against a constant.
$GLOBALS['__history']['2026-09-02'] = array( 'end' => '2026-09-02', 'pages' => array() );
$pr2 = snt_gsc_drift_progress();
ok( 8.0 === $pr2['span_days'] && $pr2['span_days'] >= $pr2['needed_days'],
	'VACUITY GUARD: adding a newer snapshot widens the span to 8.0 and crosses the threshold' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
