<?php
/**
 * S&N Dashboard — Site → Front-End, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/front-end.php, `sn_admin_render_front_end_form()`)
 * paints one form (`sn_action=save_theme` → `sn_handle_save_theme()`) of the eight
 * render knobs the companion theme reads via filters: six bounded numbers, the
 * allowlisted note-reply alias (a select, deliberately not a text field) and
 * the ⌘K palette checkbox. Same readers (`sn_setting()`, `sn_note_reply_aliases()`),
 * same field names, same bounds, same handler, same copy; the kit's parts
 * instead of wp-admin's. The registry's `wide` flag — an auto-fit field grid
 * on the classic page — is the form's `columns="auto"`.
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
 * (inc/admin-forms/front-end.php:27-34, 60), defaults included.
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
 * One bounded knob: the classic `<input type="number" min max>` with its label
 * and helper, as a kit number field in a field row.
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
 * The eight fields, in the classic order.
 *
 * @param array<string,mixed> $s From front_end_state().
 * @return string
 */
function front_end_fields_html( array $s ) {
	$options = array();
	foreach ( (array) $s['aliases'] as $alias ) {
		$options[ (string) $alias ] = (string) $alias . '@';
	}
	// The checkbox row: the kit field helper paints a bare <os-checkbox-label>,
	// so the classic field label + helper ride an <os-field-row label hint>
	// (kit-help: Field row — label, hint) around it, as the number rows do.
	$palette = \snt_kit_tag(
		'os-field-row',
		array(
			'label' => __( 'Reader command palette', 'signal-and-noise-tools' ),
			'hint'  => __( 'Turning this off hides the trigger and skips the palette’s JS/CSS entirely.', 'signal-and-noise-tools' ),
		),
		\snt_kit_field( 'checkbox', 'theme_palette_enabled', __( 'Enable the ⌘K command palette and its footer trigger', 'signal-and-noise-tools' ), $s['penab'] )
	);
	return front_end_number( 'theme_related_count', __( 'Related notes shown', 'signal-and-noise-tools' ), $s['related'], 1, 12, __( 'How many related notes appear under a single note (1–12).', 'signal-and-noise-tools' ) )
		. \snt_kit_field(
			'select',
			'theme_note_reply_alias',
			__( 'Note reply goes to', 'signal-and-noise-tools' ),
			$s['ralias'],
			array(
				'options' => $options,
				'hint'    => __( 'Which existing alias the Reply row on a note writes to. Only aliases the mailbox already filters are offered — a new local part would arrive unfiltered.', 'signal-and-noise-tools' ),
			)
		)
		. front_end_number( 'theme_palette_recent_count', __( 'Command-palette recent notes', 'signal-and-noise-tools' ), $s['precent'], 0, 20, __( 'Recent notes listed in the ⌘K reader palette (0–20).', 'signal-and-noise-tools' ) )
		. $palette
		. front_end_number( 'theme_json_feed_items', __( 'JSON feed items', 'signal-and-noise-tools' ), $s['jfeed'], 1, 50, __( 'Number of notes in the JSON feed (1–50).', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_updated_threshold_days', __( '“Updated” badge after (days)', 'signal-and-noise-tools' ), $s['uthr'], 1, 90, __( 'Show the “Updated” badge when a note was revised this many days after publishing (1–90).', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_reading_wpm', __( 'Reading speed (words/min)', 'signal-and-noise-tools' ), $s['wpm'], 100, 400, __( 'Words per minute used to estimate reading time (100–400).', 'signal-and-noise-tools' ) )
		. front_end_number( 'theme_notes_per_page', __( 'Notes per page', 'signal-and-noise-tools' ), $s['nperp'], 1, 100, __( 'How many notes per page on the /notes index (1–100). Pagination appears once published notes exceed this.', 'signal-and-noise-tools' ) );
}

/**
 * The leaf: the Front-End section around the one form. The save-row hint
 * rides the form's `footer-leading` slot (kit-help: Form — slots), beside the
 * submit button as on the classic actions row.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_site_front_end( array $ctx ) {
	unset( $ctx );
	$s    = front_end_state();
	$form = \snt_kit_form(
		'save_theme',
		front_end_fields_html( $s )
		. '<p class="snt-hint" slot="footer-leading">' . \snt_kit_esc( __( 'Changes apply on the next front-end request. Live site re-renders automatically.', 'signal-and-noise-tools' ) ) . '</p>',
		array(
			'submit'  => __( 'Save front-end settings', 'signal-and-noise-tools' ),
			'columns' => 'auto',
		)
	);
	return \snt_kit_section(
		__( 'Front-End', 'signal-and-noise-tools' ),
		$form,
		__( 'Render knobs the companion theme reads via filters. Defaults match the theme’s own hardcoded values, so changes apply only once you save here. Each takes effect on the next front-end request.', 'signal-and-noise-tools' )
	);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/front-end'] = __NAMESPACE__ . '\\paint_site_front_end';
		return $painters;
	}
);
