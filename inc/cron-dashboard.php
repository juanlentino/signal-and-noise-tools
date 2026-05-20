<?php
/**
 * Signal & Noise Tools — Cron Dashboard module.
 *
 * Surfaces WP-Cron health in the wp-admin under the Cron tab. For every
 * scheduled cron event, shows next-run, recurrence, last-fired, args,
 * and provides a Run-now button gated by manage_options + confirm().
 *
 * 4-surface dispatch (per the plugin's established Phase 14 pattern):
 *   - wp-admin form (Cron tab → Run-now button)
 *   - REST POST signal-noise/v1/cron/run
 *   - Abilities API: signal-noise/list-cron-events + get-cron-event
 *   - desktop-mode ⌘K: sn-cmd-cron-health + sn-cmd-cron-list (read-only)
 *
 * All 4 surfaces converge on the snt_cron_*_impl() pure functions below.
 * Run-now is NOT exposed to AI per spec § 6 / Q4 decision.
 *
 * Last-fired tracking: WordPress core does not track last-fired natively.
 * We register snt_cron_track_last_fired_cb() at PHP_INT_MAX for every
 * unique cron hook during DOING_CRON requests, gated at wp_loaded so
 * non-cron requests pay zero cost.
 *
 * @package SignalNoiseTools
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook names owned by this plugin. Used by snt_cron_is_sn_owned() to
 * pin SN-owned events at the top of the dashboard table.
 *
 * Kept as a string array (not constants) because the constants live in
 * the modules that schedule them and we want to avoid a require_once
 * cycle. If the list grows, consider exposing via a filter.
 */
function snt_cron_sn_owned_hooks() {
	return array(
		'sn_plausible_refresh_dashboard',
		'sn_plausible_refresh_realtime',
		'sn_rss_tracker_daily_prune',
	);
}

function snt_cron_is_sn_owned( $hook ) {
	return in_array( $hook, snt_cron_sn_owned_hooks(), true );
}

/**
 * Last-fired storage: write helper.
 *
 * Key format: snt_cron_last_fired_<md5(hook)>. md5 avoids the
 * varchar(191) wp_options key column limit for long hook names like
 * 'action_scheduler_run_queue' and handles hook names with slashes.
 *
 * Stored as integer unix timestamp. autoload=false so it doesn't
 * bloat the autoloaded options cache.
 */
function snt_cron_record_last_fired( $hook ) {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return;
	}
	update_option( 'snt_cron_last_fired_' . md5( $hook ), time(), false );
}

/**
 * Last-fired storage: read helper. Returns int|null.
 */
function snt_cron_last_fired_for( $hook ) {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return null;
	}
	$value = get_option( 'snt_cron_last_fired_' . md5( $hook ), null );
	if ( null === $value || '' === $value ) {
		return null;
	}
	return (int) $value;
}

/**
 * Named callback referenced by both wp_loaded path (registered for each
 * cron hook during DOING_CRON requests) and the synchronous run-now
 * path (registered ad-hoc in snt_cron_run_event_impl). Uses
 * current_action() so one function works for every hook.
 */
function snt_cron_track_last_fired_cb() {
	snt_cron_record_last_fired( current_action() );
}

/**
 * During DOING_CRON requests, register snt_cron_track_last_fired_cb at
 * PHP_INT_MAX for every unique cron hook. This way it fires AFTER the
 * real handler completes, capturing last-fired exactly once per event
 * firing.
 *
 * Gated at wp_loaded priority 1 so non-cron requests pay only one
 * defined() check. Pre-walks _get_cron_array() to register named
 * (not closure) listeners so WordPress's internal callback dedupe
 * works if multiple plugins do this trick.
 *
 * _get_cron_array() is underscore-prefixed (technically private) but
 * stable since WP 2.1 (2007). It's the only way to enumerate all
 * scheduled events; documented in spec § 7.4 as accepted API risk.
 */
add_action( 'wp_loaded', function() {
	if ( ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! function_exists( '_get_cron_array' ) ) {
		return;
	}
	$crons = _get_cron_array();
	if ( empty( $crons ) ) {
		return;
	}
	$seen = array();
	foreach ( $crons as $events ) {
		foreach ( $events as $hook => $_ ) {
			if ( isset( $seen[ $hook ] ) ) {
				continue;
			}
			$seen[ $hook ] = true;
			add_action( $hook, 'snt_cron_track_last_fired_cb', PHP_INT_MAX );
		}
	}
}, 1 );

/**
 * Walks _get_cron_array() and returns a flat list of event rows.
 *
 * Each row has 9 keys per spec § 4.1.
 *
 * args_signature is the md5 key the cron array uses to disambiguate
 * multiple scheduled instances of the same hook with different args
 * (e.g., wp_version_check can be scheduled twice with different args).
 *
 * Sort: SN-owned hooks first, then by next_run_ts ascending.
 *
 * @param bool $sn_only If true, filter to the 3 SN-owned hooks only.
 * @return array Flat array of event rows (empty array if cron empty).
 */
