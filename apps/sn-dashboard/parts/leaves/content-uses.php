<?php
/**
 * S&N Dashboard — Content → Uses Page, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/uses-page.php, `sn_admin_render_uses_section()`)
 * paints one form (`sn_action=uses_save`, handled by sn_handle_uses_save() through
 * the shared action table): an intro that says whether the stored override is
 * live or the form is prefilled from the theme's file groups, one card per gear
 * group (`uses[groups][<i>][label]` + `uses[groups][<i>][items]`, the items as
 * `name | note` lines), and the blank group the JS template used to clone. Same
 * readers, same names, same handler; the kit's parts instead of wp-admin's.
 *
 * What changed shape (inline scripts never run in a window): the template +
 * "+ Add group" button become one spare card painted last, under the template's
 * own index (`__U__` — the handler is key-agnostic and prunes a blank row); the
 * move-up / move-down / remove buttons were DOM-only and have no server action,
 * so removal is clearing a card and reordering is not offered here.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * What the classic leaf reads, in the order it reads it: the stored page, its
 * parsed groups, and — before the first save — the theme's live file groups.
 *
 * @return array{page:array{raw:string,updated:string}|null,groups:array<int,array<string,mixed>>,live:bool}
 */
function uses_data() {
	$page   = function_exists( 'sn_uses_page_get' ) ? \sn_uses_page_get() : null;
	$groups = $page && function_exists( 'sn_uses_parse_groups' ) ? \sn_uses_parse_groups( $page['raw'] ) : array();
	$live   = ! empty( $groups );
	if ( ! $live && function_exists( 'sn_uses_groups' ) ) {
		$groups = (array) \sn_uses_groups();
	}
	return array(
		'page'   => is_array( $page ) ? $page : null,
		'groups' => array_map(
			static function ( $g ) {
				return (array) $g;
			},
			array_values( (array) $groups )
		),
		'live'   => $live,
	);
}

/**
 * A group's items as the textarea shows them — `name | note` per line, blank
 * pairs skipped — the classic card's own collapse (sn_nuf_uses_group_card()).
 *
 * @param array<string,mixed> $group label, items.
 * @return string
 */
function uses_group_lines( array $group ) {
	$lines = array();
	foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
		$item = is_array( $item ) ? $item : array();
		$name = trim( (string) ( $item['name'] ?? '' ) );
		$note = trim( (string) ( $item['note'] ?? '' ) );
		if ( '' === $name && '' === $note ) {
			continue;
		}
		$lines[] = $name . ( '' !== $note ? ' | ' . $note : '' );
	}
	return implode( "\n", $lines );
}

/**
 * One group card: the label field and the items textarea, under the names the
 * classic card posts. `$index` is the group's position, or the template's
 * `__U__` for the spare card.
 *
 * @param string              $index Row index.
 * @param array<string,mixed> $group label, items.
 * @return string
 */
function uses_group_card( $index, array $group ) {
	$prefix = 'uses[groups][' . (string) $index . ']';
	$fields = \snt_kit_field( 'text', $prefix . '[label]', __( 'Group label', 'signal-and-noise-tools' ), (string) ( $group['label'] ?? '' ), array( 'placeholder' => 'Interface' ) )
		. \snt_kit_field(
			'textarea',
			$prefix . '[items]',
			__( 'Items — one per line, name | note', 'signal-and-noise-tools' ),
			uses_group_lines( $group ),
			array(
				'rows'        => 5,
				'placeholder' => "SSL UF8 | Advanced DAW controller\nAnother thing",
				'hint'        => __( 'The note after | is optional. A note with no name is refused at save rather than filed under a blank entry.', 'signal-and-noise-tools' ),
			)
		);
	// <os-card compact>: the kit's card, default slot = body (kit-help "Card").
	return \snt_kit_tag( 'os-card', array( 'compact' => true ), $fields );
}

/**
 * The intro: the live override with its save stamp, or the prefilled form.
 *
 * @param array{page:array|null,live:bool} $data From uses_data().
 * @return string
 */
function uses_intro_html( array $data ) {
	$link = \snt_kit_link( '/about/uses', home_url( '/about/uses' ) );
	if ( $data['live'] ) {
		$intro = sprintf(
			/* translators: 1: link to the live /about/uses page, 2: the last-saved date as code */
			\snt_kit_esc( __( 'This form is the editor for the live %1$s page. Saving here regenerates it. Last saved: %2$s.', 'signal-and-noise-tools' ) ),
			$link,
			\snt_kit_code( (string) ( $data['page']['updated'] ?? '' ), false )
		);
	} else {
		$intro = sprintf(
			/* translators: %s: link to the /about/uses page */
			\snt_kit_esc( __( 'This form is the editor for the %s page, prefilled from the current live list. Save to take over the page content.', 'signal-and-noise-tools' ) ),
			$link
		);
	}
	return '<p class="snt-prose">' . $intro . '</p>';
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_uses( array $ctx ) {
	unset( $ctx );
	$data  = uses_data();
	$cards = '';
	foreach ( $data['groups'] as $i => $group ) {
		$cards .= uses_group_card( (string) $i, $group );
	}
	// The spare card: what the classic template cloned on "+ Add group". It
	// keeps the template's index; a blank row is pruned at save, a filled one
	// is a new group (sn_uses_rows_to_text() never reads the keys).
	$cards .= uses_group_card( '__U__', array( 'items' => array( array() ) ) );

	$inner = uses_intro_html( $data )
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'Each card is one gear group: a label plus name/note rows (the note is optional). Incomplete cards are refused at save (rows need a label, a label needs at least one row, and a note needs a name). Removing every card clears the override: the page falls back to the theme\'s built-in list (it is never silently blanked).', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'The last card is a spare: fill it in to add a group. To remove a group, clear its label and items — blank cards are dropped at save. To reorder groups, move the text between cards.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form(
			'uses_save',
			'<os-stack gap="12">' . $cards . '</os-stack>',
			array( 'submit' => __( 'Save uses page', 'signal-and-noise-tools' ) )
		);
	return \snt_kit_section( __( 'Uses page', 'signal-and-noise-tools' ), $inner );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/uses'] = __NAMESPACE__ . '\\paint_content_uses';
		return $painters;
	}
);
