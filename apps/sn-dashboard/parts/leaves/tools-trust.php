<?php
/**
 * S&N Dashboard — Tools → Trust checks, painted from the kit.
 *
 * A pure readout leaf — no forms, no sn_action, no side effects, same as the
 * classic `snt_trust_render_section()` (inc/integrity-trust-admin.php): four
 * fixed health-check keys (provenance triangle, ledger CI, rights signals,
 * rights anchoring) read out of the cached health scan, plus the public-side
 * links a reader can check without this admin at all.
 *
 * The classic table's third column mixes a status pill with a full findings
 * breakdown (subject link, note, "+N more"). `<os-table>` carries rows as
 * data, not markup, so that breakdown does not fit a cell — it survives as a
 * plain-text summary in the table plus a per-check `<os-disclosure>` beneath
 * it (documented in the leaf's port report as a changed behaviour, same
 * precedent as tools-reports-parts.php's contrast tables).
 *
 * @package SignalNoiseTools
 * @since 13.108.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The glance stats: one `<os-stat>` per trust check, value + label + the
 * pill text as caption, swatched by the pill's kind.
 *
 * @param array<int,array<string,mixed>> $cards From snt_trust_cards().
 * @return string
 */
function trust_stats_html( array $cards ) {
	$out = '';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$out .= \snt_kit_stat(
			(string) ( $card['value'] ?? '' ),
			(string) ( $card['label'] ?? '' ),
			(string) ( $card['pill']['text'] ?? '' ),
			(string) ( $card['pill']['kind'] ?? '' )
		);
	}
	return '<div class="snt-stats">' . $out . '</div>';
}

/**
 * One check's findings breakdown: shown findings as a kit list (subject
 * linked when it has a URL, note as the value), a "+N more" link to Health
 * when more are hidden than shown, inside a disclosure named after the
 * check and its current reading.
 *
 * @param string               $label   Check label.
 * @param string               $reading The reading text ("3 findings" etc).
 * @param array<int,mixed>     $findings Findings from the check.
 * @param string               $tab     The painting tab (for the go target).
 * @return string
 */
function trust_findings_html( $label, $reading, array $findings, $tab ) {
	$split = \snt_trust_findings_split( $findings );
	$rows  = array();
	foreach ( $split['shown'] as $finding ) {
		if ( ! is_array( $finding ) ) {
			continue;
		}
		$subject  = (string) ( $finding['subject_label'] ?? '' );
		$note     = (string) ( $finding['note'] ?? '' );
		$url      = esc_url( (string) ( $finding['subject_url'] ?? '' ) );
		$rows[]   = '' !== $subject
			? array( 'label' => $subject, 'value' => $note, 'href' => $url )
			: array( 'label' => $note );
	}
	$body = \snt_kit_list( $rows );
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
	return \snt_kit_tag( 'os-disclosure', array( 'heading' => (string) $label, 'hint' => (string) $reading, 'open' => true ), $body );
}

