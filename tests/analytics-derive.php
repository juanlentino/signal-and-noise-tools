<?php
/**
 * Tests for inc/analytics-derive.php — the PURE Phase A derive layer
 * (spec §4: honest denominators + the never-invert integrity flag).
 *
 * The module has ZERO WordPress calls, so this suite require()s the REAL
 * file directly — no stubs, no seams (test-unguarded-fn-declarations rule:
 * never stub what you can require). Input-key spellings mirror the real
 * rollup surface (inc/analytics-rollup.php): views / visits / scroll_sum /
 * scroll_events / time_sum / time_events (+ pageview_visits, Task 3) —
 * NOT invented names (the avg_scroll-vs-scroll_avg stub-drift trap).
 *
 * Null discipline under test (each rule is a shipped-bug class):
 *   - absent key ≡ null value ≡ "never measured" → derived field null;
 *   - ratio null when ANY input null/absent OR the denominator is 0;
 *   - a real measured 0 stays 0 (never null), null never becomes 0.
 *
 * Run: php tests/analytics-derive.php
 * @since plugin v9.63.0 (Analytics Integrity Phase A, Task 2)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

require_once __DIR__ . '/../inc/analytics-derive.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

// Every §4-derived field + the guard flag, in contract order. Null-valued keys
// MUST still be present — asserted via array_key_exists, never isset (isset()
// is blind to a present-but-null key, the exact distinction under test).
$EXPECTED_KEYS = array(
	'unique_visitor_days',
	'pageview_visits',
	'viewless_visits',
	'view_visit_ratio',
	'pageviews_per_visitor_day',
	'scroll_avg_per_view',
	'time_avg_per_view',
	'scroll_avg_per_visit',
	'time_avg_per_visit',
	'integrity_violation',
);
function has_null( $out, $key ) { return array_key_exists( $key, $out ) && null === $out[ $key ]; }

echo "Analytics derive — pure Phase A metrics\n\n";

echo "Group (a): full modern row — every field exact-value pinned\n";
$a = sn_analytics_derive_metrics( array(
	'views'           => 8,
	'visits'          => 5,
	'pageview_visits' => 4,
	'scroll_sum'      => 440.0,
	'scroll_events'   => 6,
	'time_sum'        => 96000.0,
	'time_events'     => 4,
) );
ok( array_keys( $a ) === $EXPECTED_KEYS, '(a) output carries exactly the 10 contract keys, in order' );
ok( 5 === $a['unique_visitor_days'], '(a) unique_visitor_days === 5 (int passthrough of visits)' );
ok( 4 === $a['pageview_visits'], '(a) pageview_visits === 4 (int passthrough)' );
ok( 1 === $a['viewless_visits'], '(a) viewless_visits === 1 (5 − 4)' );
ok( 2.0 === $a['view_visit_ratio'], '(a) view_visit_ratio === 2.0 (8 / 4, float)' );
ok( 1.6 === $a['pageviews_per_visitor_day'], '(a) pageviews_per_visitor_day === 1.6 (8 / 5)' );
ok( 55.0 === $a['scroll_avg_per_view'], '(a) scroll_avg_per_view === 55.0 (440 / 8)' );
ok( 12000.0 === $a['time_avg_per_view'], '(a) time_avg_per_view === 12000.0 (96000 / 8)' );
ok( 88.0 === $a['scroll_avg_per_visit'], '(a) scroll_avg_per_visit === 88.0 (440 / 5)' );
ok( 19200.0 === $a['time_avg_per_visit'], '(a) time_avg_per_visit === 19200.0 (96000 / 5)' );
ok( false === $a['integrity_violation'], '(a) integrity_violation === false on valid data (8 ≥ 4)' );

echo "\nGroup (b): legacy row (NULL sums, NULL pageview_visits) — nulls, passthrough intact\n";
$b = sn_analytics_derive_metrics( array(
	'views'           => 87,
	'visits'          => 131,
	'pageview_visits' => null,
	'scroll_sum'      => null,
	'scroll_events'   => null,
	'time_sum'        => null,
	'time_events'     => null,
) );
ok( array_keys( $b ) === $EXPECTED_KEYS, '(b) all 10 keys present even when mostly null' );
ok( 131 === $b['unique_visitor_days'], '(b) legacy passthrough intact: unique_visitor_days === 131' );
ok( has_null( $b, 'pageview_visits' ), '(b) pageview_visits null (never measured), key still present' );
ok( has_null( $b, 'viewless_visits' ), '(b) viewless_visits null — one operand null, no fabricated count' );
ok( has_null( $b, 'view_visit_ratio' ), '(b) view_visit_ratio null — gated denominator unknown' );
ok( is_float( $b['pageviews_per_visitor_day'] ) && abs( $b['pageviews_per_visitor_day'] - 0.6641221374045801 ) < 1e-12,
	'(b) pageviews_per_visitor_day ≈ 0.664122 (87 / 131 — both live in legacy rows, "show the most")' );
ok( has_null( $b, 'scroll_avg_per_view' ), '(b) scroll_avg_per_view null (scroll_sum never measured)' );
ok( has_null( $b, 'time_avg_per_view' ), '(b) time_avg_per_view null' );
ok( has_null( $b, 'scroll_avg_per_visit' ), '(b) scroll_avg_per_visit null' );
ok( has_null( $b, 'time_avg_per_visit' ), '(b) time_avg_per_visit null' );
ok( false === $b['integrity_violation'], '(b) integrity_violation === false (strict bool, not null) when unjudgeable' );

echo "\nGroup (c): zero-traffic day — real 0 counts, null ratios (0 is an ANSWER, not null)\n";
$c = sn_analytics_derive_metrics( array(
	'views'           => 0,
	'visits'          => 0,
	'pageview_visits' => 0,
	'scroll_sum'      => 0.0,
	'scroll_events'   => 0,
	'time_sum'        => 0.0,
	'time_events'     => 0,
) );
ok( 0 === $c['unique_visitor_days'], '(c) unique_visitor_days === 0 (int zero, never nulled)' );
ok( 0 === $c['pageview_visits'], '(c) pageview_visits === 0 (int zero, never nulled)' );
ok( 0 === $c['viewless_visits'], '(c) viewless_visits === 0 (0 − 0, a real answer)' );
ok( has_null( $c, 'view_visit_ratio' ), '(c) view_visit_ratio null — denominator 0, never a fake 0' );
ok( has_null( $c, 'pageviews_per_visitor_day' ), '(c) pageviews_per_visitor_day null — denominator 0' );
ok( has_null( $c, 'scroll_avg_per_view' ), '(c) scroll_avg_per_view null — views 0' );
ok( has_null( $c, 'time_avg_per_view' ), '(c) time_avg_per_view null — views 0' );
ok( has_null( $c, 'scroll_avg_per_visit' ), '(c) scroll_avg_per_visit null — visitor-days 0' );
ok( has_null( $c, 'time_avg_per_visit' ), '(c) time_avg_per_visit null — visitor-days 0' );
ok( false === $c['integrity_violation'], '(c) integrity_violation === false (0 < 0 is false)' );

// The mirror pin: a MEASURED zero over a live denominator is 0.0, never null.
$c2 = sn_analytics_derive_metrics( array(
	'views'           => 10,
	'visits'          => 10,
	'pageview_visits' => 10,
	'scroll_sum'      => 0.0,
	'scroll_events'   => 0,
	'time_sum'        => 0.0,
	'time_events'     => 0,
) );
ok( 0.0 === $c2['scroll_avg_per_view'], '(c) measured scroll_sum=0 over views=10 → 0.0, never cast to null' );
ok( 0.0 === $c2['time_avg_per_visit'], '(c) measured time_sum=0 over 10 visitor-days → 0.0, never null' );
ok( 1.0 === $c2['view_visit_ratio'], '(c) view_visit_ratio === 1.0 (10 / 10)' );
ok( 0 === $c2['viewless_visits'], '(c) viewless_visits === 0 (10 − 10)' );

echo "\nGroup (d): inverted fixture (views=3, pageview_visits=5) — the alarm fires, nothing is clamped\n";
$d = sn_analytics_derive_metrics( array(
	'views'           => 3,
	'visits'          => 6,
	'pageview_visits' => 5,
	'scroll_sum'      => 120.0,
	'scroll_events'   => 2,
	'time_sum'        => 30000.0,
	'time_events'     => 2,
) );
ok( true === $d['integrity_violation'], '(d) integrity_violation === true when views < pageview_visits' );
ok( 0.6 === $d['view_visit_ratio'], '(d) view_visit_ratio === 0.6 — reported honestly, never clamped to 1' );
ok( 1 === $d['viewless_visits'], '(d) viewless_visits === 1 (6 − 5) — arithmetic stays honest under violation' );
$d2 = sn_analytics_derive_metrics( array( 'views' => 3, 'visits' => 6, 'pageview_visits' => null ) );
ok( false === $d2['integrity_violation'], '(d) violation needs BOTH operands non-null: null pageview_visits → false' );
$d3 = sn_analytics_derive_metrics( array( 'visits' => 6, 'pageview_visits' => 5 ) );
ok( false === $d3['integrity_violation'], '(d) violation needs BOTH operands non-null: absent views → false' );

echo "\nGroup (e): absent-key row — nulls across the board, ZERO notices\n";
$notices = 0;
set_error_handler( function ( $errno, $errstr ) use ( &$notices ) { ++$notices; return true; } );
$e = sn_analytics_derive_metrics( array() );
restore_error_handler();
ok( 0 === $notices, '(e) empty input raises no notices/warnings (array_key_exists discipline)' );
ok( array_keys( $e ) === $EXPECTED_KEYS, '(e) all 10 keys present on empty input' );
ok( has_null( $e, 'unique_visitor_days' ), '(e) unique_visitor_days null when visits absent (absent ≡ never measured)' );
ok( has_null( $e, 'pageview_visits' ), '(e) pageview_visits null when absent' );
ok( has_null( $e, 'viewless_visits' ), '(e) viewless_visits null when absent' );
ok( has_null( $e, 'view_visit_ratio' ), '(e) view_visit_ratio null when absent' );
ok( has_null( $e, 'pageviews_per_visitor_day' ), '(e) pageviews_per_visitor_day null when absent' );
ok( has_null( $e, 'scroll_avg_per_view' ), '(e) scroll_avg_per_view null when absent' );
ok( has_null( $e, 'time_avg_per_view' ), '(e) time_avg_per_view null when absent' );
ok( has_null( $e, 'scroll_avg_per_visit' ), '(e) scroll_avg_per_visit null when absent' );
ok( has_null( $e, 'time_avg_per_visit' ), '(e) time_avg_per_visit null when absent' );
ok( false === $e['integrity_violation'], '(e) integrity_violation === false (bool, never null) on empty input' );
// Present-but-null must behave exactly like absent — the ??/isset() blind spot.
$notices = 0;
set_error_handler( function ( $errno, $errstr ) use ( &$notices ) { ++$notices; return true; } );
$e2 = sn_analytics_derive_metrics( array( 'views' => null, 'pageview_visits' => 5 ) );
restore_error_handler();
ok( 0 === $notices, '(e) present-but-null views raises no notices' );
ok( has_null( $e2, 'view_visit_ratio' ), '(e) views=null (present key) → view_visit_ratio null, same as absent' );
ok( false === $e2['integrity_violation'], '(e) views=null → violation unjudgeable → false' );

echo "\nGroup (f): wpdb string reality — numeric strings coerce, types stay honest\n";
$f = sn_analytics_derive_metrics( array(
	'views'           => '8',
	'visits'          => '5',
	'pageview_visits' => '4',
	'scroll_sum'      => '440',
	'scroll_events'   => '6',
	'time_sum'        => '96000',
	'time_events'     => '4',
) );
ok( 5 === $f['unique_visitor_days'], '(f) string "5" → int 5 (real int, not the string)' );
ok( 4 === $f['pageview_visits'], '(f) string "4" → int 4' );
ok( 1 === $f['viewless_visits'], '(f) viewless from strings === int 1' );
ok( 2.0 === $f['view_visit_ratio'], '(f) ratio from strings === float 2.0' );
ok( 55.0 === $f['scroll_avg_per_view'], '(f) scroll_avg_per_view from strings === 55.0' );
ok( false === $f['integrity_violation'], '(f) no violation on valid string row' );
$f2 = sn_analytics_derive_metrics( array( 'views' => '3', 'visits' => '6', 'pageview_visits' => '5' ) );
ok( true === $f2['integrity_violation'], '(f) inverted string row ("3" < "5") → violation true (numeric compare)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
