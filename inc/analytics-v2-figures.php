<?php
/**
 * Signal & Noise Tools: what the analytics 2.0 check accepts when the two
 * pageview datasets cannot be equal row for row (owner rule 2026-10-07).
 *
 * Analytics Engine samples each dataset on its own at write time, human
 * visitor-days included, and drops the odd row on either side with no code
 * difference (writeDataPoint is fire-and-forget). Exact equality is then
 * unreachable on a busy day. Two things are accepted instead, and nothing
 * else: a gap of at most two rows on an exactly counted event other than
 * pageviews, and, on a sampled day, the human figures a reader sees (views
 * and visits) agreeing between the datasets within 1% or 2, whichever is
 * larger.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_V2_ROW_ALLOWANCE = 2;    // rows an exact non-pageview event may differ by.
const SN_ANALYTICS_V2_FIGURE_MARGIN = 0.01; // share a human figure may differ by on a sampled day.
const SN_ANALYTICS_V2_FIGURE_FLOOR  = 2;    // and never less than this many.

/**
 * An exactly counted event whose two sides differ by no more than the
 * allowance. PURE. Pageviews never qualify: they are the figure itself.
 *
 * @param string $ev Event type.
 * @param array  $a  {n, exact, v} one side.
 * @param array  $b  {n, exact, v} the other.
 * @return bool
 */
function sn_analytics_v2_allowed( $ev, array $a, array $b ) {
	if ( 'pv' === $ev || empty( $a['exact'] ) || empty( $b['exact'] ) || ( $a['n'] === $b['n'] && $a['v'] === $b['v'] ) ) {
		return false;
	}
	return abs( $a['n'] - $b['n'] ) <= SN_ANALYTICS_V2_ROW_ALLOWANCE && abs( $a['v'] - $b['v'] ) <= SN_ANALYTICS_V2_ROW_ALLOWANCE;
}

/**
 * AE SQL: human views and pageview visits per UTC day in one pageview
 * dataset, under the same rule every human figure uses. PURE given the rule.
 *
 * @param string $dataset sn_pageviews or sn_pageviews_v2.
 * @param int    $days    Days back, today included (1..14).
 * @param array  $hashes  Over-cap visitor-day hashes.
 * @return string
 */
function sn_analytics_v2_figures_sql( $dataset, $days, array $hashes ) {
	$dataset = in_array( $dataset, array( SN_ANALYTICS_DATASET, SN_ANALYTICS_DATASET_PV_V2 ), true ) ? $dataset : SN_ANALYTICS_DATASET;
	$days    = max( 1, min( 14, (int) $days ) );
	$human   = function_exists( 'sn_analytics_counted_condition' ) ? sn_analytics_counted_condition( 'human', $hashes ) : "blob7 != 'bot'";
	return 'SELECT ' . "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, sum(_sample_interval) AS views, count(DISTINCT index1) AS visits"
		. ' FROM ' . $dataset
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '" . ( $days - 1 ) . "' DAY) AND blob1 = 'pv' AND " . $human
		. ' GROUP BY day';
}

/**
 * Whether two readings of one figure agree within the margin. PURE.
 *
 * @param int $a One dataset's figure.
 * @param int $b The other's.
 * @return bool
 */
function sn_analytics_v2_within( $a, $b ) {
	return abs( (int) $a - (int) $b ) <= max( SN_ANALYTICS_V2_FIGURE_FLOOR, (int) ceil( SN_ANALYTICS_V2_FIGURE_MARGIN * max( (int) $a, (int) $b ) ) );
}

/**
 * One day's human figures from both datasets and whether they agree. PURE.
 * Null when either side was not read: an unread figure proves nothing.
 *
 * @param array|null $figures {legacy, pageviews}: sn_analytics_v2_figures_sql() rows; null when not read.
 * @param string     $day     YYYY-MM-DD.
 * @return array{legacy:array,v2:array,agree:bool}|null
 */
function sn_analytics_v2_day_figures( $figures, $day ) {
	if ( ! is_array( $figures ) || ! is_array( $figures['legacy'] ?? null ) || ! is_array( $figures['pageviews'] ?? null ) ) {
		return null;
	}
	$pick = static function ( $rows ) use ( $day ) {
		foreach ( $rows as $r ) {
			if ( (string) ( $r['day'] ?? '' ) === $day ) {
				return array( 'views' => (int) round( (float) ( $r['views'] ?? 0 ) ), 'visits' => (int) round( (float) ( $r['visits'] ?? 0 ) ) );
			}
		}
		return array( 'views' => 0, 'visits' => 0 );
	};
	$l = $pick( $figures['legacy'] );
	$p = $pick( $figures['pageviews'] );
	return array(
		'legacy' => $l,
		'v2'     => $p,
		// A day with no human views on both sides proves nothing either way.
		'agree'  => ( $l['views'] > 0 || $p['views'] > 0 ) && sn_analytics_v2_within( $l['views'], $p['views'] ) && sn_analytics_v2_within( $l['visits'], $p['visits'] ),
	);
}
