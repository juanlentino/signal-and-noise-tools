<?php
/**
 * The north star's settings: which page groups count and what counts as a
 * read. One open box on Settings › Analytics, on both twins (the window had
 * it on Measurement › Analytics until 2026-11; the classic pane gained its
 * twin that month), and one save handler on the shared sn_action dispatcher
 * (the nonce is checked there before any handler runs).
 *
 * The handler rewrites all three keys on every save, so each twin posts every
 * group, the scroll depth and the time on page from ONE form, under the same
 * names (tests/lib/whole-forms.php). Both read their words and values from
 * snt_nsm_settings_model(), so a label cannot differ between them.
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
 * What both forms show: one row per page group (its field name, label,
 * slug and whether it counts), the two numbers, and the sentences.
 *
 * @return array{groups:array<int,array{name:string,label:string,slug:string,counts:bool}>,scroll:int,dwell_s:int,state:string,intro:string,labels:array<string,string>}
 */
function snt_nsm_settings_model() {
	$cfg      = snt_nsm_config();
	$sections = snt_nsm_sections();
	$groups   = array();
	$counted  = array();
	foreach ( $sections as $slug => $def ) {
		$groups[] = array(
			'name'   => 'sn_nsm_sections[' . count( $groups ) . ']',
			'label'  => $def['label'] . ' (' . implode( ', ', $def['prefixes'] ) . ')',
			'slug'   => $slug,
			'counts' => in_array( $slug, $cfg['sections'], true ),
		);
	}
	foreach ( $cfg['sections'] as $slug ) {
		$counted[] = $sections[ $slug ]['label'];
	}
	return array(
		'groups'  => $groups,
		'scroll'  => (int) $cfg['scroll'],
		'dwell_s' => (int) ( $cfg['dwell_ms'] / 1000 ),
		/* translators: 1: the page groups that count, 2: scroll depth %, 3: seconds on the page */
		'state'   => sprintf( __( 'Counts %1$s; a read is %2$d%% scroll or %3$d s on the page.', 'signal-and-noise-tools' ), implode( ', ', $counted ), $cfg['scroll'], $cfg['dwell_ms'] / 1000 ),
		'intro'   => __( 'The north star counts human visitors, per day, who read at least one page in the groups this box lists: past the scroll depth OR the time on page. The notes index, its pages, tags and feed never count; a listing is not a read.', 'signal-and-noise-tools' ),
		'labels'  => array(
			'counts' => __( 'Counts', 'signal-and-noise-tools' ),
			'not'    => __( 'Does not count', 'signal-and-noise-tools' ),
			'scroll' => __( 'Read at scroll depth (%)', 'signal-and-noise-tools' ),
			'dwell'  => __( 'Or after time on page (seconds)', 'signal-and-noise-tools' ),
			'submit' => __( 'Save north star', 'signal-and-noise-tools' ),
		),
	);
}

/**
 * The native box on Settings › Analytics: the current definition as its
 * one-line description, the form below it.
 * 2026-10-11: no fold (owner rule: no collapsible sections on S&N Home).
 *
 * @return string
 */
function snt_nsm_settings_html() {
	$m      = snt_nsm_settings_model();
	$fields = '';
	foreach ( $m['groups'] as $g ) {
		$fields .= snt_kit_field( 'select', $g['name'], $g['label'], $g['counts'] ? $g['slug'] : '', array( 'options' => array( $g['slug'] => $m['labels']['counts'], '' => $m['labels']['not'] ) ) );
	}
	$fields .= snt_kit_field( 'number', 'sn_nsm_scroll', $m['labels']['scroll'], (string) $m['scroll'], array( 'min' => 1, 'max' => 100 ) )
		. snt_kit_field( 'number', 'sn_nsm_dwell_s', $m['labels']['dwell'], (string) $m['dwell_s'], array( 'min' => 1, 'max' => 600 ) );

	return snt_kit_section(
		__( 'North star', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . snt_kit_esc( $m['intro'] ) . '</p>' . snt_kit_form( 'north_star_save', $fields, array( 'submit' => $m['labels']['submit'], 'columns' => 'auto' ) ),
		$m['state']
	);
}

/**
 * The classic twin, the body of the pane's North star box (the pane prints
 * the heading). ONE form, the window's field names: one select per group
 * (the handler takes a slug per group, empty when it does not count), then
 * the two numbers inside the handler's bounds.
 *
 * @return void
 */
function snt_nsm_render_settings() {
	$m = snt_nsm_settings_model();
	echo '<p class="sn-an-settings-help"><strong>' . esc_html( $m['state'] ) . '</strong></p>';
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-an-settings">';
	wp_nonce_field( 'sn_north_star_save' );
	echo '<p class="sn-an-settings-help">' . esc_html( $m['intro'] ) . '</p>';
	foreach ( $m['groups'] as $i => $g ) {
		$id = 'sn_nsm_sections_' . $i;
		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $g['label'] ) . '</strong></label><br>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $g['name'] ) . '"><option value="' . esc_attr( $g['slug'] ) . '"' . selected( $g['counts'], true, false ) . '>' . esc_html( $m['labels']['counts'] ) . '</option>';
		echo '<option value=""' . selected( $g['counts'], false, false ) . '>' . esc_html( $m['labels']['not'] ) . '</option></select></p>';
	}
	foreach ( array( 'sn_nsm_scroll' => array( 'scroll', $m['scroll'], 100 ), 'sn_nsm_dwell_s' => array( 'dwell', $m['dwell_s'], 600 ) ) as $name => $f ) {
		echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $m['labels'][ $f[0] ] ) . '</strong></label><br>';
		echo '<input type="number" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $f[1] ) . '" min="1" max="' . esc_attr( (string) $f[2] ) . '" step="1" class="small-text"></p>';
	}
	echo '<p><button type="submit" name="action" value="sn_north_star_save" class="button button-primary">' . esc_html( $m['labels']['submit'] ) . '</button></p>';
	echo '</form>';
}
