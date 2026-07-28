<?php
/**
 * Tests for inc/abilities-analytics.php — read-only analytics Abilities.
 * Self-contained: stubs the Abilities API seam, fires the captured
 * wp_abilities_api_init closure.
 *
 * @package SignalNoiseTools
 * @since   6.1.0
 */
define( 'ABSPATH', '/' );
$GLOBALS['__ab'] = array();
function wp_register_ability( $id, $args ) { $GLOBALS['__ab'][ $id ] = $args; }
$GLOBALS['__ab_cb'] = null;
function add_action( $h, $c = null, $p = 10, $a = 1 ) { if ( 'wp_abilities_api_init' === $h ) { $GLOBALS['__ab_cb'] = $c; } }
$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
require __DIR__ . '/../inc/abilities-analytics.php';
call_user_func( $GLOBALS['__ab_cb'] );
echo "\nGroup: abilities\n";
ok( isset( $GLOBALS['__ab']['signal-noise/get-analytics-summary'] ), 'summary ability registered' );
$a = $GLOBALS['__ab']['signal-noise/get-analytics-summary'];
ok( ! empty( $a['meta']['show_in_rest'] ), 'exposed in REST' );
ok( empty( $a['meta']['annotations']['destructive'] ), 'read-only: not destructive' );
ok( ! empty( $a['meta']['annotations']['idempotent'] ), 'marked idempotent' );
ok( is_string( $a['permission_callback'] ) && $a['permission_callback'] !== '', 'has a permission callback' );
ok( $a['permission_callback'] === 'snt_ability_perm_manage_options', 'permission_callback is the shared manage_options guard' );
ok( isset( $a['execute_callback'] ), 'has an execute callback' );

echo "\nGroup: get-analytics-events ability\n";
ok( isset( $GLOBALS['__ab']['signal-noise/get-analytics-events'] ), 'events ability registered' );
$ae = $GLOBALS['__ab']['signal-noise/get-analytics-events'];
ok( ! empty( $ae['meta']['show_in_rest'] ), 'events ability exposed in REST' );
ok( empty( $ae['meta']['annotations']['destructive'] ), 'events ability: read-only (not destructive)' );
ok( ! empty( $ae['meta']['annotations']['idempotent'] ), 'events ability: marked idempotent' );
ok( isset( $ae['permission_callback'] ) && $ae['permission_callback'] === 'snt_ability_perm_manage_options', 'events ability permission_callback is snt_ability_perm_manage_options' );

// ─── Phase A (spec §4): output-schema contract for get-analytics-summary ─────
// The FULL field set, pinned in response order (array_merge($legacy, $derived,
// exact_metrics_since) inside sn_analytics_range_totals()). ADDITIVE ONLY:
// the legacy quartet keeps its exact names + never-null types — Desktop Mode
// normalizes these schemas at desktop_mode_ai_tools, so a rename/removal is a
// breaking change there. Nullable unions mirror where the derive layer can
// return null ("never measured" — never a fabricated 0).

$expected_types = array(
	'views'                     => 'integer',
	'visits'                    => 'integer',
	'scroll_avg'                => 'number',
	'time_avg'                  => 'number',
	'unique_visitor_days'       => array( 'integer', 'null' ),
	'pageview_visits'           => array( 'integer', 'null' ),
	'viewless_visits'           => array( 'integer', 'null' ),
	'view_visit_ratio'          => array( 'number', 'null' ),
	'pageviews_per_visitor_day' => array( 'number', 'null' ),
	'scroll_avg_per_view'       => array( 'number', 'null' ),
	'time_avg_per_view'         => array( 'number', 'null' ),
	'scroll_avg_per_visit'      => array( 'number', 'null' ),
	'time_avg_per_visit'        => array( 'number', 'null' ),
	'integrity_violation'       => 'boolean',
	'exact_metrics_since'       => array( 'string', 'null' ),
);

echo "\nGroup: summary output schema (Phase A spec-§4 contract)\n";
$schema = isset( $a['output_schema'] ) && is_array( $a['output_schema'] ) ? $a['output_schema'] : array();
ok( ( $schema['type'] ?? null ) === 'object', 'output schema type is object' );
$props = ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) ? $schema['properties'] : array();
ok( array_keys( $props ) === array_keys( $expected_types ), 'schema property keys pin the FULL spec-§4 field list, in response order' );
foreach ( $expected_types as $field => $type ) {
	$label = is_array( $type ) ? implode( '|', $type ) : $type;
	ok( array_key_exists( $field, $props ) && ( $props[ $field ]['type'] ?? null ) === $type, "schema declares $field as $label" );
}

