<?php
/**
 * S&N Dashboard: Integrity > Trust checks, painted from the kit.
 *
 * A pure readout leaf (no forms, no sn_action, no side effects), the twin of
 * the classic `snt_trust_render_section()` (inc/integrity-trust-render.php):
 * four fixed health-check keys (provenance integrity, ledger CI, rights
 * signals, rights anchoring) read out of the cached health scan, plus the
 * public-side links a reader can check without this admin at all.
 *
 * Reads top-down: one tile per check (the reading), the checks table beside
 * the public side, then a row of open sections, one per check that needs a
 * look or was not measured (tools-trust-parts.php). Every reading comes from
 * snt_trust_reading(), shared with the classic leaf, so a skipped check reads
 * "not measured" with its reason on both, never "clear" (2026-10-11).
 *
 * @package SignalNoiseTools
 * @since 13.108.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/tools-trust-parts.php';

/**
 * The glance stats: one `<os-stat>` per trust check, value + label + the
 * pill text as caption, swatched by the pill's kind (a skip is neutral).
 *
 * @param array<int,array<string,mixed>> $cards From snt_trust_cards().
 * @return string
 */
function trust_stats_html( array $cards ) {
	$out = array();
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$out[] = \snt_kit_stat(
			(string) ( $card['value'] ?? '' ),
			(string) ( $card['label'] ?? '' ),
			(string) ( $card['pill']['text'] ?? '' ),
			(string) ( $card['pill']['kind'] ?? '' )
		);
	}
	return \snt_kit_grid( $out, 160, 10 );
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
	if ( ! function_exists( 'snt_trust_cards' ) || ! function_exists( 'snt_trust_reading' ) || ! function_exists( 'snt_trust_findings_split' ) ) {
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
		$intro = '<p class="snt-prose">' . sprintf(
			/* translators: 1: relative time element; 2: link to the Health leaf, already escaped HTML. */
			esc_html__( 'Read from the health scan that ran %1$s: this leaf never scans on its own. Re-run from %2$s.', 'signal-and-noise-tools' ),
			\snt_kit_relative_time( (int) ( $scan['scanned_at'] ?? 0 ) ),
			$health_lnk
		) . '</p>';
	}

	$checks = is_array( $scan ) && isset( $scan['checks'] ) ? (array) $scan['checks'] : array();
	$rows   = array();
	$detail = array();
	foreach ( \snt_trust_check_keys() as $key => $meta ) {
		$reading = \snt_trust_reading( $checks[ $key ] ?? null );
		$rows[]  = array(
			'check'   => (string) $meta['label'],
			'proves'  => html_entity_decode( (string) $meta['blurb'], ENT_QUOTES, 'UTF-8' ),
			'reading' => $reading['text'],
		);
		$detail[] = trust_check_section( (string) $meta['label'], $reading, (array) ( $checks[ $key ]['findings'] ?? array() ), $tab );
	}

	$table = \snt_kit_table(
		array(
			array( 'key' => 'check', 'label' => __( 'Check', 'signal-and-noise-tools' ) ),
			array( 'key' => 'proves', 'label' => __( 'What it proves', 'signal-and-noise-tools' ) ),
			array( 'key' => 'reading', 'label' => __( 'Latest reading', 'signal-and-noise-tools' ) ),
		),
		$rows
	);

	// Siblings of a height: what the checks watch, and what the public sees of
	// them. Then the per-check sections share a row too: each is capped at
	// three findings (SNT_TRUST_FINDINGS_CAP) and a skipped one is a sentence,
	// so stacked full width they were short boxes 1,300px wide (2026-10-12).
	$out .= \snt_kit_grid(
		array(
			\snt_kit_section( __( 'What these four watch', 'signal-and-noise-tools' ), $intro . $table ),
			trust_public_html(),
		)
	);

	return $out . \snt_kit_grid( $detail );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/trust'] = __NAMESPACE__ . '\\paint_tools_trust';
		return $painters;
	}
);
