<?php
/**
 * S&N Dashboard: Content > Workflow, painted from the kit.
 *
 * The native twin of inc/admin-forms/workflow-page.php: the same
 * `workflow[...]` names in the same order and the same action
 * (workflow_save, sn_handle_workflow_save()). The page fields sit in compact
 * cards paired two-up, as on Now and Uses. Map, Rules and Proof are Resume's
 * repeater (resume_list(), content-resume-parts.php): an <os-repeater> with
 * Add, Remove and reorder, the classic <template> (`__M__`, `__R__`, `__P__`)
 * inert inside it, driven by assets/resume-admin.js; the form posts rows in
 * screen order and sn_workflow_normalize() reindexes them. The "Show on page"
 * checkbox posts nothing when unchecked, and absent means hidden
 * (sn_workflow_normalize()), so a new row is hidden until checked. The leaf
 * itself (one table of the three lists, one edit region, no folds since
 * 2026-10-11) is content-workflow-lists.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-cards-parts.php';
require_once __DIR__ . '/content-resume-parts.php';
require_once __DIR__ . '/content-resume-edit.php';
require_once __DIR__ . '/content-workflow-lists.php';

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
 * The finding (moved here from content-workflow-lists.php, 2026-10-12): how many rows of each list reach the page.
 *
 * @param array $lists workflow_list_map().
 * @return string
 */
function workflow_finding( array $lists ) {
	$map   = $lists['map'];
	$rules = $lists['rules'];
	$proof = $lists['proof'];
	if ( 0 === $map['rows'] + $rules['rows'] + $proof['rows'] ) {
		return __( 'No map steps, rules or proof links yet.', 'signal-and-noise-tools' );
	}
	return sprintf(
		/* translators: 1: map steps ("4 of 6 map steps"), 2: rules ("8 of 8 rules"), 3: proof links ("3 of 5 proof links") */
		__( '%1$s, %2$s and %3$s show on the page.', 'signal-and-noise-tools' ),
		0 === $map['rows'] ? __( 'no map steps', 'signal-and-noise-tools' )
			/* translators: 1: map steps on the page, 2: map steps in all */
			: sprintf( _n( '%1$s of %2$s map step', '%1$s of %2$s map steps', $map['rows'], 'signal-and-noise-tools' ), number_format_i18n( $map['shown'] ), number_format_i18n( $map['rows'] ) ),
		0 === $rules['rows'] ? __( 'no rules', 'signal-and-noise-tools' )
			/* translators: 1: rules on the page, 2: rules in all */
			: sprintf( _n( '%1$s of %2$s rule', '%1$s of %2$s rules', $rules['rows'], 'signal-and-noise-tools' ), number_format_i18n( $rules['shown'] ), number_format_i18n( $rules['rows'] ) ),
		0 === $proof['rows'] ? __( 'no proof links', 'signal-and-noise-tools' )
			/* translators: 1: proof links on the page, 2: proof links in all */
			: sprintf( _n( '%1$s of %2$s proof link', '%1$s of %2$s proof links', $proof['rows'], 'signal-and-noise-tools' ), number_format_i18n( $proof['shown'] ), number_format_i18n( $proof['rows'] ) )
	);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/workflow'] = __NAMESPACE__ . '\\paint_content_workflow';
		return $painters;
	}
);
