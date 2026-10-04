<?php
/**
 * Beacon bot signals, OBSERVE-ONLY (owner plan 2026-09-27): the theme beacon
 * sends five booleans, the analytics worker (1.22.0) packs them into double8
 * with a presence bit (32, because AE reads an absent double as 0) and stores
 * the raw browser UTC offset in double9. This reads them per visitor-day and
 * asks one question per signal: does it fire on readers known to be people
 * (relay Safari, intent visitors) and on readers known not to be (stored bots
 * that ran JS, over-cap visitor-days, hosting suspects)?
 *
 * NOTHING HERE SUBTRACTS. The "likely automated" count sits beside human; the
 * one counted-human rule (inc/analytics-human-rule.php) is untouched, and the
 * reader only borrows its seams (network SQL, the page-view cap).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

require_once __DIR__ . '/analytics-network-terms.php';
require_once __DIR__ . '/analytics-human-rule.php';

// Bit => name; weight per bit for the score. Weights are a first guess to be
// replaced by the two-week validation, not a finding.
const SNT_BOT_SIGNAL_BITS      = array( 1 => 'webdriver', 2 => 'no_input', 4 => 'ua_mismatch', 8 => 'headless', 16 => 'tz_mismatch' );
const SNT_BOT_SIGNAL_WEIGHTS   = array( 'webdriver' => 3, 'no_input' => 2, 'ua_mismatch' => 2, 'headless' => 1, 'tz_mismatch' => 1 );
const SNT_BOT_SIGNAL_THRESHOLD = 3;
const SNT_BOT_SIGNAL_PRESENT   = 32;
const SNT_BOT_SIGNAL_DAYS      = 14;
const SNT_BOT_SIGNAL_LIMIT     = 5000; // well under the AE 10,000-row cap that trips strict mode
const SNT_BOT_SIGNAL_COHORTS   = array( 'relay', 'intent', 'stored_bot', 'over_cap', 'hosting' );
const SNT_BOT_SIGNAL_MIN_N     = 20;
const SNT_BOT_SIGNAL_OPTION    = 'sn_bot_signals_last';

/**
 * One row per visitor-day that carried signals: each bit's max, pv views, and
 * the cohort flags. Absent-signal rows are filtered out by the presence bit. PURE.
 *
 * @param int $days Trailing window.
 * @return string
 */
function sn_bot_signals_sql( $days = SNT_BOT_SIGNAL_DAYS ) {
	$days = max( 1, min( 92, (int) $days ) );
	$source = sn_analytics_source( sn_analytics_trailing_from( $days ) );
	$t    = sn_analytics_network_terms();
	$net  = sn_analytics_network_human_sql();
	$cols = array( 'index1 AS vid' );
	foreach ( SNT_BOT_SIGNAL_BITS as $bit => $name ) {
		$cols[] = "max(bitAnd(toUInt32(double8), {$bit})) AS {$name}";
	}
	$cols[] = "sum(if(blob1 = 'pv', _sample_interval, 0)) AS views";
	$ce     = sn_analytics_col( 'blob16', $source ); // the custom event's name.
	$cols[] = "max(if(blob1 = 'ce' AND ({$ce} = 'download' OR {$ce} = 'verify' OR {$ce} LIKE 'contact%'), 1, 0)) AS intent";
	$cols[] = "max(if(blob7 = 'bot', 1, 0)) AS stored_bot";
	$cols[] = "max(if(blob7 != 'bot' AND blob8 = 'Safari' AND (blob9 = 'iOS' OR blob9 = 'macOS') AND " . sn_analytics_org_ilike_any( $t['relay'] ) . ', 1, 0)) AS relay';
	$cols[] = "max(if(blob7 != 'bot' AND NOT ({$net}), 1, 0)) AS hosting";
	$cols[] = "max(if(blob7 != 'bot' AND ({$net}), 1, 0)) AS human";
	return 'SELECT ' . implode( ', ', $cols )
		. ' FROM ' . $source
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY) AND bitAnd(toUInt32(double8), " . SNT_BOT_SIGNAL_PRESENT . ') > 0'
		. ' GROUP BY vid LIMIT ' . SNT_BOT_SIGNAL_LIMIT;
}