/**
 * The Trust checks leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_trust( array $ctx ) {
	$tab = (string) ( $ctx['tab'] ?? 'tools' );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	if ( ! function_exists( 'snt_trust_cards' ) || ! function_exists( 'snt_trust_check_keys' ) || ! function_exists( 'snt_trust_findings_split' ) ) {
		return \snt_kit_empty( __( 'Trust checks are not available.', 'signal-and-noise-tools' ) );
	}

	$scan       = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	$health_go  = array( 'tab' => 'monitoring', 'sub' => 'health', 'current' => $tab );
	$health_lnk = \snt_kit_go( __( 'Measurement → Health', 'signal-and-noise-tools' ), $health_go );

	$out  = '<section aria-label="' . \snt_kit_esc( __( 'Trust checks at a glance', 'signal-and-noise-tools' ) ) . '">';
	$out .= trust_stats_html( \snt_trust_cards( $scan ) );
	$out .= '</section>';

	if ( ! is_array( $scan ) ) {
		$intro = '<p class="snt-prose">' . sprintf(
			/* translators: %s: link to the Health leaf, already escaped HTML. */
			esc_html__( 'No health scan has run yet, so these four have no reading. They ride the same 24-hour cycle as every other check: run one from %s.', 'signal-and-noise-tools' ),
			$health_lnk
		) . '</p>';
	} else {
		$age   = ! empty( $scan['scanned_at'] ) ? human_time_diff( (int) $scan['scanned_at'], time() ) : '';
		$intro = '<p class="snt-prose">' . sprintf(
			/* translators: 1: how long ago the scan ran; 2: link to the Health leaf, already escaped HTML. */
			esc_html__( 'Read from the health scan that ran %1$s ago: this leaf never scans on its own. Re-run from %2$s.', 'signal-and-noise-tools' ),
			esc_html( $age ),
			$health_lnk
		) . '</p>';
	}

	$checks = is_array( $scan ) && isset( $scan['checks'] ) ? (array) $scan['checks'] : array();
	$rows   = array();
	$detail = '';
	foreach ( \snt_trust_check_keys() as $key => $meta ) {
		$has     = isset( $checks[ $key ] ) && is_array( $checks[ $key ] );
		$count   = $has ? (int) ( $checks[ $key ]['count'] ?? 0 ) : -1;
		$reading = ! $has
			? __( 'not run', 'signal-and-noise-tools' )
			: ( 0 === $count
				? __( 'clear', 'signal-and-noise-tools' )
				/* translators: %d: number of findings for one trust check. */
				: sprintf( _n( '%d finding', '%d findings', $count, 'signal-and-noise-tools' ), $count ) );
		$rows[]  = array(
			'check'   => (string) $meta['label'],
			'proves'  => html_entity_decode( (string) $meta['blurb'], ENT_QUOTES, 'UTF-8' ),
			'reading' => $reading,
		);
		if ( $has && $count > 0 ) {
			$detail .= trust_findings_html( (string) $meta['label'], $reading, (array) ( $checks[ $key ]['findings'] ?? array() ), $tab );
		}
	}

	$table = \snt_kit_table(
		array(
			array( 'key' => 'check', 'label' => __( 'Check', 'signal-and-noise-tools' ) ),
			array( 'key' => 'proves', 'label' => __( 'What it proves', 'signal-and-noise-tools' ) ),
			array( 'key' => 'reading', 'label' => __( 'Latest reading', 'signal-and-noise-tools' ) ),
		),
		$rows
	);

	$out .= \snt_kit_section( __( 'What these four watch', 'signal-and-noise-tools' ), $intro . $table . $detail );

	// Public-facing counterparts: the surfaces a READER uses to check the same
	// guarantees without trusting this admin at all.
	$public  = '<p class="snt-prose">' . esc_html__( 'The same guarantees, checkable by anyone without access to this admin.', 'signal-and-noise-tools' ) . '</p>';
	$public .= '<ul class="snt-plain">';
	$public .= '<li>' . \snt_kit_link( __( 'Verification docket', 'signal-and-noise-tools' ), home_url( '/verify/' ) ) . '. ' . esc_html__( 'per-note signature, content hash, live match, and anchor, checked in the reader’s own browser.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '<li>' . \snt_kit_link( __( 'Public ledger', 'signal-and-noise-tools' ), 'https://github.com/juanlentino/signal-and-noise-provenance' ) . '. ' . esc_html__( 'the signed records and their daily verify workflow.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '<li>' . \snt_kit_link( __( 'TDM policy', 'signal-and-noise-tools' ), home_url( '/tdm-policy/' ) ) . '. ' . esc_html__( 'the human-readable terms behind the reservation headers.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '<li>' . \snt_kit_link( __( 'RSL licence', 'signal-and-noise-tools' ), home_url( '/license.xml' ) ) . '. ' . esc_html__( 'machine-readable licensing terms.', 'signal-and-noise-tools' ) . '</li>';
	$public .= '</ul>';
	$out    .= \snt_kit_section( __( 'The public side', 'signal-and-noise-tools' ), $public );

	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/trust'] = __NAMESPACE__ . '\\paint_tools_trust';
		return $painters;
	}
);
