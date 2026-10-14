<?php
/**
 * Signal & Noise Tools: Cron Dashboard, the event reads and writes (list, run now, unschedule, schedule).
 *
 * Split from inc/cron-dashboard.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			sprintf( 'No handler registered for "%s": this is an orphan event.', $hook ),
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
		// v3.7.6: server-log surfacing per v3.7.1 lesson. The $error is also
		// persisted to snt_cron_history below, but DB-only logging is invisible
		// to log search tools (grep, journalctl, fail2ban). One-line PHP log
		// keeps both surfaces in sync.
		error_log( 'snt_cron_run hook "' . $hook . '" failed: ' . $e->getMessage() );
	}

	$elapsed_ms = ( microtime( true ) - $start ) * 1000;
	$last_fired_ts = snt_cron_last_fired_for( $hook );

	// v3.2.0: write a history row with the impl's own success/error
	// values. The post-hook fires AFTER this (since we're still inside
	// do_action_ref_array's after-hooks) so we set a global flag to
	// skip the auto-record in snt_cron_history_post_cb and avoid a
	// duplicate row.
	if ( function_exists( 'snt_cron_history_record' ) ) {
		$GLOBALS['__snt_cron_history_skip_auto'] = true;
		snt_cron_history_record( $hook, is_array( $args ) ? $args : array(), $elapsed_ms, $success, $error );
	}

	return array(
		'success'              => $success,
		'elapsed_ms'           => $elapsed_ms,
		'error'                => $error,
		'last_fired_ts'        => $last_fired_ts,
		// v3.0.1: include a server-side-formatted timestamp so the JS
		// doesn't need to call toISOString() (which produces UTC, not
		// site timezone). Matches the wp_date format used by the PHP
		// renderer for the initial cell content — keeps the inline
		// cell update visually consistent with the rest of the table.
		'last_fired_formatted' => $last_fired_ts ? wp_date( 'Y-m-d H:i:s', $last_fired_ts ) : null,
		'hook'                 => $hook,
	);
}

/**
 * Unschedule a cron event by hook + args.
 *
 * Uses wp_clear_scheduled_hook() rather than wp_unschedule_event() so
 * BOTH the next firing AND the recurring schedule are removed in one
 * call. A user looking at a recurring row in the dashboard almost
 * certainly wants to STOP the schedule entirely, not skip one firing
 * (which would re-appear at the next interval).
 *
 * Safety gates:
 *   1. manage_options (defense in depth; REST + ability layers also gate)
 *   2. SN-owned hook refusal — the dashboard hides this surface from the
 *      UI but a direct REST / ability caller could still try; refuse so
 *      "kill the cron that powers the dashboard widgets" isn't a single
 *      misclick away.
 *   3. has_action() pre-flight is NOT enforced here — unscheduling
 *      orphan events is the whole point of cleanup, so we WANT to allow
 *      that path. (The orphan-cleanup GHA workflow uses wp-cli; this
 *      gives REST/ability callers the same capability.)
 *
 * @since 3.1.0
 *
 * @param string $hook Hook name to unschedule.
 * @param array  $args Optional args array (must match the scheduled signature).
 * @return array|WP_Error On success: { success: true, hook, args, cleared: int }
 *                        where cleared is the count of events removed
 *                        (0 if hook+args didn't match anything). On
 *                        failure: WP_Error with snt_cron_* code.
 */
function snt_cron_unschedule_event_impl( $hook, $args = array() ) {
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
	if ( snt_cron_is_sn_owned( $hook ) ) {
		return new WP_Error(
			'snt_cron_sn_owned_refused',
			sprintf( 'Refusing to unschedule "%s": this hook is registered by Signal & Noise itself and unscheduling it would silently break dashboard refreshes or RSS tracking. Disable the owning module (RSS) from the admin instead.', $hook ),
			array( 'status' => 400 )
		);
	}

	$args = is_array( $args ) ? $args : array();

	// wp_clear_scheduled_hook() returns int (events cleared) since WP 6.1;
	// older returns null/false. Normalize both branches.
	$cleared = wp_clear_scheduled_hook( $hook, $args );
	if ( false === $cleared || null === $cleared ) {
		$cleared = 0;
	}
	$cleared = (int) $cleared;

	return array(
		'success' => true,
		'hook'    => $hook,
		'args'    => $args,
		'cleared' => $cleared,
	);
}

