<?php
/**
 * S&N Dashboard, Content > Workflow: the page fields, the lists ledger, and
 * the one edit region.
 *
 * 2026-10-11, owner rule: no collapsible sections. Map, Rules and Proof were
 * three folds of up to dozens of rows each; open, the form ran far past the
 * page fields. So they are ONE table (rows, how many reach the page, the
 * first entry, an Edit button), and `sn_edit=<list>` paints that list's
 * repeater as the one region below it. The page fields are a short settings
 * form and stay open. Everything sits in the one workflow_save form, and the
 * lists not being edited ride along as hidden carriers
 * (content-resume-edit.php), because sn_workflow_normalize() rebuilds the
 * whole document from the post.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The three lists: title, helper line, rows, how many the public view keeps
 * (sn_workflow_public_data(), the wall every public surface reads), and the
 * painter of the list's repeater.
 *
 * @param array<string,mixed> $doc Normalized document.
 * @return array<string,array{title:string,hint:string,rows:int,shown:int,first:string,paint:callable}>
 */
function workflow_list_map( array $doc ) {
	$public  = \sn_workflow_public_data( $doc );
	$ns      = __NAMESPACE__;
	$reorder = __( 'Add, remove and reorder rows with their buttons, or Alt+Arrow keys on a row; the page shows them in this order.', 'signal-and-noise-tools' );
	$specs   = array(
		'map'   => array( 'title' => __( 'Map steps', 'signal-and-noise-tools' ), 'hint' => __( 'A map step appears on the public page only when "Show on page" is checked. Unchecked steps stay here and nowhere else.', 'signal-and-noise-tools' ) . ' ' . $reorder, 'first' => 'title', 'row' => 'workflow_map_row', 'token' => '__M__', 'add' => __( '+ Add map step', 'signal-and-noise-tools' ), 'noun' => __( 'map step', 'signal-and-noise-tools' ) ),
		'rules' => array( 'title' => __( 'Rules', 'signal-and-noise-tools' ), 'hint' => $reorder, 'first' => 'rule', 'row' => 'workflow_rule_row', 'token' => '__R__', 'add' => __( '+ Add rule', 'signal-and-noise-tools' ), 'noun' => __( 'rule', 'signal-and-noise-tools' ) ),
		'proof' => array( 'title' => __( 'Proof links', 'signal-and-noise-tools' ), 'hint' => __( 'Links to evidence a reader can check. A row shows only when "Show on page" is ticked and its link is a path on this site (/maturity/) or an https URL.', 'signal-and-noise-tools' ) . ' ' . $reorder, 'first' => 'title', 'row' => 'workflow_proof_row', 'token' => '__P__', 'add' => __( '+ Add proof link', 'signal-and-noise-tools' ), 'noun' => __( 'proof link', 'signal-and-noise-tools' ) ),
	);
	$out     = array();
	foreach ( $specs as $key => $spec ) {
		$rows        = (array) $doc[ $key ];
		$out[ $key ] = array(
			'title' => $spec['title'],
			'hint'  => $spec['hint'],
			'rows'  => count( $rows ),
			'shown' => count( (array) ( $public[ $key ] ?? array() ) ),
			'first' => (string) ( $rows[0][ $spec['first'] ] ?? '' ),
			'paint' => static function () use ( $rows, $key, $spec, $ns ) {
				return resume_list( $rows, $ns . '\\' . $spec['row'], 'workflow[' . $key . ']', $spec['token'], $spec['add'], $spec['noun'] );
			},
		);
	}
	return $out;
}

/**
 * The finding: how many rows of each list reach the page.
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

/**
 * The finding over ONE table of the three lists. Never capped.
 *
 * @param array $lists workflow_list_map().
 * @return string
 */
function workflow_ledger( array $lists ) {
	$rows = array();
	foreach ( $lists as $key => $l ) {
		$rows[] = array(
			'_key'  => $key,
			'list'  => $l['title'],
			'rows'  => number_format_i18n( $l['rows'] ),
			'shown' => number_format_i18n( $l['shown'] ),
			'first' => resume_clip( $l['first'] ),
			'edit'  => array( 'html' => resume_edit_button( 'workflow', $key ), 'text' => '' ),
		);
	}
	$columns = array(
		array( 'key' => 'list', 'label' => __( 'List', 'signal-and-noise-tools' ), 'stack' => 'title' ),
		array( 'key' => 'rows', 'label' => __( 'Rows', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'shown', 'label' => __( 'On the page', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'first', 'label' => __( 'Opens with', 'signal-and-noise-tools' ) ),
		array( 'key' => 'edit', 'label' => __( 'Edit', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	return \snt_kit_ledger( workflow_finding( $lists ), $columns, $rows, count( $rows ), array( 'stack_on_phone' => true, 'striped' => false ) );
}

/**
 * The leaf: the page fields, the lists ledger and, when a list is being
 * edited, its region, all in the one workflow_save form.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_workflow( array $ctx ) {
	$doc     = function_exists( 'sn_workflow_page_get' ) ? \sn_workflow_page_get() : null;
	$doc     = is_array( $doc ) ? $doc : \sn_workflow_normalize( array() );
	$lists   = workflow_list_map( $doc );
	$editing = resume_edit_key( $ctx, array_keys( $lists ) );

	$intro = '<p class="snt-prose">' . sprintf(
		/* translators: %s: link to the /workflow page */
		\snt_kit_esc( __( 'This form is the editor for the %s page. Saving regenerates it; the page does not exist until a save has something to show.', 'signal-and-noise-tools' ) ),
		\snt_kit_link( '/workflow', home_url( '/workflow' ) )
	) . '</p>';
	$inner = \snt_kit_section( __( 'Workflow page', 'signal-and-noise-tools' ), $intro . snt_pair_cards( workflow_top_cards( $doc ) ) )
		. \snt_kit_section( __( 'Map, rules and proof', 'signal-and-noise-tools' ), workflow_ledger( $lists ) );
	if ( '' !== $editing ) {
		$inner .= \snt_kit_section( $lists[ $editing ]['title'], (string) call_user_func( $lists[ $editing ]['paint'] ), $lists[ $editing ]['hint'] );
	}
	return \snt_kit_form(
		'workflow_save',
		\snt_kit_stack( $inner, 18 ) . ( '' !== $editing ? resume_edit_cancel( 'workflow' ) : '' ),
		array(
			'submit' => __( 'Save workflow page', 'signal-and-noise-tools' ),
			'hidden' => resume_carry_others( $lists, $editing ),
		)
	);
}
