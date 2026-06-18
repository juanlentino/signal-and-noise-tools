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
 * v4.1.1 (D-05): switched to constant references so a hook rename in
 * rss-feed-tracker.php auto-propagates. The plugin bootstrap requires
 * that file before cron-dashboard.php so the constant is always defined at
 * call time. The defensive `defined()` fallback to the legacy string keeps
 * this resilient if a constant ever vanishes (e.g., partial deploy).
 */
function snt_cron_sn_owned_hooks() {
	return array(
		defined( 'SN_RSS_TRACKER_CRON_HOOK' ) ? SN_RSS_TRACKER_CRON_HOOK : 'sn_rss_tracker_daily_prune',
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
	$history_loaded = function_exists( 'snt_cron_history_pre_cb' );
	foreach ( $crons as $events ) {
		foreach ( $events as $hook => $_ ) {
			if ( isset( $seen[ $hook ] ) ) {
				continue;
			}
			$seen[ $hook ] = true;
			add_action( $hook, 'snt_cron_track_last_fired_cb', PHP_INT_MAX );
			// v3.2.0: history capture pair. Pre at -PHP_INT_MAX stashes
			// start time; post at PHP_INT_MAX records elapsed + INSERTs.
			// Bracketing the real handlers gives an accurate elapsed_ms
			// (within PHP precision; some firings will see negligible
			// time but that still surfaces as "fired ok, ~0ms").
			if ( $history_loaded ) {
				add_action( $hook, 'snt_cron_history_pre_cb', -PHP_INT_MAX );
				add_action( $hook, 'snt_cron_history_post_cb', PHP_INT_MAX );
			}
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
 * Compact summary for desktop-mode wp_localize_script. Avoids serializing
 * the full event list into snDesktopData on every admin page load.
 */
function snt_cron_summary_for_localize() {
	$rows = snt_cron_get_events_impl();
	$total = count( $rows );
	$sn_count = 0;
	$orphans = 0;
	foreach ( $rows as $row ) {
		if ( $row['is_sn_owned'] ) {
			$sn_count++;
		}
		if ( ! $row['has_handler'] ) {
			$orphans++;
		}
	}
	return array(
		'total'    => $total,
		'sn_count' => $sn_count,
		'orphans'  => $orphans,
	);
}

/* ─────────────────────────────────────────────────────────────────────
 * Native WordPress Site Health async test (v4.9.0, Task 2)
 *
 * Tools → Site Health → Status runs an async test against the SN cron
 * pipeline. Core fetches the REST route below and renders the returned
 * envelope. This surfaces SN cron health where site admins already look
 * for it (native WP), without a bespoke SN widget.
 * ───────────────────────────────────────────────────────────────────── */

/**
 * The full set of cron hooks the pipeline owns, including the cron-history
 * prune hook (defined in inc/cron-history.php). Kept separate from
 * snt_cron_sn_owned_hooks() (which the dashboard uses to pin rows) so the
 * Site Health test can include the history-prune hook without changing the
 * dashboard's ordering set.
 */
function snt_cron_site_health_hooks() {
	$hooks = snt_cron_sn_owned_hooks();
	if ( defined( 'SNT_CRON_HISTORY_CRON_HOOK' ) ) {
		$hooks[] = SNT_CRON_HISTORY_CRON_HOOK;
	}
	return array_values( array_unique( $hooks ) );
}

/**
 * Resolve a hook's recurrence interval in seconds (for staleness math).
 * Returns 0 when the schedule is unknown.
 */
function snt_cron_interval_seconds( $hook ) {
	if ( ! function_exists( 'wp_get_schedule' ) || ! function_exists( 'wp_get_schedules' ) ) {
		return 0;
	}
	$slug = wp_get_schedule( $hook );
	if ( ! $slug ) {
		return 0;
	}
	$schedules = wp_get_schedules();
	return isset( $schedules[ $slug ]['interval'] ) ? (int) $schedules[ $slug ]['interval'] : 0;
}

/**
 * Build the Site Health result envelope for the SN cron pipeline.
 *
 * status:
 *   - 'good'        : every hook scheduled, none stale, cron not silently off
 *   - 'recommended' : any unscheduled / stale (>2× interval) / cron disabled
 *                     without a declared system-cron replacement
 *
 * "Silently disabled" = DISABLE_WP_CRON is true AND no system cron has been
 * declared via the sn_cron_system_cron_configured filter (defaults false).
 *
 * @since 4.9.0
 * @return array
 */
function snt_cron_site_health_result() {
	$hooks   = snt_cron_site_health_hooks();
	$now     = time();
	$issues  = array();
	$lines   = array();

	foreach ( $hooks as $hook ) {
		$next       = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
		$last_fired = snt_cron_last_fired_for( $hook );
		$interval   = snt_cron_interval_seconds( $hook );

		$next_label = ( false !== $next && is_numeric( $next ) )
			? sprintf( /* translators: %s: human time diff. */ __( 'next run in %s', 'signal-noise-tools' ), human_time_diff( $now, (int) $next ) )
			: __( 'NOT scheduled', 'signal-noise-tools' );

		$last_label = ( null !== $last_fired )
			? sprintf( /* translators: %s: human time diff. */ __( 'last fired %s ago', 'signal-noise-tools' ), human_time_diff( (int) $last_fired, $now ) )
			: __( 'never fired', 'signal-noise-tools' );

		if ( false === $next || ! is_numeric( $next ) ) {
			$issues[] = $hook;
		} elseif ( $interval > 0 && null !== $last_fired && ( $now - (int) $last_fired ) > ( 2 * $interval ) ) {
			// Fired but the last firing is older than 2× the recurrence — stale.
			$issues[] = $hook;
		}

		$lines[] = esc_html( $hook ) . ' — ' . esc_html( $next_label ) . '; ' . esc_html( $last_label );
	}

	$cron_silently_disabled = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
		&& ! apply_filters( 'sn_cron_system_cron_configured', false );

	$status = ( empty( $issues ) && ! $cron_silently_disabled ) ? 'good' : 'recommended';

	$description = '<p>' . wp_kses_post( implode( '<br>', $lines ) ) . '</p>';
	if ( $cron_silently_disabled ) {
		$description .= '<p>' . esc_html__( 'DISABLE_WP_CRON is set but no system cron has been declared — scheduled events will not fire on their own.', 'signal-noise-tools' ) . '</p>';
	}

	$cron_tab_url = admin_url( 'admin.php?page=sn-theme-options&tab=connections&sub=cron' );

	return array(
		'label'       => __( 'Signal & Noise cron pipeline', 'signal-noise-tools' ),
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Performance', 'signal-noise-tools' ),
			'color' => 'blue',
		),
		'description' => $description,
		'actions'     => '<p><a href="' . esc_url( $cron_tab_url ) . '">' . esc_html__( 'Open the Cron tab', 'signal-noise-tools' ) . '</a></p>',
		'test'        => 'sn_cron_pipeline',
	);
}

/**
 * Register the async Site Health test.
 */
add_filter( 'site_status_tests', 'snt_cron_register_site_health_test' );
function snt_cron_register_site_health_test( $tests ) {
	$tests['async']['sn_cron_pipeline'] = array(
		'label'    => __( 'Signal & Noise cron pipeline', 'signal-noise-tools' ),
		'test'     => rest_url( 'signal-noise/v1/site-health/cron' ),
		'has_rest' => true,
	);
	return $tests;
}

/**
 * REST endpoint the async test polls. manage_options-gated.
 */
add_action( 'rest_api_init', 'snt_cron_register_site_health_route' );
function snt_cron_register_site_health_route() {
	register_rest_route( 'signal-noise/v1', '/site-health/cron', array(
		'methods'             => 'GET',
		'callback'            => 'snt_cron_site_health_rest',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
}

function snt_cron_site_health_rest() {
	return snt_cron_site_health_result();
}
