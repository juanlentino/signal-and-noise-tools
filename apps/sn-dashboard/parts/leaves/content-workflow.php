<?php
/**
 * S&N Dashboard: Content > Workflow, painted from the kit.
 *
 * The native twin of inc/admin-forms/workflow-page.php: the same
 * `workflow[...]` names in the same order and the same action
 * (workflow_save, sn_handle_workflow_save()). The page fields sit in compact
 * cards paired two-up, as on Now and Uses. Map and Rules are Resume's
 * repeater (resume_list(), content-resume-parts.php): an <os-repeater> with
 * Add, Remove and reorder, the classic <template> (`__M__`, `__R__`) inert
 * inside it, driven by assets/resume-admin.js; the form posts rows in screen
 * order and sn_workflow_normalize() reindexes them. The "Show on page" checkbox posts
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
require_once __DIR__ . '/content-resume-parts.php';

/** A compact card around stacked fields, the Now/Uses card. @param string $fields @return string */
function workflow_card( $fields ) {
	return \snt_kit_tag( 'os-card', array( 'compact' => true ), '<os-stack gap="8">' . $fields . '</os-stack>' );
}

/** One map row in its repeater: step, line, Show on page. @param string $prefix @param array $row {title,line,show} @return string */
function workflow_map_row( $prefix, array $row ) {
	return resume_card(
		$prefix,
		resume_pair(
			resume_text( $prefix . '[title]', __( 'Map step', 'signal-and-noise-tools' ), $row['title'] ?? '', 'Drafting' ),
			resume_text( $prefix . '[line]', __( 'Line', 'signal-and-noise-tools' ), $row['line'] ?? '', 'One sentence on what happens here' )
		) . \snt_kit_field( 'checkbox', $prefix . '[show]', __( 'Show on page', 'signal-and-noise-tools' ), true === ( $row['show'] ?? false ) ),
		true
	);
}

/** One rule row in its repeater: rule, explanation. @param string $prefix @param array $row {rule,explanation} @return string */
function workflow_rule_row( $prefix, array $row ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[rule]', __( 'Rule', 'signal-and-noise-tools' ), $row['rule'] ?? '' )
		. \snt_kit_field( 'textarea', $prefix . '[explanation]', __( 'Explanation', 'signal-and-noise-tools' ), (string) ( $row['explanation'] ?? '' ), array( 'rows' => 3 ) ),
		true
	);
}

/** One proof row in its repeater: title, link, line, Show on page. @param string $prefix @param array $row {title,url,line,show} @return string */
function workflow_proof_row( $prefix, array $row ) {
	return resume_card(
		$prefix,
		resume_pair(
			\snt_kit_field( 'text', $prefix . '[title]', __( 'Title', 'signal-and-noise-tools' ), (string) ( $row['title'] ?? '' ), array( 'placeholder' => 'Maturity index', 'hint' => __( 'The title is the link text. Name where it goes ("Maturity index"), not "here" or "link".', 'signal-and-noise-tools' ) ) ),
			resume_text( $prefix . '[url]', __( 'Link', 'signal-and-noise-tools' ), $row['url'] ?? '', '/maturity/' )
		)
		. resume_text( $prefix . '[line]', __( 'Line', 'signal-and-noise-tools' ), $row['line'] ?? '', 'One sentence on what it proves' )
		. \snt_kit_field( 'checkbox', $prefix . '[show]', __( 'Show on page', 'signal-and-noise-tools' ), true === ( $row['show'] ?? false ) ),
		true
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
			. \snt_kit_field( 'text', 'workflow[rules_heading]', __( 'Rules heading', 'signal-and-noise-tools' ), $doc['rules_heading'], array( 'placeholder' => 'Field rules' ) )
			. \snt_kit_field( 'text', 'workflow[proof_heading]', __( 'Proof heading', 'signal-and-noise-tools' ), $doc['proof_heading'], array( 'placeholder' => 'Check it yourself', 'hint' => __( 'Each heading shows only over rows that are on the page.', 'signal-and-noise-tools' ) ) )
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

	$ns    = __NAMESPACE__;
	$map   = resume_list( $doc['map'], $ns . '\\workflow_map_row', 'workflow[map]', '__M__', __( '+ Add map step', 'signal-and-noise-tools' ), __( 'map step', 'signal-and-noise-tools' ) );
	$rules = resume_list( $doc['rules'], $ns . '\\workflow_rule_row', 'workflow[rules]', '__R__', __( '+ Add rule', 'signal-and-noise-tools' ), __( 'rule', 'signal-and-noise-tools' ) );
	$proof = resume_list( $doc['proof'], $ns . '\\workflow_proof_row', 'workflow[proof]', '__P__', __( '+ Add proof link', 'signal-and-noise-tools' ), __( 'proof link', 'signal-and-noise-tools' ) );

	$intro = '<p class="snt-prose">' . sprintf(
		/* translators: %s: link to the /workflow page */
		\snt_kit_esc( __( 'This form is the editor for the %s page. Saving regenerates it; the page does not exist until a save has something to show.', 'signal-and-noise-tools' ) ),
		\snt_kit_link( '/workflow', home_url( '/workflow' ) )
	) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'A map step appears on the public page only when "Show on page" is checked. Unchecked steps stay here and nowhere else.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'Add, remove and reorder map steps and rules with their buttons, or Alt+Arrow keys on a row; the page shows them in this order.', 'signal-and-noise-tools' ) ) . '</p>';

	$cards = snt_pair_cards( workflow_top_cards( $doc ) )
		. resume_section( __( 'Map', 'signal-and-noise-tools' ), '', count( $doc['map'] ), $map )
		. resume_section( __( 'Rules', 'signal-and-noise-tools' ), '', count( $doc['rules'] ), $rules )
		. resume_section( __( 'Proof', 'signal-and-noise-tools' ), __( 'Links to evidence a reader can check. A row shows only when "Show on page" is ticked and its link is a path on this site (/maturity/) or an https URL.', 'signal-and-noise-tools' ), count( $doc['proof'] ), $proof );
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
