<?php
/**
 * Constraint: a handler that writes several settings from `! empty()` or `??`
 * on every plain save resets whatever its form leaves out. Three of them back
 * Settings > AI (the first is the shape tests/lib/brief-forms.php guards for
 * the morning brief):
 *
 * - sn_handle_ai_settings_save() (inc/admin-post-actions/theme-ai.php) writes
 *   both models, the budget (`?? 0`), the Jev credit (`?? 5`) and its cycle
 *   day (`?? 17`): a form without the budget field removes the cap.
 * - sn_handle_scheduled_reads_save() (inc/admin-post-actions/reports.php)
 *   writes the daily switch from `! empty()`, unless snt_scheduled_reads_now
 *   came with the post, in which case it returns before the write.
 * - sn_handle_remote_toggle() (inc/admin-post-actions/mcp.php) writes the
 *   remote door's switch from `! empty()`.
 *
 * Two more back Settings > Front end:
 *
 * - sn_handle_save_theme() (inc/admin-post-actions/theme-ai.php) writes all
 *   six front-end settings on every save: four numbers from `?? <default>`
 *   (a form without Reading speed sets it back to 225), the palette switch
 *   from `! empty()` (omitted, the palette turns off) and the reply alias
 *   from `?? 'research'`.
 * - sn_handle_perf_save() (inc/admin-post-actions/monitoring.php) writes the
 *   speculative loading switch from `! empty()`. It is the handler's only
 *   field, so the rule for it is that exactly one form posts the action.
 *
 * Three more back Settings > Accounts and connections, and a fourth is held
 * to the same rule because its two fields are one instruction:
 *
 * - sn_handle_zenodo_env_save() (inc/admin-post-actions/zenodo.php) writes the
 *   environment (anything but `production` is sandbox) and the ledger concept
 *   DOI (`?? ''`, which clears it): a form without the DOI removes it.
 * - sn_handle_zenodo_doi_line_save() (same file) writes the downloads line's
 *   switch (`! empty()`), label (`?? ''`) and floor (`?? 0`) as one option.
 * - sn_handle_indexnow_save() (inc/admin-post-actions/indexnow.php) writes
 *   the IndexNow switch from `! empty()`, its only field.
 * - sn_handle_keyring_save() (inc/admin-post-actions/keyring.php) resets
 *   nothing by omission (no `key_id` is refused, an empty `key_value` changes
 *   nothing), but the picker and the value are useless apart.
 *
 * Eight more back Settings > Analytics:
 *
 * - sn_handle_analytics_exclude_save() (inc/admin-post-actions/analytics.php)
 *   writes the whole role list from `?? array()`: a form without the roles
 *   counts every role again. The role controls are generated per role, so
 *   the rule for it is one form, holding one control per role.
 * - sn_handle_analytics_tuning_save() (same file) writes the baseline window
 *   (`?? 30`) and the sensitivity (`?? 'standard'`): a form with one resets
 *   the other.
 * - sn_handle_analytics_funnels_save() (same file) parses the textarea from
 *   `?? ''` and saves the result: a form without it saves no funnels.
 * - sn_handle_machine_readers_save() (same file) writes the Worker URL from
 *   `?? ''`, which clears it. The read token is left alone when blank.
 * - sn_handle_save_insights_settings() (inc/admin-post-actions/health-insights.php)
 *   writes the weekly scan switch from `! empty()`, its only field.
 * - sn_rss_tracker_handle_form() (inc/rss-feed-tracker-form-parts.php) rebuilds
 *   the tracker's option on a save: the switch from `! empty()`, the event
 *   name and the retention from their defaults when missing.
 * - sn_handle_analytics_publish_window_save() (inc/publish-window-settings.php)
 *   sets a missing window length back to 60, its only field.
 * - sn_handle_north_star_save() (inc/north-star-settings.php) writes the page
 *   groups (none posted: every group counts), the scroll depth (`?? 50`) and
 *   the time on page (`?? 30`) as one setting. The groups are one select per
 *   group, so the rule adds: one form, holding one per group. Held on BOTH
 *   twins since the classic pane gained the box (2026-11).
 *
 * One more backs Settings > General, the widest of them:
 *
 * - sn_handle_save_identity() (inc/admin-post-actions/system.php) hands the
 *   post to sn_settings_save() (inc/settings-save-parts.php), which rebuilds
 *   the identity, social, og and seo_copy subtrees from the post alone: the
 *   person name, availability, handle, OG image and six SEO strings from
 *   `?? ''`, the card size from `?? 1200` and `?? 630`, the profile URLs from
 *   `?? array()`; the site name, description and locale overrides, the job
 *   title and the topics are stored only when posted and non-empty. A form
 *   without a field clears it or sets it back to its default. The profile
 *   rows are `social_same_as[]` on the classic form and `social_same_as[N]`
 *   in the window, so a suite adds its surface's spelling to the list.
 *
 * sn_handle_analytics_collector_save(), sn_handle_gsc_credential_save() and
 * sn_handle_gsc_property_save() reset nothing: a missing or empty field is
 * refused or changes nothing.
 *
 * So a form posting one of these actions must carry every field the handler
 * writes, or, as a HIDDEN field so it rides every submit, a verb the handler
 * returns on first. A submit button's name does not count: it is sent only
 * when that button is pressed.
 *
 * @param string   $html   Painted markup.
 * @param string   $tag    `form` (classic) or `os-form` (native).
 * @param string   $action The sn_action, without its `sn_` prefix.
 * @param string[] $fields Every field name the handler writes on a plain save.
 * @param string[] $verbs  Field names the handler returns on before any write.
 * @return int How many forms post $action, or -1 when one of them would reset
 *             a setting by omission.
 */
