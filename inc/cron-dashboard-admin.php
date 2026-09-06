<?php
/**
 * Signal & Noise Tools — Cron Dashboard admin tab renderer.
 *
 * Hooks into the sn_admin_cron_tab action (dispatched by
 * inc/admin-page.php when ?tab=cron) and renders the cron events
 * table with the live filter input + Run-now buttons.
 *
 * @package SignalNoiseTools
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_cron_tab', 'snt_cron_render_admin_tab' );

/**
 * Register the cron dashboard script with its strings, once.
 *
 * Shared by the classic admin page (below) and the S&N Dashboard host
 * window (inc/openstation-host.php), which cannot ride the
 * `admin_enqueue_scripts` gate: one registrar, one source of strings.
 *
 * @return void
 */
function snt_cron_dashboard_register_script() {
	if ( wp_script_is( 'sn-cron-dashboard', 'registered' ) ) {
		return;
	}
	wp_register_script(
		'sn-cron-dashboard',
		plugins_url( 'assets/cron-dashboard.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'wp-data', 'snt-ability-run' ),
		SNT_VERSION,
		true
	);

	// v3.0.2: localize user-facing JS strings so they're translatable
	// (no .pot file yet, but the call site uses sntCronI18n rather than
	// inline English, which means future translation work is a config
	// change, not a code change).
	wp_localize_script( 'sn-cron-dashboard', 'sntCronI18n', array(
		/* translators: button label while a cron event is being dispatched */
		'running'          => __( 'Running…', 'signal-and-noise-tools' ),
		/* translators: button label when idle */
		'runNow'           => __( 'Run now', 'signal-and-noise-tools' ),
		/* translators: relative-time label shown immediately after a manual run */
		'justNow'          => __( 'just now', 'signal-and-noise-tools' ),
		/* translators: %s is the cron hook name (e.g., wp_version_check) */
		'confirmRun'       => __( "Run cron event '%s' now?", 'signal-and-noise-tools' ),
		'apiFetchMissing'  => __( 'wp.apiFetch unavailable: cannot dispatch.', 'signal-and-noise-tools' ),
		'unknownError'     => __( 'unknown error', 'signal-and-noise-tools' ),
		/* translators: 1: hook name, 2: elapsed time in milliseconds */
		'firedTemplate'    => __( '%1$s fired in %2$dms', 'signal-and-noise-tools' ),
		/* translators: %s is the error message returned by the REST endpoint */
		'runFailedTemplate' => __( 'Run failed: %s', 'signal-and-noise-tools' ),
		/* translators: button label while a cron event is being unscheduled (v3.1.0) */
		'unscheduling'     => __( 'Unscheduling…', 'signal-and-noise-tools' ),
		/* translators: button label when idle (v3.1.0) */
		'unschedule'       => __( 'Unschedule', 'signal-and-noise-tools' ),
		/* translators: %s is the cron hook name — confirmation prompt before destructive unschedule */
		'confirmUnschedule' => __( "Permanently unschedule '%s'?\n\nThis removes both the next firing AND the recurring schedule if any. Cannot be undone — the event will re-appear only if a plugin re-registers it.", 'signal-and-noise-tools' ),
		/* translators: 1: hook name, 2: number of events cleared */
		'unscheduledTemplate' => __( "%1\$s unscheduled (%2\$d event(s) cleared)", 'signal-and-noise-tools' ),
		'unscheduledNoMatch' => __( 'No matching scheduled event found: likely already gone.', 'signal-and-noise-tools' ),
		/* translators: %s is the error message returned by the REST endpoint */
		'unscheduleFailedTemplate' => __( 'Unschedule failed: %s', 'signal-and-noise-tools' ),
		// v3.2.0: cron history panel
		'historyShow'      => __( 'history', 'signal-and-noise-tools' ),
		'historyHide'      => __( 'hide', 'signal-and-noise-tools' ),
		'historyLoading'   => __( 'Loading history…', 'signal-and-noise-tools' ),
		'historyEmpty'     => __( 'No firings recorded yet (history tracking landed in plugin v3.2.0).', 'signal-and-noise-tools' ),
		'historyHeaderTime'    => __( 'Fired at', 'signal-and-noise-tools' ),
		'historyHeaderElapsed' => __( 'Elapsed', 'signal-and-noise-tools' ),
		'historyHeaderStatus'  => __( 'Status', 'signal-and-noise-tools' ),
		'historyOk'        => __( 'ok', 'signal-and-noise-tools' ),
		'historyFail'      => __( 'fail', 'signal-and-noise-tools' ),
		/* translators: %d is the elapsed time in milliseconds */
		'historyMs'        => __( '%dms', 'signal-and-noise-tools' ),
		/* translators: %s is the error message returned by the REST endpoint */
		'historyFetchFailed' => __( 'Could not load history: %s', 'signal-and-noise-tools' ),
	) );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'sn-cron-dashboard', 'signal-and-noise-tools' );
	}
}

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// v4.1.6 (D-11): use the canonical guard from admin-page.php:532. Pre-v3.8.1
	// the cron tab was its own submenu page with hook_suffix containing 'sn-cron';
	// post-v3.8.1 the cron sub-tab lives inside the Automation top-tab page whose
	// hook_suffix is 'signal-noise_page_sn-automation' — the old strpos check was
	// silently never matching, so the cron JS was broken since v3.8.1. Loading on
	// every SN admin page is fine: the JS is a no-op when its selectors don't match.
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	snt_cron_dashboard_register_script();
	wp_enqueue_script( 'sn-cron-dashboard' );
} );

