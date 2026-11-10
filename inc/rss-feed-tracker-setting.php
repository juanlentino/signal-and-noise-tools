<?php
/**
 * Signal & Noise Tools: the RSS tracker's settings option, declared to core.
 *
 * sn_rss_tracker_settings has three writers (the RSS form, the Analytics
 * collector save in inc/admin-post-actions/analytics.php, and the reset) and
 * had no shared sanitizer: each one cleaned its own keys by hand. Core's
 * register_setting() binds the callback below to sanitize_option_{$option},
 * which update_option() and add_option() both run, so every writer, WP-CLI
 * and a migration included, lands the same floor.
 *
 * Mirrors sn_settings_register() (inc/settings-accessors-parts.php): a private
 * group that no settings_fields() form posts, because a Settings API form
 * saves every option in its group, posted or not. The handlers stay the
 * writers. show_in_rest stays false, and `default` is omitted because
 * sn_rss_tracker_settings() merges sn_rss_tracker_defaults() itself.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On init, not admin_init: admin_init never fires for WP-CLI or cron.
 *
 * @return void
 */
function sn_rss_tracker_register_setting() {
	register_setting(
		'sn_private',
		SN_RSS_TRACKER_SETTINGS_OPT,
		array(
			'type'              => 'object',
			'sanitize_callback' => 'sn_rss_tracker_sanitize_settings',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'init', 'sn_rss_tracker_register_setting' );

/**
 * Sanitize the keys that are present; add none and drop none.
 *
 * The reset writes collector_url alone and readers fill the rest from the
 * defaults, so adding keys here would change what is stored. Idempotent: the
 * form cleans its values first and update_option() runs this on the result.
 *
 * @param mixed $value The array a writer handed update_option().
 * @return array<string,mixed>
 */
function sn_rss_tracker_sanitize_settings( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}
	if ( array_key_exists( 'enabled', $value ) ) {
		$value['enabled'] = ! empty( $value['enabled'] );
	}
	if ( array_key_exists( 'collector_url', $value ) ) {
		$value['collector_url'] = esc_url_raw( (string) $value['collector_url'] );
	}
	if ( array_key_exists( 'event_name', $value ) ) {
		$value['event_name'] = sanitize_text_field( (string) $value['event_name'] );
	}
	if ( array_key_exists( 'log_retention_days', $value ) ) {
		$value['log_retention_days'] = max( 7, min( 365, (int) $value['log_retention_days'] ) );
	}
	return $value;
}
