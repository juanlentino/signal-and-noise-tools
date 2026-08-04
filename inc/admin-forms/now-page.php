<?php
/**
 * Signal & Noise — Now Page admin section (Content tab → Now Page sub-tab).
 *
 * The STRUCTURED editor for the /now page (v10.40.0): section cards with a
 * label field and repeatable item rows, replacing the `## Label` textarea.
 * The text document STAYS the stored format — sn_action=now_save serializes
 * the posted rows back into it (sn_now_rows_to_text, inc/admin-post-actions
 * .php) and stores via sn_now_page_save() (inc/now-page.php) exactly as
 * before; data layer, sync engine, and migrations untouched. Since v9.19.0
 * /now is a real CMS Page regenerated on every save: this form is the editor,
 * the Page is the rendered artifact + Excerpt/SEO surface. Zero rows clears
 * (theme file content returns); rows that cannot survive the text format are
 * refused, never mis-filed — the page is never silently blanked.
 *
 * Repeatable-row mechanics ride assets/resume-admin.js (self-gating, loaded
 * on every SN admin page) and the sn_rsm_* helpers from admin-forms/
 * resume-page.php (all form files load before any renders). The group
 * template bakes the __G__ token into names and nested item-list ids; the JS
 * swaps it for a unique key at clone time. Items are a plain [] leaf list —
 * order is DOM order.
 *
 * Every renderer ECHOES with esc_* at the echo site (Plugin Check runs its
 * own EscapeOutput sniff and ignores phpcs.xml.dist).
 *
 * @package SignalNoiseTools
 * @since 7.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One /now section card: label + repeatable item rows.
 *
 * @param string $prefix   Input-name prefix (e.g. now[groups][0]; may carry __G__).
 * @param string $items_id data-rsm id for this card's items list (may carry __G__).
 * @param array  $section  {label,items[]} (empty for the template).
 */
function sn_nuf_now_group_card( $prefix, $items_id, $section ) {
	echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row>';
	echo '<div class="sn-rsm-card-head">';
	sn_rsm_input( $prefix . '[label]', (string) ( $section['label'] ?? '' ), 'Section label', 'Building' );
	sn_rsm_controls();
	echo '</div>';
	echo '<div class="sn-rsm-list" data-rsm-list="' . esc_attr( $items_id ) . '">';
	foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
		sn_nuf_now_item_row( $prefix, (string) $item );
	}
	echo '</div>';
	echo '<template data-rsm-tpl="' . esc_attr( $items_id ) . '">';
	sn_nuf_now_item_row( $prefix, '' );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="' . esc_attr( $items_id ) . '">+ Add item</button>';
	echo '</div>';
}

/** One /now item row (leaf list — plain [] name). @param string $prefix @param string $value */
function sn_nuf_now_item_row( $prefix, $value ) {
	echo '<div class="sn-rsm-row" data-rsm-row>';
	sn_rsm_input( $prefix . '[items][]', $value, 'Item', 'One line about what you are doing' );
	sn_rsm_controls();
	echo '</div>';
}

/**
 * Render the Now Page section body. Used as the sn_admin_render_section()
 * callback for the Content tab's 'now' sub-tab.
 *
 * @since 7.5.0 (structured form since 10.40.0)
 */
function sn_admin_render_now_section() {
	$page     = function_exists( 'sn_now_page_get' ) ? sn_now_page_get() : null;
	$sections = $page && function_exists( 'sn_now_parse_sections' ) ? sn_now_parse_sections( $page['raw'] ) : array();

	echo '<form method="post" class="sn-rsm-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Now page</h2>';

	if ( ! empty( $sections ) ) {
		echo '<p class="sn-fieldset-intro">This form is the editor for the live <a href="' . esc_url( home_url( '/now' ) ) . '" target="_blank" rel="noopener">/now</a> page. Saving here regenerates it. Last saved: <code>' . esc_html( (string) $page['updated'] ) . '</code>.</p>';
	} else {
		echo '<p class="sn-fieldset-intro">This form is the editor for the <a href="' . esc_url( home_url( '/now' ) ) . '" target="_blank" rel="noopener">/now</a> page. Add sections below and save to publish it.</p>';
	}
	echo '<p class="sn-field-helper">Each card is one section on /now: a label plus its items. Incomplete cards are refused at save (items need a label; a label needs at least one item). Removing every card clears the override — the page falls back to the theme\'s built-in content (it is never silently blanked).</p>';

	echo '<div class="sn-rsm-list" data-rsm-list="now-groups">';
	$i = 0;
	foreach ( $sections as $section ) {
		sn_nuf_now_group_card( 'now[groups][' . $i . ']', 'nit-' . $i, $section );
		$i++;
	}
	echo '</div>';
	echo '<template data-rsm-tpl="now-groups" data-rsm-token="__G__">';
	sn_nuf_now_group_card( 'now[groups][__G__]', 'nit-__G__', array( 'items' => array( '' ) ) );
	echo '</template>';
	echo '<button type="button" class="button sn-rsm-add" data-rsm-add="now-groups">+ Add section</button>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="now_save" class="button button-primary">Save now page</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