echo "\nGroup: summary description (every denominator documented)\n";
$desc = (string) ( $a['description'] ?? '' );
foreach ( array(
	'unique_visitor_days',
	'pageview_visits',
	'viewless_visits',
	'scroll_avg_per_view',
	'time_avg_per_view',
	'scroll_avg_per_visit',
	'time_avg_per_visit',
	'exact_metrics_since',
) as $needle ) {
	ok( false !== strpos( $desc, $needle ), "description names $needle" );
}
foreach ( array(
	'DEPRECATED'               => 'the visits deprecation is stated',
	'(removed in v10.0.0)'     => 'the v10 removal window is stated',
	'visitor-days'             => 'the visitor-day unit is stated',
	'IP+date'                  => 'the IP+date approximation is stated',
	'NOT sessions'             => 'visits ≠ sessions is stated',
	'can exceed'               => 'visits-can-exceed-views is stated',
	'headline'                 => 'pageview_visits is called the headline metric',
	'cannot invert'            => 'the never-invert property is stated',
	'diluted by viewless days' => 'per_visit dilution is stated',
	'views-weighted'           => 'legacy scroll_avg/time_avg denominators are stated',
	// v9.64.0 scroll-unit redefinition: the depth identity must be documented
	// so an AI caller can never re-derive the shipped-113% scroll_sum unit.
	'25 * scroll_events'       => 'the depth identity (25 * scroll_events / denominator) is stated',
	'max scroll depth'         => 'the unit is named a true mean max scroll depth',
	'milestone'                => 'the cumulative-milestone beacon mechanics are stated',
	// v9.65.0 (Part 3): the wp-admin Sessions tab counts within-day sessions —
	// a THIRD unit an AI caller could confuse with either visits field. Stated
	// additively (no schema field renamed).
	'within-day sessions'      => 'the Sessions tab\'s distinct unit is stated',
) as $needle => $label ) {
	ok( false !== strpos( $desc, $needle ), "description: $label ('$needle')" );
}

// ─── Full-response contract: the REAL ability callback through the REAL read
// layer (inc/analytics-read.php + inc/analytics-derive.php required, never
// stubbed). The wpdb stub models the TRANSPORT exactly like the known-good
// sibling tests/analytics-read.php: every value comes back a STRING, SQL NULL
// as PHP null, SUM() skips NULLs and is NULL over zero non-null inputs,
// COUNT(col) counts non-null only, COUNT(*) counts rows. ─────────────────────

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );

$GLOBALS['__opts'] = array( 'sn_analytics_exact_metrics_since' => '2026-04-19' );
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

// Input plumbing (inc/analytics-admin.php) — faithful return SHAPES (whitelisted
// int range, whitelisted class string, [from,to] Y-m-d pair) on a fixed clock.
function snt_analytics_resolve_range( $raw ) { return in_array( (int) $raw, array( 7, 14, 30, 90, 365 ), true ) ? (int) $raw : 7; }
function snt_analytics_resolve_class( $raw ) { return in_array( (string) $raw, SN_ANALYTICS_CLASSES, true ) ? (string) $raw : 'human'; }
function snt_analytics_range_dates( $range, $now = null ) {
	$anchor = gmmktime( 12, 0, 0, 7, 18, 2026 ); // fixed 2026-07-18 12:00 UTC
	return array( gmdate( 'Y-m-d', $anchor - ( ( (int) $range - 1 ) * 86400 ) ), gmdate( 'Y-m-d', $anchor ) );
}

