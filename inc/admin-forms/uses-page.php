<?php
/**
 * Signal & Noise — Uses Page admin section (Content tab → Uses Page sub-tab).
 *
 * The STRUCTURED editor for the /about/uses gear list (v10.41.0): group cards
 * with a label field and repeatable name/note pair rows, replacing the
 * `## Label` / `- name | note` textarea. The text document STAYS the stored
 * format — sn_action=uses_save serializes the posted rows back into it
 * (sn_uses_rows_to_text, inc/admin-post-actions.php) and stores via
 * sn_uses_page_save() (inc/uses-page.php) exactly as before; data layer,
 * sync engine, and migrations untouched. Since v9.20.0 /about/uses is a real
 * CMS child Page regenerated on every save. Zero rows clears (theme file
 * content returns); rows that cannot survive the text format (items under a
 * blank label, a note with no name) are refused — never silently lost.
 *
 * Pair rows are INDEXED (name+note per row), so the per-group item template
 * carries its own __I__ token beside the group's __U__ — the same two-token
 * nesting as the resume employer/role cards, cloned and rewritten by
 * assets/resume-admin.js. A `|` typed into a name is stripped at save (it is
 * the format's separator); notes keep their pipes.
 *
 * Every renderer ECHOES with esc_* at the echo site (Plugin Check runs its
 * own EscapeOutput sniff and ignores phpcs.xml.dist).
 *
 * @package SignalNoiseTools
 * @since 7.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One /uses pair row: name + note. @param string $prefix Row prefix (may carry tokens). @param array $item {name,note}. */
function sn_nuf_uses_item_row( $prefix, $item ) {
	echo '<div class="sn-rsm-row" data-rsm-row><div class="sn-rsm-pair">';
	sn_rsm_input( $prefix . '[name]', (string) ( $item['name'] ?? '' ), 'Name', 'SSL UF8' );
	sn_rsm_input( $prefix . '[note]', (string) ( $item['note'] ?? '' ), 'Note (optional)', 'Advanced DAW controller' );
	echo '</div>';
	sn_rsm_controls();
	echo '</div>';
}

/**
 * One /uses group card: label + repeatable indexed pair rows.
 *
 * @param string $prefix     Input-name prefix (e.g. uses[groups][0]; may carry __U__).
 * @param string $items_id   data-rsm id for this card's items list (may carry __U__).
 * @param string $item_token Token the pair-row template bakes for its own key.
 * @param array  $group      {label,items[]} (empty for the template).
 */
function sn_nuf_uses_group_card( $prefix, $items_id, $item_token, $group ) {
	echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row>';
	echo '<div class="sn-rsm-card-head">';
	sn_rsm_input( $prefix . '[label]', (string) ( $group['label'] ?? '' ), 'Group label', 'Interface' );
	sn_rsm_controls();
	echo '</div>';
	echo '<div class="sn-rsm-list" data-rsm-list="' . esc_attr( $items_id ) . '">';
	$i = 0;
	foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
		sn_nuf_uses_item_row( $prefix . '[items][' . $i . ']', (array) $item );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="' . esc_attr( $items_id ) . '" data-rsm-token="' . esc_attr( $item_token ) . '">';
	sn_nuf_uses_item_row( $prefix . '[items][' . $item_token . ']', array() );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="' . esc_attr( $items_id ) . '">+ Add item</button>';
	echo '</div>';
}

/**
 * Render the Uses Page section body. Used as the sn_admin_render_section()
 * callback for the Content tab's 'uses' sub-tab.
 *
 * @since 7.6.0 (structured form since 10.41.0)
 */
function sn_admin_render_uses_section() {
	$page   = function_exists( 'sn_uses_page_get' ) ? sn_uses_page_get() : null;
	$groups = $page && function_exists( 'sn_uses_parse_groups' ) ? sn_uses_parse_groups( $page['raw'] ) : array();
	$live   = ! empty( $groups );

	// First open: prefill from the theme's live file groups so the owner edits
	// the current list, not a blank form (the shape is already {label,items:
	// {name,note}} — no serialize/parse round-trip needed).
	if ( ! $live && function_exists( 'sn_uses_groups' ) ) {
		$groups = (array) sn_uses_groups();
	}

	echo '<form method="post" class="sn-rsm-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Uses page</h2>';

	if ( $live ) {
		echo '<p class="sn-fieldset-intro">This form is the editor for the live <a href="' . esc_url( home_url( '/about/uses' ) ) . '" target="_blank" rel="noopener">/about/uses</a> page. Saving here regenerates it. Last saved: <code>' . esc_html( (string) $page['updated'] ) . '</code>.</p>';
	} else {
		echo '<p class="sn-fieldset-intro">This form is the editor for the <a href="' . esc_url( home_url( '/about/uses' ) ) . '" target="_blank" rel="noopener">/about/uses</a> page, prefilled from the current live list. Save to take over the page content.</p>';
	}
	echo '<p class="sn-field-helper">Each card is one gear group: a label plus name/note rows (the note is optional). Incomplete cards are refused at save (rows need a label, a label needs at least one row, and a note needs a name). Removing every card clears the override — the page falls back to the theme\'s built-in list (it is never silently blanked).</p>';

	echo '<div class="sn-rsm-list" data-rsm-list="uses-groups">';
	$i = 0;
	foreach ( $groups as $group ) {
		sn_nuf_uses_group_card( 'uses[groups][' . $i . ']', 'uit-' . $i, '__I__', (array) $group );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="uses-groups" data-rsm-token="__U__">';
	sn_nuf_uses_group_card( 'uses[groups][__U__]', 'uit-__U__', '__I__', array( 'items' => array( array() ) ) );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="uses-groups">+ Add group</button>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="uses_save" class="button button-primary">Save uses page</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
