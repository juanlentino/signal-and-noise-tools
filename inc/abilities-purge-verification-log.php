<?php
/**
 * Signal & Noise Tools — Abilities API: the purge-verification trail, in rows.
 *
 * The rows are ALREADY RENDERED — inc/cloudflare-purge.php draws them under
 * "Post-purge probes" in the Cloudflare admin tab. What did not exist was a
 * MACHINE reader. The two glance surfaces (the Classic Admin ops box and the
 * OpenStation cache widget) carry only snt_cf_freshness_summary()'s five
 * aggregate numbers, and those are the numbers an agent gets asked about.
 *
 * So on 2026-09-02, asked why the stale count was climbing, the honest answer
 * was "I can see the summary and not the rows, send me a screenshot" — while a
 * human had them on screen the whole time. This closes that, and only that: it
 * adds no surface a person did not already have.
 *
 * (Stated precisely because the first draft of this file claimed nothing could
 * read the rows at all. That came from grepping for readers of the SUMMARY
 * function, which by construction cannot find a reader that goes to the option
 * directly — a scoped search inventing a gap.)
 *
 * It was diagnosed exactly that way on 2026-09-02, and the guess was wrong in a
 * way the summary could not correct: `total` is capped at
 * SN_CF_PROBE_LOG_CAP, so a full buffer always reads 20 and looks like a
 * lifetime count. Read as cumulative it says "this can only ever go up". Read
 * correctly — a ROLLING WINDOW of the last 20 probes — a rising stale count
 * means fresh rows are being evicted by stale ones and the RATE is climbing.
 * Opposite conclusions from the same five numbers. Hence rows, and hence an
 * explicit `window` block.
 *
 * READ-ONLY. Returns the recorded trail; probes nothing, purges nothing.
 * Verdicts are produced only by the scheduled probe after a post save.
 *
 * @package SignalNoiseTools
 * @since 13.86.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/purge-verification-log', array(
		'label'               => 'Purge Verification Log',
		'description'         => 'Returns the per-row trail of edge-freshness probes as DATA — the same rows the Cloudflare admin tab renders for a human under \'Post-purge probes\', which no machine reader could reach. After each post save the plugin waits SN_CF_PROBE_DELAY seconds, fetches the post URL a reader would get and the same URL cache-busted, and compares the normalized <main> region. Call this when the cache widget shows a rising stale count, when asked whether the edge is serving old renders, or before concluding a purge failed. READ THE WINDOW BEFORE THE COUNTS: the log is a rolling buffer capped at 20 entries, so `counts.total` pins at 20 once full and is NOT a lifetime figure — it is the size of the recent window. A rising `counts.stale` against that fixed denominator therefore means the recent failure RATE is rising, not that a lifetime tally is accumulating; reading it the other way inverts the conclusion. `rows` are newest-first, each carrying `time_iso`, `url`, `result` (fresh|stale), `escalated` (whether a zone purge was dispatched) and `algo` (which detector version produced it). Compare `time_iso` against deploy times before blaming the edge: every deploy rewrites site-wide HTML, so a probe whose window straddles a deploy reports stale CORRECTLY and transiently. `counts` are computed over current-detector rows only (algo >= SN_CF_PROBE_ALGO); rows from a retired detector are returned but excluded from the counts, and `counts_excluded_rows` says how many. A null `state` of `never_probed` is NOT a clean edge — it means no verdict has been recorded, which is a gap in evidence rather than a pass. There is no recheck loop: each probe records one verdict and escalates at most once, so a `stale` row describes an instant in the past, never an ongoing condition.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_purge_verification_log',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'                => array(
					'type'        => 'string',
					'enum'        => array( 'never_probed', 'recorded' ),
					'description' => 'never_probed means no verdict exists. That is an absence of evidence, never a clean bill of health.',
				),
				'cap'                  => array(
					'type'        => 'integer',
					'description' => 'Maximum rows retained. counts.total can never exceed this, which is why total is a window size and not a lifetime count.',
				),
				'window'               => array(
					'type'        => 'object',
					'description' => 'oldest/newest ISO timestamps and span_hours across the retained rows — the period counts actually describe.',
				),
				'counts'               => array(
					'type'        => 'object',
					'description' => 'total, fresh, stale, escalated and stale_pct over CURRENT-detector rows only.',
				),
				'counts_excluded_rows' => array(
					'type'        => 'integer',
					'description' => 'Rows present but excluded from counts because a retired detector produced them.',
				),
				'rows'                 => array(
					'type'        => 'array',
					'description' => 'Newest-first probe outcomes: time, time_iso, post_id, url, result, escalated, algo.',
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				// readonly: a pure option read. It deliberately does NOT probe —
				// an ability that measured the edge would write new verdicts into
				// the log it exists to report, and every read would change the
				// thing being read.
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/purge-verification-log.
 *
 * @param array|null $input Unused; the ability takes no arguments.
 * @return array|WP_Error
 */
