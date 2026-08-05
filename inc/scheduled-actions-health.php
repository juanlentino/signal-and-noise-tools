<?php
/**
 * Action Scheduler backlog diagnostic (Site Health).
 *
 * WHY: Action Scheduler (bundled by several third-party plugins) gates its
 * async queue runner with a COUNT of pending-and-due actions on essentially
 * EVERY page load, front and admin. That query's cost scales with the
 * `{prefix}actionscheduler_actions` table, and this site's dead-cron era
 * (see inc/cron-dashboard.php's starvation test) let both the pending
 * backlog and the completed-row retention balloon unprocessed — a 68.7 ms
 * dispatch-gate count was observed live in Query Monitor. The table is not
 * ours to prune: AS's own QueueCleaner drains it now that cron runs. What
 * the site's ops plugin CAN do is observe it — name the backlog, flag it
 * when it is the thing taxing every page, and let the owner watch it drain.
 *
 * Read-only by design: two SELECTs (a status GROUP BY + an overdue COUNT),
 * fired ONLY from Site Health surfaces (the async status test's REST
 * callback and the Info panel row in inc/admin-tab-dashboard.php) — never
 * on ordinary page loads, which are exactly what a bloated table taxes.
 *
 * @package SignalNoiseTools
 * @since 9.48.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Overdue-pending actions before the Site Health test recommends attention. */
const SN_ASB_OVERDUE_WARN = 50;

/** Total stored actions before the table counts as bloated. */
const SN_ASB_ROWS_WARN = 100000;

/**
 * Snapshot the Action Scheduler actions table: per-status counts, total,
 * and how many pending actions are already overdue (scheduled_date_gmt at
 * or before "now"). Returns null when the table doesn't exist — absence is
 * a first-class answer ("not installed"), never a row of invented zeros.
 *
 * $db and $now_gmt are only ever overridden by tests; production always
 * reads the real $wpdb and the wall clock.
 *
 * @param wpdb|object|null $db      Override for tests; defaults to $wpdb.
 * @param int|null         $now_gmt Override for tests; defaults to time().
 * @return array{counts:array<string,int>,total:int,overdue_pending:int}|null
 */
function snt_asb_snapshot( $db = null, $now_gmt = null ) {
	$db = null !== $db ? $db : ( $GLOBALS['wpdb'] ?? null );
	if ( ! is_object( $db ) ) {
		return null;
	}

	$table = $db->prefix . 'actionscheduler_actions';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only existence probe, Site Health surfaces only.
	$exists = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return null;
	}

	$counts = array();
	$total  = 0;
	// Table name is $wpdb->prefix + a fixed literal — no user input can reach it.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate on another plugin's table, Site Health surfaces only.
	$rows = $db->get_results( "SELECT status, COUNT(*) AS n FROM `{$table}` GROUP BY status", ARRAY_A );
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';
		if ( '' === $status ) {
			continue;
		}
		$n                 = isset( $row['n'] ) ? (int) $row['n'] : 0;
		$counts[ $status ] = $n;
		$total            += $n;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', is_numeric( $now_gmt ) ? (int) $now_gmt : time() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only aggregate on another plugin's table, Site Health surfaces only.
	$overdue = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending' AND scheduled_date_gmt <= %s", $cutoff ) );

	return array(
		'counts'          => $counts,
		'total'           => $total,
		'overdue_pending' => $overdue,
	);
}

/**
 * One-line snapshot summary for the Site Health Info panel row. Pure
 * formatting over raw AS status identifiers (DB enum values, not prose) —
 * untranslated by module stance, mirroring sn_httpdiag_format_call().
 *
 * @param array|null $snapshot A snt_asb_snapshot() result.
 * @return string e.g. "pending 12 (3 overdue) | complete 1204 | failed 8 | total 1224".
 */
function snt_asb_summary_line( $snapshot ) {
	if ( null === $snapshot || ! is_array( $snapshot ) ) {
		return 'Action Scheduler not installed';
	}

	$parts   = array();
	$overdue = (int) ( $snapshot['overdue_pending'] ?? 0 );
	foreach ( (array) ( $snapshot['counts'] ?? array() ) as $status => $n ) {
		$part = $status . ' ' . (int) $n;
		if ( 'pending' === $status && $overdue > 0 ) {
			$part .= ' (' . $overdue . ' overdue)';
		}
		$parts[] = $part;
	}
	$parts[] = 'total ' . (int) ( $snapshot['total'] ?? 0 );

	return implode( ' | ', $parts );
}

