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
