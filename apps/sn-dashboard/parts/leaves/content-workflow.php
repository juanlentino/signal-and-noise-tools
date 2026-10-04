<?php
/**
 * S&N Dashboard: Content > Workflow, painted from the kit.
 *
 * The native twin of inc/admin-forms/workflow-page.php: the same
 * `workflow[...]` names in the same order and the same action
 * (workflow_save, sn_handle_workflow_save()). Laid out like Now and Uses:
 * every field sits in a compact card, cards pair two-up, and each list ends
 * in one blank spare card under the classic template's token (`__M__`,
 * `__R__`); a blank row is pruned at save. The "Show on page" checkbox posts
 * nothing when unchecked, and absent means hidden (sn_workflow_normalize()),
 * so a new row is hidden until checked.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-cards-parts.php';

/** A compact card around stacked fields, the Now/Uses card. @param string $fields @return string */
function workflow_card( $fields ) {
	return \snt_kit_tag( 'os-card', array( 'compact' => true ), '<os-stack gap="8">' . $fields . '</os-stack>' );
}

/**
 * One map card: step, line, Show on page.
 *
 * @param string              $index Row index, or `__M__` for the spare.
 * @param array<string,mixed> $row   title, line, show.
 * @param string              $hint  Optional hint under the step field.
 * @return string
 */
function workflow_map_card( $index, array $row, $hint = '' ) {
	$p = 'workflow[map][' . $index . ']';
	return workflow_card(
		\snt_kit_field( 'text', $p . '[title]', __( 'Map step', 'signal-and-noise-tools' ), (string) ( $row['title'] ?? '' ), array( 'placeholder' => 'Drafting', 'hint' => '' !== $hint ? $hint : null ) )
		. \snt_kit_field( 'text', $p . '[line]', __( 'Line', 'signal-and-noise-tools' ), (string) ( $row['line'] ?? '' ), array( 'placeholder' => 'One sentence on what happens here' ) )
		. \snt_kit_field( 'checkbox', $p . '[show]', __( 'Show on page', 'signal-and-noise-tools' ), true === ( $row['show'] ?? false ) )
	);
}

/**
 * One rule card: rule, explanation.
 *
 * @param string              $index Row index, or `__R__` for the spare.
 * @param array<string,mixed> $row   rule, explanation.
 * @param string              $hint  Optional hint under the rule field.
 * @return string
 */
function workflow_rule_card( $index, array $row, $hint = '' ) {
	$p = 'workflow[rules][' . $index . ']';
	return workflow_card(
		\snt_kit_field( 'text', $p . '[rule]', __( 'Rule', 'signal-and-noise-tools' ), (string) ( $row['rule'] ?? '' ), array( 'hint' => '' !== $hint ? $hint : null ) )
		. \snt_kit_field( 'textarea', $p . '[explanation]', __( 'Explanation', 'signal-and-noise-tools' ), (string) ( $row['explanation'] ?? '' ), array( 'rows' => 3 ) )
	);
}

/**
 * The page-level cards, paired: Page beside the section headings, the
 * sample's text beside its Body.
 *
 * @param array<string,mixed> $doc Normalized document.
 * @return string[]
 */
function workflow_top_cards( array $doc ) {
	$s = $doc['sample'];
	return array(
		workflow_card(
			\snt_kit_field( 'text', 'workflow[title]', __( 'Page title', 'signal-and-noise-tools' ), $doc['title'], array( 'placeholder' => 'Workflow' ) )
			. \snt_kit_field( 'textarea', 'workflow[dek]', __( 'Dek (also the meta description)', 'signal-and-noise-tools' ), $doc['dek'], array( 'rows' => 3 ) )
		),
		workflow_card(
			\snt_kit_field( 'text', 'workflow[map_heading]', __( 'Map heading', 'signal-and-noise-tools' ), $doc['map_heading'], array( 'placeholder' => 'The rest of the set' ) )
			. \snt_kit_field( 'text', 'workflow[rules_heading]', __( 'Rules heading', 'signal-and-noise-tools' ), $doc['rules_heading'], array( 'placeholder' => 'Field rules', 'hint' => __( 'Each heading shows only over rows that are on the page.', 'signal-and-noise-tools' ) ) )
		),
		workflow_card(
			\snt_kit_field( 'text', 'workflow[sample][label]', __( 'Sample label', 'signal-and-noise-tools' ), $s['label'], array( 'placeholder' => 'Sample' ) )
			. \snt_kit_field( 'text', 'workflow[sample][title]', __( 'Sample title', 'signal-and-noise-tools' ), $s['title'] )
			. \snt_kit_field( 'textarea', 'workflow[sample][intro]', __( 'Intro', 'signal-and-noise-tools' ), $s['intro'], array( 'rows' => 3 ) )
			. \snt_kit_field( 'textarea', 'workflow[sample][outcome]', __( 'Outcome (optional)', 'signal-and-noise-tools' ), $s['outcome'], array( 'rows' => 3, 'hint' => __( 'Shown before the Body. Write it to stand on its own.', 'signal-and-noise-tools' ) ) )
		),
		workflow_card(
			\snt_kit_field( 'textarea', 'workflow[sample][body]', __( 'Sample body', 'signal-and-noise-tools' ), $s['body'], array( 'rows' => 16, 'hint' => __( 'Shown exactly as typed, whitespace included, in a monospace block.', 'signal-and-noise-tools' ) ) )
		),
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

	$map = array();
	foreach ( $doc['map'] as $i => $row ) {
		$map[] = workflow_map_card( (string) $i, $row );
	}
	$map[]  = workflow_map_card( '__M__', array(), __( 'New map step: fill it in to add it, or leave it empty.', 'signal-and-noise-tools' ) );
	$rules = array();
	foreach ( $doc['rules'] as $i => $row ) {
		$rules[] = workflow_rule_card( (string) $i, $row );
	}
	$rules[] = workflow_rule_card( '__R__', array(), __( 'New rule: fill it in to add it, or leave it empty.', 'signal-and-noise-tools' ) );

	$intro = '<p class="snt-prose">' . sprintf(
		/* translators: %s: link to the /workflow page */
		\snt_kit_esc( __( 'This form is the editor for the %s page. Saving regenerates it; the page does not exist until a save has something to show.', 'signal-and-noise-tools' ) ),
		\snt_kit_link( '/workflow', home_url( '/workflow' ) )
	) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'A map step appears on the public page only when "Show on page" is checked. Unchecked steps stay here and nowhere else.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'The last map card and the last rule card are spares: fill one in to add a row. To remove a row, clear its fields; to reorder, move the text between cards.', 'signal-and-noise-tools' ) ) . '</p>';

	$cards = snt_pair_cards( workflow_top_cards( $doc ) ) . snt_pair_cards( $map ) . snt_pair_cards( $rules );
	$form  = \snt_kit_form( 'workflow_save', '<os-stack gap="12">' . $cards . '</os-stack>', array( 'submit' => __( 'Save workflow page', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Workflow page', 'signal-and-noise-tools' ), $intro . $form );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/workflow'] = __NAMESPACE__ . '\\paint_content_workflow';
		return $painters;
	}
);
