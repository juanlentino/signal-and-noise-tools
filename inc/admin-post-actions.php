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
	} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
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
 * v4.12.0: the AI text-generation model allowlist (single source for the
 * Front-End form's <select> AND the save handler's validation). Keys are the
 * model ids passed to the snt_ai_model_preference filter; values are UI labels.
 *
 * Ids are the alias form (no date suffix), verified Active against the
 * claude-api model catalog: Sonnet 4.6 (current pin), Opus 4.8 (most capable),
 * Haiku 4.5 (fastest/cheapest). Loaded unconditionally at bootstrap, so it is
 * available on the front end too (sn_tf_ai_model() calls it during AI requests).
 *
 * @return array<string,string>
 */
function sn_theme_ai_models() {
	return array(
		'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (balanced — default)',
		'claude-opus-4-8'   => 'Claude Opus 4.8 (most capable)',
		'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fastest, cheapest)',
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
 * v4.11.0 (T5): seed a draft Note from a cached Insights recommendation.
 *
 * Zero new AI calls — the rec text is already in the cached scan. Resolves the
 * SAME rec the user saw (snt_insights_find_rec) so a stale/expired cache fails
 * cleanly instead of fabricating a draft. On success it marks the rec done and
 * stashes the new draft's edit link in a short per-user transient; the
 * dispatcher PRG-redirects (Option A), so sn_admin_flash_to_notice() reads the
 * link back to render the "edit it" notice. The dispatcher itself is untouched.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_insights_create_draft( $post ) {
	if ( ! function_exists( 'snt_insights_find_rec' ) || ! function_exists( 'snt_insights_create_draft_from_rec' ) ) {
		return 'insights_draft_failed';
	}

	$rec_id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
	$rec    = snt_insights_find_rec( $rec_id );
	if ( ! is_array( $rec ) ) {
		// Cache miss / expired / unknown id — nothing to seed from.
		return 'insights_draft_stale';
	}

	$new_id = snt_insights_create_draft_from_rec( $rec );
	if ( is_wp_error( $new_id ) || ! $new_id ) {
		return 'insights_draft_failed';
	}

	// Mark the rec done so it greys out on the next render.
	if ( function_exists( 'snt_insights_mark_done' ) ) {
		snt_insights_mark_done( $rec_id );
	}

	// Stash the edit link for the success notice. get_edit_post_link() carries
	// a nonce, so it must NOT ride the redirect query string — a per-user
	// transient is the safe carrier (mirrors the release-notes pattern).
	$edit_link = get_edit_post_link( (int) $new_id, 'raw' );
	set_transient(
		sn_insights_draft_result_key(),
		array(
			'post_id'   => (int) $new_id,
			'edit_link' => is_string( $edit_link ) ? $edit_link : '',
		),
		5 * MINUTE_IN_SECONDS
	);

	return 'insights_draft_created';
}

/**
 * Per-user transient key carrying the "Create draft" result (the new draft's
 * id + edit link) across the dispatcher's PRG redirect. Per-user so concurrent
 * admins don't clobber each other's notice.
 *
 * @return string
 */
function sn_insights_draft_result_key() {
	return 'sn_insights_draft_result_' . get_current_user_id();
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
