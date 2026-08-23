<?php
/**
 * Signal & Noise — admin POST handlers: scheduled and retained reports: audit, security digest, morning brief, scheduled reads.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: audit_save_retention, security_digest_save,
 * morning_brief_save, scheduled_reads_save
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_audit_save_retention( $post ) {
	$raw  = isset( $post['audit_retention_days'] ) ? (int) $post['audit_retention_days'] : 90;
	$days = max( 7, min( 365, $raw ) );
	$ok   = sn_setting_update( 'audit.retention_days', $days );
	return $ok ? 'audit_retention_saved' : 'audit_retention_unchanged';
}

/**
 * v7.2.0: save the weekly security-digest opt-in (Security → Login defense), or
 * send a test digest when the test button submitted. Single-key write via
 * sn_setting_update() (no whole-subtree replace), then immediate cron sync so
 * the toggle takes effect without waiting for the next init.
 */
function sn_handle_security_digest_save( $post ) {
	if ( isset( $post['sn_digest_test'] ) ) {
		return snt_security_digest_send( true ) ? 'digest_test_sent' : 'digest_test_failed';
	}
	sn_setting_update( 'audit.digest_email_enabled', isset( $post['sn_digest_enabled'] ) );
	if ( function_exists( 'snt_security_digest_maybe_schedule_cron' ) ) {
		snt_security_digest_maybe_schedule_cron();
	}
	return 'digest_saved';
}

/** Save/test the Operations brief, or explicitly move the drift baseline. */
function sn_handle_morning_brief_save( $post ) {
	if ( isset( $post['snt_morning_brief_test'] ) ) {
		return snt_morning_brief_send( true ) ? 'morning_brief_test_sent' : 'morning_brief_test_failed';
	}
	if ( isset( $post['snt_config_drift_acknowledge'] ) && function_exists( 'snt_config_drift_acknowledge' ) ) {
		snt_config_drift_acknowledge();
		return 'config_drift_acknowledged';
	}
	sn_setting_update( 'operations.morning_brief_enabled', isset( $post['snt_morning_brief_enabled'] ) );
	if ( function_exists( 'snt_morning_brief_maybe_schedule_cron' ) ) {
		snt_morning_brief_maybe_schedule_cron();
	}
	return 'morning_brief_saved';
}

/** Save the scheduled read-only runs toggle, or run the fixed list now. */
function sn_handle_scheduled_reads_save( $post ) {
	if ( isset( $post['snt_scheduled_reads_now'] ) ) {
		return null !== snt_scheduled_reads_run() ? 'scheduled_reads_ran' : 'scheduled_reads_run_failed';
	}
	sn_setting_update( 'operations.scheduled_reads_enabled', isset( $post['snt_scheduled_reads_enabled'] ) );
	if ( function_exists( 'snt_scheduled_reads_maybe_schedule_cron' ) ) {
		snt_scheduled_reads_maybe_schedule_cron();
	}
	return 'scheduled_reads_saved';
}