class AB_Stub_wpdb {
	public $prefix = 'wp_';
	public $rows = array();
	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		if ( false === stripos( $sql, 'row_count' ) ) { return array(); }
		$rows = $this->rows;
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cm ) { return (string) ( $r['class'] ?? 'human' ) === $cm[1]; } ) );
		}
		$sum = function ( $key ) use ( $rows ) {
			$acc = null;
			foreach ( $rows as $r ) {
				$v = array_key_exists( $key, $r ) ? $r[ $key ] : null;
				if ( null !== $v ) { $acc = ( null === $acc ? 0 : $acc ) + (float) $v; }
			}
			return $acc;
		};
		$cnt = function ( $key ) use ( $rows ) {
			$c = 0;
			foreach ( $rows as $r ) { if ( null !== ( array_key_exists( $key, $r ) ? $r[ $key ] : null ) ) { ++$c; } }
			return $c;
		};
		$str = function ( $v ) { return null === $v ? null : (string) $v; };
		$v   = $sum( 'views' );
		$sw  = 0.0; $tw = 0.0;
		foreach ( $rows as $r ) { $sw += (float) ( $r['scroll_avg'] ?? 0 ) * (int) ( $r['views'] ?? 0 ); $tw += (float) ( $r['time_avg'] ?? 0 ) * (int) ( $r['views'] ?? 0 ); }
		return array( array(
			'views'           => $str( $v ),
			'visits'          => $str( $sum( 'visits' ) ),
			'scroll_avg'      => ( null !== $v && $v > 0 ) ? (string) ( $sw / $v ) : null,
			'time_avg'        => ( null !== $v && $v > 0 ) ? (string) ( $tw / $v ) : null,
			'scroll_sum'      => $str( $sum( 'scroll_sum' ) ),
			'scroll_events'   => $str( $sum( 'scroll_events' ) ),
			'time_sum'        => $str( $sum( 'time_sum' ) ),
			'time_events'     => $str( $sum( 'time_events' ) ),
			'pageview_visits' => $str( $sum( 'pageview_visits' ) ),
			'row_count'       => (string) count( $rows ),
			'exact_rows'      => (string) $cnt( 'scroll_sum' ),
			'gated_rows'      => (string) $cnt( 'pageview_visits' ),
		) );
	}
}
$GLOBALS['wpdb'] = new AB_Stub_wpdb();
require __DIR__ . '/../inc/analytics-read.php';

/** True iff $value conforms to a declared JSON-Schema type (string or union). */
function ab_type_ok( $value, $type ) {
	foreach ( (array) $type as $t ) {
		if ( ( 'null' === $t && null === $value )
			|| ( 'integer' === $t && is_int( $value ) )
			|| ( 'number' === $t && ( is_int( $value ) || is_float( $value ) ) )
			|| ( 'boolean' === $t && is_bool( $value ) )
			|| ( 'string' === $t && is_string( $value ) ) ) {
			return true;
		}
	}
	return false;
}

echo "\nGroup: summary full response — modern v5 range (every value pinned)\n";
$GLOBALS['wpdb']->rows = array(
	array( 'class' => 'human', 'views' => 50, 'visits' => 20, 'scroll_avg' => 30.0, 'time_avg' => 4000.0, 'scroll_sum' => 1500.0, 'scroll_events' => 40, 'time_sum' => 160000.0, 'time_events' => 40, 'pageview_visits' => 15 ),
	array( 'class' => 'human', 'views' => 30, 'visits' => 12, 'scroll_avg' => 20.0, 'time_avg' => 3000.0, 'scroll_sum' => 600.0,  'scroll_events' => 25, 'time_sum' => 90000.0,  'time_events' => 25, 'pageview_visits' => 10 ),
	array( 'class' => 'human', 'views' => 20, 'visits' => 8,  'scroll_avg' => 20.0, 'time_avg' => 2500.0, 'scroll_sum' => 400.0,  'scroll_events' => 15, 'time_sum' => 50000.0,  'time_events' => 15, 'pageview_visits' => 5 ),
);
$out = sn_ability_get_analytics_summary( array( 'range' => 30, 'class' => 'human' ) );
ok( is_array( $out ), 'callback returns an array' );
ok( array_keys( $out ) === array_keys( $props ), 'ACTUAL response keys === DECLARED schema keys (no contract drift)' );
ok( 100 === ( $out['views'] ?? null ), 'views === 100 (int, legacy untouched)' );
ok( 40 === ( $out['visits'] ?? null ), 'visits === 40 (int, kept-deprecated)' );
ok( 25.0 === ( $out['scroll_avg'] ?? null ), 'scroll_avg === 25.0 (views-weighted legacy)' );
ok( 3400.0 === ( $out['time_avg'] ?? null ), 'time_avg === 3400.0 (views-weighted legacy)' );
ok( 40 === ( $out['unique_visitor_days'] ?? null ), 'unique_visitor_days === 40 (honest alias of visits)' );
ok( 30 === ( $out['pageview_visits'] ?? null ), 'pageview_visits === 30 (headline, gated)' );
ok( 10 === ( $out['viewless_visits'] ?? null ), 'viewless_visits === 10 (40 − 30)' );
ok( is_float( $out['view_visit_ratio'] ?? null ) && abs( $out['view_visit_ratio'] - 100 / 30 ) < 1e-12, 'view_visit_ratio === 100/30' );
ok( 2.5 === ( $out['pageviews_per_visitor_day'] ?? null ), 'pageviews_per_visitor_day === 2.5 (100/40)' );
ok( 20.0 === ( $out['scroll_avg_per_view'] ?? null ), 'scroll_avg_per_view === 20.0 (25×80 events/100 views — v9.64.0 depth unit)' );
ok( 3000.0 === ( $out['time_avg_per_view'] ?? null ), 'time_avg_per_view === 3000.0 (300000/100 exact)' );
ok( 50.0 === ( $out['scroll_avg_per_visit'] ?? null ), 'scroll_avg_per_visit === 50.0 (25×80/40, diluted denominator)' );
ok( 7500.0 === ( $out['time_avg_per_visit'] ?? null ), 'time_avg_per_visit === 7500.0 (300000/40)' );
ok( false === ( $out['integrity_violation'] ?? null ), 'integrity_violation === false (strict bool, valid data)' );
ok( '2026-04-19' === ( $out['exact_metrics_since'] ?? null ), 'exact_metrics_since passes the option through' );
foreach ( $expected_types as $field => $type ) {
	ok( array_key_exists( $field, $out ) && ab_type_ok( $out[ $field ], $type ), "modern: $field present + conforms to declared type" );
}

