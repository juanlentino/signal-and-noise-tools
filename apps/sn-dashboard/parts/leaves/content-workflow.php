<?php
/**
 * S&N Dashboard: Content > Workflow, painted from the kit.
 *
 * The native twin of inc/admin-forms/workflow-page.php: the same
 * `workflow[...]` names in the same order and the same action
 * (workflow_save, sn_handle_workflow_save()). The classic repeaters'
 * <template> rows become one blank spare card per list, painted last under
 * the template's own token (`__M__`, `__R__`); a blank row is pruned at save.
 * The "Show on page" checkbox posts nothing when unchecked, and absent means
 * hidden (sn_workflow_normalize()), so a new row is hidden until checked.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-cards-parts.php';

/**
 * One map card: title, line, Show on page.
 *
 * @param string              $index Row index, or `__M__` for the spare.
 * @param array<string,mixed> $row   title, line, show.
 * @return string
 */
function workflow_map_card( $index, array $row ) {
	$p = 'workflow[map][' . $index . ']';
	return \snt_kit_tag(
		'os-card',
		array( 'compact' => true ),
		\snt_kit_field( 'text', $p . '[title]', __( 'Title', 'signal-and-noise-tools' ), (string) ( $row['title'] ?? '' ), array( 'placeholder' => 'Drafting' ) )
		. \snt_kit_field( 'text', $p . '[line]', __( 'Line', 'signal-and-noise-tools' ), (string) ( $row['line'] ?? '' ), array( 'placeholder' => 'One sentence on what happens here' ) )
		. \snt_kit_field( 'checkbox', $p . '[show]', __( 'Show on page', 'signal-and-noise-tools' ), true === ( $row['show'] ?? false ) )
	);
}

/**
 * One rule card: rule, explanation.
 *
 * @param string              $index Row index, or `__R__` for the spare.
 * @param array<string,mixed> $row   rule, explanation.
 * @return string
 */
function workflow_rule_card( $index, array $row ) {
	$p = 'workflow[rules][' . $index . ']';
	return \snt_kit_tag(
		'os-card',
		array( 'compact' => true ),
		\snt_kit_field( 'text', $p . '[rule]', __( 'Rule', 'signal-and-noise-tools' ), (string) ( $row['rule'] ?? '' ) )
		. \snt_kit_field( 'textarea', $p . '[explanation]', __( 'Explanation', 'signal-and-noise-tools' ), (string) ( $row['explanation'] ?? '' ), array( 'rows' => 3 ) )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_workflow( array $ctx ) {
	unset( $ctx );
	$doc = function_exists( 'sn_workflow_page_get' ) ? \sn_workflow_page_get() : null;
	$doc = is_array( $doc ) ? $doc : \sn_workflow_normalize( array() );
	$s   = $doc['sample'];

	$map = array();
	foreach ( $doc['map'] as $i => $row ) {
		$map[] = workflow_map_card( (string) $i, $row );
	}
	$map[] = workflow_map_card( '__M__', array() );
	$rules = array();
	foreach ( $doc['rules'] as $i => $row ) {
		$rules[] = workflow_rule_card( (string) $i, $row );
	}
	$rules[] = workflow_rule_card( '__R__', array() );

	$fields = \snt_kit_field( 'text', 'workflow[title]', __( 'Title', 'signal-and-noise-tools' ), $doc['title'], array( 'placeholder' => 'Workflow' ) )
		. \snt_kit_field( 'textarea', 'workflow[dek]', __( 'Dek (also the meta description)', 'signal-and-noise-tools' ), $doc['dek'], array( 'rows' => 2 ) )
		. '<h4 class="snt-h">' . \snt_kit_esc( __( 'Sample', 'signal-and-noise-tools' ) ) . '</h4>'
		. \snt_kit_field( 'text', 'workflow[sample][label]', __( 'Label', 'signal-and-noise-tools' ), $s['label'], array( 'placeholder' => 'Sample' ) )
		. \snt_kit_field( 'text', 'workflow[sample][title]', __( 'Title', 'signal-and-noise-tools' ), $s['title'] )
		. \snt_kit_field( 'textarea', 'workflow[sample][intro]', __( 'Intro', 'signal-and-noise-tools' ), $s['intro'], array( 'rows' => 3 ) )
		. \snt_kit_field( 'textarea', 'workflow[sample][outcome]', __( 'Outcome (optional)', 'signal-and-noise-tools' ), $s['outcome'], array( 'rows' => 3, 'hint' => __( 'Shown before the Body. Write it to stand on its own.', 'signal-and-noise-tools' ) ) )
		. \snt_kit_field( 'textarea', 'workflow[sample][body]', __( 'Body', 'signal-and-noise-tools' ), $s['body'], array( 'rows' => 14, 'hint' => __( 'Shown exactly as typed, whitespace included, in a monospace block.', 'signal-and-noise-tools' ) ) )
		. '<h4 class="snt-h">' . \snt_kit_esc( __( 'Map', 'signal-and-noise-tools' ) ) . '</h4>'
		. \snt_kit_field( 'text', 'workflow[map_heading]', __( 'Heading', 'signal-and-noise-tools' ), $doc['map_heading'], array( 'placeholder' => 'The rest of the set' ) )
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'A row appears on the public page only when "Show on page" is checked. Unchecked rows stay here and nowhere else.', 'signal-and-noise-tools' ) ) . '</p>'
		. snt_pair_cards( $map )
		. '<h4 class="snt-h">' . \snt_kit_esc( __( 'Rules', 'signal-and-noise-tools' ) ) . '</h4>'
		. \snt_kit_field( 'text', 'workflow[rules_heading]', __( 'Heading', 'signal-and-noise-tools' ), $doc['rules_heading'], array( 'placeholder' => 'Field rules' ) )
		. snt_pair_cards( $rules );

	$inner = '<p class="snt-prose">' . sprintf(
		/* translators: %s: link to the /workflow page */
		\snt_kit_esc( __( 'This form is the editor for the %s page. Saving regenerates it; the page does not exist until a save has something to show.', 'signal-and-noise-tools' ) ),
		\snt_kit_link( '/workflow', home_url( '/workflow' ) )
	) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'The last card in each list is a spare: fill it in to add a row. To remove a row, clear its fields. To reorder, move the text between cards.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form( 'workflow_save', '<os-stack gap="12">' . $fields . '</os-stack>', array( 'submit' => __( 'Save workflow page', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Workflow page', 'signal-and-noise-tools' ), $inner );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/workflow'] = __NAMESPACE__ . '\\paint_content_workflow';
		return $painters;
	}
);
