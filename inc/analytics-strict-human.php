<?php
/**
 * Strictly human (owner decision 2026-11-11): a second figure beside the
 * presumed-human one, never in its place. `human` is not changed and nothing
 * is subtracted from it; the strict figure is human less the visitor-days the
 * rule below removes, and both are always shown, with the count removed.
 *
 * The removals come from the nightly per-day bot-signal store
 * (inc/analytics-bot-signals-by-day.php), so they are counted over UTC days
 * and over the visitor-days that carried the beacon's signals, and no render
 * queries Analytics Engine. A UTC day stored before the rule existed has no
 * strict reading and counts as unmeasured, never as 0 removed.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict rule v1. A presumed-human visitor-day (the readout's human flag, not
 * over the page-view cap) leaves the strict figure only when its score reaches
 * SNT_BOT_SIGNAL_THRESHOLD AND it fired webdriver, a user-agent mismatch, or
 * headless together with a time-zone mismatch. The score alone is not enough
 * (78 flagged by score over the last 30 days, 61 removed by this rule):
 * - tz_mismatch fires for readers on a VPN or traveling;
 * - no_input fires for a phone reader who scrolls without touching;
 * - an intent reader (a download, a verify, a contact) fired no_input with
 *   headless, so that pair never removes a visitor-day either.
 */
const SNT_STRICT_HUMAN_RULE = 'strict_v1';

/** The reason, in one sentence, for every surface that carries the figure. */
const SNT_STRICT_HUMAN_REASON = 'Presumed-human visitor-days removed when the beacon\'s bot-signal score reaches 3 and they fired webdriver, a user-agent mismatch, or headless with a time-zone mismatch; no_input and tz_mismatch never remove one alone or together.';

/**
 * Whether strict rule v1 removes this visitor-day. PURE.
 *
 * @param array $row A bot-signal row (the bits, views, human).
 * @return bool
 */
function sn_bot_signals_strict_removes( array $row ) {
	if ( ! sn_bot_signals_counted_human( $row ) || sn_bot_signals_score( $row ) < SNT_BOT_SIGNAL_THRESHOLD ) {
		return false;
	}
	$fired = static fn( $name ) => (float) ( $row[ $name ] ?? 0 ) > 0;
	return $fired( 'webdriver' ) || $fired( 'ua_mismatch' ) || ( $fired( 'headless' ) && $fired( 'tz_mismatch' ) );
}

/**
 * The strict figure over [$from, $to]: the presumed-human totals less what the
 * stored days removed. A day counts only when it carries the strict reading;
 * null when none does (not measured, never 0). PURE.
 *
 * @param array  $by_day Stored day map.
 * @param string $from   YYYY-MM-DD.
 * @param string $to     YYYY-MM-DD.
 * @param array  $human  The presumed-human totals the surface shows (unique_visitor_days, views).
 * @return array|null
 */
function sn_bot_signals_strict( array $by_day, $from, $to, array $human ) {
	$removed = array( 'visitor_days' => 0, 'views' => 0 );
	$days    = array();
	foreach ( $by_day as $day => $d ) {
		if ( (string) $day < (string) $from || (string) $day > (string) $to || ! is_array( $d ) || ! array_key_exists( 'strict_removed', $d ) ) {
			continue;
		}
		$days[]                   = (string) $day;
		$removed['visitor_days'] += (int) $d['strict_removed'];
		$removed['views']        += (int) ( $d['strict_removed_views'] ?? 0 );
	}
	if ( array() === $days ) {
		return null;
	}
	sort( $days );
	$vd = $human['unique_visitor_days'] ?? null; // never visits or pageview_visits: another unit
	$vw = $human['views'] ?? null;
	return array(
		'visitor_days' => null === $vd ? null : max( 0, (int) $vd - $removed['visitor_days'] ),
		'views'        => null === $vw ? null : max( 0, (int) $vw - $removed['views'] ),
		'removed'      => $removed,
		'rule'         => SNT_STRICT_HUMAN_RULE,
		'reason'       => SNT_STRICT_HUMAN_REASON,
		'basis'        => 'inferred',
		// partial: the stored days start after the range or end before it.
		'window'       => array( 'from' => $days[0], 'through' => end( $days ), 'partial' => $days[0] > (string) $from || end( $days ) < (string) $to ),
	);
}

/**
 * The strict figure for a range, from the stored days. Never queries AE.
 *
 * @param string $from  YYYY-MM-DD.
 * @param string $to    YYYY-MM-DD.
 * @param array  $human The presumed-human totals the surface shows.
 * @return array|null
 */
function sn_analytics_strict_human( $from, $to, array $human ) {
	$by_day = function_exists( 'get_option' ) ? get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION, array() ) : array();
	return sn_bot_signals_strict( is_array( $by_day ) ? $by_day : array(), (string) $from, (string) $to, $human );
}

/**
 * The one sentence every surface paints beside the presumed-human number. PURE.
 *
 * @param array|null $strict sn_bot_signals_strict().
 * @param string     $unit   visitor_days|views.
 * @return string
 */
function sn_analytics_strict_human_line( $strict, $unit = 'visitor_days' ) {
	$word = 'views' === $unit ? 'views' : 'visitor-days';
	if ( ! is_array( $strict ) ) {
		return 'Strictly human: not measured in this range (no UTC day of it has the strict reading stored).';
	}
	$num  = static fn( $n ) => function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( (int) $n ) : number_format( (int) $n );
	$key  = 'views' === $unit ? 'views' : 'visitor_days';
	$n    = null === $strict[ $key ] ? 'not read' : $num( $strict[ $key ] );
	$line = sprintf( 'Strictly human: %1$s %2$s (%3$s removed: automation signals)', $n, $word, $num( $strict['removed'][ $key ] ) );
	if ( ! empty( $strict['window']['partial'] ) ) {
		$line .= sprintf( '; removals counted over UTC days %1$s to %2$s only', $strict['window']['from'], $strict['window']['through'] );
	}
	return $line . '.';
}

/**
 * The field's schema on the local summary (never on its remote twin). PURE.
 *
 * @return array
 */
function sn_analytics_strict_human_schema() {
	$count = array( 'type' => array( 'integer', 'null' ) );
	$int   = array( 'type' => 'integer' );
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Class human only (null otherwise, and null when no UTC day of the range has the strict reading stored). The presumed-human totals less the visitor-days strict rule v1 removes: ' . SNT_STRICT_HUMAN_REASON . ' Removals are counted over UTC days and over the visitor-days that carried the beacon\'s signals (window: the first and last such day; partial when that is narrower than the range); human itself is unchanged. Basis inferred.',
		'properties'  => array(
			'visitor_days' => $count,
			'views'        => $count,
			'removed'      => array( 'type' => 'object', 'properties' => array( 'visitor_days' => $int, 'views' => $int ) ),
			'rule'         => array( 'type' => 'string', 'enum' => array( SNT_STRICT_HUMAN_RULE ) ),
			'reason'       => array( 'type' => 'string' ),
			'basis'        => array( 'type' => 'string', 'enum' => array( 'inferred' ) ),
			'window'       => array( 'type' => 'object', 'properties' => array( 'from' => array( 'type' => 'string' ), 'through' => array( 'type' => 'string' ), 'partial' => array( 'type' => 'boolean' ) ) ),
		),
	);
}