/**
 * Build the first-glance hero cards for the Cron tab from the event rows:
 * total events, the count Signal & Noise owns, and the orphan count (events with
 * no registered handler). Pure — takes the rows, returns sn_admin_glance_grid()
 * cards. Sourced only from the rows already fetched (no extra query).
 *
 * @param array $rows snt_cron_get_events_impl() rows.
 * @return array<int,array<string,mixed>> Cards for sn_admin_glance_grid().
 *
 * @since 6.45.0
 */
function snt_cron_glance_cards( $rows ) {
	$total   = is_array( $rows ) ? count( $rows ) : 0;
	$owned   = 0;
	$orphans = 0;
	foreach ( (array) $rows as $r ) {
		if ( ! empty( $r['is_sn_owned'] ) ) {
			$owned++;
		}
		if ( empty( $r['has_handler'] ) ) {
			$orphans++;
		}
	}
	return array(
		array(
			'label' => 'Scheduled events',
			'value' => (string) $total,
		),
		array(
			'label'     => 'Signal & Noise',
			'value'     => (string) $owned,
			'meta_html' => esc_html( 'plugin-owned' ),
		),
		array(
			'label' => 'Orphans',
			'value' => (string) $orphans,
			'pill'  => array(
				'kind' => $orphans > 0 ? 'warn' : 'ok',
				'text' => $orphans > 0 ? 'no handler' : 'all handled',
			),
		),
	);
}