function snt_whole_forms( $html, $tag, $action, array $fields, array $verbs = array() ) {
	preg_match_all( '#<' . $tag . '\b.*?</' . $tag . '>#s', (string) $html, $m );
	$n = 0;
	foreach ( $m[0] as $form ) {
		if ( false === strpos( $form, 'sn_' . $action ) ) {
			continue;
		}
		++$n;
		$whole = true;
		foreach ( $fields as $field ) {
			$whole = $whole && false !== strpos( $form, 'name="' . $field . '"' );
		}
		$verb = array() !== $verbs && 1 === preg_match( '#<input type="hidden" name="(' . implode( '|', array_map( 'preg_quote', $verbs ) ) . ')"#', $form );
		if ( ! $whole && ! $verb ) {
			return -1;
		}
	}
	return $n;
}

/** The five fields sn_handle_ai_settings_save() writes on every save. */
const SNT_AI_SAVE_FIELDS = array( 'theme_ai_model', 'theme_ai_alt_model', 'theme_ai_monthly_budget', 'theme_jev_credit', 'theme_jev_cycle_day' );

/** The six fields sn_handle_save_theme() writes on every save. */
const SNT_THEME_SAVE_FIELDS = array( 'theme_related_count', 'theme_palette_recent_count', 'theme_palette_enabled', 'theme_updated_threshold_days', 'theme_reading_wpm', 'theme_note_reply_alias' );

/** The two switches sn_handle_mcp_doors_save() writes on every save (an unchecked box writes off). */
const SNT_MCP_DOORS_SAVE_FIELDS = array( 'sn_read_door_enabled', 'sn_rw_door_enabled' );

/** The one field sn_handle_related_source_save() reads. */
const SNT_RELATED_SOURCE_SAVE_FIELDS = array( 'sn_related_source' );

/** The one field sn_handle_mcp_rw_notify_save() writes on every save. */
const SNT_MCP_RW_NOTIFY_SAVE_FIELDS = array( 'sn_rw_notify' );

/** The two fields sn_handle_reading_save() reads (it keeps a value that is missing, so one form carrying both is a parity rule, not a reset guard). */
const SNT_READING_SAVE_FIELDS = array( 'theme_notes_per_page', 'theme_json_feed_items' );

/** The one field sn_handle_perf_save() writes on every save. */
const SNT_PERF_SAVE_FIELDS = array( 'speculative_loading' );

/** The two fields sn_handle_zenodo_env_save() writes on every save. */
const SNT_ZENODO_ENV_SAVE_FIELDS = array( 'zenodo_env', 'zenodo_ledger_doi' );

/** The three fields sn_handle_zenodo_doi_line_save() writes on every save. */
const SNT_ZENODO_DOI_LINE_SAVE_FIELDS = array( 'zenodo_doi_line_enabled', 'zenodo_doi_line_label', 'zenodo_doi_line_floor' );

/** The one field sn_handle_indexnow_save() writes on every save. */
const SNT_INDEXNOW_SAVE_FIELDS = array( 'indexnow_enabled' );

/** The two fields sn_handle_keyring_save() reads: which row, and what to do with it. */
const SNT_KEYRING_SAVE_FIELDS = array( 'key_id', 'key_value' );

/** The two fields sn_handle_analytics_tuning_save() writes on every save. */
const SNT_ANALYTICS_TUNING_SAVE_FIELDS = array( 'sn_signal_baseline_days', 'sn_anomaly_sensitivity' );

/** The one field sn_handle_analytics_funnels_save() writes on every save. */
const SNT_ANALYTICS_FUNNELS_SAVE_FIELDS = array( 'sn_funnels' );

/** The one field sn_handle_machine_readers_save() clears when it is missing. */
const SNT_MR_SAVE_FIELDS = array( 'sn_mr_worker_url' );

/** The one field sn_handle_save_insights_settings() writes on every save. */
const SNT_INSIGHTS_SAVE_FIELDS = array( 'insights_weekly_cron' );

/** The three fields the RSS tracker's save rebuilds its option from. */
const SNT_RSS_SAVE_FIELDS = array( 'enabled', 'event_name', 'log_retention_days' );

/** The one field sn_handle_analytics_publish_window_save() writes on every save. */
const SNT_PUBLISH_WINDOW_SAVE_FIELDS = array( 'sn_publish_window_minutes' );

/** The two fixed fields sn_handle_north_star_save() writes beside its page groups. */
const SNT_NORTH_STAR_SAVE_FIELDS = array( 'sn_nsm_scroll', 'sn_nsm_dwell_s' );

/** The seventeen fixed fields sn_settings_save() writes beside the profile URL rows. */
const SNT_IDENTITY_SAVE_FIELDS = array( 'identity_site_name', 'identity_site_description', 'identity_person_name', 'identity_job_title', 'identity_availability', 'identity_knows_about', 'identity_locale', 'social_twitter_handle', 'og_default_image_url', 'og_card_width', 'og_card_height', 'seo_home_title', 'seo_home_description', 'seo_notes_title', 'seo_notes_description', 'seo_provenance_title', 'seo_provenance_description' );
