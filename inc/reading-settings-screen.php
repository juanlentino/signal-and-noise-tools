<?php
/**
 * Settings > Reading: Notes per page and JSON feed items, on WordPress's own
 * screen, in a section of their own below core's fields.
 *
 * Convention: core's Reading screen is where WordPress keeps "how many per
 * page" and "how many in the feed", so these sit beside core's two and never
 * replace them (core's still govern the blog archive and RSS). Registered
 * through the Settings API: `sn_reading` is allowlisted on the `reading`
 * group so options.php accepts the post, and pre_update_option_sn_reading
 * hands the values to sn_reading_save() and returns the old value, so no
 * `sn_reading` option is ever stored. The native twin is the
 * "Notes and the JSON feed" form on Settings > Front end.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the setting, the section and its two fields.
 *
 * @return void
 */
function sn_reading_screen_register() {
	register_setting( 'reading', 'sn_reading', array( 'type' => 'array', 'show_in_rest' => false ) );
	add_settings_section( 'sn_reading', __( 'Notes', 'signal-and-noise-tools' ), 'sn_reading_screen_intro', 'reading' );
	add_settings_field( 'sn_reading_notes_per_page', __( 'Notes pages show at most', 'signal-and-noise-tools' ), 'sn_reading_screen_field', 'reading', 'sn_reading', array( 'key' => 'notes_per_page', 'value' => (int) sn_setting( 'theme.notes_per_page', 20 ), 'max' => 100, 'unit' => __( 'notes', 'signal-and-noise-tools' ), 'hint' => __( 'On the /notes index (1 to 100). Pagination appears once published notes exceed this.', 'signal-and-noise-tools' ), 'label_for' => 'sn_reading_notes_per_page' ) );
	add_settings_field( 'sn_reading_json_feed_items', __( 'The JSON feed shows the most recent', 'signal-and-noise-tools' ), 'sn_reading_screen_field', 'reading', 'sn_reading', array( 'key' => 'json_feed_items', 'value' => (int) sn_setting( 'theme.json_feed_items', 20 ), 'max' => 50, 'unit' => __( 'notes', 'signal-and-noise-tools' ), 'hint' => __( 'In the JSON feed (1 to 50).', 'signal-and-noise-tools' ), 'label_for' => 'sn_reading_json_feed_items' ) );
}
add_action( 'admin_init', 'sn_reading_screen_register' );

/**
 * The section's one line: what these govern, and what core's two still do.
 *
 * @return void
 */
function sn_reading_screen_intro() {
	echo '<p>' . esc_html__( 'From Signal & Noise Tools. These set the /notes index and the JSON feed; WordPress’s own two still set the blog pages and the RSS feeds.', 'signal-and-noise-tools' ) . '</p>';
}

/**
 * One number field in core's own markup (`small-text`, the unit after it,
 * the hint as a `description`), the way core paints "Blog pages show at most".
 *
 * @param array $a key, value, max, unit, hint, label_for.
 * @return void
 */
function sn_reading_screen_field( $a ) {
	printf(
		'<input name="sn_reading[%1$s]" type="number" step="1" min="1" max="%2$d" id="%3$s" value="%4$d" class="small-text" /> %5$s<p class="description">%6$s</p>',
		esc_attr( $a['key'] ),
		(int) $a['max'],
		esc_attr( $a['label_for'] ),
		(int) $a['value'],
		esc_html( $a['unit'] ),
		esc_html( $a['hint'] )
	);
}

/**
 * options.php calls update_option( 'sn_reading', $_POST['sn_reading'] ).
 * Write the two values through the shared path and return the old value, so
 * update_option() sees no change and stores nothing.
 *
 * @param mixed $value     The posted array.
 * @param mixed $old_value The stored value (always absent).
 * @return mixed $old_value.
 */
function sn_reading_screen_save( $value, $old_value ) {
	if ( is_array( $value ) && current_user_can( 'manage_options' ) ) {
		sn_reading_save( $value['notes_per_page'] ?? null, $value['json_feed_items'] ?? null );
	}
	return $old_value;
}
add_filter( 'pre_update_option_sn_reading', 'sn_reading_screen_save', 10, 2 );
