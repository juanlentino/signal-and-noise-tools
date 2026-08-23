<?php
/**
 * Signal & Noise — admin POST handlers: health scan and the insights queue.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: health_scan, insights_run, insights_dismiss,
 * insights_snooze, insights_mark_done, save_insights_settings
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_health_scan( $post ) {
	// v3.5.1: route through the central dispatcher per the established pattern.
	// The impl module owns the work; this handler just dispatches + sets flash.
	if ( function_exists( 'sn_health_run_scan' ) ) {
		$scan = sn_health_run_scan();
		// v8.0.1: findings-aware flash. The runner returns the fresh scan, so
		// the count is free here — a clean run must not promise "findings below".
		if ( is_array( $scan ) && function_exists( 'sn_health_finding_total' ) && 0 === sn_health_finding_total( $scan ) ) {
			return 'health_scanned_clean';
		}
	}
	return 'health_scanned';
}

function sn_handle_insights_run( $post ) {
	if ( ! function_exists( 'snt_insights_run_scan' ) ) {
		return 'insights_failed';
	}
	$force  = ! empty( $post['force'] );
	$result = snt_insights_run_scan( $force );

	if ( is_wp_error( $result ) ) {
		// v7.0.1: record the REAL error so the admin notice can report it. The
		// old handler collapsed EVERY WP_Error to the blanket "configure an AI
		// provider" copy, so a parse error, a transport timeout, or an empty
		// response all read as "you haven't set up AI" even when AI is
		// configured + billing (the weekly digest, same transport, works).
		if ( function_exists( 'snt_insights_store_last_error' ) ) {
			snt_insights_store_last_error( $result );
		}
		// Only a genuine "no AI provider configured" failure earns the
		// configure-AI copy; every other (insights-specific) failure surfaces
		// its real code + message via the 'insights_failed' live-data notice.
		return 'snt_insights_ai_unavailable' === $result->get_error_code()
			? 'insights_ai_unavailable'
			: 'insights_failed';
	}

	// Success: drop any stale diagnostic from a prior failed run.
	if ( function_exists( 'snt_insights_clear_last_error' ) ) {
		snt_insights_clear_last_error();
	}
	return 'insights_scanned';
}

function sn_handle_insights_dismiss( $post ) {
	if ( function_exists( 'snt_insights_dismiss' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_dismiss( $id );
	}
	return 'insights_dismissed';
}

function sn_handle_insights_snooze( $post ) {
	if ( function_exists( 'snt_insights_snooze' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_snooze( $id );
	}
	return 'insights_snoozed';
}

function sn_handle_insights_mark_done( $post ) {
	if ( function_exists( 'snt_insights_mark_done' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_mark_done( $id );
	}
	return 'insights_done';
}

function sn_handle_save_insights_settings( $post ) {
	// v4.2.0 (D-06): write via sn_setting_update() — busts the per-request
	// cache so the cron sync below reads back the new value.
	$enabled = ! empty( $post['insights_weekly_cron'] );
	sn_setting_update( 'insights.weekly_cron_enabled', $enabled );

	// Sync the cron schedule with the new setting.
	if ( $enabled ) {
		if ( function_exists( 'snt_insights_maybe_schedule_weekly_cron' ) ) {
			snt_insights_maybe_schedule_weekly_cron();
		}
	} else {
		if ( function_exists( 'snt_insights_unschedule_weekly_cron' ) ) {
			snt_insights_unschedule_weekly_cron();
		}
	}

	return 'insights_settings_saved';
}