function snt_cron_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'signal-and-noise-tools' ) );
	}

	$rows = function_exists( 'snt_cron_get_events_impl' )
		? snt_cron_get_events_impl()
		: array();

	echo '<div class="sn-cron-dashboard">';

	if ( empty( $rows ) ) {
		echo '<div class="sn-card"><h3>' . esc_html__( 'No scheduled events.', 'signal-and-noise-tools' ) . '</h3>';
		echo '<p>' . wp_kses_post( __( 'This is unusual. WordPress core typically schedules <code>wp_version_check</code>, <code>wp_update_plugins</code>, <code>wp_update_themes</code>, and <code>wp_scheduled_delete</code> at install. If your cron is empty, something has cleared it. Check your hosting provider\'s cron configuration.', 'signal-and-noise-tools' ) ) . '</p></div></div>';
		return;
	}

	// Glance hero (v6.45.0): events / SN-owned / orphans — first-glance over the
	// full-width table (the leaf is marked 'wide' so the table fills the page).
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="Cron at a glance">';
		sn_admin_glance_grid( snt_cron_glance_cards( $rows ) );
		echo '</section>';
	}

	$count = count( $rows );
	echo '<p class="sn-field-helper">';
	printf(
		/* translators: %s is the number of scheduled cron events */
		esc_html( _n( '%s scheduled event. Signal & Noise–owned events pinned at top.', '%s scheduled events. Signal & Noise–owned events pinned at top.', $count, 'signal-and-noise-tools' ) ),
		esc_html( number_format_i18n( $count ) )
	);
	echo '</p>';

	echo '<p>';
	echo '<label for="sn-cron-filter" class="screen-reader-text">' . esc_html__( 'Filter cron events by hook name', 'signal-and-noise-tools' ) . '</label>';
	echo '<input type="search" id="sn-cron-filter" class="sn-input--filter" placeholder="' . esc_attr__( 'Filter by hook name…', 'signal-and-noise-tools' ) . '" />';
	echo '</p>';

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped" id="sn-cron-table">';
	echo '<caption class="screen-reader-text">' . esc_html__( 'Scheduled cron events with next run time, recurrence, last-fired timestamp, arguments, and per-event actions.', 'signal-and-noise-tools' ) . '</caption>';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Hook', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Next run', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Recurrence', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Last fired', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Args', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actions', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$row_class = $row['is_sn_owned'] ? 'sn-cron-row sn-cron-owned' : 'sn-cron-row';
		echo '<tr class="' . esc_attr( $row_class ) . '" data-hook="' . esc_attr( $row['hook'] ) . '" data-sig="' . esc_attr( $row['args_signature'] ) . '">';

		// Hook
		echo '<th scope="row"><code>' . esc_html( $row['hook'] ) . '</code>';
		if ( $row['is_sn_owned'] ) {
			echo ' <span class="sn-badge" title="' . ( esc_attr__( 'Signal & Noise–owned event', 'signal-and-noise-tools' ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Signal and Noise owned:', 'signal-and-noise-tools' ) . '</span>' . esc_html__( 'SN', 'signal-and-noise-tools' ) . '</span>';
		}
		if ( ! $row['has_handler'] ) {
			echo ' <span class="sn-badge sn-badge-warn" title="' . ( esc_attr__( 'No handler registered: schedule will fire to nothing', 'signal-and-noise-tools' ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Warning:', 'signal-and-noise-tools' ) . '</span>' . esc_html__( 'orphan', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</th>';

		// Next run
		$next_str = wp_date( 'Y-m-d H:i:s', $row['next_run_ts'] );
		$next_rel = human_time_diff( time(), $row['next_run_ts'] );
		echo '<td>' . esc_html( $next_str ) . '<br><small>';
		/* translators: %s is a human-readable relative time, e.g., "5 mins" */
		printf( esc_html__( 'in %s', 'signal-and-noise-tools' ), esc_html( $next_rel ) );
		echo '</small></td>';

		// Recurrence
		if ( $row['schedule'] ) {
			echo '<td>' . esc_html( $row['schedule'] );
			if ( $row['interval_s'] ) {
				echo '<br><small>' . esc_html( human_time_diff( 0, $row['interval_s'] ) ) . '</small>';
			}
			echo '</td>';
		} else {
			echo '<td><small>' . esc_html__( 'single event', 'signal-and-noise-tools' ) . '</small></td>';
		}

		// Last fired
		$history_toggle_aria = sprintf(
			/* translators: %s is the cron hook name */
			__( 'Show firing history for %s', 'signal-and-noise-tools' ),
			$row['hook']
		);
		if ( $row['last_fired_ts'] ) {
			$last_str = wp_date( 'Y-m-d H:i:s', $row['last_fired_ts'] );
			$last_rel = human_time_diff( $row['last_fired_ts'], time() );
			echo '<td class="sn-cron-last-fired">' . esc_html( $last_str ) . '<br><small>';
			/* translators: %s is a human-readable relative time, e.g., "5 mins" */
			printf( esc_html__( '%s ago', 'signal-and-noise-tools' ), esc_html( $last_rel ) );
			echo '</small>';
			// v3.2.0: history toggle.
			echo ' <button type="button" class="button-link sn-cron-history-toggle" aria-expanded="false" aria-label="' . esc_attr( $history_toggle_aria ) . '" title="' . esc_attr__( 'Show recent firings', 'signal-and-noise-tools' ) . '">' . esc_html__( 'history', 'signal-and-noise-tools' ) . '</button>';
			echo '<div class="sn-cron-history-panel" hidden></div>';
			echo '</td>';
		} else {
			echo '<td class="sn-cron-last-fired">&mdash;';
			// v3.2.0: still allow the toggle — history can exist even
			// without a last-fired record (manual one-shot dispatches
			// land in history without updating the last-fired option
			// during edge cases).
			echo ' <button type="button" class="button-link sn-cron-history-toggle" aria-expanded="false" aria-label="' . esc_attr( $history_toggle_aria ) . '" title="' . esc_attr__( 'Show recent firings', 'signal-and-noise-tools' ) . '">' . esc_html__( 'history', 'signal-and-noise-tools' ) . '</button>';
			echo '<div class="sn-cron-history-panel" hidden></div>';
			echo '</td>';
		}

		// Args
		if ( ! empty( $row['args'] ) ) {
			echo '<td><code>' . esc_html( wp_json_encode( $row['args'] ) ) . '</code></td>';
		} else {
			echo '<td><small>&mdash;</small></td>';
		}

		// Actions — aria-label disambiguates which hook each button targets
		// for screen readers (visible labels are repeated per row).
		$run_aria = sprintf(
			/* translators: %s is the cron hook name */
			__( 'Run cron event %s now', 'signal-and-noise-tools' ),
			$row['hook']
		);
		$unschedule_aria = sprintf(
			/* translators: %s is the cron hook name */
			__( 'Unschedule cron event %s', 'signal-and-noise-tools' ),
			$row['hook']
		);
		// Encode the args array as a JSON data attribute so the JS can
		// echo it back unchanged on the REST call. Empty args = '[]'.
		$args_json = wp_json_encode( $row['args'] );
		if ( ! is_string( $args_json ) ) {
			$args_json = '[]';
		}
		echo '<td>';
		if ( ! $row['has_handler'] ) {
			echo '<button class="button button-small" type="button" disabled aria-label="' . esc_attr( $run_aria ) . '" title="' . ( esc_attr__( 'No handler registered', 'signal-and-noise-tools' ) ) . '">' . esc_html__( 'Run now', 'signal-and-noise-tools' ) . '</button>';
		} elseif ( str_starts_with( (string) $row['hook'], 'sn_' ) ) {
			// v6.55.0: Run-now dispatches via the signal-noise/run-cron-event
			// ability (run-path), which refuses sn_* hooks — Signal & Noise's own
			// internal events fire on their own schedule and have dedicated
			// abilities. Disable + explain instead of letting the click resolve to
			// a refusal toast, mirroring the Unschedule gate below.
			echo '<button class="button button-small" type="button" disabled aria-label="' . esc_attr( $run_aria ) . '" title="' . ( esc_attr__( 'Signal & Noise–internal event: dispatched on its own schedule, not manually runnable here', 'signal-and-noise-tools' ) ) . '">' . esc_html__( 'Run now', 'signal-and-noise-tools' ) . '</button>';
		} else {
			echo '<button class="button button-small sn-cron-run-now" type="button" aria-label="' . esc_attr( $run_aria ) . '">' . esc_html__( 'Run now', 'signal-and-noise-tools' ) . '</button>';
		}
		// v3.1.0: Unschedule button. SN-owned events refuse this op via
		// the impl-layer guard, so disable the button + explain why
		// rather than letting the user click into an error.
		echo ' ';
		if ( $row['is_sn_owned'] ) {
			echo '<button class="button button-small" type="button" disabled aria-label="' . esc_attr( $unschedule_aria ) . '" title="' . ( esc_attr__( 'Signal & Noise–owned: disable the owning module from its settings tab instead', 'signal-and-noise-tools' ) ) . '">' . esc_html__( 'Unschedule', 'signal-and-noise-tools' ) . '</button>';
		} else {
			echo '<button class="button button-small button-link-delete sn-cron-unschedule" type="button" aria-label="' . esc_attr( $unschedule_aria ) . '" data-args="' . esc_attr( $args_json ) . '">' . esc_html__( 'Unschedule', 'signal-and-noise-tools' ) . '</button>';
		}
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';  // .snt-scroll-table
	echo '</div>';  // .sn-cron-dashboard
}
