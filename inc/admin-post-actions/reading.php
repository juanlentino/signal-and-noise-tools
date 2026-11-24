<?php
/**
 * Notes per page and JSON feed items: the one save path both surfaces use.
 *
 * The two numbers sit on WordPress's own Settings > Reading, below core's
 * "Blog pages show at most" and "Syndication feeds show the most recent"
 * (inc/reading-settings-screen.php), and in the native window on Settings >
 * Front end (its own form, action `reading_save`). Core's two options govern
 * the blog archive and the RSS feeds; these govern /notes and the JSON feed,
 * which the theme reads only through the sn_notes_per_page and
 * sn_json_feed_items filters (inc/theme-filters.php). Storage is unchanged:
 * theme.notes_per_page and theme.json_feed_items in sn_settings.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write both numbers, clamped to the bounds the theme filters enforce, then
 * refresh the cached pages when either changed. A missing value keeps what is
 * stored, so no caller can reset one by leaving it out.
 *
 * @param mixed $notes Notes per page on /notes (1 to 100), or null to keep.
 * @param mixed $feed  Notes in the JSON feed (1 to 50), or null to keep.
 * @return bool True when every write succeeded.
 */
function sn_reading_save( $notes, $feed ) {
	$before = sn_settings_snapshot();
	$ok     = true;
	if ( null !== $notes && '' !== $notes ) {
		$ok = sn_setting_update( 'theme.notes_per_page', max( 1, min( 100, (int) $notes ) ) ) && $ok;
	}
	if ( null !== $feed && '' !== $feed ) {
		$ok = sn_setting_update( 'theme.json_feed_items', max( 1, min( 50, (int) $feed ) ) ) && $ok;
	}
	sn_settings_purge_if_changed( $before );
	return $ok;
}

/**
 * The native form's handler (Settings > Front end, "Notes and the JSON feed").
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_reading_save( $post ) {
	return sn_reading_save( $post['theme_notes_per_page'] ?? null, $post['theme_json_feed_items'] ?? null ) ? 'reading_saved' : 'theme_unchanged';
}
