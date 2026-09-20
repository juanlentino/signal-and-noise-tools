<?php
/**
 * S&N Dashboard, Monitoring > Health: the finding rows.
 *
 * Split out of monitoring-health-parts.php to keep that file under the house
 * line cap. Every function here is prefixed `health_` (unique across leaves,
 * per the port brief).
 *
 * The rows are NOT an `<os-table>`: that component takes its cells as JSON
 * and paints them in its shadow root, so the per-finding Suggest button could
 * neither be painted into it nor be reached by the shared
 * assets/health-suggest-actions.js. The shape is the Block Migrations queue's
 * (content-block-migrations.php): light-DOM `<os-row>`s, the action pair in
 * an `<os-cluster>`, `role="row"` / `role="columnheader"` on the header.
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

	// The classic 55% / auto / 90px split (40% / auto / 90px / 280px with AI), rounded to the 12-column grid.
	$cols   = $show_ai ? array( '4', '3', '1', '4' ) : array( '5', '5', '2' );
	$labels = array( __( 'Subject', 'signal-and-noise-tools' ), __( 'Note', 'signal-and-noise-tools' ), __( 'Edit', 'signal-and-noise-tools' ) );
	if ( $show_ai ) {
		$labels[] = __( 'AI fix', 'signal-and-noise-tools' );
	}
	$head = '';
	foreach ( $labels as $i => $label ) {
		$head .= \snt_kit_tag( 'span', array( 'col' => $cols[ $i ], 'class' => 'snt-col__h', 'role' => 'columnheader' ), \snt_kit_esc( $label ) );
	}
	$rows = \snt_kit_tag( 'os-row', array( 'gap' => '12', 'role' => 'row' ), $head );
	foreach ( $visible as $f ) {
		$edit  = (string) ( $f['edit_url'] ?? '' );
		// esc_url() (classic) blanks a disallowed scheme; snt_kit_link() does not.
		$edit  = preg_match( '#^https?://#i', $edit ) ? $edit : '';
		$cells = \snt_kit_tag( 'div', array( 'col' => $cols[0] ), \snt_kit_code( (string) ( $f['subject_label'] ?? '' ), false ) )
			. \snt_kit_tag( 'div', array( 'col' => $cols[1] ), \snt_kit_esc( (string) ( $f['note'] ?? '' ) ) )
			. \snt_kit_tag( 'div', array( 'col' => $cols[2] ), '' !== $edit ? \snt_kit_link( __( 'Edit', 'signal-and-noise-tools' ), $edit ) : '' );
		if ( $show_ai ) {
			$attrs  = sn_health_suggest_cell_attrs( $key, $f );
			$cells .= \snt_kit_tag(
				'os-cluster',
				array( 'col' => $cols[3], 'gap' => '6' ),
				$attrs ? \snt_kit_tag( 'os-button', array( 'variant' => 'secondary' ) + $attrs, \snt_kit_esc( __( 'Suggest', 'signal-and-noise-tools' ) ) ) : ''
			);
		}
		$rows .= \snt_kit_tag( 'os-row', array( 'gap' => '12' ), $cells );
	}
	$out = \snt_kit_tag( 'os-stack', array( 'gap' => '8' ), $rows );
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
