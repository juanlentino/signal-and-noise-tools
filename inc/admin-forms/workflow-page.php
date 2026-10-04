<?php
/**
 * Signal & Noise: Content > Workflow (the /workflow page editor, classic form).
 *
 * Posts `workflow[...]` with action sn_workflow_save to
 * sn_handle_workflow_save() (inc/admin-post-actions/workflow.php). The native
 * twin is apps/sn-dashboard/parts/leaves/content-workflow.php; both post the
 * same field names in the same order (tests/os-leaf-content-workflow.php).
 *
 * Repeaters (map, rules) use the resume editor's <template data-rsm-tpl>
 * rows, cloned by assets/resume-admin.js. A new map row is NOT shown on the
 * page until its "Show on page" box is checked.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One labelled textarea. The newline right after the opening tag is eaten by
 * the browser, so a value that starts with a newline survives a re-edit.
 *
 * @param string $name  Field name.
 * @param string $value Current value.
 * @param string $label Visible label.
 * @param int    $rows  Rows.
 * @param string $class Extra class (`code` for monospace).
 */
function sn_wf_textarea( $name, $value, $label, $rows = 3, $class = '' ) {
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">' . esc_html( $label ) . '</span>'
		. '<textarea name="' . esc_attr( $name ) . '" rows="' . esc_attr( (string) (int) $rows ) . '" class="' . esc_attr( trim( 'large-text ' . $class ) ) . '">' . "\n"
		. esc_textarea( $value ) . '</textarea></label>';
}

/** One map row: title, line, Show on page. @param string $prefix @param array $row */
function sn_wf_map_row( $prefix, array $row ) {
	echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
	sn_rsm_input( $prefix . '[title]', (string) ( $row['title'] ?? '' ), 'Title', 'Drafting' );
	sn_rsm_controls();
	echo '</div>';
	sn_rsm_input( $prefix . '[line]', (string) ( $row['line'] ?? '' ), 'Line', 'One sentence on what happens here' );
	echo '<label class="sn-rsm-field"><input type="checkbox" name="' . esc_attr( $prefix . '[show]' ) . '" value="1"' . ( true === ( $row['show'] ?? false ) ? ' checked="checked"' : '' ) . '> Show on page</label>';
	echo '</div>';
}

/** One rule row: rule, explanation. @param string $prefix @param array $row */
function sn_wf_rule_row( $prefix, array $row ) {
	echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
	sn_rsm_input( $prefix . '[rule]', (string) ( $row['rule'] ?? '' ), 'Rule', '' );
	sn_rsm_controls();
	echo '</div>';
	sn_wf_textarea( $prefix . '[explanation]', (string) ( $row['explanation'] ?? '' ), 'Explanation', 3 );
	echo '</div>';
}

/**
 * Render the Workflow section body: the sn_admin_render_section() callback
 * for the Content tab's 'workflow' sub-tab.
 */
function sn_admin_render_workflow_section() {
	$doc = function_exists( 'sn_workflow_page_get' ) ? sn_workflow_page_get() : null;
	$doc = is_array( $doc ) ? $doc : sn_workflow_normalize( array() );
	$s   = $doc['sample'];

	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-rsm-form">';
	wp_nonce_field( 'sn_workflow_save' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Workflow page</h2>';
	echo '<p class="sn-fieldset-intro">This form is the editor for the <a href="' . esc_url( home_url( '/workflow' ) ) . '" target="_blank" rel="noopener">/workflow</a> page. Saving regenerates it; the page does not exist until a save has something to show.</p>';

	sn_rsm_input( 'workflow[title]', $doc['title'], 'Title', 'Workflow' );
	sn_wf_textarea( 'workflow[dek]', $doc['dek'], 'Dek (also the meta description)', 2 );

	echo '<h3>Sample</h3>';
	sn_rsm_input( 'workflow[sample][label]', $s['label'], 'Label', 'Sample' );
	sn_rsm_input( 'workflow[sample][title]', $s['title'], 'Title', '' );
	sn_wf_textarea( 'workflow[sample][intro]', $s['intro'], 'Intro', 3 );
	sn_wf_textarea( 'workflow[sample][outcome]', $s['outcome'], 'Outcome (optional)', 3 );
	echo '<p class="sn-field-helper">Shown before the Body. Write it to stand on its own.</p>';
	sn_wf_textarea( 'workflow[sample][body]', $s['body'], 'Body (shown exactly as typed, whitespace included)', 14, 'code' );

	echo '<h3>Map</h3>';
	sn_rsm_input( 'workflow[map_heading]', $doc['map_heading'], 'Heading', 'The rest of the set' );
	echo '<p class="sn-field-helper">A row appears on the public page only when "Show on page" is checked. Unchecked rows stay here and nowhere else.</p>';
	echo '<div class="sn-rsm-list" data-rsm-list="workflow-map">';
	foreach ( $doc['map'] as $i => $row ) {
		sn_wf_map_row( 'workflow[map][' . $i . ']', $row );
	}
	echo '</div><template data-rsm-tpl="workflow-map" data-rsm-token="__M__">';
	sn_wf_map_row( 'workflow[map][__M__]', array() );
	echo '</template><button type="button" class="button sn-rsm-add" data-rsm-add="workflow-map">+ Add map row</button>';

	echo '<h3>Rules</h3>';
	sn_rsm_input( 'workflow[rules_heading]', $doc['rules_heading'], 'Heading', 'Field rules' );
	echo '<div class="sn-rsm-list" data-rsm-list="workflow-rules">';
	foreach ( $doc['rules'] as $i => $row ) {
		sn_wf_rule_row( 'workflow[rules][' . $i . ']', $row );
	}
	echo '</div><template data-rsm-tpl="workflow-rules" data-rsm-token="__R__">';
	sn_wf_rule_row( 'workflow[rules][__R__]', array() );
	echo '</template><button type="button" class="button sn-rsm-add" data-rsm-add="workflow-rules">+ Add rule</button>';

	echo '<div class="sn-fieldset-actions"><button type="submit" name="action" value="sn_workflow_save" class="button button-primary">Save workflow page</button></div>';
	echo '</div></form>';
}
