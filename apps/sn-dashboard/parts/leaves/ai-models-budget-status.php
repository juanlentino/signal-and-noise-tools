<?php
/**
 * S&N Dashboard, AI > Models & Budget: the status card.
 *
 * 2026-10-16 (owner, on the Measurement › Analytics leaf: its anatomy goes
 * "throughout the plugin, in some form or shape"; docs/kit-leaf-anatomy.md):
 * one line answers "is the AI budget fine?" with the spend against the cap,
 * whether the Workers AI token is set, and whether a billing read failed.
 * The leaf's intro paragraph is this card's How this works fold. Every
 * warning stays open in the box it concerns (the cap notice on the spend
 * box, the billing notice on the platform box); a chip only repeats it.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The status card: the status line, then the How this works fold.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_status_html( array $d ) {
	$chips = array( models_budget_cap_chip( $d ) );
	if ( $d['embed_has_check'] ) {
		$chips[] = '' !== (string) $d['embed_token']
			? array( 'tone' => 'ok', 'label' => __( 'Workers AI token set', 'signal-and-noise-tools' ) )
			: array( 'tone' => 'warn', 'label' => __( 'Workers AI token not set', 'signal-and-noise-tools' ) );
	}
	foreach ( array( 'ai', 'gh' ) as $key ) {
		if ( is_array( $d[ $key ] ) && empty( $d[ $key ]['ok'] ) ) {
			$chips[] = array( 'tone' => 'warn', 'label' => __( 'A billing read failed', 'signal-and-noise-tools' ) );
			break;
		}
	}
	$how = \snt_kit_how_fold(
		__( 'One monthly budget for every AI feature here; Copilot and agents run outside it.', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( 'Which models this plugin calls, and the ceiling on what they may cost. Every AI feature here (drafts, insights, meta descriptions, alt text) draws on the same monthly budget. Copilot and agents run outside it.', 'signal-and-noise-tools' ) ) . '</p>'
	);
	return \snt_kit_section( __( 'AI budget', 'signal-and-noise-tools' ), \snt_kit_status_line( $chips ) . models_budget_reads_html() . $how );
}

/**
 * The spend against the cap: neutral with no cap, ok under it, danger once
 * it is reached (the same test the spend box's notice uses).
 *
 * @param array $d From models_budget_data().
 * @return array<string,string> A status-line chip.
 */
function models_budget_cap_chip( array $d ) {
	$budget = (float) $d['budget'];
	$spent  = (float) $d['spent'];
	if ( $budget <= 0 ) {
		return array( 'tone' => 'neutral', 'label' => __( 'No monthly cap', 'signal-and-noise-tools' ) );
	}
	if ( $spent >= $budget ) {
		/* translators: 1: spent USD, 2: the cap in USD. */
		return array( 'tone' => 'bad', 'label' => sprintf( __( 'Cap reached: $%1$s of $%2$s', 'signal-and-noise-tools' ), number_format_i18n( $spent, 2 ), number_format_i18n( $budget, 2 ) ) );
	}
	/* translators: 1: spent USD, 2: the cap in USD, 3: percent of the cap. */
	return array( 'tone' => 'ok', 'label' => sprintf( __( '$%1$s of $%2$s used (%3$s%%)', 'signal-and-noise-tools' ), number_format_i18n( $spent, 2 ), number_format_i18n( $budget, 2 ), number_format_i18n( (int) round( ( $spent / $budget ) * 100 ) ) ) );
}

/**
 * When the model lists and prices were last read, or why they were not.
 * Review of #2120: this can say a read failed, so it stays on the open
 * status card, never inside the closed Models and budget fold.
 *
 * @return string
 */
function models_budget_reads_html() {
	if ( ! function_exists( '\\sn_ai_models_status_line' ) || ! function_exists( '\\sn_ai_prices_status_line' ) ) {
		return '';
	}
	return '<p class="snt-hint">' . \snt_kit_esc( \sn_ai_models_status_line() . ' ' . \sn_ai_prices_status_line() ) . '</p>';
}
