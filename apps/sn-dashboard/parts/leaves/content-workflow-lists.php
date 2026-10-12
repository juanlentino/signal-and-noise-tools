<?php
/**
 * S&N Dashboard, Content > Workflow: the page fields, the lists ledger, and
 * the one edit region.
 *
 * 2026-10-11, owner rule: no collapsible sections. Map, Rules and Proof were
 * three folds of up to dozens of rows each; open, the form ran far past the
 * page fields. So they are ONE table (rows, how many reach the page, the
 * first entry, an Edit button), and `sn_edit=<list>` paints that list's
 * repeater as the one editor, under its row (2026-10-12). The page fields are
 * a short settings form and stay open.
 *
 * ONE workflow_save form, so every save posts everything typed: the page
 * fields and the open list together, the lists not being edited riding along
 * as hidden carriers (content-resume-edit.php), because
 * sn_workflow_normalize() rebuilds the whole document from the post. Two
 * forms each carried the other's STORED values, so a save from either one
 * dropped what was typed in the other (2026-10-12 review of the audit fix).
 * The form's own footer is hidden; its Save sits where the fields are
 * instead: one under the page fields, one with Cancel under the open list
 * (2026-10-12 audit: Save and Cancel sat at the foot of everything). Both are
 * the same form's submit (workflow_save_row(), assets/os-kit.js).
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
		'proof' => array( 'title' => __( 'Proof links', 'signal-and-noise-tools' ), 'hint' => __( 'Links to evidence a reader can check. A row shows only when "Show on page" is checked and its link is a path on this site (/maturity/) or an https URL.', 'signal-and-noise-tools' ) . ' ' . $reorder, 'first' => 'title', 'row' => 'workflow_proof_row', 'token' => '__P__', 'add' => __( '+ Add proof link', 'signal-and-noise-tools' ), 'noun' => __( 'proof link', 'signal-and-noise-tools' ) ),
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
 * @param bool   $live    Whether /workflow is published.
 * @return array<string,mixed>
 */
function workflow_ledger( array $lists, $editing = '', $live = true ) {
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
		'lead'    => '<p class="snt-prose">' . \snt_kit_esc( workflow_finding( $lists, $live ) ) . '</p>',
		'columns' => array(
			array( 'key' => 'list', 'label' => __( 'List', 'signal-and-noise-tools' ), 'stack' => 'title' ),
			array( 'key' => 'rows', 'label' => __( 'Rows', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'shown', 'label' => __( 'Shows when live', 'signal-and-noise-tools' ), 'align' => 'end' ),
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
 * A Save where the fields are: a submit of the ONE enclosing workflow_save
 * form (assets/os-kit.js submits the os-form around a `data-snt-form-submit`
 * button), and, under an open list, its Cancel (a `go` that clears sn_edit).
 *
 * @param string $cancel The open list, '' for no Cancel.
 * @return string
 */
function workflow_save_row( $cancel = '' ) {
	$cancel_html = '' !== (string) $cancel
		? \snt_kit_button( __( 'Cancel', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => 'workflow', 'anchor' => \snt_kit_row_anchor( (string) $cancel ) ) ) )
		: '';
	return \snt_kit_tag(
		'os-cluster',
		array( 'class' => 'snt-save-row', 'gap' => '8', 'justify' => 'end' ),
		$cancel_html . \snt_kit_tag( 'os-button', array( 'variant' => 'primary', 'data-snt-form-submit' => true ), \snt_kit_esc( __( 'Save workflow page', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * The open list's editor, under its row: the list's repeater, then its Save
 * and Cancel. Its fields belong to the leaf's one form.
 *
 * @param array  $lists workflow_list_map().
 * @param string $key   The list being edited.
 * @return string
 */
function workflow_edit_region( array $lists, $key ) {
	$list = $lists[ $key ];
	return \snt_kit_section(
		$list['title'],
		(string) call_user_func( $list['paint'] ) . workflow_save_row( $key ),
		$list['hint'],
		array( 'id' => 'snt-edit-region', 'tabindex' => '-1', 'data-snt-focus' => true )
	);
}

/**
 * The leaf: ONE workflow_save form holding the page fields (their Save under
 * them), the lists ledger and, when a list is being edited, its editor under
 * its row (its Save and Cancel under it).
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_workflow( array $ctx ) {
	$doc     = function_exists( 'sn_workflow_page_get' ) ? \sn_workflow_page_get() : null;
	$doc     = is_array( $doc ) ? $doc : \sn_workflow_normalize( array() );
	$lists   = workflow_list_map( $doc );
	$editing = resume_edit_key( $ctx, array_keys( $lists ) );
	$state   = \sn_workflow_page_state();
	// 2026-10-12: the list's editor opens under its row (snt_kit_edit_list()).
	$editor = '' !== $editing ? workflow_edit_region( $lists, $editing ) : '';
	$inner  = \snt_kit_section( __( 'Workflow page', 'signal-and-noise-tools' ), workflow_intro( $state ) . snt_pair_cards( workflow_top_cards( $doc ) ) . workflow_save_row() )
		. \snt_kit_edit_list( workflow_ledger( $lists, $editing, 'publish' === $state['status'] ), $editor );
	return \snt_kit_form(
		'workflow_save',
		\snt_kit_stack( $inner, 18 ),
		array(
			// The footer's Save would sit under the ledger, far from both field
			// groups; each has its own Save (sn-dashboard.css hides the footer).
			'class'  => 'snt-form--saves-inline',
			'submit' => __( 'Save workflow page', 'signal-and-noise-tools' ),
			'hidden' => resume_carry_others( $lists, $editing ),
		)
	);
}