/**
 * Schedule ONE future run of an SN-owned cron hook, then return immediately.
 *
 * THE POLARITY IS INVERTED FROM snt_cron_unschedule_event_impl(), deliberately,
 * and the same predicate bounds both. Unscheduling REFUSES an SN-owned hook,
 * because stopping our own maintenance is the harm there. Scheduling ACCEPTS
 * ONLY an SN-owned hook, because the harm here is the opposite one: deferred
 * dispatch of somebody else's registered code. `snt_cron_sn_owned_hooks()` is a
 * live, constant-referenced allow-list rather than an `sn_`/`snt_` prefix match,
 * so the bound is DERIVED from what this plugin actually schedules — never a
 * list invented for this function.
 *
 * WHY THIS EXISTS RATHER THAN A DOORED run-cron-event: `run-cron-event`
 * dispatches synchronously via do_action(), which is why it sits on no door and
 * stays there. The health scan is the case that motivated this — roughly 35s
 * normally and up to ~105s when something is actually down, against
 * Cloudflare's ~100s edge cap, so running it on the wire hands a caller a tool
 * that hangs and then dies at the edge. Scheduling costs a single row write and
 * returns at once; WP-Cron runs the work out of band, and the verdict stays
 * readable through get-health-scan. Fire-and-forget is the whole point: this
 * function never reports what the hook DID, only that a run was booked.
 *
 * A hook with no registered handler is refused rather than booked. Scheduling
 * one would write a row that fires into nothing, and the caller would wait for
 * a result that can never arrive — the honest-null rule applied to cron.
 *
 * @since 13.49.0
 * @param string $hook  SN-owned cron hook name.
 * @param array  $args  Args array; must match the scheduled signature.
 * @param int    $delay Seconds from now. Clamped to 0..HOUR_IN_SECONDS.
 * @return array{success:bool,hook:string,args:array,scheduled_for:int,already_scheduled:bool}|WP_Error
 */
function snt_cron_schedule_event_impl( $hook, $args = array(), $delay = 0 ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'snt_cron_forbidden', 'Insufficient permissions.', array( 'status' => 403 ) );
	}
	if ( ! is_string( $hook ) || '' === $hook ) {
		return new WP_Error( 'snt_cron_invalid_hook', 'Hook name must be a non-empty string.', array( 'status' => 400 ) );
	}
	// REFUSE BY NAME, and name the alternative — a silent no-op would report a
	// run that was never booked, and the caller would wait forever for it.
	if ( ! snt_cron_is_sn_owned( $hook ) ) {
		return new WP_Error(
			'snt_cron_not_sn_owned',
			sprintf(
				/* translators: 1: requested hook, 2: the schedulable hooks. */
				__( 'Refusing to schedule "%1$s": only Signal & Noise\'s own cron hooks may be scheduled here, because booking a third-party hook is deferred dispatch of code this plugin does not own. Schedulable hooks are: %2$s.', 'signal-and-noise-tools' ),
				$hook,
				implode( ', ', snt_cron_sn_owned_hooks() )
			),
			array( 'status' => 400 )
		);
	}
	if ( false === has_action( $hook ) ) {
		return new WP_Error(
			'snt_cron_no_handler',
			sprintf(
				/* translators: %s: the requested hook. */
				__( 'Refusing to schedule "%s": no handler is registered for it, so the booked run would fire into nothing.', 'signal-and-noise-tools' ),
				$hook
			),
			array( 'status' => 400 )
		);
	}

	$args  = is_array( $args ) ? $args : array();
	$delay = max( 0, min( (int) $delay, HOUR_IN_SECONDS ) );

	// An identical pending event means the work is ALREADY booked. Reported, not
	// duplicated: a second row would run the same maintenance twice.
	$existing = wp_next_scheduled( $hook, $args );
	if ( false !== $existing ) {
		return array(
			'success'           => true,
			'hook'              => $hook,
			'args'              => $args,
			'scheduled_for'     => (int) $existing,
			'already_scheduled' => true,
		);
	}

	$when = time() + $delay;
	// Returns true on success, false or WP_Error on failure (WP 5.7+ can return
	// WP_Error when a pre-filter blocks it). Both non-true branches are failures.
	$booked = wp_schedule_single_event( $when, $hook, $args, true );
	if ( is_wp_error( $booked ) ) {
		return new WP_Error( 'snt_cron_schedule_failed', $booked->get_error_message(), array( 'status' => 500 ) );
	}
	if ( true !== $booked ) {
		return new WP_Error( 'snt_cron_schedule_failed', sprintf( 'Could not schedule "%s".', $hook ), array( 'status' => 500 ) );
	}

	return array(
		'success'           => true,
		'hook'              => $hook,
		'args'              => $args,
		'scheduled_for'     => $when,
		'already_scheduled' => false,
	);
}