function snt_ability_purge_verification_log( $input ) {
	unset( $input );

	if ( ! defined( 'SN_CF_PROBE_LOG_OPT' ) || ! defined( 'SN_CF_PROBE_ALGO' ) ) {
		return new WP_Error(
			'snt_purge_verify_unavailable',
			'Purge verification module not loaded.',
			array( 'status' => 500 )
		);
	}

	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	$cap = defined( 'SN_CF_PROBE_LOG_CAP' ) ? (int) SN_CF_PROBE_LOG_CAP : 20;

	if ( ! is_array( $log ) || empty( $log ) ) {
		// Deliberately the same discipline as snt_cf_freshness_summary(): an
		// empty log is "nothing measured", and reporting it as a clean edge is
		// the green-readout-over-a-stale-page failure this module exists to
		// catch (2026-08-15).
		return array(
			'state'                => 'never_probed',
			'cap'                  => $cap,
			'window'               => array( 'oldest' => null, 'newest' => null, 'span_hours' => null ),
			'counts'               => array( 'total' => 0, 'fresh' => 0, 'stale' => 0, 'escalated' => 0, 'stale_pct' => null ),
			'counts_excluded_rows' => 0,
			'rows'                 => array(),
		);
	}

	$rows     = array();
	$times    = array();
	$excluded = 0;
	$counts   = array( 'total' => 0, 'fresh' => 0, 'stale' => 0, 'escalated' => 0 );

	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$algo   = (int) ( $entry['algo'] ?? 1 );
		$time   = (int) ( $entry['time'] ?? 0 );
		$result = (string) ( $entry['result'] ?? '' );

		$rows[] = array(
			'time'      => $time,
			// ISO alongside the epoch on purpose: correlating these against a
			// deploy time is the whole point, and that is not a thing anyone
			// should be doing by eye against a unix timestamp.
			'time_iso'  => $time > 0 ? gmdate( 'c', $time ) : null,
			'post_id'   => (int) ( $entry['post_id'] ?? 0 ),
			'url'       => (string) ( $entry['url'] ?? '' ),
			'result'    => in_array( $result, array( 'fresh', 'stale' ), true ) ? $result : 'unknown',
			'escalated' => ! empty( $entry['escalated'] ),
			'algo'      => $algo,
		);

		if ( $time > 0 ) {
			$times[] = $time;
		}

		// Counts follow the summary's rule exactly, so the two surfaces cannot
		// disagree: a retired detector's readings belong in neither the
		// numerator nor the denominator.
		if ( $algo < SN_CF_PROBE_ALGO ) {
			++$excluded;
			continue;
		}
		++$counts['total'];
		if ( 'stale' === $result ) {
			++$counts['stale'];
			if ( ! empty( $entry['escalated'] ) ) {
				++$counts['escalated'];
			}
		} elseif ( 'fresh' === $result ) {
			++$counts['fresh'];
		}
	}

	// Percentage, not a bare count, because the count alone is what invited the
	// cumulative misreading. Null rather than 0 when there is nothing to divide.
	$counts['stale_pct'] = $counts['total'] > 0
		? round( ( $counts['stale'] / $counts['total'] ) * 100, 1 )
		: null;

	$oldest = $times ? min( $times ) : 0;
	$newest = $times ? max( $times ) : 0;

	return array(
		'state'                => 'recorded',
		'cap'                  => $cap,
		'window'               => array(
			'oldest'     => $oldest > 0 ? gmdate( 'c', $oldest ) : null,
			'newest'     => $newest > 0 ? gmdate( 'c', $newest ) : null,
			'span_hours' => ( $oldest > 0 && $newest > $oldest ) ? round( ( $newest - $oldest ) / HOUR_IN_SECONDS, 1 ) : null,
		),
		'counts'               => $counts,
		'counts_excluded_rows' => $excluded,
		'rows'                 => $rows,
	);
}