function snt_cron_get_events_impl( $sn_only = false ) {
	if ( ! function_exists( '_get_cron_array' ) ) {
		return array();
	}
	$crons = _get_cron_array();
	if ( empty( $crons ) ) {
		return array();
	}

	$rows = array();
	foreach ( $crons as $ts => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			$is_sn = snt_cron_is_sn_owned( $hook );
			if ( $sn_only && ! $is_sn ) {
				continue;
			}
			foreach ( $events as $signature => $data ) {
				$rows[] = array(
					'hook'           => $hook,
					'args_signature' => (string) $signature,
					'next_run_ts'    => (int) $ts,
					'schedule'       => isset( $data['schedule'] ) ? $data['schedule'] : false,
					'interval_s'     => isset( $data['interval'] ) ? (int) $data['interval'] : null,
					'args'           => isset( $data['args'] ) ? (array) $data['args'] : array(),
					'last_fired_ts'  => snt_cron_last_fired_for( $hook ),
					'has_handler'    => has_action( $hook ) !== false,
					'is_sn_owned'    => $is_sn,
				);
			}
		}
	}

	// Sort: SN-owned first, then by next_run_ts ascending.
	usort( $rows, function( $a, $b ) {
		if ( $a['is_sn_owned'] !== $b['is_sn_owned'] ) {
			return $a['is_sn_owned'] ? -1 : 1;
		}
		return $a['next_run_ts'] - $b['next_run_ts'];
	} );

	return $rows;
}

/**
 * Single-event variant. Returns the row matching hook+signature, or
 * null if no match. Useful for the get-cron-event ability.
 */
function snt_cron_get_event_impl( $hook, $args_signature ) {
	foreach ( snt_cron_get_events_impl() as $row ) {
		if ( $row['hook'] === $hook && $row['args_signature'] === $args_signature ) {
			return $row;
		}
	}
	return null;
}

/**
 * Synchronously dispatch a cron event. The 4 safety guards:
 *
 *   1. manage_options gate (defense in depth; REST also gates)
 *   2. has_action() pre-flight — orphan hooks return WP_Error rather
 *      than dispatching to nothing
 *   3. DOING_CRON spoof — handlers that guard on wp_doing_cron() (e.g.,
 *      Action Scheduler) will actually execute. Standard pattern from
 *      WP-Crontrol since 2012.
 *   4. Throwable catch — PHP 7+ throws fatals as Error subclasses, so
 *      Throwable covers Exception, TypeError, ParseError, OutOfMemory*,
 *      ArgumentCountError, etc. Only truly unrecoverable cases (segfault,
 *      hard OOM) bypass it; those return 502 to the browser.
 *
 * Time limit: @set_time_limit(30) is best-effort (some hosts disable).
 * If exceeded, PHP kills the process → browser sees 502/timeout. The
 * Cron tab is the recovery surface — refresh, check last-fired column.
 *
 * Note on the ad-hoc tracker registration here: wp_loaded already fired
 * by the time this REST request reaches us. The DOING_CRON gate at
 * wp_loaded didn't register listeners. We register one manually for
 * just this hook so the synchronous dispatch updates last-fired too.
 */
function snt_cron_run_event_impl( $hook, $args = array() ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'snt_cron_forbidden',
			'Insufficient permissions.',
			array( 'status' => 403 )
		);
	}
	if ( ! is_string( $hook ) || '' === $hook ) {
		return new WP_Error(
			'snt_cron_invalid_hook',
			'Hook name must be a non-empty string.',
			array( 'status' => 400 )
		);
	}
	if ( false === has_action( $hook ) ) {
		return new WP_Error(
			'snt_cron_no_handler',
			sprintf( 'No handler registered for "%s" — this is an orphan event.', $hook ),
			array( 'status' => 400 )
		);
	}

	// DOING_CRON spoof — makes wp_doing_cron() return true for guarded handlers.
	if ( ! defined( 'DOING_CRON' ) ) {
		define( 'DOING_CRON', true );
	}

	// Best-effort time limit. Some shared hosts disable set_time_limit.
	@set_time_limit( 30 );

	// Register the last-fired tracker ad-hoc (wp_loaded already fired).
	add_action( $hook, 'snt_cron_track_last_fired_cb', PHP_INT_MAX );

	$start = microtime( true );
	$success = true;
	$error = null;

	try {
		do_action_ref_array( $hook, is_array( $args ) ? $args : array() );
	} catch ( Throwable $e ) {
		$success = false;
		$error = $e->getMessage();
	}

	$elapsed_ms = ( microtime( true ) - $start ) * 1000;

	return array(
		'success'       => $success,
		'elapsed_ms'    => $elapsed_ms,
		'error'         => $error,
		'last_fired_ts' => snt_cron_last_fired_for( $hook ),
		'hook'          => $hook,
	);
}
