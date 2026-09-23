<?php
/**
 * S&N Dashboard, Monitoring > Health: the finding rows.
 *
 * Split out of monitoring-health-parts.php to keep that file under the house
 * line cap. Every function here is prefixed `health_` (unique across leaves,
 * per the port brief).
 *
 * 17.9.0 (#1624): an `<os-table>`. It could not be one while the component
 * took cells as JSON only: the per-finding Suggest button could neither be
 * painted into it nor be reached by the shared assets/health-suggest-actions.js.
 * OpenStation 1.1.11's slot cells (upstream #874) fixed that: the subject,
 * the Edit link and the Suggest button ride as light-DOM children through
 * snt_kit_table()'s `html` cells, where the script's document delegation still
 * finds them. The table sorts by subject and, marked stack_on_phone, is a card
 * per finding on a phone, with no hidden header and no `!important` collapse.
 *
 * @package SignalNoiseTools
 * @since 17.2.2
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The classic "Suggest all N" batch button (`snt_health_suggest_all_button_html()`)
 * as a kit button: same data contract, N clamped to the same cap.
 *
 * @param int $count Number of findings in the check.
 * @return string
 */
function health_suggest_all_html( $count ) {
	$max = SNT_AI_SUGGEST_ALL_MAX;
	return \snt_kit_tag(
		'os-button',
		array( 'variant' => 'secondary', 'data-snt-suggest-all' => '1', 'data-snt-suggest-all-max' => (string) $max ),
		/* translators: %d is the number of items to suggest */
		\snt_kit_esc( sprintf( __( 'Suggest all %d', 'signal-and-noise-tools' ), min( (int) $count, $max ) ) )
	);
}

/**
 * One check's findings as rows: subject, note, edit and, when the AI column
 * shows, the Suggest button carrying the data contract the classic cell
 * carries (`sn_health_suggest_cell_attrs()`; an empty contract paints an
 * empty cluster, the classic `''` path). Capped at 50 rows with the same
 * "+N more" hint the classic table carries.
 *
 * @param string $key         Check key.
 * @param array  $check       Check envelope.
 * @param bool   $is_advisory Advisory tier (only changes the "+N more" noun).
 * @param bool   $show_ai     Whether the AI fix column paints.
 * @return string
 */
function health_finding_rows_html( $key, array $check, $is_advisory, $show_ai ) {
	$findings = isset( $check['findings'] ) && is_array( $check['findings'] ) ? $check['findings'] : array();
	$visible  = array_slice( $findings, 0, 50 );
	$hidden   = count( $findings ) - count( $visible );

	$columns = array(
		array( 'key' => 'subject', 'label' => __( 'Subject', 'signal-and-noise-tools' ), 'sortable' => true, 'stack' => 'title' ),
		array( 'key' => 'note', 'label' => __( 'Note', 'signal-and-noise-tools' ) ),
		array( 'key' => 'edit', 'label' => __( 'Edit', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	if ( $show_ai ) {
		$columns[] = array( 'key' => 'ai', 'label' => __( 'AI fix', 'signal-and-noise-tools' ), 'stack' => 'actions' );
	}
	$rows = array();
	foreach ( $visible as $f ) {
		$edit    = (string) ( $f['edit_url'] ?? '' );
		// esc_url() (classic) blanks a disallowed scheme; snt_kit_link() does not.
		$edit    = preg_match( '#^https?://#i', $edit ) ? $edit : '';
		$subject = (string) ( $f['subject_label'] ?? '' );
		$row     = array(
			'subject' => array( 'html' => \snt_kit_code( $subject, false ), 'text' => $subject ),
			'note'    => (string) ( $f['note'] ?? '' ),
			'edit'    => array( 'html' => '' !== $edit ? \snt_kit_link( __( 'Edit', 'signal-and-noise-tools' ), $edit ) : '', 'text' => '' ),
		);
		if ( $show_ai ) {
			$attrs     = sn_health_suggest_cell_attrs( $key, $f );
			$row['ai'] = array(
				'html' => $attrs ? \snt_kit_tag( 'os-button', array( 'variant' => 'secondary' ) + $attrs, \snt_kit_esc( __( 'Suggest', 'signal-and-noise-tools' ) ) ) : '',
				'text' => '',
			);
		}
		$rows[] = $row;
	}
	$out = \snt_kit_table( $columns, $rows, array( 'stack_on_phone' => true, 'striped' => false ) );
	if ( $hidden > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: 1: hidden row count, 2: "findings" or "advisories" */
				__( '+%1$d more %2$s: re-run scan after fixing the top batch.', 'signal-and-noise-tools' ),
				$hidden,
				$is_advisory ? __( 'advisories', 'signal-and-noise-tools' ) : __( 'findings', 'signal-and-noise-tools' )
			)
		) . '</p>';
	}
	return $out;
}
