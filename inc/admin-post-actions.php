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

function sn_handle_cf_save( $post ) {
	$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

	if ( ! $token_const ) {
		$new_token = isset( $post['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( SN_CF_TOKEN_OPT );
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
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

function sn_handle_narration_run( $post ) {
	if ( ! function_exists( 'snt_narration_run' ) ) {
		return 'narration_failed';
	}
	$force  = ! empty( $post['force'] );
	$result = snt_narration_run( $force );
	if ( is_wp_error( $result ) ) {
		// v7.2.2: record the REAL error (the insights v7.0.1 pattern) so the
		// notice can report it. Only the genuine no-provider code earns the
		// configure-AI copy; a parse/transport/empty failure is digest-specific.
		if ( function_exists( 'snt_narration_store_last_error' ) ) {
			snt_narration_store_last_error( $result );
		}
		return 'snt_ai_unavailable' === $result->get_error_code() ? 'narration_ai_unavailable' : 'narration_failed';
	}
	if ( function_exists( 'snt_narration_clear_last_error' ) ) {
		snt_narration_clear_last_error();
	}
	return 'narration_generated';
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

	// v6.30.0: weekly digest-narration opt-in rides the same Settings form.
	$narration = ! empty( $post['insights_narration'] );
	sn_setting_update( 'insights.narration_enabled', $narration );
	if ( function_exists( 'snt_narration_maybe_schedule_cron' ) ) {
		// Self-healing: schedules when on+unscheduled, unschedules when off+scheduled.
		snt_narration_maybe_schedule_cron();
	}

	return 'insights_settings_saved';
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

/**
 * v7.5.0: save (or clear) the /now page content (Content → Now Page).
 * Whitespace-only input clears the override — /now reverts to the theme's
 * built-in file content. sanitize_textarea_field per line keeps the document
 * plain text (the theme escapes every item at the render sink anyway).
 */
function sn_handle_now_save( $post ) {
	if ( ! function_exists( 'sn_now_page_save' ) ) {
		return 'now_failed';
	}
	$raw = isset( $post['now_content'] ) ? (string) wp_unslash( $post['now_content'] ) : '';
	// sanitize_textarea_field would collapse the newlines we parse on — run it
	// per line instead (strips tags/control chars, keeps the line structure).
	$lines = preg_split( '/\R/u', $raw );
	$raw   = implode( "\n", array_map( 'sanitize_textarea_field', is_array( $lines ) ? $lines : array() ) );

	if ( '' === trim( $raw ) ) {
		sn_now_page_save( '' );
		return 'now_cleared';
	}
	if ( empty( sn_now_parse_sections( $raw ) ) ) {
		// Refuse saves that would parse to nothing — the filter guard would
		// keep the live page on theme content anyway, but a silent "saved"
		// here would lie about what /now is rendering.
		return 'now_unparseable';
	}
	return sn_now_page_save( $raw ) ? 'now_saved' : 'now_unchanged';
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
 * v4.12.0: the AI text-generation model allowlist (single source for the
 * Front-End form's <select> AND the save handler's validation). Keys are the
 * model ids passed to the snt_ai_model_preference filter; values are UI labels.
 *
 * Ids are the alias form (no date suffix), verified Active against the
 * claude-api model catalog: Sonnet 5 (default), Sonnet 4.6 (previous), Opus 4.8
 * (most capable), Haiku 4.5 (fastest/cheapest). v6.52.0: this stays a small
 * hand-maintained list rather than a live enumeration. The WP AI Client exposes
 * no public model-list helper (only an SDK-internal registry path that hits the
 * network on admin render and is untestable in CI), so a curated allowlist keeps
 * the picker priced, predictable, and testable. Loaded unconditionally at
 * bootstrap, so it is available on the front end too (sn_tf_ai_model() calls it
 * during AI requests).
 *
 * @return array<string,string>
 */
function sn_theme_ai_models() {
	return array(
		'claude-sonnet-5'   => 'Claude Sonnet 5 (balanced, default)',
		'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (balanced, previous)',
		'claude-opus-4-8'   => 'Claude Opus 4.8 (most capable)',
		'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fastest, cheapest)',
	);
}

/**
 * Curated vision-capable model allowlist for the alt-text route (v7.3.0).
 * Same contract as sn_theme_ai_models(): keys are wp-ai-client model ids
 * (Gemini ids resolve live from the provider), values are UI labels. The
 * default pin matches the ai-bootstrap alt-text route.
 *
 * @return array<string,string>
 */
function sn_theme_ai_vision_models() {
	return array(
		'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (default — fast, cheap vision)',
		'gemini-2.5-flash'      => 'Gemini 2.5 Flash (stronger vision)',
		'gemini-2.5-pro'        => 'Gemini 2.5 Pro (strongest — slower, pricier)',
	);
}

/**
 * v4.12.0: persist the Front-End settings form (Tools → Front-End sub-tab).
 *
 * Sparse writes via sn_setting_update() so the sibling sn_settings subtrees are
 * never clobbered (same whole-option-replace hazard the audit/monitoring/perf
 * handlers avoid). Ints are clamped to the same bounds the theme-filter
 * callbacks enforce; the model select is VALIDATED against the allowlist
 * (validation > sanitization) and falls back to the current value (then the
 * first allowlisted id) when an off-list id is posted.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_save_theme( $post ) {
	$ok  = sn_setting_update( 'theme.related_count', max( 1, min( 12, (int) ( $post['theme_related_count'] ?? 3 ) ) ) );
	$ok &= sn_setting_update( 'theme.palette_recent_count', max( 0, min( 20, (int) ( $post['theme_palette_recent_count'] ?? 8 ) ) ) );
	$ok &= sn_setting_update( 'theme.palette_enabled', ! empty( $post['theme_palette_enabled'] ) );
	$ok &= sn_setting_update( 'theme.json_feed_items', max( 1, min( 50, (int) ( $post['theme_json_feed_items'] ?? 20 ) ) ) );
	$ok &= sn_setting_update( 'theme.updated_threshold_days', max( 1, min( 90, (int) ( $post['theme_updated_threshold_days'] ?? 14 ) ) ) );
	$ok &= sn_setting_update( 'theme.reading_wpm', max( 100, min( 400, (int) ( $post['theme_reading_wpm'] ?? 225 ) ) ) );
	$ok &= sn_setting_update( 'theme.notes_per_page', max( 1, min( 100, (int) ( $post['theme_notes_per_page'] ?? 20 ) ) ) );

	$allowed = array_keys( sn_theme_ai_models() );
	$model   = isset( $post['theme_ai_model'] ) ? sanitize_text_field( wp_unslash( $post['theme_ai_model'] ) ) : '';
	$ok     &= sn_setting_update( 'theme.ai_model', in_array( $model, $allowed, true ) ? $model : (string) sn_setting( 'theme.ai_model', $allowed[0] ) );

	// v7.3.0: vision (alt-text) model — same validate-against-allowlist pattern;
	// an off-list id keeps the current value (then the pinned default).
	$vision_allowed = array_keys( sn_theme_ai_vision_models() );
	$vision         = isset( $post['theme_ai_alt_model'] ) ? sanitize_text_field( wp_unslash( $post['theme_ai_alt_model'] ) ) : '';
	$ok            &= sn_setting_update( 'theme.ai_alt_model', in_array( $vision, $vision_allowed, true ) ? $vision : (string) sn_setting( 'theme.ai_alt_model', $vision_allowed[0] ) );

	return $ok ? 'theme_saved' : 'theme_unchanged';
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

/**
 * v4.13.0 (Music Identity, T6): save ONE masked, constant-lockable credential.
 *
 * Shared by the Spotify client id + secret. Mirrors the cf_save per-field
 * pattern (locked fields skip; 'clear' deletes) BUT fixes the masked-skip check:
 * the obscured value is "••••" + last 4 chars, so the placeholder is detected
 * with 0 === strpos($v, '••••'), NOT substr($v, 0, 4) (a bullet is 3 bytes, so
 * substr cuts mid-character and the comparison never matches — which would
 * persist the literal placeholder). Returns the running $changed flag, OR'd with
 * whether THIS field actually changed (update_option returns false when the
 * value is identical, so an unedited save reports music_unchanged).
 *
 * @param array  $post    Raw $_POST.
 * @param string $field   POST field name.
 * @param string $opt     Option key.
 * @param string $const   wp-config constant name that locks this field.
 * @param bool   $changed Running changed flag.
 * @return bool Updated changed flag.
 */
function sn_music_save_cred( $post, $field, $opt, $const, $changed ) {
	if ( defined( $const ) && constant( $const ) ) {
		return $changed; // locked by wp-config — admin edits are ignored.
	}
	$value = isset( $post[ $field ] ) ? sanitize_text_field( wp_unslash( $post[ $field ] ) ) : '';
	if ( 'clear' === $value ) {
		delete_option( $opt );
		return true;
	}
	// Skip the masked placeholder (leaves the stored value untouched). A real
	// pasted value never begins with the bullet run.
	if ( '' !== $value && 0 !== strpos( $value, '••••' ) && update_option( $opt, $value, false ) ) {
		return true;
	}
	return $changed;
}

/**
 * v4.13.0 (Music Identity, T6): save the Monitoring → Music credentials.
 *
 * Spotify client id + secret (masked, non-autoloaded, constant-lockable via
 * SN_SPOTIFY_CLIENT_ID / SN_SPOTIFY_CLIENT_SECRET) + the Muso profile id (not
 * secret — it's in the public Muso URL — but still constant-lockable via
 * SN_MUSO_PROFILE_ID). No Muso credential exists: the data source is the
 * unauthenticated public endpoint. Drops the cached Spotify token on any change
 * so the next sync re-authenticates.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_music_save( $post ) {
	// v4.14.0: featured-release URL — validate BEFORE any write so a bad paste
	// errors cleanly instead of partially saving the other fields.
	$raw_featured    = isset( $post['sn_music_featured'] ) ? trim( (string) wp_unslash( $post['sn_music_featured'] ) ) : '';
	$featured_parsed = null;
	if ( '' !== $raw_featured && 'clear' !== $raw_featured ) {
		$featured_parsed = function_exists( 'sn_music_featured_parse' ) ? sn_music_featured_parse( $raw_featured ) : null;
		if ( ! $featured_parsed ) {
			return 'music_featured_invalid';
		}
	}

	$changed = false;
	$changed = sn_music_save_cred( $post, 'sn_spotify_id', SN_SPOTIFY_ID_OPT, 'SN_SPOTIFY_CLIENT_ID', $changed );
	$changed = sn_music_save_cred( $post, 'sn_spotify_secret', SN_SPOTIFY_SECRET_OPT, 'SN_SPOTIFY_CLIENT_SECRET', $changed );

	// Muso profile id — plain (no mask), constant-lockable.
	if ( ! ( defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID ) ) {
		$pid = isset( $post['sn_muso_profile'] ) ? sanitize_text_field( wp_unslash( $post['sn_muso_profile'] ) ) : '';
		if ( 'clear' === $pid ) {
			delete_option( SN_MUSO_PROFILE_OPT );
			$changed = true;
		} elseif ( '' !== $pid && update_option( SN_MUSO_PROFILE_OPT, $pid, false ) ) {
			$changed = true;
		}
	}

	// Featured release — apply (validated above).
	if ( defined( 'SN_MUSIC_FEATURED_OPT' ) ) {
		if ( 'clear' === $raw_featured ) {
			delete_option( SN_MUSIC_FEATURED_OPT );
			$changed = true;
		} elseif ( is_array( $featured_parsed ) && update_option( SN_MUSIC_FEATURED_OPT, $featured_parsed, false ) ) {
			$changed = true;
		}
	}

	if ( $changed && function_exists( 'sn_spotify_invalidate_token' ) ) {
		sn_spotify_invalidate_token(); // creds changed → force re-auth next sync.
	}
	return $changed ? 'music_saved' : 'music_unchanged';
}

/**
 * v4.13.0 (Music Identity, T6): run a discography sync on demand ("Sync now").
 * Routes through the central orchestrator; a false return means the source
 * failed and the last-good store was preserved (page never blanks).
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_music_sync( $post ) {
	if ( ! function_exists( 'sn_discography_run_sync' ) ) {
		return 'music_sync_failed';
	}
	return sn_discography_run_sync() ? 'music_synced' : 'music_sync_failed';
}

/**
 * Commit a tag merge (POSTed from the Content > Tags confirm panel). The central
 * dispatcher already verified the nonce + manage_options. Returns a ?sn_flash code.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_tag_merge( $post ) {
	$from = array_filter( array_map( 'intval', explode( ',', isset( $post['sn_tag_from'] ) ? sanitize_text_field( wp_unslash( $post['sn_tag_from'] ) ) : '' ) ) );
	$into = isset( $post['sn_tag_into'] ) ? (int) $post['sn_tag_into'] : 0;
	if ( ! $from || ! $into || ! function_exists( 'sn_tag_merge' ) ) {
		return 'tag_merge_error';
	}
	$res = sn_tag_merge( $from, $into );
	return is_wp_error( $res ) ? 'tag_merge_error' : 'tag_merge_ok';
}

/**
 * Run the AI tag-suggestion pass over untagged Notes; store the results in a
 * per-user transient for review. Returns a flash code.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_ai_suggest( $post ) {
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return 'tag_ai_unavailable';
	}
	if ( ! function_exists( 'sn_tag_untagged_notes' ) || ! function_exists( 'snt_ai_tag_suggest_impl' ) ) {
		return 'tag_ai_none';
	}
	$results = array();
	foreach ( sn_tag_untagged_notes( 20 ) as $note ) {
		$out = snt_ai_tag_suggest_impl( (int) $note['id'] );
		if ( ! is_wp_error( $out ) && ! empty( $out['suggested'] ) ) {
			$out['title'] = (string) $note['title'];
			$results[]    = $out;
		}
	}
	if ( ! $results ) {
		return 'tag_ai_none';
	}
	set_transient( 'sn_tag_ai_suggestions_' . get_current_user_id(), $results, HOUR_IN_SECONDS );
	return 'tag_ai_suggested';
}

/**
 * Apply the AI tag suggestions the owner checked. Reads assign[post_id][] = term_id.
 *
 * SECURITY (v6.39.2): the POSTed assign map is fully attacker-controllable, so
 * it is NOT trusted directly. The cached suggestion transient written by
 * sn_handle_tag_ai_suggest() is the authoritative allow-list — a (post,term)
 * pair is applied ONLY when:
 *   1. SN proposed that exact term for that exact post in this user's last scan,
 *   2. the post is an editable Note (post_type 'post' — the only type the
 *      suggester scans; never a page/CPT/attachment), and
 *   3. the current user can edit_post that specific post (per-resource cap, not
 *      a blanket manage_options — the dispatcher already checked the nonce).
 * Submitted term ids are intersected with the suggested set for that post, so a
 * forged term riding alongside a legitimate one is dropped, not applied.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_ai_apply( $post ) {
	$assign = isset( $post['assign'] ) && is_array( $post['assign'] ) ? wp_unslash( $post['assign'] ) : array();

	// Build the allow-list: post_id => set of suggested term_ids.
	$cache   = get_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() );
	$allowed = array();
	if ( is_array( $cache ) ) {
		foreach ( $cache as $row ) {
			if ( ! is_array( $row ) || empty( $row['suggested'] ) || ! is_array( $row['suggested'] ) ) {
				continue;
			}
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			foreach ( $row['suggested'] as $s ) {
				$tid = (int) ( is_array( $s ) ? ( $s['term_id'] ?? 0 ) : 0 );
				if ( $tid > 0 ) {
					$allowed[ $pid ][ $tid ] = true;
				}
			}
		}
	}

	foreach ( $assign as $pid => $term_ids ) {
		$pid = (int) $pid;
		if ( $pid <= 0 || empty( $allowed[ $pid ] ) ) {
			continue; // never suggested for this post.
		}
		if ( 'post' !== get_post_type( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
			continue; // not an editable Note for this user.
		}
		$ids = array();
		foreach ( (array) $term_ids as $tid ) {
			$tid = (int) $tid;
			if ( $tid > 0 && isset( $allowed[ $pid ][ $tid ] ) ) {
				$ids[ $tid ] = $tid; // intersect with the suggested set; dedupe.
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $pid, array_values( $ids ), 'post_tag', true );
		}
	}

	delete_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() );
	return 'tag_ai_applied';
}

/**
 * Delete the selected unused (count-0) tags. Reads sn_tag_unused[] = term_id.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_prune_unused( $post ) {
	$ids = isset( $post['sn_tag_unused'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $post['sn_tag_unused'] ) ) ) : array();
	if ( ! $ids || ! function_exists( 'sn_tag_delete_unused' ) ) {
		return 'tag_prune_error';
	}
	$res = sn_tag_delete_unused( $ids );
	return is_wp_error( $res ) ? 'tag_prune_error' : 'tag_pruned';
}

/**
 * v5.1.0: save the IndexNow enable toggle. Enabling mints a key on first use
 * (so /<key>.txt resolves immediately). The key lives in its own non-autoloaded
 * option; the toggle in sn_settings.indexnow.enabled.
 */
function sn_handle_indexnow_save( $post ) {
	$enabled = ! empty( $post['indexnow_enabled'] );
	sn_setting_update( 'indexnow.enabled', $enabled );
	if ( $enabled ) {
		sn_indexnow_ensure_key();
	}
	return 'indexnow_saved';
}

/** v5.1.0: regenerate the IndexNow key (invalidates the old /<key>.txt). */
function sn_handle_indexnow_regenerate( $post ) {
	sn_indexnow_regenerate_key();
	return 'indexnow_key_regenerated';
}

/**
 * v5.1.0: one-shot backfill — submit the most-recent published posts so
 * IndexNow learns about content that predates enabling. Bounded to 100.
 */
function sn_handle_indexnow_ping_now( $post ) {
	if ( ! sn_indexnow_is_enabled() || '' === sn_indexnow_get_key() ) {
		return 'indexnow_disabled';
	}
	$ids = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$urls = array_map( 'get_permalink', $ids );
	$urls[] = home_url( '/notes/' );
	sn_indexnow_enqueue( $urls );
	return 'indexnow_pinged';
}

/**
 * S2 (P2 analytics data layer): save the Cloudflare Analytics Engine credentials
 * from the Analytics settings form.
 *
 * Two fields:
 *   sn_cf_account_id       — plain identifier (not a secret), change-detected.
 *   sn_cf_analytics_token  — secret token; masked field; a '••••…' placeholder
 *                             means "no edit" and is silently skipped so the stored
 *                             value is never clobbered by the placeholder text.
 *
 * Both are constant-lockable: when SN_CF_ANALYTICS_TOKEN AND SN_CF_ACCOUNT_ID are
 * both defined and non-empty in wp-config.php, admin edits are rejected entirely.
 * When only one is locked, that field is skipped and the other may still be saved.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_saved' | 'analytics_unchanged' | 'analytics_locked'.
 */
function sn_handle_analytics_save( $post ) {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	if ( $token_locked && $acct_locked ) {
		return 'analytics_locked';
	}

	$changed = false;

	// Account ID — identifier, not a secret: plain text, change-detected.
	if ( ! $acct_locked && isset( $post['sn_cf_account_id'] ) ) {
		$acct = sanitize_text_field( wp_unslash( $post['sn_cf_account_id'] ) );
		if ( 'clear' === $acct ) {
			if ( '' !== (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' ) ) {
				delete_option( SN_CF_ACCOUNT_ID_OPT );
				$changed = true;
			}
		} elseif ( '' !== $acct && $acct !== (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' ) ) {
			update_option( SN_CF_ACCOUNT_ID_OPT, $acct, false );
			$changed = true;
		}
	}

	// Token — secret: masked field, ignore an un-edited '••••…' placeholder.
	if ( ! $token_locked ) {
		$new_token = isset( $post['sn_cf_analytics_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_analytics_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			if ( '' !== (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' ) ) {
				delete_option( SN_CF_ANALYTICS_TOKEN_OPT );
				$changed = true;
			}
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( SN_CF_ANALYTICS_TOKEN_OPT, $new_token, false );
			$changed = true;
		}
	}

	return $changed ? 'analytics_saved' : 'analytics_unchanged';
}

/**
 * S2 (P2 analytics data layer): test the Cloudflare Analytics Engine credentials
 * via a lightweight probe query (admin "Test connection" button).
 *
 * Dispatches through the sn_analytics_config() / sn_analytics_probe() seam so
 * both functions are replaceable in unit tests without network access.
 *
 * @param array $post Raw $_POST (unused; kept for dispatcher contract).
 * @return string Flash code: 'analytics_test_unconfigured' | 'analytics_test_ok' | 'analytics_test_err'.
 */
function sn_handle_analytics_test( $post ) {
	if ( ! sn_analytics_config() ) {
		return 'analytics_test_unconfigured';
	}
	delete_transient( SN_ANALYTICS_ERR_KEY ); // force-fresh: show THIS test's result, not a stale failure
	return sn_analytics_probe() ? 'analytics_test_ok' : 'analytics_test_err';
}

/**
 * v6.23.0: save the "Exclude my own visits" role allow-list (Monitoring →
 * Analytics). Sanitizes the submitted role slugs against the real role list
 * (sn_beacon_sanitize_exclude_roles) and persists them to the analytics subtree.
 * The theme's sn_beacon_enabled filter (inc/beacon-owner-exclusion.php) reads
 * this to suppress the front-end beacon for logged-in users in those roles.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_exclude_saved' | 'analytics_exclude_unchanged'.
 */
function sn_handle_analytics_exclude_save( $post ) {
	$raw = isset( $post['sn_exclude_roles'] ) ? wp_unslash( $post['sn_exclude_roles'] ) : array();
	$new = sn_beacon_sanitize_exclude_roles( $raw );
	sort( $new );

	$prior = (array) sn_setting( 'analytics.exclude_roles', array() );
	sort( $prior );

	if ( $new === $prior ) {
		return 'analytics_exclude_unchanged';
	}
	return sn_setting_update( 'analytics.exclude_roles', $new ) ? 'analytics_exclude_saved' : 'analytics_exclude_unchanged';
}

/**
 * v6.1.0: stream a CSV or JSON download of the current analytics range/class.
 *
 * This handler intentionally does NOT return a flash code — it streams a file
 * download and calls exit(), so the dispatcher's PRG redirect never runs.
 *
 * Load-order note: inc/analytics-read.php (sn_analytics_top_paths) and
 * inc/analytics-admin.php (snt_analytics_resolve_range / snt_analytics_resolve_class /
 * snt_analytics_range_dates) are both loaded unconditionally via require_once in
 * signal-and-noise-tools.php before any WordPress hook fires, so they are always
 * available at admin_init. inc/analytics-export.php (the formatters) is a new
 * file not yet in the bootstrap — require_once it here on first use.
 *
 * @param array $post Raw $_POST.
 * @return void (exits after streaming the download)
 */
function sn_handle_analytics_export( $post ) {
	if ( ! function_exists( 'sn_analytics_export_csv' ) ) {
		require_once __DIR__ . '/analytics-export.php';
	}

	$range_raw = isset( $post['sn_range'] ) ? sanitize_text_field( wp_unslash( $post['sn_range'] ) ) : '30';
	$from_raw  = isset( $post['sn_from'] ) ? sanitize_text_field( wp_unslash( $post['sn_from'] ) ) : '';
	$to_raw    = isset( $post['sn_to'] ) ? sanitize_text_field( wp_unslash( $post['sn_to'] ) ) : '';
	$class     = isset( $post['sn_class'] ) ? snt_analytics_resolve_class( sanitize_text_field( wp_unslash( $post['sn_class'] ) ) ) : 'human';
	$fmt       = ( isset( $post['format'] ) && 'json' === $post['format'] ) ? 'json' : 'csv';
	list( $range, $from, $to ) = snt_analytics_resolve_window( $range_raw, $from_raw, $to_raw );

	$rows  = sn_analytics_top_paths( $from, $to, $class, 500 );
	$fname = 'sn-analytics-' . $from . '_' . $to . '-' . $class . '.' . $fmt;

	if ( 'json' === $fmt ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		echo sn_analytics_export_json( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- file download, not HTML
	} else {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		echo sn_analytics_export_csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- file download, not HTML
	}
	exit;
}

/**
 * One-time import of Plausible CSV exports into the first-party rollup tables
 * (v6.0.0). Validates each uploaded file (genuine upload, ≤5MB, no upload error),
 * hands the temp paths to sn_analytics_import_run() (which parses, maps, and
 * idempotently upserts), and stashes the count report in a short transient the
 * settings section renders once. Payload is in $_FILES, not $_POST.
 *
 * @param array $post Raw $_POST (unused; kept for dispatcher contract).
 * @return string Flash code: 'analytics_imported' | 'analytics_import_empty' | 'analytics_import_err'.
 */
function sn_handle_analytics_import( $post ) {
	if ( ! function_exists( 'sn_analytics_import_run' ) || ! function_exists( 'sn_analytics_import_types' ) ) {
		return 'analytics_import_err';
	}

	$files = array();
	foreach ( array_keys( sn_analytics_import_types() ) as $type ) {
		$field = 'sn_import_' . $type;
		if ( ! isset( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
			continue;
		}
		$err = isset( $_FILES[ $field ]['error'] ) ? (int) $_FILES[ $field ]['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $err ) {
			continue;
		}
		// tmp_name is a server-generated path; sanitize for the linter, then validate
		// it's a genuine upload with is_uploaded_file(). size is int-cast.
		$tmp  = isset( $_FILES[ $field ]['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES[ $field ]['tmp_name'] ) ) : '';
		$size = isset( $_FILES[ $field ]['size'] ) ? (int) $_FILES[ $field ]['size'] : 0;
		if ( '' !== $tmp && is_uploaded_file( $tmp ) && $size > 0 && $size <= 5 * 1024 * 1024 ) {
			$files[ $type ] = $tmp;
		}
	}

	if ( empty( $files ) ) {
		return 'analytics_import_empty';
	}

	$report = sn_analytics_import_run( $files );
	set_transient( 'sn_analytics_import_report', $report, 5 * MINUTE_IN_SECONDS );
	return 'analytics_imported';
}
