<?php
/**
 * Signal & Noise Tools: Analytics Sessions, the funnel and goal reports and the Analytics Engine fetch (the step match, the funnel report, goal attribution, the session SQL and fetch).
 *
 * Split from inc/analytics-sessions.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does one compact event (from a summary 'events' list) satisfy a funnel step?
 *
 * @param array $step  array{match:string,value:string,prefix?:bool}.
 * @param array $event array{ev:string,path:string,ce:string}.
 * @return bool
 */
function sn_funnel_step_matches( array $step, array $event ) {
	$match  = (string) ( $step['match'] ?? '' );
	$value  = (string) ( $step['value'] ?? '' );
	$prefix = ! empty( $step['prefix'] );

	if ( 'path' === $match ) {
		if ( 'pv' !== (string) ( $event['ev'] ?? '' ) ) {
			return false;
		}
		$path = (string) ( $event['path'] ?? '' );
		return $prefix ? ( 0 === strncmp( $path, $value, strlen( $value ) ) ) : ( $path === $value );
	}
	if ( 'ce' === $match ) {
		if ( 'ce' !== (string) ( $event['ev'] ?? '' ) ) {
			return false;
		}
		$ce = (string) ( $event['ce'] ?? '' );
		// Honor the documented prefix flag, mirroring the 'path' branch — so a
		// single step (e.g. value 'contact-') captures a whole goal family
		// (contact-research, contact-press, …) the theme emits per alias.
		return $prefix ? ( 0 === strncmp( $ce, $value, strlen( $value ) ) ) : ( $ce === $value );
	}
	return false;
}

/**
 * Aggregate ordered-step completion across visits.
 *
 * @param array $summaries Visit summaries (each with an ordered 'events' list).
 * @param array $funnel    Ordered steps.
 * @return array List of array{label:string,reached:int,rate:float,drop:int}.
 */
function sn_funnel_report( array $summaries, array $funnel ) {
	$steps = array_values( $funnel );
	$n     = count( $steps );
	if ( 0 === $n ) {
		return array();
	}
	$reached = array_fill( 0, $n, 0 );

	foreach ( $summaries as $s ) {
		$events = isset( $s['events'] ) ? (array) $s['events'] : array();
		$idx    = 0;
		foreach ( $events as $e ) {
			if ( $idx >= $n ) {
				break;
			}
			if ( sn_funnel_step_matches( $steps[ $idx ], (array) $e ) ) {
				$reached[ $idx ]++;
				$idx++;
			}
		}
	}

	$first = $reached[0] > 0 ? $reached[0] : 0;
	$out   = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$label = (string) ( $steps[ $i ]['value'] ?? ( 'step ' . ( $i + 1 ) ) );
		$prev  = $i > 0 ? $reached[ $i - 1 ] : $reached[ $i ];
		$out[] = array(
			'label'   => $label,
			'reached' => $reached[ $i ],
			'rate'    => ( $first > 0 ) ? ( $reached[ $i ] / $first ) : 0.0,
			'drop'    => ( $i > 0 ) ? max( 0, $prev - $reached[ $i ] ) : 0,
		);
	}
	return $out;
}

/**
 * Attribute converting visits to their entry page.
 *
 * For each visit whose goals include one matching $goal_value (prefix or exact),
 * credit its entry page once — so the result answers "where did the visitors who
 * actually converted first land?" (e.g. did /services feed the contact form, or
 * did people reach /contact directly?). A visit is counted once even if it fired
 * two matching goals: this counts converting VISITS, mirroring sn_funnel_report's
 * visit semantics, not raw conversion events. Runs over the same within-day
 * summaries the Visits view already holds — no extra query. Cookieless and
 * aggregate: only the entry PATH is retained, never an identity.
 *
 * @param array  $summaries  Visit summaries (each with 'entry' + 'goals').
 * @param string $goal_value Goal name, or a family prefix (e.g. 'contact-').
 * @param bool   $prefix     Match $goal_value as a prefix. Default true.
 * @param int    $limit      Max rows returned (<= 0 = no cap). Default 10.
 * @return array List of array{entry:string,conversions:int}, desc by conversions.
 */
