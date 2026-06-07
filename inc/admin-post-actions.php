<?php
/**
 * Signal & Noise — admin POST action handlers.
 *
 * One small function per form action, each fn( array $post ): string that
 * performs the action's side effects (option writes, filter dispatch, module
 * calls) and returns a ?sn_flash=… code. Dispatched by sn_handle_admin_post()
 * (inc/admin-post-handler.php) via the sn_admin_post_handlers() map. Extracted
 * verbatim from the 270-line if/elseif in inc/admin-page.php in v4.5.4.
 *
 * Handlers receive the RAW $_POST and unslash per-field exactly as the original
 * arms did (notably: save_identity passes the raw array straight to
 * sn_settings_save(), which is the pre-existing behavior — do not "fix" it).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_clear_overrides( $post ) {
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	return 'cleared_' . $count;
}

function sn_handle_purge_caches( $post ) {
	apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
	return 'purged';
}

function sn_handle_full_reset( $post ) {
	// v4.1.1 (D-07): pass explicit template_overrides=true rather than an
	// empty args array. "Full reset" semantically includes template overrides;
	// being explicit prevents drift if the theme tightens its filter contract.
	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => true ) );
	return 'reset_' . $count;
}

function sn_handle_save_identity( $post ) {
	$saved = sn_settings_save( $post );
	return $saved ? 'identity_saved' : 'identity_unchanged';
}

function sn_handle_save_login( $post ) {
	$slug = isset( $post['login_slug'] ) ? sanitize_title( wp_unslash( $post['login_slug'] ) ) : '';
	if ( ! $slug ) {
		return 'login_empty';
	}
	// v4.2.0 (D-06): write via sn_setting_update() so the per-request static
	// cache is busted — any sn_setting() call later in this request sees the
	// new slug.
	$ok = sn_setting_update( 'login.slug', $slug );
	return $ok ? 'login_saved' : 'login_failed';
}

function sn_handle_pl_save( $post ) {
	// Constant-locked field: short-circuit the save so admin edits can't
	// override wp-config. Matches the locked-field-disabled pattern on Login.
	if ( defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) && SN_PLAUSIBLE_STATS_TOKEN ) {
		return 'pl_locked';
	}
	$new_token = isset( $post['sn_pl_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_pl_token'] ) ) : '';
	if ( 'clear' === $new_token ) {
		delete_option( SN_PLAUSIBLE_TOKEN_OPT );
		sn_pl_admin_invalidate_caches();
		return 'pl_cleared';
	} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
		update_option( SN_PLAUSIBLE_TOKEN_OPT, $new_token, false ); // not autoloaded
		sn_pl_admin_invalidate_caches();
		return 'pl_saved';
	}
	// Empty submission with the obscured placeholder = leave alone.
	return 'pl_unchanged';
}

function sn_handle_pl_test( $post ) {
	$cfg = sn_plausible_config();
	if ( ! $cfg ) {
		return 'pl_test_unconfigured';
	}
	delete_transient( SN_PLAUSIBLE_ERR_KEY ); // force-fresh
	$result = sn_plausible_api( 'aggregate', array( 'period' => '7d', 'metrics' => 'visitors' ), $cfg );
	return is_array( $result ) ? 'pl_test_ok' : 'pl_test_err';
}

function sn_handle_cf_save( $post ) {
	$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

	if ( ! $token_const ) {
		$new_token = isset( $post['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( SN_CF_TOKEN_OPT );
		} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
			update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
		}
	}
	if ( ! $zone_const ) {
		$new_zone = isset( $post['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_zone'] ) ) : '';
		if ( 'clear' === $new_zone ) {
			delete_option( SN_CF_ZONE_OPT );
		} elseif ( '' !== $new_zone ) {
			update_option( SN_CF_ZONE_OPT, $new_zone, true );
		}
	}
	return 'cf_saved';
}

function sn_handle_cf_purge_now( $post ) {
	return sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
}

function sn_handle_apply_reading_time_cleanup( $post ) {
	$count = (int) sn_apply_legacy_reading_time_cleanup();
	return 'rt_applied_' . $count;
}

function sn_handle_health_scan( $post ) {
	// v3.5.1: route through the central dispatcher per the established pattern.
	// The impl module owns the work; this handler just dispatches + sets flash.
	if ( function_exists( 'sn_health_run_scan' ) ) {
		sn_health_run_scan();
	}
	return 'health_scanned';
}

function sn_handle_webhook_add( $post ) {
	if ( function_exists( 'sn_webhook_create' ) ) {
		$result = sn_webhook_create( wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_invalid';
		}
		// Encode new id in the flash so the renderer can show the secret once.
		return 'wh_added_' . $result['id'];
	}
	return 'wh_invalid';
}

function sn_handle_webhook_update( $post ) {
	if ( function_exists( 'sn_webhook_update' ) ) {
		$id     = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		$rotate = ! empty( $post['rotate_secret'] );
		$result = sn_webhook_update( $id, wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_not_found';
		}
		return $rotate ? ( 'wh_rotated_' . $id ) : 'wh_updated';
	}
	return 'wh_not_found';
}

function sn_handle_webhook_delete( $post ) {
	if ( function_exists( 'sn_webhook_delete' ) ) {
		$id = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		sn_webhook_delete( $id );
	}
	return 'wh_deleted';
}

function sn_handle_insights_run( $post ) {
	if ( function_exists( 'snt_insights_run_scan' ) ) {
		$force  = ! empty( $post['force'] );
		$result = snt_insights_run_scan( $force );
		return is_wp_error( $result ) ? 'insights_failed' : 'insights_scanned';
	}
	return 'insights_failed';
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

function sn_handle_audit_save_retention( $post ) {
	$raw  = isset( $post['audit_retention_days'] ) ? (int) $post['audit_retention_days'] : 90;
	$days = max( 7, min( 365, $raw ) );
	$ok   = sn_setting_update( 'audit.retention_days', $days );
	return $ok ? 'audit_retention_saved' : 'audit_retention_unchanged';
}

function sn_handle_pattern_adoption_scan( $post ) {
	// v4.3.0: routes through the central dispatcher per the health_scan pattern.
	if ( function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		snt_pattern_adoption_run_scan();
	}
	return 'pattern_adoption_scanned';
}

function sn_handle_block_migrations_scan( $post ) {
	// v4.5.0: mirrors the pattern_adoption_scan dispatcher.
	if ( function_exists( 'snt_block_migrations_run_scan' ) ) {
		snt_block_migrations_run_scan();
	}
	return 'block_migrations_scanned';
}

/**
 * v4.9.0 (T4): save the Uptime Kuma heartbeat settings from the Webhooks tab.
 * Writes through sn_setting_update('monitoring.*', …) then reconciles the
 * cron schedule immediately so toggling on/off takes effect without waiting
 * for the next init.
 */
