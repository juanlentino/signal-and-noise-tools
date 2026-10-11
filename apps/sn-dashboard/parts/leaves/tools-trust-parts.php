<?php
/**
 * S&N Dashboard, Integrity > Trust checks: the per-check sections and the
 * public side, split out of tools-trust.php (line cap) on 2026-10-11.
 *
 * Owner rule (2026-10-11): no collapsible sections. Each check's findings sat
 * in an always-open `<os-disclosure>` inside the checks box. A check that
 * needs a look is now its own open section under the paired row: the count
 * as the finding, the shown findings as a ledger (subject, note), and the
 * rest counted out loud as a door to Health. A check that ran and skipped
 * gets a section too, because its reason is the reading: unknown is never
 * zero, so it reads "not measured" and why, never "clear".
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One check's own section, or '' when its row already says everything
 * (clear, or not run: the intro line explains a missing scan).
 *
 * @param string               $label    Check label.
 * @param array<string,mixed>  $reading  From snt_trust_reading().
 * @param array<int,mixed>     $findings The check's findings.
 * @param string               $tab      The painting tab (for the go target).
 * @return string
 */
function trust_check_section( $label, array $reading, array $findings, $tab ) {
	if ( 'skipped' === $reading['state'] ) {
		return \snt_kit_section(
			$label,
			'<p class="snt-prose">' . \snt_kit_esc(
				/* translators: %s: the check's own reason for skipping this scan. */
				sprintf( __( 'Not measured this scan. %s', 'signal-and-noise-tools' ), (string) $reading['reason'] )
			) . '</p>'
		);
	}
	if ( 'findings' !== $reading['state'] ) {
		return '';
	}
	$split   = \snt_trust_findings_split( $findings );
	$rows    = array();
	$subject = false;
	foreach ( $split['shown'] as $finding ) {
		$finding = (array) $finding;
		$name    = (string) ( $finding['subject_label'] ?? '' );
		$url     = (string) ( $finding['subject_url'] ?? '' );
		$url     = preg_match( '#^https?://#i', $url ) ? $url : ''; // snt_kit_link() filters no scheme; a GitHub html_url is outside data.
		$subject = $subject || '' !== $name;
		$rows[]  = array(
			'subject' => '' !== $name && '' !== $url ? array( 'html' => \snt_kit_link( $name, $url ), 'text' => $name ) : $name,
			'note'    => (string) ( $finding['note'] ?? '' ),
		);
	}
	$columns = array( array( 'key' => 'note', 'label' => __( 'Note', 'signal-and-noise-tools' ) ) );
	if ( $subject ) {
		array_unshift( $columns, array( 'key' => 'subject', 'label' => __( 'Subject', 'signal-and-noise-tools' ) ) );
	}
	/* translators: %d: number of findings for one trust check. */
	$finding = sprintf( _n( '%d finding needs a look.', '%d findings need a look.', (int) $reading['count'], 'signal-and-noise-tools' ), (int) $reading['count'] );
	// A count with no finding notes stored paints the count alone, never an empty table.
	$body = array() === $rows
		? '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>'
		: \snt_kit_ledger( $finding, $columns, $rows, count( $rows ) );
	if ( $split['hidden'] > 0 ) {
		$body .= '<p class="snt-hint">' . \snt_kit_go(
			sprintf(
				/* translators: %d: findings not shown here. */
				__( '+%d more on Health →', 'signal-and-noise-tools' ),
				$split['hidden']
			),
			array( 'tab' => 'monitoring', 'sub' => 'health', 'current' => $tab )
		) . '</p>';
	}
	return \snt_kit_section( $label, $body );
}

/**
 * The public side: the surfaces a READER uses to check the same guarantees
 * without trusting this admin at all.
 *
 * @return string
 */
function trust_public_html() {
	$public  = '<p class="snt-prose">' . esc_html__( 'The same guarantees, checkable by anyone without access to this admin.', 'signal-and-noise-tools' ) . '</p>';
	$public .= '<ul class="snt-plain">';
	$public .= '<li>' . \snt_kit_link( __( 'Verification docket', 'signal-and-noise-tools' ), home_url( '/verify/' ) ) . ': ' . esc_html__( 'per-note signature, content hash, live match, and anchor, checked in the reader’s own browser.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '<li>' . \snt_kit_link( __( 'Public ledger', 'signal-and-noise-tools' ), 'https://github.com/juanlentino/signal-and-noise-provenance' ) . ': ' . esc_html__( 'the signed records and their daily verify workflow.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '<li>' . \snt_kit_link( __( 'TDM policy', 'signal-and-noise-tools' ), home_url( '/tdm-policy/' ) ) . ': ' . esc_html__( 'the human-readable terms behind the reservation headers.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '<li>' . \snt_kit_link( __( 'RSL licence', 'signal-and-noise-tools' ), home_url( '/license.xml' ) ) . ': ' . esc_html__( 'machine-readable licensing terms.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '</ul>';
	return \snt_kit_section( __( 'The public side', 'signal-and-noise-tools' ), $public );
}
