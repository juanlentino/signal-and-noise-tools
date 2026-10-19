<?php
/**
 * Signal & Noise Tools: alerts, how much of a spiking path looks automated.
 *
 * 2026-10-18: a spike on a 404 the owner's own sweep had walked. The page test
 * now keeps 404s out; this says, for a real page that spikes, how many of its
 * presumed-human visitor-days the beacon bot-signal score marks likely
 * automated (inc/analytics-bot-signals.php). OBSERVE-ONLY like that score:
 * shown beside the views in the mail, never subtracted from them, basis
 * inferred.
 *
 * The one Analytics Engine read the alerts make, asked only for a path that
 * fired a spike, so a quiet hour reads nothing. A failed read is null and the
 * mail says it could not be read, never 0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One UTC day's signal-bearing rows for one path: the shape
 * sn_bot_signals_by_day() scores, filtered to the path in both spellings.
 * PURE given the dataset helpers.
 *
 * ponytail: the page-view cap and the bits are read off this path's rows only,
 * not the visitor's whole day; read the visitor's day when a spike needs it.
 *
 * @param string $path   Request path (untrusted: escaped as a string literal).
 * @param string $day    UTC day, YYYY-MM-DD.
 * @param string $source One half of a stitched read; '' picks the dataset.
 * @param string $range  That half's side of the split.
 * @return string
 */
function snt_alerts_automated_sql( $path, $day, $source = '', $range = '' ) {
	$day    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ? (string) $day : '1970-01-01';
	$source = '' !== (string) $source ? (string) $source : sn_analytics_source( $day );
	$range  = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	$bare   = rtrim( (string) $path, '/' );
	$lit    = static fn( $v ) => "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $v ) . "'";
	$paths  = '' === $bare ? $lit( '/' ) : $lit( $bare ) . ', ' . $lit( $bare . '/' );
	$cols   = array( "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day", 'index1 AS vid' );
	foreach ( SNT_BOT_SIGNAL_BITS as $bit => $name ) {
		$cols[] = "max(bitAnd(toUInt32(double8), {$bit})) AS {$name}";
	}
	$cols[] = "sum(if(blob1 = 'pv', _sample_interval, 0)) AS views";
	$cols[] = "max(if(blob7 != 'bot' AND (" . sn_analytics_network_human_sql() . '), 1, 0)) AS human';
	return 'SELECT ' . implode( ', ', $cols ) . ' FROM ' . $source
		. " WHERE timestamp >= toDateTime('{$day} 00:00:00') AND timestamp <= toDateTime('{$day} 23:59:59'){$range}"
		. " AND blob2 IN ({$paths}) AND bitAnd(toUInt32(double8), " . SNT_BOT_SIGNAL_PRESENT . ') > 0'
		. ' GROUP BY day, vid LIMIT ' . SNT_BOT_SIGNAL_LIMIT;
}

/**
 * Each path spike gains `automated`: {day, visitor_days, likely} for the UTC
 * day of $now, or null when the read failed. The site-wide spike and every
 * other alert are left as they are.
 *
 * @param array<int,array<string,mixed>> $alerts From snt_alerts_evaluate().
 * @param int                            $now    Unix time.
 * @return array<int,array<string,mixed>>
 */
function snt_alerts_automated_attach( array $alerts, $now ) {
	$day = gmdate( 'Y-m-d', (int) $now );
	foreach ( $alerts as $i => $a ) {
		if ( 'spike' !== ( $a['kind'] ?? '' ) ) {
			continue;
		}
		$sets = function_exists( 'sn_analytics_stitched_rows' ) && function_exists( 'sn_bot_signals_by_day' )
			? sn_analytics_stitched_rows( static fn( $s, $r ) => snt_alerts_automated_sql( (string) $a['path'], $day, $s, $r ), $day, gmdate( 'Y-m-d H:i:s', (int) $now ) )
			: null;
		$scored = null === $sets ? null : ( sn_bot_signals_by_day( array_merge( ...$sets ) )[ $day ] ?? array( 'visitor_days' => 0, 'likely_automated' => 0 ) );
		$alerts[ $i ]['automated'] = null === $scored ? null : array( 'day' => $day, 'visitor_days' => (int) $scored['visitor_days'], 'likely' => (int) $scored['likely_automated'] );
	}
	return $alerts;
}

/**
 * The mail's line for one spike, '' when the spike carries no reading. PURE.
 *
 * @param array<string,mixed> $a One alert.
 * @return string
 */
function snt_alerts_automated_line( array $a ) {
	if ( ! array_key_exists( 'automated', $a ) ) {
		return '';
	}
	$r = $a['automated'];
	if ( ! is_array( $r ) ) {
		return 'Likely automated: could not be read just now, which is not the same as none.';
	}
	if ( (int) $r['visitor_days'] < 1 ) {
		return sprintf( 'Likely automated: not measured; no presumed-human visitor-day on this path carried beacon signals (UTC day %s).', $r['day'] );
	}
	return sprintf( 'Likely automated (inferred from beacon signals, shown only, never subtracted): %d of %d presumed-human visitor-days on this path (%d%%), UTC day %s.', $r['likely'], $r['visitor_days'], (int) round( 100 * $r['likely'] / $r['visitor_days'] ), $r['day'] );
}