function sn_goal_attribution( array $summaries, $goal_value, $prefix = true, $limit = 10 ) {
	$value = (string) $goal_value;
	$vlen  = strlen( $value );
	$tally = array();
	foreach ( $summaries as $s ) {
		$goals = isset( $s['goals'] ) ? (array) $s['goals'] : array();
		$hit   = false;
		foreach ( $goals as $g ) {
			$g = (string) $g;
			if ( $prefix ? ( 0 === strncmp( $g, $value, $vlen ) ) : ( $g === $value ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$entry           = (string) ( $s['entry'] ?? '' );
		$entry           = ( '' !== $entry ) ? $entry : '(unknown)';
		$tally[ $entry ] = ( $tally[ $entry ] ?? 0 ) + 1;
	}
	$rows = array();
	foreach ( $tally as $entry => $count ) {
		$rows[] = array( 'entry' => $entry, 'conversions' => $count );
	}
	// Desc by conversions; entry name asc as a stable tiebreak (deterministic output).
	usort(
		$rows,
		function ( $a, $b ) {
			return ( $a['conversions'] === $b['conversions'] )
				? strcmp( $a['entry'], $b['entry'] )
				: ( $b['conversions'] - $a['conversions'] );
		}
	);
	if ( $limit > 0 && count( $rows ) > $limit ) {
		$rows = array_slice( $rows, 0, $limit );
	}
	return $rows;
}

/**
 * Build the AE SQL that pulls raw human events for sessionization. Returns ''
 * (so the caller no-ops) if the window or class is invalid. class is strictly
 * whitelisted and dates are format-validated — the only interpolated values —
 * so the string is injection-safe against the AE SQL API.
 *
 * @param string $from  Window start, 'Y-m-d'.
 * @param string $to    Window end, 'Y-m-d'.
 * @param string $class Traffic class (human/suspect/bot).
 * @param int    $cap   Row cap (LIMIT).
 * @param string $source One half of a stitched read; '' picks the dataset.
 * @param string $range  That half's side of the split (sn_analytics_split_range_ok()).
 * @return string SQL, or '' when inputs are invalid.
 */
function sn_analytics_session_sql( $from, $to, $class, $cap, $source = '', $range = '' ) {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from )
		|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return '';
	}
	$allowed = defined( 'SN_ANALYTICS_CLASSES' ) ? SN_ANALYTICS_CLASSES : array( 'human', 'suspect', 'bot' );
	if ( ! in_array( (string) $class, $allowed, true ) ) {
		return '';
	}
	$cap     = max( 1, (int) $cap );
	$dataset = '' !== (string) $source ? (string) $source : sn_analytics_source( (string) $from );
	$range   = sn_analytics_split_range_ok( $range ) ? (string) $range : '';

	return implode(
		' ',
		array(
			'SELECT index1 AS vid, toUnixTimestamp(timestamp) AS ts,',
			// The custom event's name: blob16 in the legacy row, blob17 on a `ce` row of the second generation.
			'blob1 AS ev, blob2 AS path, blob3 AS ref, ' . sn_analytics_col( 'blob16', $dataset ) . ' AS ce,',
			'double1 AS scroll, double2 AS dwell, blob5 AS device',
			'FROM ' . $dataset,
			// AE's SQL types are strict: the DateTime `timestamp` column cannot be
			// compared to a String literal (>= 422s), so wrap the validated bounds
			// in toDateTime(). $from/$to are regex-checked Y-m-d above.
			"WHERE timestamp >= toDateTime('{$from} 00:00:00') AND timestamp <= toDateTime('{$to} 23:59:59'){$range}",
			'AND ' . sn_analytics_class_where( $class ),
			"AND blob1 IN ('pv','sc','tm','ce')",
			// No ORDER BY: AE resolves ORDER BY against SELECT aliases (not raw
			// columns), so `index1`/`timestamp` both 422. sn_sessionize sorts each
			// visitor's events by ts in PHP anyway; the row cap only binds far above
			// this site's volume, where the `capped` flag already warns.
			"LIMIT {$cap}",
		)
	);
}

/**
 * Fetch + sessionize + summarize a window into visit summaries. Returns an
 * array with the summaries plus a 'capped' flag (true when the row cap was hit,
 * so the view can warn instead of silently truncating).
 *
 * @param string $from  Window start, 'Y-m-d'.
 * @param string $to    Window end, 'Y-m-d'.
 * @param string $class Traffic class.
 * @return array{summaries:array,visits:array,capped:bool,configured:bool}
 */
function sn_analytics_fetch_session_events( $from, $to, $class ) {
	$cfg = sn_analytics_session_config();
	$sql = sn_analytics_session_sql( $from, $to, $class, $cfg['row_cap'] );
	if ( '' === $sql || ! function_exists( 'sn_analytics_query' ) ) {
		return array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => false );
	}
	// Analytics 2.0: across the clean day each generation is read for its side;
	// a visitor-day (and so a session) lives on one side, so the rows simply
	// join. Either half reaching the cap is a capped read.
	$sets = sn_analytics_stitched_rows(
		static fn( $source, $range ) => sn_analytics_session_sql( $from, $to, $class, $cfg['row_cap'], $source, $range ),
		(string) $from,
		$to . ' 23:59:59'
	);
	if ( null === $sets ) {
		return array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => false );
	}
	$rows   = array_merge( ...$sets );
	$capped = array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= $cfg['row_cap'] );
	$visits = sn_sessionize( $rows, $cfg['gap_sec'] );
	$summaries = array();
	foreach ( $visits as $v ) {
		$summaries[] = sn_visit_summary( $v, $cfg['engaged_scroll'], $cfg['engaged_ms'] );
	}
	return array( 'summaries' => $summaries, 'visits' => $visits, 'capped' => $capped, 'configured' => true );
}
