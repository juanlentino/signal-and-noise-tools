<?php
/**
 * Signal & Noise — cadence flags (v10.22.0, ML pipeline #5).
 *
 * The kernel's EWMA/z-score deviation math (snt_ml_cadence_deviation) applied
 * to two operational rhythms:
 *   - PUBLISH cadence: the site's own publish timestamps — is the current
 *     quiet spell surprising against the historical rhythm?
 *   - CRON cadence: every hook with enough recorded firings in the cron
 *     history table — a hook whose current gap z-scores over the flag
 *     threshold has probably stopped firing.
 *
 * One-sided by design: only LATE flags (a publish burst or a tight cron
 * retry storm is not an ops deviation). Honest unknowns throughout: thin
 * history never flags, a zero-spread metronome is unquantifiable (watched,
 * never flagged), and a FAILED cron-history read skips the cron section and
 * SAYS SO in the envelope — a partial answer never poses as a full one.
 *
 * Consumed by the health check (inc/health-check-ml-cadence.php — the cached
 * 24h scan carries the count to the health widget + attention badge with
 * zero pageload compute) and the 'cadence-flags' pipeline / read-door tool.
 *
 * @package SignalNoiseTools
 * @since 10.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ML_CADENCE_Z_FLAG        = 3.0; // Flag at three sigmas — conservative by design.
const SNT_ML_CADENCE_PUBLISH_DEPTH = 20;  // Publish events considered.
const SNT_ML_CADENCE_CRON_DEPTH    = 50;  // Firings per hook considered.

/**
 * Compute the cadence flags.
 *
 * @param int|null $now Observation instant; null reads the clock (tests pass
 *                      an explicit instant — the math itself never reads time).
 * @return array{ok:bool,flags:array<int,array{kind:string,subject:string,z:float,ewma:float,current_gap:float,last_at:int}>,watched_hooks:int,cron_skipped:bool}
 */
function snt_ml_cadence_flags( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$flags = array();

	// ── Publish cadence ──────────────────────────────────────────────
	$events = array();
	foreach ( (array) get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => SNT_ML_CADENCE_PUBLISH_DEPTH,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) ) as $p ) {
		$ts = strtotime( (string) ( $p->post_date_gmt ?? '' ) . ' UTC' );
		if ( false !== $ts ) {
			$events[] = $ts;
		}
	}
	$dev = snt_ml_cadence_deviation( $events, $now );
	if ( is_array( $dev ) && null !== $dev['z'] && $dev['z'] >= SNT_ML_CADENCE_Z_FLAG ) {
		$flags[] = array(
			'kind'        => 'publish',
			'subject'     => __( 'Publishing cadence', 'signal-and-noise-tools' ),
			'z'           => (float) $dev['z'],
			'ewma'        => (float) $dev['ewma'],
			'current_gap' => (float) $dev['current_gap'],
			'last_at'     => $events ? (int) max( $events ) : 0,
		);
	}

	// ── Cron cadence ─────────────────────────────────────────────────
	global $wpdb;
	$watched      = 0;
	$cron_skipped = false;
	$table        = $wpdb->prefix . ( defined( 'SNT_CRON_HISTORY_TABLE' ) ? SNT_CRON_HISTORY_TABLE : 'snt_cron_history' );
	$hooks        = $wpdb->get_results( "SELECT hook, COUNT(*) AS c FROM {$table} GROUP BY hook", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix + constant, no user input.
	if ( ! is_array( $hooks ) || '' !== (string) $wpdb->last_error ) {
		$cron_skipped = true; // Partial answer, spoken in the envelope.
		$hooks        = array();
	}
	$cron_flags = array();
	foreach ( $hooks as $row ) {
		$hook = (string) ( $row['hook'] ?? '' );
		if ( '' === $hook ) {
			continue;
		}
		// fired_at is stored UTC (gmdate at write time); convert PHP-side with
		// the snt_cron_history_for_hook idiom rather than UNIX_TIMESTAMP(),
		// whose session-timezone dependence would shift the current-gap term.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT fired_at FROM {$table} WHERE hook = %s ORDER BY fired_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- same bounded table name.
			$hook,
			SNT_ML_CADENCE_CRON_DEPTH
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			$cron_skipped = true;
			break;
		}
		$events = array();
		foreach ( $rows as $r ) {
			$ts = strtotime( (string) ( $r['fired_at'] ?? '' ) . ' UTC' );
			if ( false !== $ts ) {
				$events[] = (float) $ts;
			}
		}
		$dev = snt_ml_cadence_deviation( $events, $now );
		if ( ! is_array( $dev ) ) {
			continue; // Thin history: unknown, not watched.
		}
		$watched++;
		if ( null !== $dev['z'] && $dev['z'] >= SNT_ML_CADENCE_Z_FLAG ) {
			$cron_flags[] = array(
				'kind'        => 'cron',
				'subject'     => $hook,
				'z'           => (float) $dev['z'],
				'ewma'        => (float) $dev['ewma'],
				'current_gap' => (float) $dev['current_gap'],
				'last_at'     => $events ? (int) max( $events ) : 0,
			);
		}
	}
	usort( $cron_flags, static function ( $a, $b ) {
		if ( $a['z'] === $b['z'] ) {
			return strcmp( $a['subject'], $b['subject'] ); // Deterministic ties.
		}
		return $b['z'] <=> $a['z'];
	} );

	return array(
		'ok'            => true,
		'flags'         => array_merge( $flags, $cron_flags ),
		'watched_hooks' => $watched,
		'cron_skipped'  => $cron_skipped,
	);
}

/**
 * Humanize a duration in seconds without any i18n dependency — this string
 * feeds admin-only notes.
 *
 * @param float $seconds Duration.
 * @return string e.g. "3.5d", "7.2h", "45m", "30s".
 */
function snt_ml_cadence_human_gap( $seconds ) {
	$seconds = (float) $seconds;
	if ( $seconds >= DAY_IN_SECONDS ) {
		return sprintf( '%.1fd', $seconds / DAY_IN_SECONDS );
	}
	if ( $seconds >= HOUR_IN_SECONDS ) {
		return sprintf( '%.1fh', $seconds / HOUR_IN_SECONDS );
	}
	if ( $seconds >= MINUTE_IN_SECONDS ) {
		return sprintf( '%.0fm', $seconds / MINUTE_IN_SECONDS );
	}
	return sprintf( '%.0fs', $seconds );
}