function sn_handle_monitoring_save( $post ) {
	$enabled = ! empty( $post['uptime_kuma_enabled'] );
	$url     = isset( $post['uptime_kuma_push_url'] )
		? esc_url_raw( trim( (string) wp_unslash( $post['uptime_kuma_push_url'] ) ) )
		: '';

	// T4 (Fix C): enforce https on the push URL, matching the UI's "Must be
	// https://" hint. esc_url_raw permits http/ftp/etc.; an http push URL would
	// leak the monitor token over the wire. Reject + clear + flash an error.
	$had = ( '' !== $url );
	if ( $had && 0 !== stripos( $url, 'https://' ) ) {
		$url = '';
		sn_setting_update( 'monitoring.uptime_kuma_enabled', $enabled );
		sn_setting_update( 'monitoring.uptime_kuma_push_url', $url );
		if ( function_exists( 'sn_uptime_heartbeat_schedule' ) ) {
			sn_uptime_heartbeat_schedule();
		}
		return 'monitoring_url_not_https';
	}

	sn_setting_update( 'monitoring.uptime_kuma_enabled', $enabled );
	sn_setting_update( 'monitoring.uptime_kuma_push_url', $url );

	// Apply the schedule change now (the init-time reconciler already ran).
	if ( function_exists( 'sn_uptime_heartbeat_schedule' ) ) {
		sn_uptime_heartbeat_schedule();
	}

	return 'monitoring_saved';
}

/**
 * v4.10.0 (T6): save the Speculation Rules toggle from the Tools → Performance
 * sub-tab. Writes the boolean through sn_setting_update('perf.speculative_loading',
 * …); the wp_speculation_rules_configuration filter reads it on the next page load.
 */
function sn_handle_perf_save( $post ) {
	$enabled = ! empty( $post['speculative_loading'] );
	sn_setting_update( 'perf.speculative_loading', $enabled );
	return 'perf_saved';
}

/**
 * v4.11.0 (T4): draft Mimestream-style release notes from a pasted CHANGELOG
 * delta. The dispatcher PRG-redirects, so the generated markdown (or the AI
 * error message) is stashed in a short per-user transient that
 * sn_admin_render_release_notes_section() reads back + clears for redisplay.
 */
function sn_handle_release_notes_draft( $post ) {
	$delta = isset( $post['changelog_delta'] )
		? sanitize_textarea_field( wp_unslash( $post['changelog_delta'] ) )
		: '';

	if ( ! function_exists( 'snt_release_notes_draft_impl' ) || ! function_exists( 'sn_release_notes_result_key' ) ) {
		return 'release_notes_failed';
	}

	$result = snt_release_notes_draft_impl( $delta );

	if ( is_wp_error( $result ) ) {
		set_transient(
			sn_release_notes_result_key(),
			array(
				'delta' => $delta,
				'error' => $result->get_error_message(),
			),
			5 * MINUTE_IN_SECONDS
		);
		return 'release_notes_failed';
	}

	set_transient(
		sn_release_notes_result_key(),
		array(
			'delta'  => $delta,
			'result' => (string) $result,
		),
		5 * MINUTE_IN_SECONDS
	);
	return 'release_notes_drafted';
}
