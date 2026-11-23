<?php
/**
 * Constraint: sn_handle_morning_brief_save() (inc/admin-post-actions/reports.php)
 * writes BOTH operations.morning_brief_enabled and operations.alerts_enabled
 * from `! empty()` on every plain save. So a form posting morning_brief_save
 * must carry both checkboxes, or, as a HIDDEN field so it rides every submit,
 * one of the two verbs the handler returns on first (send a test, acknowledge
 * drift). A submit button's name does not count: it is sent only when that
 * button is pressed, so a form with a test button still makes plain saves.
 *
 * @param string $html Painted markup.
 * @param string $tag  `form` (classic) or `os-form` (native).
 * @return int How many forms post morning_brief_save, or -1 when one of them
 *             would silently switch an email off.
 */
function snt_brief_forms( $html, $tag ) {
	preg_match_all( '#<' . $tag . '\b.*?</' . $tag . '>#s', (string) $html, $m );
	$n = 0;
	foreach ( $m[0] as $form ) {
		if ( false === strpos( $form, 'morning_brief_save' ) ) {
			continue;
		}
		++$n;
		$both = false !== strpos( $form, 'name="snt_morning_brief_enabled"' ) && false !== strpos( $form, 'name="snt_alerts_enabled"' );
		$verb = 1 === preg_match( '#<input type="hidden" name="(snt_morning_brief_test|snt_config_drift_acknowledge)"#', $form );
		if ( ! $both && ! $verb ) {
			return -1;
		}
	}
	return $n;
}