/**
 * UTC days in the window that carried any signal-bearing row. PURE.
 *
 * @param int $days Trailing window.
 * @return string
 */
function sn_bot_signals_days_sql( $days = SNT_BOT_SIGNAL_DAYS ) {
	$days = max( 1, min( 92, (int) $days ) );
	$source = sn_analytics_source( sn_analytics_trailing_from( $days ) );
	return 'SELECT toStartOfDay(timestamp) AS day, count() AS n FROM ' . $source
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY) AND bitAnd(toUInt32(double8), " . SNT_BOT_SIGNAL_PRESENT . ') > 0 GROUP BY day';
}

/**
 * A visitor-day's score: the weights of the bits it fired. PURE.
 *
 * @param array $row AE row.
 * @return int
 */
function sn_bot_signals_score( array $row ) {
	$s = 0;
	foreach ( SNT_BOT_SIGNAL_WEIGHTS as $name => $w ) {
		$s += (int) ( (float) ( $row[ $name ] ?? 0 ) > 0 ) * $w;
	}
	return $s;
}

/**
 * The readout from AE rows (numerics may arrive as strings). PURE.
 *
 * @param array $rows     sn_bot_signals_sql() rows.
 * @param array $day_rows sn_bot_signals_days_sql() rows.
 * @return array
 */
function sn_bot_signals_readout( array $rows, array $day_rows ) {
	$zero    = array_fill_keys( SNT_BOT_SIGNAL_BITS, 0 );
	$cohorts = array();
	foreach ( SNT_BOT_SIGNAL_COHORTS as $c ) {
		$cohorts[ $c ] = array( 'n' => 0, 'fired' => $zero, 'likely_automated' => 0 );
	}
	$human = array( 'visitor_days' => 0, 'likely_automated' => 0 );
	foreach ( $rows as $r ) {
		$r      = (array) $r;
		$likely = sn_bot_signals_score( $r ) >= SNT_BOT_SIGNAL_THRESHOLD;
		$in     = array(
			'relay'      => (int) ( $r['relay'] ?? 0 ) > 0,
			'intent'     => (int) ( $r['intent'] ?? 0 ) > 0,
			'stored_bot' => (int) ( $r['stored_bot'] ?? 0 ) > 0,
			'over_cap'   => (float) ( $r['views'] ?? 0 ) > SNT_ANALYTICS_VDAY_PV_CAP,
			'hosting'    => (int) ( $r['hosting'] ?? 0 ) > 0,
		);
		foreach ( array_keys( array_filter( $in ) ) as $c ) {
			++$cohorts[ $c ]['n'];
			$cohorts[ $c ]['likely_automated'] += (int) $likely;
			foreach ( SNT_BOT_SIGNAL_BITS as $name ) {
				$cohorts[ $c ]['fired'][ $name ] += (int) ( (float) ( $r[ $name ] ?? 0 ) > 0 );
			}
		}
		if ( (int) ( $r['human'] ?? 0 ) > 0 && ! $in['over_cap'] ) {
			++$human['visitor_days'];
			$human['likely_automated'] += (int) $likely;
		}
	}
	foreach ( $cohorts as $c => $v ) {
		$cohorts[ $c ]['rate'] = array();
		foreach ( $v['fired'] as $name => $f ) {
			$cohorts[ $c ]['rate'][ $name ] = $v['n'] > 0 ? round( 100 * $f / $v['n'], 1 ) : null;
		}
	}
	return array(
		'window_days'  => SNT_BOT_SIGNAL_DAYS,
		'visitor_days' => count( $rows ),
		'truncated'    => count( $rows ) >= SNT_BOT_SIGNAL_LIMIT,
		'days_present' => count( array_filter( $day_rows, static fn( $d ) => (int) ( ( (array) $d )['n'] ?? 0 ) > 0 ) ),
		'human'        => $human,
		'cohorts'      => $cohorts,
		'weights'      => SNT_BOT_SIGNAL_WEIGHTS,
		'threshold'    => SNT_BOT_SIGNAL_THRESHOLD,
		'subtracted'   => false,
	);
}
