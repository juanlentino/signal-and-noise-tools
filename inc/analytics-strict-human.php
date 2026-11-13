<?php
/**
 * Strictly human (owner decision 2026-11-11): a second figure beside the
 * presumed-human one, never in its place. `human` is not changed and nothing
 * is subtracted from it; the strict figure is human less the visitor-days the
 * rule removes, and both are always shown, with each count removed and why.
 * Rule v2 (2026-11-13) removes for two reasons, each its own count: automation
 * signals (below) and no engagement recorded (inc/analytics-strict-no-engagement.php).
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

require_once __DIR__ . '/analytics-strict-no-engagement.php'; // the second removal, no engagement recorded (2026-11-13).
require_once __DIR__ . '/analytics-strict-human-parts.php'; // the one line and the schema.

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
const SNT_STRICT_HUMAN_RULE = 'strict_v2'; // v1 (2026-11-11) was the automation-signal removal alone.

/** The automation-signal reason, in one sentence, for every surface that carries the figure. */
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
 * stored days removed, each reason counted apart. A day counts for a reason
 * only when it carries that reason's reading; null when no day carries the
 * automation reading (not measured, never 0), and removed_no_engagement null
 * when no day carries that one. PURE.
 *
 * @param array  $by_day Stored day map.
 * @param string $from   YYYY-MM-DD.
 * @param string $to     YYYY-MM-DD.
 * @param array  $human  The presumed-human totals the surface shows (unique_visitor_days, views).
 * @return array|null
 */
function sn_bot_signals_strict( array $by_day, $from, $to, array $human ) {
	$sum = static function ( $key ) use ( $by_day, $from, $to ) {
		$removed = array( 'visitor_days' => 0, 'views' => 0 );
		$days    = array();
		foreach ( $by_day as $day => $d ) {
			if ( (string) $day < (string) $from || (string) $day > (string) $to || ! is_array( $d ) || ! array_key_exists( $key, $d ) ) {
				continue;
			}
			$days[]                   = (string) $day;
			$removed['visitor_days'] += (int) $d[ $key ];
			$removed['views']        += (int) ( $d[ $key . '_views' ] ?? 0 );
		}
		sort( $days );
		// partial: the stored days start after the range or end before it.
		return array() === $days ? null : array( $removed, array( 'from' => $days[0], 'through' => end( $days ), 'partial' => $days[0] > (string) $from || end( $days ) < (string) $to ) );
	};
	$auto  = $sum( 'strict_removed' );
	$quiet = $sum( 'no_engagement_removed' );
	if ( null === $auto ) {
		return null;
	}
	$out = static fn( $unit ) => $auto[0][ $unit ] + ( null === $quiet ? 0 : $quiet[0][ $unit ] );
	$vd  = $human['unique_visitor_days'] ?? null; // never visits or pageview_visits: another unit
	$vw  = $human['views'] ?? null;
	return array(
		'visitor_days'                 => null === $vd ? null : max( 0, (int) $vd - $out( 'visitor_days' ) ),
		'views'                        => null === $vw ? null : max( 0, (int) $vw - $out( 'views' ) ),
		'removed'                      => $auto[0], // automation signals
		'removed_no_engagement'        => null === $quiet ? null : $quiet[0],
		'rule'                         => SNT_STRICT_HUMAN_RULE,
		'reason'                       => SNT_STRICT_HUMAN_REASON,
		'reason_no_engagement'         => SNT_STRICT_NO_ENGAGEMENT_REASON,
		'basis'                        => 'inferred',
		'window'                       => $auto[1],
		'window_no_engagement'         => null === $quiet ? null : $quiet[1],
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

