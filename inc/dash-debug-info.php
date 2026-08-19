<?php
/**
 * Signal & Noise — Site Health debug information.
 *
 * Feeds the WordPress Site Health "Info" tab. Nothing here renders on the
 * Dashboard tab itself; it lived in that file only by history.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 4.9.0
 * @param array $info Core's accumulated debug-info panels.
 * @return array
 */
function snt_dashboard_debug_information( $info ) {
	$fields = array();

	// Plugin + theme versions (public).
	$fields['plugin_version'] = array(
		'label' => __( 'Plugin version', 'signal-and-noise-tools' ),
		'value' => defined( 'SNT_VERSION' ) ? SNT_VERSION : '',
	);
	$fields['theme_version'] = array(
		'label' => __( 'Signal & Noise theme version', 'signal-and-noise-tools' ),
		'value' => (string) wp_get_theme( 'signal-and-noise' )->get( 'Version' ),
	);

	// Plugin update state (public).
	if ( function_exists( 'snt_deploy_status_for' ) ) {
		$plugin = snt_deploy_status_for( 'plugin' );
		$fields['plugin_update_state'] = array(
			'label' => __( 'Plugin update state', 'signal-and-noise-tools' ),
			'value' => isset( $plugin['state'] ) ? (string) $plugin['state'] : 'unknown',
		);
	}

	// DB override count (public).
	$fields['db_overrides'] = array(
		'label' => __( 'Database template/navigation overrides', 'signal-and-noise-tools' ),
		'value' => snt_dashboard_override_count(),
	);

	// Cron pipeline summary (private — internal hook names).
	$cron_lines = array();
	$hooks      = function_exists( 'snt_cron_sn_owned_hooks' ) ? snt_cron_sn_owned_hooks() : array();
	foreach ( $hooks as $hook ) {
		$next       = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
		$last_fired = function_exists( 'snt_cron_last_fired_for' ) ? snt_cron_last_fired_for( $hook ) : null;
		$sched      = ( false !== $next && is_numeric( $next ) ) ? __( 'scheduled', 'signal-and-noise-tools' ) : __( 'NOT scheduled', 'signal-and-noise-tools' );
		$fired      = ( null !== $last_fired )
			? sprintf( /* translators: %s: human time diff. */ __( 'fired %s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $last_fired, time() ) )
			: __( 'never', 'signal-and-noise-tools' );
		$cron_lines[] = $hook . ': ' . $sched . ', ' . $fired;
	}
	$fields['cron_pipeline'] = array(
		'label'   => __( 'Cron pipeline', 'signal-and-noise-tools' ),
		'value'   => $cron_lines ? implode( ' | ', $cron_lines ) : __( 'no SN-owned hooks', 'signal-and-noise-tools' ),
		'private' => true,
	);

	// Cron-history table present? (private).
	$fields['cron_history_table'] = array(
		'label'   => __( 'Cron history table installed', 'signal-and-noise-tools' ),
		'value'   => ( defined( 'SNT_CRON_HISTORY_DB_VERSION_OPT' ) && get_option( SNT_CRON_HISTORY_DB_VERSION_OPT ) )
			? __( 'yes', 'signal-and-noise-tools' )
			: __( 'no', 'signal-and-noise-tools' ),
		'private' => true,
	);

	// External-API rate state (private — integration-adjacent).
	if ( function_exists( 'snt_rate_limit_all_statuses' ) ) {
		$rate_lines = array();
		foreach ( snt_rate_limit_all_statuses() as $host => $row ) {
			$snapshot  = isset( $row['snapshot'] ) ? $row['snapshot'] : array();
			$state     = function_exists( 'snt_rate_limit_state' ) ? snt_rate_limit_state( $snapshot ) : 'unknown';
			$label     = isset( $row['label'] ) ? (string) $row['label'] : (string) $host;
			$rate_lines[] = $label . ': ' . $state;
		}
		$fields['api_rate_state'] = array(
			'label'   => __( 'External API rate state', 'signal-and-noise-tools' ),
			'value'   => $rate_lines ? implode( ', ', $rate_lines ) : __( 'none', 'signal-and-noise-tools' ),
			'private' => true,
		);
	}

	// AI availability (private).
	if ( function_exists( 'snt_ai_is_available' ) ) {
		$fields['ai_available'] = array(
			'label'   => __( 'AI provider available', 'signal-and-noise-tools' ),
			'value'   => snt_ai_is_available() ? __( 'yes', 'signal-and-noise-tools' ) : __( 'no', 'signal-and-noise-tools' ),
			'private' => true,
		);
	}

	// Webhooks count (public — counts only, no URLs/secrets).
	if ( function_exists( 'sn_webhooks_all' ) ) {
		$all     = sn_webhooks_all();
		$total   = is_array( $all ) ? count( $all ) : 0;
		$enabled = 0;
		if ( is_array( $all ) ) {
			foreach ( $all as $wh ) {
				if ( ! empty( $wh['enabled'] ) ) { $enabled++; }
			}
		}
		$fields['webhooks'] = array(
			'label' => __( 'Webhooks (total / enabled)', 'signal-and-noise-tools' ),
			'value' => sprintf( '%d / %d', $total, $enabled ),
		);
	}

	// Action Scheduler backlog (private — v9.48.0). Another plugin's queue,
	// but its dispatch-gate COUNT runs on every page load, so its size is an
	// ops concern for this site. Absent module or table degrades gracefully.
	if ( function_exists( 'snt_asb_snapshot' ) ) {
		$fields['as_backlog'] = array(
			'label'   => __( 'Scheduled Actions backlog', 'signal-and-noise-tools' ),
			'value'   => snt_asb_summary_line( snt_asb_snapshot() ),
			'private' => true,
		);
	}

	// Cache state — health-scan presence/age (private).
	$cache_bits = array();
	// v6.47.2: read through the accessor (a durable option since v6.47.2), not a
	// direct get_transient — the scan no longer lives in a transient.
	$health = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	if ( is_array( $health ) && ! empty( $health['scanned_at'] ) ) {
		$cache_bits[] = 'health-scan: ' . human_time_diff( (int) $health['scanned_at'], time() ) . ' ago';
	} else {
		$cache_bits[] = 'health-scan: none';
	}
	$fields['cache_state'] = array(
		'label'   => __( 'Cache state', 'signal-and-noise-tools' ),
		'value'   => implode( '; ', $cache_bits ),
		'private' => true,
	);

	$info['signal-noise-tools'] = array(
		'label'       => __( 'Signal & Noise Tools', 'signal-and-noise-tools' ),
		'description' => __( 'Operational state for the Signal & Noise Tools plugin (versions, cron pipeline, integrations, caches).', 'signal-and-noise-tools' ),
		'fields'      => $fields,
	);

	return $info;
}

/* ════════════════════════════════════════════════════════════════════════
 * SITE HEALTH > INFO panel (v4.9.0, Task 3)
 *
 * Surfaces SN operational state in Tools → Site Health → Info under a
 * "Signal & Noise Tools" panel. Every field is read from an EXISTING
 * getter — no new computation. Integration-adjacent fields (API rate
 * state, AI availability, cron internals) are marked private => true so
 * they're excluded from the "Copy site info to clipboard" export.
 *
 * The registration lives WITH the callback (v11.28.0). A filter registered in
 * one file for a function defined in another resolves fine at runtime and is
 * invisible to anyone reading either file on its own.
 * ════════════════════════════════════════════════════════════════════════ */

add_filter( 'debug_information', 'snt_dashboard_debug_information' );
