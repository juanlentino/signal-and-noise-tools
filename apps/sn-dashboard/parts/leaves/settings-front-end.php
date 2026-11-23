<?php
/**
 * S&N Dashboard: Settings > Front end, painted from the kit. The fourth pane
 * of the Settings tab, the native twin of inc/admin-forms/settings-front-end.php.
 *
 * 1. The eight front-end settings the companion theme reads. This file WAS
 *    the Site > Front-End leaf (site-front-end.php) until 2026-11; the leaf
 *    held nothing but this one form, so it moved whole. Same readers
 *    (`sn_setting()`, `sn_note_reply_aliases()`), same field names, bounds
 *    and copy as the classic form (inc/admin-forms/front-end.php), same
 *    action (`save_theme`). ONE form, because sn_handle_save_theme() writes
 *    all eight on every save; the three headed boxes are sections inside it.
 * 2. The speculative loading switch, from Site > Performance, which keeps
 *    its readings. Same field, same action (`perf_save`). It was a Setup
 *    fold there; a setting on a settings pane is open.
 *
 * A save is answered on the tab and leaf it was posted from
 * (sn-dashboard.os.php, the `post` action), so it lands back on this pane.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The eight knobs and the alias allowlist, read the way the classic form reads them
 * (inc/admin-forms/front-end.php), defaults included.
 *
 * @return array{related:int,precent:int,penab:bool,jfeed:int,uthr:int,wpm:int,nperp:int,ralias:string,aliases:array<int,string>}
 */
function front_end_state() {
	return array(
		'related' => (int) \sn_setting( 'theme.related_count', 3 ),
		'precent' => (int) \sn_setting( 'theme.palette_recent_count', 8 ),
		'penab'   => (bool) \sn_setting( 'theme.palette_enabled', true ),
		'jfeed'   => (int) \sn_setting( 'theme.json_feed_items', 20 ),
		'uthr'    => (int) \sn_setting( 'theme.updated_threshold_days', 14 ),
		'wpm'     => (int) \sn_setting( 'theme.reading_wpm', 225 ),
		'nperp'   => (int) \sn_setting( 'theme.notes_per_page', 20 ),
		'ralias'  => (string) \sn_setting( 'theme.note_reply_alias', 'research' ),
		'aliases' => function_exists( 'sn_note_reply_aliases' ) ? (array) \sn_note_reply_aliases() : array( 'research' ),
	);
}

/**
 * One bounded number field: the classic `<input type="number" min max>` with
 * its label and helper, as a kit number field in a field row.
 *
 * @param string $name  Field name (the handler's key).
 * @param string $label Label.
 * @param int    $value Current value.
 * @param int    $min   Lower bound.
 * @param int    $max   Upper bound.
 * @param string $hint  Helper text.
 * @return string
 */
function front_end_number( $name, $label, $value, $min, $max, $hint ) {
	return \snt_kit_field( 'number', $name, $label, (int) $value, array( 'min' => (string) $min, 'max' => (string) $max, 'hint' => $hint ) );
}

/**
 * The eight fields as three headed boxes, in the classic twin's order:
 * Reading, Command palette, Replies. Each box is a section INSIDE the one
 * form; assets/os-app.css lays a section's field rows out as the form's own.
 *
 * @param array<string,mixed> $s From front_end_state().
 * @return string
 */
