<?php
/**
 * Strictly human, the words: the one line every surface paints and the field's
 * schema. Split from inc/analytics-strict-human.php (house line cap, 2026-11-13).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
	$num   = static fn( $n ) => function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( (int) $n ) : number_format( (int) $n );
	$key   = 'views' === $unit ? 'views' : 'visitor_days';
	$n     = null === $strict[ $key ] ? 'not read' : $num( $strict[ $key ] );
	$quiet = $strict['removed_no_engagement'] ?? null;
	$line  = sprintf(
		'Strictly human: %1$s %2$s (%3$s removed: automation signals; %4$s)',
		$n,
		$word,
		$num( $strict['removed'][ $key ] ),
		is_array( $quiet ) ? $num( $quiet[ $key ] ) . ' removed: no engagement recorded' : 'no engagement recorded: not measured'
	);
	if ( ! empty( $strict['window']['partial'] ) ) {
		$line .= sprintf( '; automation removals counted over UTC days %1$s to %2$s only', $strict['window']['from'], $strict['window']['through'] );
	}
	if ( ! empty( $strict['window_no_engagement']['partial'] ) ) {
		$line .= sprintf( '; no-engagement removals over UTC days %1$s to %2$s only', $strict['window_no_engagement']['from'], $strict['window_no_engagement']['through'] );
	}
	return $line . '.';
}

/**
 * The field's schema on the local summary (never on its remote twin). PURE.
 *
 * @return array
 */
function sn_analytics_strict_human_schema() {
	$count  = array( 'type' => array( 'integer', 'null' ) );
	$int    = array( 'type' => 'integer' );
	$window = array( 'type' => 'object', 'properties' => array( 'from' => array( 'type' => 'string' ), 'through' => array( 'type' => 'string' ), 'partial' => array( 'type' => 'boolean' ) ) );
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Class human only (null otherwise, and null when no UTC day of the range has the strict reading stored). The presumed-human totals less the visitor-days strict rule v2 removes, for two reasons counted apart and never folded together: removed, automation signals (' . SNT_STRICT_HUMAN_REASON . ') and removed_no_engagement, no engagement recorded (' . SNT_STRICT_NO_ENGAGEMENT_REASON . ' Automation wins where both apply, so a visitor-day is removed once). removed_no_engagement is null when no UTC day of the range has that reading (not measured, never 0). Removals are counted over UTC days (window and window_no_engagement: the first and last such day; partial when narrower than the range); human itself is unchanged. Basis inferred.',
		'properties'  => array(
			'visitor_days' => $count,
			'views'        => $count,
			'removed'      => array( 'type' => 'object', 'properties' => array( 'visitor_days' => $int, 'views' => $int ) ),
			'removed_no_engagement' => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'visitor_days' => $int, 'views' => $int ) ),
			'reason_no_engagement'  => array( 'type' => 'string' ),
			'window_no_engagement'  => array( 'type' => array( 'object', 'null' ), 'properties' => $window['properties'] ),
			'rule'         => array( 'type' => 'string', 'enum' => array( SNT_STRICT_HUMAN_RULE ) ),
			'reason'       => array( 'type' => 'string' ),
			'basis'        => array( 'type' => 'string', 'enum' => array( 'inferred' ) ),
			'window'       => $window,
		),
	);
}