/**
 * The Site Health test result. GOOD when Action Scheduler is absent or the
 * table is small and current; RECOMMENDED (never critical — this is perf
 * hygiene, not an outage) when the overdue-pending backlog or the total
 * row count crosses its threshold. The description always carries the raw
 * counts so the owner can watch the numbers drain across visits.
 *
 * @param wpdb|object|null $db      Override for tests; defaults to $wpdb.
 * @param int|null         $now_gmt Override for tests; defaults to time().
 * @return array The shape core's Site Health status tests expect.
 */
function snt_asb_site_health_result( $db = null, $now_gmt = null ) {
	$snapshot = snt_asb_snapshot( $db, $now_gmt );

	$lines  = array();
	$status = 'good';

	if ( null === $snapshot ) {
		$lines[] = esc_html__( 'Action Scheduler is not installed on this site: nothing to check.', 'signal-and-noise-tools' );
	} else {
		$lines[] = esc_html( snt_asb_summary_line( $snapshot ) );

		if ( $snapshot['overdue_pending'] >= SN_ASB_OVERDUE_WARN ) {
			$status  = 'recommended';
			$lines[] = sprintf(
				/* translators: %d: number of pending scheduled actions already past their run date. */
				esc_html__( '%d pending actions are overdue. A backlog this size usually means the queue runner is not keeping up (this site\'s cron was disabled for a long stretch); it should drain now that cron runs: if the number is not shrinking across visits, inspect Tools → Scheduled Actions for failing recurring actions.', 'signal-and-noise-tools' ),
				(int) $snapshot['overdue_pending']
			);
		}

		if ( $snapshot['total'] >= SN_ASB_ROWS_WARN ) {
			$status  = 'recommended';
			$lines[] = sprintf(
				/* translators: %d: total rows in the actionscheduler_actions table. */
				esc_html__( 'The actions table holds %d rows. Action Scheduler counts pending-and-due actions on every page load, so a table this large taxes every request; its cleaner purges old completed actions now that cron runs, or prune retained rows from Tools → Scheduled Actions.', 'signal-and-noise-tools' ),
				(int) $snapshot['total']
			);
		}
	}

	$actions = '';
	if ( null !== $snapshot && class_exists( 'ActionScheduler' ) ) {
		$actions = '<p><a href="' . esc_url( admin_url( 'tools.php?page=action-scheduler' ) ) . '">'
			. esc_html__( 'Open Scheduled Actions', 'signal-and-noise-tools' ) . '</a></p>';
	}

	return array(
		'label'       => __( 'Action Scheduler backlog', 'signal-and-noise-tools' ),
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Performance', 'signal-and-noise-tools' ),
			'color' => 'blue',
		),
		'description' => '<p>' . wp_kses_post( implode( '<br>', $lines ) ) . '</p>',
		'actions'     => $actions,
		'test'        => 'sn_as_backlog',
	);
}

/**
 * `site_status_tests` filter: register as an async test (the counts run
 * against a table whose whole problem may be its size — never inline them
 * into the Status page render; mirrors snt_cron_register_site_health_test).
 *
 * @param array $tests Core's accumulated Site Health tests.
 * @return array
 */
function snt_asb_register_site_health_test( $tests ) {
	$tests['async']['sn_as_backlog'] = array(
		'label'    => __( 'Action Scheduler backlog', 'signal-and-noise-tools' ),
		'test'     => rest_url( 'signal-noise/v1/site-health/scheduled-actions' ),
		'has_rest' => true,
	);
	return $tests;
}

/**
 * `rest_api_init` callback: the endpoint the async test polls.
 * manage_options-gated, same floor as the cron test's route.
 */
function snt_asb_register_rest_route() {
	register_rest_route(
		'signal-noise/v1',
		'/site-health/scheduled-actions',
		array(
			'methods'             => 'GET',
			'callback'            => 'snt_asb_site_health_rest',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}

/**
 * REST callback — production entry point, no overrides.
 *
 * @return array
 */
function snt_asb_site_health_rest() {
	return snt_asb_site_health_result();
}

if ( function_exists( 'add_action' ) ) {
	add_filter( 'site_status_tests', 'snt_asb_register_site_health_test' );
	add_action( 'rest_api_init', 'snt_asb_register_rest_route' );
}
