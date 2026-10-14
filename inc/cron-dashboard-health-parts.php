<?php
/**
 * Signal & Noise Tools: Cron Dashboard, the health model, its summary and the native Site Health test.
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
 * Build the Site Health result envelope for the SN cron pipeline.
 *
 * status:
 *   - 'good'        : every EXPECTED hook scheduled, none stale, cron not
 *                     silently off (config-off features are exempt — see
 *                     snt_cron_hook_is_expected)
 *   - 'recommended' : any expected-but-unscheduled / stale (>2× interval) /
 *                     cron disabled without a declared system-cron
 *                     replacement
 *
 * "Silently disabled" = DISABLE_WP_CRON is true AND no system cron has been
 * declared via the sn_cron_system_cron_configured filter (defaults false)
 * AND no monitored hook has fired within 2× its recurrence. The last clause
 * (v8.1.4) makes the warning evidence-based: recent firings prove a system
 * cron IS running (e.g. the Cloudways wp-cron.php job), so an undeclared
 * filter alone must not raise a false alarm.
 *
 * @since 4.9.0
 * @return array
 */
function snt_cron_site_health_result() {
	$now            = time();
	$model          = snt_cron_health_model( $now );
	$issues         = $model['issues'];
	$overdue_hooks  = $model['overdue']; // (FIX 4): scheduled-but-stale, the signal that elevates to critical.
	$fired_recently = $model['fired_recently'];
	$lines          = array();

	foreach ( $model['rows'] as $row ) {
		$hook       = $row['hook'];
		$next       = null === $row['next'] ? false : $row['next'];
		$last_fired = $row['last_fired'];
		$interval   = $row['interval'];
		$expected   = $row['expected'];
		$on_demand  = $row['on_demand'];

		if ( $on_demand ) {
			$unscheduled_label = __( 'on-demand (single events, clears after firing)', 'signal-and-noise-tools' );
		} elseif ( $expected ) {
			$unscheduled_label = __( 'NOT scheduled', 'signal-and-noise-tools' );
		} else {
			$unscheduled_label = __( 'not scheduled (feature off)', 'signal-and-noise-tools' );
		}
		$next_label = ( false !== $next && is_numeric( $next ) )
			// #1222: the sentence "next run %s" takes either half of
			// snt_cron_next_run_label()'s "in 5 mins" / "5 mins overdue".
			/* translators: %s: "in <human time diff>" or "<human time diff> overdue". */
			? sprintf( __( 'next run %s', 'signal-and-noise-tools' ), snt_cron_next_run_label( $now, (int) $next ) )
			: $unscheduled_label;

		$last_label = ( null !== $last_fired )
			? sprintf( /* translators: %s: human time diff. */ __( 'last fired %s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $last_fired, $now ) )
			: __( 'never fired', 'signal-and-noise-tools' );

		$lines[] = esc_html( $hook ) . ' — ' . esc_html( $next_label ) . '; ' . esc_html( $last_label );
	}

	$cron_silently_disabled = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
		&& ! $fired_recently
		&& ! apply_filters( 'sn_cron_system_cron_configured', false );

	// (render hardening FIX 4): elevate 'recommended' → 'critical' when
	// DISABLE_WP_CRON is silently starving cron AND events are actually piling
	// up overdue (not just theoretically at risk) — hard evidence, not a guess.
	$cron_overdue_and_starved = $cron_silently_disabled && ! empty( $overdue_hooks );

	if ( $cron_overdue_and_starved ) {
		$status = 'critical';
	} elseif ( ! empty( $issues ) || $cron_silently_disabled ) {
		$status = 'recommended';
	} else {
		$status = 'good';
	}

	$description = '<p>' . wp_kses_post( implode( '<br>', $lines ) ) . '</p>';
	if ( $cron_overdue_and_starved ) {
		$description .= '<p>' . esc_html__( 'DISABLE_WP_CRON is set but no system cron appears to be running wp-cron.php: scheduled events are piling up overdue. Add a system cron job that requests wp-cron.php on an interval (e.g. every 5 minutes), or unset DISABLE_WP_CRON.', 'signal-and-noise-tools' ) . '</p>';
	} elseif ( $cron_silently_disabled ) {
		$description .= '<p>' . esc_html__( 'DISABLE_WP_CRON is set but no system cron has been declared: scheduled events will not fire on their own.', 'signal-and-noise-tools' ) . '</p>';
	}

	$cron_tab_url = admin_url( 'admin.php?page=sn-theme-options&tab=connections&sub=cron' );

	return array(
		'label'       => __( 'Signal & Noise cron pipeline', 'signal-and-noise-tools' ),
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Performance', 'signal-and-noise-tools' ),
			'color' => 'blue',
		),
		'description' => $description,
		'actions'     => '<p><a href="' . esc_url( $cron_tab_url ) . '">' . esc_html__( 'Open the Cron tab', 'signal-and-noise-tools' ) . '</a></p>',
		'test'        => 'sn_cron_pipeline',
	);
}

/**
 * The cron-health MODEL: every watched hook derived to plain facts, once.
 *
 * Extracted from snt_cron_site_health_result() (v13.52.0) so the Site Health
 * check and the cron-health-summary ability consume ONE derivation — the
 * overdue rule (scheduled, fired before, and the last firing older than 2x the
 * recurrence) must never exist twice, or the dashboard and the phone would
 * eventually disagree about whether the same job is late. Facts only: no HTML,
 * no i18n, no admin URLs — presentation stays with each consumer.
 *
 * @since 13.52.0
 * @param int|null $now Unix time; injectable for tests.
 * @return array{rows:array<int,array<string,mixed>>,fired_recently:bool,issues:string[],overdue:string[]}
 */
function snt_cron_health_model( $now = null ) {
	$now            = null === $now ? time() : (int) $now;
	$rows           = array();
	$issues         = array();
	$overdue_hooks  = array();
	$fired_recently = false;

	foreach ( snt_cron_site_health_hooks() as $hook ) {
		$next       = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
		$last_fired = snt_cron_last_fired_for( $hook );
		$interval   = snt_cron_interval_seconds( $hook );
		$expected   = snt_cron_hook_is_expected( $hook );
		$on_demand  = snt_cron_hook_is_on_demand( $hook );

		$scheduled = ( false !== $next && is_numeric( $next ) );
		$overdue   = ( $scheduled && ! $on_demand && $interval > 0 && null !== $last_fired && ( $now - (int) $last_fired ) > ( 2 * $interval ) );
		$missing   = ( ! $scheduled && $expected && ! $on_demand );

		if ( $missing || $overdue ) {
			$issues[] = $hook;
		}
		if ( $overdue ) {
			$overdue_hooks[] = $hook;
		}
		if ( $interval > 0 && null !== $last_fired && ( $now - (int) $last_fired ) <= ( 2 * $interval ) ) {
			$fired_recently = true;
		}

		$rows[] = array(
			'hook'       => $hook,
			'next'       => $scheduled ? (int) $next : null,
			'last_fired' => $last_fired,
			'interval'   => (int) $interval,
			'expected'   => (bool) $expected,
			'on_demand'  => (bool) $on_demand,
			'scheduled'  => $scheduled,
			'overdue'    => $overdue,
		);
	}

	return array(
		'rows'           => $rows,
		'fired_recently' => $fired_recently,
		'issues'         => $issues,
		'overdue'        => $overdue_hooks,
	);
}

/**
 * The cron-health SUMMARY: the phone-readable model, derived once.
 *
 * WHY THIS EXISTS (v13.52.0). The 2026-08-11 partition deferred the cron
 * sections from the remote door "with a pass" — a model-never-levers output
 * review — because a list of raw hook rows tells a phone that machinery exists
 * without saying whether anything is WRONG. This is that review's product: the
 * answer to "is cron healthy?" is `status` + `summary`, and the evidence is the
 * overdue/missing lists. Detail stays on the desktop sections.
 *
 * `summary` is DERIVED from the same counts in the same function — never
 * hand-written by a caller — so the sentence cannot drift from the numbers it
 * describes. That is the output-parity discipline v13.51.0 enforces at the
 * schema layer, applied at the prose layer.
 *
 * Facts share snt_cron_health_model() with the Site Health check: ONE overdue
 * rule (scheduled, fired before, last firing older than 2x cadence), two
 * consumers, zero copies.
 *
 * @since 13.52.0
 * @param int|null $now Unix time; injectable for tests.
 * @return array<string,mixed>
 */
function snt_cron_health_summary_impl( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$model = snt_cron_health_model( $now );

	$recurring = 0;
	$overdue   = array();
	$missing   = array();
	foreach ( $model['rows'] as $row ) {
		if ( $row['on_demand'] || ! $row['expected'] ) {
			continue;
		}
		$recurring++;
		if ( $row['overdue'] ) {
			$overdue[] = array(
				'job'        => (string) $row['hook'],
				'cadence'    => (int) $row['interval'],
				'overdue_by' => max( 0, $now - (int) $row['last_fired'] - (int) $row['interval'] ),
			);
		} elseif ( ! $row['scheduled'] ) {
			$missing[] = (string) $row['hook'];
		}
	}

	// Same elevation ladder as the Site Health check, minus the HTML.
	$flags         = snt_cron_constant_flags(
		defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		(bool) $model['fired_recently'],
		(bool) apply_filters( 'sn_cron_system_cron_configured', false )
	);
	$cron_disabled = $flags['cron_stalled_no_runner'];
	if ( $cron_disabled && array() !== $overdue ) {
		$status = 'critical';
	} elseif ( array() !== $model['issues'] || $cron_disabled ) {
		$status = 'recommended';
	} else {
		$status = 'good';
	}

	$on_schedule = $recurring - count( $overdue ) - count( $missing );
	if ( 'good' === $status ) {
		$summary = sprintf( 'All %d recurring jobs are firing on schedule.', $recurring );
	} else {
		$parts = array();
		if ( array() !== $overdue ) {
			$parts[] = sprintf( '%d overdue', count( $overdue ) );
		}
		if ( array() !== $missing ) {
			$parts[] = sprintf( '%d expected but not scheduled', count( $missing ) );
		}
		if ( $cron_disabled ) {
			$parts[] = 'DISABLE_WP_CRON is set, nothing fired recently, and no system cron is declared';
		}
		$summary = sprintf( '%d of %d recurring jobs on schedule; %s.', max( 0, $on_schedule ), $recurring, implode( ', ', $parts ) );
	}

	return array(
		'ok'                     => 'good' === $status,
		'status'                 => $status,
		'checked_at'             => $now,
		'recurring'              => $recurring,
		'on_schedule'            => max( 0, $on_schedule ),
		'overdue'                => $overdue,
		'missing'                => $missing,
		// NOT "is DISABLE_WP_CRON set". It is a PROBLEM FLAG: the constant is
		// set AND nothing fired recently AND no system cron is declared. It
		// reads false both when the constant is absent and when the constant is
		// set and everything is working - two opposite situations. The name has
		// already misled one reader (v13.97.3, mine); `wp_cron_offload` below
		// is the field that actually answers "what happened to the constant".
		// DEPRECATED name, kept for callers: same value as cron_stalled_no_runner.
		// Read cron_stalled_no_runner (the alarm) and disable_wp_cron (the
		// constant). The two new keys are payload-only: the output_schema is
		// the remote contract (shape hash + byte parity with the twin), so
		// declaring them is a contract bump that waits for a worker deploy.
		'cron_disabled_constant' => (bool) $cron_disabled,
		'cron_stalled_no_runner' => $flags['cron_stalled_no_runner'],
		'disable_wp_cron'        => $flags['disable_wp_cron'],
		'summary'                => $summary,
	);
}

/**
 * The two readings the old `cron_disabled_constant` field conflated.
 *
 * disable_wp_cron: is the constant set. cron_stalled_no_runner: the alarm,
 * constant set AND nothing fired recently AND no system cron declared.
 *
 * @return array{disable_wp_cron: bool, cron_stalled_no_runner: bool}
 */
function snt_cron_constant_flags( $constant_set, $fired_recently, $system_cron ) {
	return array(
		'disable_wp_cron'        => (bool) $constant_set,
		'cron_stalled_no_runner' => (bool) $constant_set && ! $fired_recently && ! $system_cron,
	);
}

/**
 * Register the async Site Health test.
 */
add_filter( 'site_status_tests', 'snt_cron_register_site_health_test' );
function snt_cron_register_site_health_test( $tests ) {
	$tests['async']['sn_cron_pipeline'] = array(
		'label'    => __( 'Signal & Noise cron pipeline', 'signal-and-noise-tools' ),
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