echo "\nGroup: summary full response — legacy pre-backfill range (nullables are honest)\n";
$GLOBALS['wpdb']->rows = array(
	array( 'class' => 'human', 'views' => 10, 'visits' => 15, 'scroll_avg' => 20.0, 'time_avg' => 2000.0, 'scroll_sum' => null, 'scroll_events' => null, 'time_sum' => null, 'time_events' => null, 'pageview_visits' => null ),
	array( 'class' => 'human', 'views' => 8,  'visits' => 10, 'scroll_avg' => 15.0, 'time_avg' => 2500.0, 'scroll_sum' => null, 'scroll_events' => null, 'time_sum' => null, 'time_events' => null, 'pageview_visits' => null ),
);
$out = sn_ability_get_analytics_summary( array( 'range' => 7, 'class' => 'human' ) ); // range 7 → fresh memo key
ok( 18 === ( $out['views'] ?? null ) && 25 === ( $out['visits'] ?? null ), 'legacy quartet intact — and visits (25) EXCEEDS views (18), the documented deprecated semantics' );
ok( is_float( $out['scroll_avg'] ?? null ) && abs( $out['scroll_avg'] - 320 / 18 ) < 1e-6, 'scroll_avg still views-weighted (320/18)' );
ok( is_float( $out['time_avg'] ?? null ) && abs( $out['time_avg'] - 40000 / 18 ) < 1e-6, 'time_avg still views-weighted (40000/18)' );
ok( 25 === ( $out['unique_visitor_days'] ?? null ), 'unique_visitor_days === 25 (from NOT NULL visits — known even pre-backfill)' );
ok( array_key_exists( 'pageview_visits', $out ) && null === $out['pageview_visits'], 'pageview_visits null (never measured, not 0)' );
ok( array_key_exists( 'viewless_visits', $out ) && null === $out['viewless_visits'], 'viewless_visits null' );
ok( array_key_exists( 'view_visit_ratio', $out ) && null === $out['view_visit_ratio'], 'view_visit_ratio null' );
ok( 0.72 === ( $out['pageviews_per_visitor_day'] ?? null ), 'pageviews_per_visitor_day === 0.72 (18/25 — stays exact: both denominators legacy NOT NULL)' );
ok( array_key_exists( 'scroll_avg_per_view', $out ) && null === $out['scroll_avg_per_view'], 'scroll_avg_per_view null' );
ok( array_key_exists( 'time_avg_per_view', $out ) && null === $out['time_avg_per_view'], 'time_avg_per_view null' );
ok( array_key_exists( 'scroll_avg_per_visit', $out ) && null === $out['scroll_avg_per_visit'], 'scroll_avg_per_visit null' );
ok( array_key_exists( 'time_avg_per_visit', $out ) && null === $out['time_avg_per_visit'], 'time_avg_per_visit null' );
ok( false === ( $out['integrity_violation'] ?? null ), 'integrity_violation false (unknown gated side is NOT a violation)' );
ok( '2026-04-19' === ( $out['exact_metrics_since'] ?? null ), 'exact_metrics_since says WHY the exact fields are null' );
foreach ( $expected_types as $field => $type ) {
	ok( array_key_exists( $field, $out ) && ab_type_ok( $out[ $field ], $type ), "legacy: $field present + conforms to declared type" );
	if ( null === $out[ $field ] ) {
		ok( is_array( $type ) && in_array( 'null', $type, true ), "legacy: $field is null AND its schema union allows null" );
	}
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
