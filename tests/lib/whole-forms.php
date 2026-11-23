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
 *   eight front-end settings on every save: six numbers from `?? <default>`
 *   (a form without Notes per page sets it back to 20), the palette switch
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

/** The eight fields sn_handle_save_theme() writes on every save. */
const SNT_THEME_SAVE_FIELDS = array( 'theme_related_count', 'theme_palette_recent_count', 'theme_palette_enabled', 'theme_json_feed_items', 'theme_updated_threshold_days', 'theme_reading_wpm', 'theme_notes_per_page', 'theme_note_reply_alias' );

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
