<?php
/**
 * The north star's settings: which page groups count and what counts as a
 * read. One open section on Measurement › Analytics, one save handler on the
 * shared sn_action dispatcher (the nonce is checked there before any handler
 * runs).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save the north star definition and drop the cached reading so the next
 * paint reads it.
 *
 * Each section posts as its OWN indexed select (`sn_nsm_sections[i]` = slug
 * or ''): the runtime keys form values by name, so a shared checkbox name
 * would collapse to one key (the exclusion fold's lesson).
 *
 * @param array $post Posted fields.
 * @return string Flash code.
 */
function sn_handle_north_star_save( $post ) {
	$valid    = array_keys( snt_nsm_sections() );
	$sections = array_values( array_intersect( $valid, array_map( 'sanitize_key', (array) wp_unslash( $post['sn_nsm_sections'] ?? array() ) ) ) );
	$next     = array(
		'sections' => $sections ? $sections : $valid,
		'scroll'   => max( 1, min( 100, (int) ( $post['sn_nsm_scroll'] ?? 50 ) ) ),
		'dwell_s'  => max( 1, min( 600, (int) ( $post['sn_nsm_dwell_s'] ?? 30 ) ) ),
	);
	$prev = (array) sn_setting( 'north_star', array() );
	if ( ( $prev['sections'] ?? null ) === $next['sections'] && (int) ( $prev['scroll'] ?? 0 ) === $next['scroll'] && (int) ( $prev['dwell_s'] ?? 0 ) === $next['dwell_s'] ) {
		return 'north_star_unchanged';
	}
	$ok = sn_setting_update( 'north_star', $next );
	delete_transient( SNT_NSM_CACHE_KEY );
	return $ok ? 'north_star_saved' : 'north_star_unchanged';
}

/**
 * The settings, painted on Measurement › Analytics as an open section: the
 * current definition as its one-line description, the form below it.
 * 2026-10-11: no fold (owner rule: no collapsible sections on S&N Home).
 *
 * @return string
 */
function snt_nsm_settings_html() {
	$cfg      = snt_nsm_config();
	$sections = snt_nsm_sections();
	$fields   = '';
	$i        = 0;
	foreach ( $sections as $slug => $def ) {
		$fields .= snt_kit_field(
			'select',
			'sn_nsm_sections[' . $i++ . ']',
			$def['label'] . ' (' . implode( ', ', $def['prefixes'] ) . ')',
			in_array( $slug, $cfg['sections'], true ) ? $slug : '',
			array(
				'options' => array(
					$slug => __( 'Counts', 'signal-and-noise-tools' ),
					''    => __( 'Does not count', 'signal-and-noise-tools' ),
				),
			)
		);
	}
	$fields .= snt_kit_field( 'number', 'sn_nsm_scroll', __( 'Read at scroll depth (%)', 'signal-and-noise-tools' ), (string) $cfg['scroll'], array( 'min' => 1, 'max' => 100 ) )
		. snt_kit_field( 'number', 'sn_nsm_dwell_s', __( 'Or after time on page (seconds)', 'signal-and-noise-tools' ), (string) ( $cfg['dwell_ms'] / 1000 ), array( 'min' => 1, 'max' => 600 ) );

	$labels = array();
	foreach ( $cfg['sections'] as $slug ) {
		$labels[] = $sections[ $slug ]['label'];
	}
	/* translators: 1: the page groups that count, 2: scroll depth %, 3: seconds on the page */
	$state = sprintf( __( 'Counts %1$s; a read is %2$d%% scroll or %3$d s on the page.', 'signal-and-noise-tools' ), implode( ', ', $labels ), $cfg['scroll'], $cfg['dwell_ms'] / 1000 );
	$intro = '<p class="snt-prose">' . snt_kit_esc( __( 'The north star counts human visitors, per day, who read at least one page in the groups below: past the scroll depth OR the time on page. The notes index, its pages, tags and feed never count; a listing is not a read.', 'signal-and-noise-tools' ) ) . '</p>';

	return snt_kit_section(
		__( 'North star', 'signal-and-noise-tools' ),
		$intro . snt_kit_form( 'north_star_save', $fields, array( 'submit' => __( 'Save north star', 'signal-and-noise-tools' ), 'columns' => 'auto' ) ),
		$state
	);
}
