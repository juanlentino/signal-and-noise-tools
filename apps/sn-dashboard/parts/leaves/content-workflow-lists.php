<?php
/**
 * S&N Dashboard, Content > Workflow: the page fields, the lists ledger, and
 * the one edit region.
 *
 * 2026-10-11, owner rule: no collapsible sections. Map, Rules and Proof were
 * three folds of up to dozens of rows each; open, the form ran far past the
 * page fields. So they are ONE table (rows, how many reach the page, the
 * first entry, an Edit button), and `sn_edit=<list>` paints that list's
 * repeater as the one editor, under its row (2026-10-12). The page fields are a short settings
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
 * The ledger of the three lists, as snt_kit_edit_list() takes it: the
 * finding as the lead, ONE table, the open list's row index. Never capped.
 *
 * @param array  $lists   workflow_list_map().
 * @param string $editing The open list, '' for none.
 * @return array<string,mixed>
 */
function workflow_ledger( array $lists, $editing = '' ) {
	$rows = array();
	$at   = -1;
	foreach ( $lists as $key => $l ) {
		$at     = (string) $key === (string) $editing ? count( $rows ) : $at;
		$rows[] = array(
			'_key'  => $key,
			'list'  => $l['title'],
			'rows'  => number_format_i18n( $l['rows'] ),
			'shown' => number_format_i18n( $l['shown'] ),
			'first' => resume_clip( $l['first'] ),
			'edit'  => array( 'html' => resume_edit_button( 'workflow', $key, (string) $key === (string) $editing ), 'text' => '' ),
		);
	}
	return array(
		'heading' => __( 'Map, rules and proof', 'signal-and-noise-tools' ),
		'lead'    => '<p class="snt-prose">' . \snt_kit_esc( workflow_finding( $lists ) ) . '</p>',
		'columns' => array(
			array( 'key' => 'list', 'label' => __( 'List', 'signal-and-noise-tools' ), 'stack' => 'title' ),
			array( 'key' => 'rows', 'label' => __( 'Rows', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'shown', 'label' => __( 'On the page', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'first', 'label' => __( 'Opens with', 'signal-and-noise-tools' ) ),
			array( 'key' => 'edit', 'label' => __( 'Edit', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
		),
		'rows'    => $rows,
		'at'      => $at,
		'attrs'   => array( 'id' => 'snt-edit-list', 'tabindex' => '-1', 'data-snt-focus' => true ),
		'table'   => array( 'striped' => false ),
	);
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
	// 2026-10-12: the list's editor opens under its row (snt_kit_edit_list()).
	$editor = '' !== $editing ? \snt_kit_section( $lists[ $editing ]['title'], (string) call_user_func( $lists[ $editing ]['paint'] ), $lists[ $editing ]['hint'], array( 'id' => 'snt-edit-region', 'tabindex' => '-1', 'data-snt-focus' => true ) ) : '';
	$inner  = \snt_kit_section( __( 'Workflow page', 'signal-and-noise-tools' ), $intro . snt_pair_cards( workflow_top_cards( $doc ) ) )
		. \snt_kit_edit_list( workflow_ledger( $lists, $editing ), $editor );
	return \snt_kit_form(
		'workflow_save',
		\snt_kit_stack( $inner, 18 ) . ( '' !== $editing ? resume_edit_cancel( 'workflow', $editing ) : '' ),
		array(
			'submit' => __( 'Save workflow page', 'signal-and-noise-tools' ),
			'hidden' => resume_carry_others( $lists, $editing ),
		)
	);
}
