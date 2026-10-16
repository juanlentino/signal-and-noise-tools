<?php
/**
 * Signal & Noise Tools: the publish window's one setting, its length.
 *
 * `analytics.publish_window_minutes` (default 60, 5 to 1440): how long after
 * a note goes live its "first hour" lasts (inc/publish-window.php). The
 * classic Analytics settings fold and the native Setup fold
 * (apps/sn-dashboard/parts/leaves/monitoring-analytics-setup.php) post the
 * same field to the same handler. A changed length re-reads closed windows
 * on their next report: the cache keeps the length it was read with.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The fold's one-line snapshot: the setting's current value.
 *
 * @return string
 */
function sn_publish_window_snapshot() {
	/* translators: %d: minutes. */
	return sprintf( _n( '%d minute after a note goes live', '%d minutes after a note goes live', sn_publish_window_minutes(), 'signal-and-noise-tools' ), sn_publish_window_minutes() );
}

/**
 * The field's hint, shared by both forms.
 *
 * @return string
 */
function sn_publish_window_hint() {
	return __( 'Each note\'s report splits its readers into this window, from the moment it went live, and everything after. 5 to 1440 minutes.', 'signal-and-noise-tools' );
}

/**
 * Save the length. The dispatcher has already checked the nonce.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_analytics_publish_window_save( $post ) {
	$minutes = sn_publish_window_clamp( isset( $post['sn_publish_window_minutes'] ) && is_scalar( $post['sn_publish_window_minutes'] ) ? $post['sn_publish_window_minutes'] : SN_PUBLISH_WINDOW_DEFAULT );
	if ( sn_publish_window_minutes() === $minutes ) {
		return 'analytics_publish_window_unchanged';
	}
	return sn_setting_update( 'analytics.publish_window_minutes', $minutes ) ? 'analytics_publish_window_saved' : 'analytics_publish_window_unchanged';
}

/**
 * The classic form (Analytics › Settings, a fold beside Engine tuning).
 *
 * @return void
 */
function snt_analytics_render_publish_window() {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-an-settings">';
	wp_nonce_field( 'sn_analytics_publish_window_save' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Publish windows', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p><label for="sn_publish_window_minutes"><strong>' . esc_html__( 'Window length', 'signal-and-noise-tools' ) . '</strong></label><br>';
	echo '<input type="number" id="sn_publish_window_minutes" name="sn_publish_window_minutes" value="' . esc_attr( (string) sn_publish_window_minutes() ) . '" min="' . esc_attr( (string) SN_PUBLISH_WINDOW_MIN ) . '" max="' . esc_attr( (string) SN_PUBLISH_WINDOW_MAX ) . '" step="1" class="small-text"> ' . esc_html__( 'minutes', 'signal-and-noise-tools' );
	echo '<br><span class="sn-an-settings-help">' . esc_html( sn_publish_window_hint() ) . '</span></p>';
	echo '<p><button type="submit" name="action" value="sn_analytics_publish_window_save" class="button button-primary">' . esc_html__( 'Save window', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}

/**
 * The native Setup fold (snt_kit_setup_card()).
 *
 * @return array<string,mixed>
 */
function sn_publish_window_setup_fold() {
	$field = snt_kit_field( 'number', 'sn_publish_window_minutes', __( 'Window length, minutes', 'signal-and-noise-tools' ), sn_publish_window_minutes(), array( 'min' => SN_PUBLISH_WINDOW_MIN, 'max' => SN_PUBLISH_WINDOW_MAX, 'step' => 1, 'hint' => sn_publish_window_hint() ) );
	return array(
		'title'   => __( 'Publish windows', 'signal-and-noise-tools' ),
		'summary' => sn_publish_window_snapshot(),
		'body'    => snt_kit_form( 'analytics_publish_window_save', $field, array( 'submit' => __( 'Save window', 'signal-and-noise-tools' ) ) ),
	);
}