function front_end_boxes_html( array $s ) {
	$options = array();
	foreach ( (array) $s['aliases'] as $alias ) {
		$options[ (string) $alias ] = (string) $alias . '@';
	}
	$reading = front_end_number( 'theme_related_count', __( 'Related notes shown', 'signal-and-noise-tools' ), $s['related'], 1, 12, __( 'How many related notes appear under a single note (1 to 12).', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_updated_threshold_days', __( '“Updated” badge after (days)', 'signal-and-noise-tools' ), $s['uthr'], 1, 90, __( 'Show the “Updated” badge when a note was revised this many days after publishing (1 to 90).', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_reading_wpm', __( 'Reading speed (words/min)', 'signal-and-noise-tools' ), $s['wpm'], 100, 400, __( 'Words per minute used to estimate reading time (100 to 400).', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_notes_per_page', __( 'Notes per page', 'signal-and-noise-tools' ), $s['nperp'], 1, 100, __( 'How many notes per page on the /notes index (1 to 100). Pagination appears once published notes exceed this.', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_json_feed_items', __( 'JSON feed items', 'signal-and-noise-tools' ), $s['jfeed'], 1, 50, __( 'Number of notes in the JSON feed (1 to 50).', 'signal-and-noise-tools' ) );
	// The kit field helper paints a bare <os-checkbox-label>, so the classic
	// label + helper ride an <os-field-row label hint> around it.
	$palette = \snt_kit_tag(
		'os-field-row',
		array( 'label' => __( 'Reader command palette', 'signal-and-noise-tools' ), 'hint' => __( 'Turning this off hides the trigger and skips loading the palette’s scripts and styles.', 'signal-and-noise-tools' ) ),
		\snt_kit_field( 'checkbox', 'theme_palette_enabled', __( 'Enable the ⌘K command palette and its footer trigger', 'signal-and-noise-tools' ), $s['penab'] )
	)
		. front_end_number( 'theme_palette_recent_count', __( 'Command-palette recent notes', 'signal-and-noise-tools' ), $s['precent'], 0, 20, __( 'Recent notes listed in the ⌘K reader palette (0 to 20).', 'signal-and-noise-tools' ) );
	$replies = \snt_kit_field( 'select', 'theme_note_reply_alias', __( 'Note reply goes to', 'signal-and-noise-tools' ), $s['ralias'], array( 'options' => $options, 'hint' => __( 'Which existing alias the Reply row on a note writes to. Only aliases the mailbox already filters are offered; a new address would arrive unfiltered.', 'signal-and-noise-tools' ) ) );
	return \snt_kit_grid(
		array(
			\snt_kit_section( __( 'Reading', 'signal-and-noise-tools' ), $reading ),
			\snt_kit_section( __( 'Command palette', 'signal-and-noise-tools' ), $palette ),
			\snt_kit_section( __( 'Replies', 'signal-and-noise-tools' ), $replies ),
		),
		260
	);
}

/**
 * The Performance box: the speculative loading switch in its row, the hint
 * saying what each state does (the classic twin's sentence), and Save.
 *
 * @return string
 */
function front_end_speculative_html() {
	$row = \snt_kit_tag(
		'os-field-row',
		array( 'label' => __( 'Speculative loading', 'signal-and-noise-tools' ), 'hint' => \snt_speculative_loading_hint() ),
		\snt_kit_field( 'checkbox', 'speculative_loading', __( 'Enabled: prerender the pages a visitor is likely to open next', 'signal-and-noise-tools' ), (bool) \sn_setting( 'perf.speculative_loading', true ) )
	);
	return \snt_kit_section( __( 'Performance', 'signal-and-noise-tools' ), \snt_kit_form( 'perf_save', $row, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ) );
}

/**
 * The pane: the one front-end form (the intro, the three boxes in a row, the
 * save-row hint in the form's `footer-leading` slot, beside the submit
 * button), then the Performance box, a sibling form.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_front_end( array $ctx ) {
	unset( $ctx );
	$form = \snt_kit_form(
		'save_theme',
		'<p class="snt-prose">' . \snt_kit_esc( __( 'How the site’s pages render; the companion theme reads these values. The defaults are the theme’s own, so nothing changes until you save here.', 'signal-and-noise-tools' ) ) . '</p>'
		. front_end_boxes_html( front_end_state() )
		. '<p class="snt-hint" slot="footer-leading">' . \snt_kit_esc( __( 'Saving a change refreshes the site’s cached pages, so the live site shows it the next time a page loads.', 'signal-and-noise-tools' ) ) . '</p>',
		array(
			'submit'  => __( 'Save front-end settings', 'signal-and-noise-tools' ),
			'columns' => 'auto',
			// The window's width (sn-dashboard.css), as the classic wide pane.
			'class'   => 'snt-site-form',
		)
	);
	return $form . ( function_exists( 'snt_speculative_loading_hint' ) ? front_end_speculative_html() : '' );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/front-end'] = __NAMESPACE__ . '\\paint_settings_front_end';
		return $painters;
	}
);
