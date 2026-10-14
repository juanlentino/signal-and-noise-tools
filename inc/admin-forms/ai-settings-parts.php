<?php
/**
 * Signal & Noise Tools: AI › Models & Budget, the words both twins print,
 * split from inc/admin-forms/ai-settings.php (house line cap, review on
 * #2022): the semantic-embeddings sentence and the Copilot row.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The semantic-embeddings state in one sentence, the same on both twins: what
 * runs, or what is missing (2026-10-12 audit: one twin said "never shown to a
 * reader", the other "nothing the site serves uses them yet", and the
 * unconfigured state was a bare "Not configured." pill).
 *
 * @param bool   $configured Whether snt_ml_embed_configured().
 * @param string $account_id The Cloudflare account ID ('' when unset).
 * @return string
 */
function sn_admin_ai_embed_sentence( $configured, $account_id ) {
	if ( $configured ) {
		return __( 'Embeddings run in SHADOW mode: they are computed and compared against the existing ranking, and nothing the site serves uses them yet.', 'signal-and-noise-tools' );
	}
	return '' === (string) $account_id
		? __( 'Not configured: no Cloudflare account ID is set, so nothing is embedded. Set it under', 'signal-and-noise-tools' )
		: __( 'Not configured: the Workers AI token is not set, so nothing is embedded. Set it under', 'signal-and-noise-tools' );
}

/**
 * The Copilot row the native leaf paints under the features (#1597): Ask AI
 * runs on the same connector but outside the cap, priced at list rates. Ported
 * to the classic page on the 2026-10-12 audit (it was native-only).
 *
 * @return void
 */
function sn_admin_ai_copilot_row() {
	if ( ! function_exists( 'snt_ai_copilot_spend_this_month' ) ) {
		return;
	}
	$copilot  = (float) snt_ai_copilot_spend_this_month();
	$unpriced = function_exists( 'snt_ai_copilot_unpriced_this_month' ) ? (int) snt_ai_copilot_unpriced_this_month() : 0;
	echo '<div class="sn-field"><p class="sn-field-helper">' . esc_html__( 'Copilot (Ask AI), not counted against the cap', 'signal-and-noise-tools' ) . ': $' . esc_html( number_format_i18n( $copilot, $copilot > 0 && $copilot < 0.01 ? 4 : 2 ) ) . '</p>';
	echo '<p class="sn-field-helper">' . esc_html__( 'Copilot is priced at list rates from the tokens the station reports, with no prompt-cache discount, so it reads high on a warm cache.', 'signal-and-noise-tools' ) . '</p>';
	if ( $unpriced > 0 ) {
		/* translators: %s: Copilot turns. */
		echo '<p class="sn-field-helper">' . esc_html( sprintf( _n( '%s Copilot turn this month reported no usage, or a model with no list price on file, and is not in the dollar figure.', '%s Copilot turns this month reported no usage, or a model with no list price on file, and are not in the dollar figure.', $unpriced, 'signal-and-noise-tools' ), number_format_i18n( $unpriced ) ) ) . '</p>';
	}
	// OpenStation 1.1.14: agent runs, the same pricing in a bucket of their own.
	$agents   = function_exists( 'snt_ai_agents_spend_this_month' ) ? (float) snt_ai_agents_spend_this_month() : 0.0;
	$unpriced = function_exists( 'snt_ai_agents_unpriced_this_month' ) ? (int) snt_ai_agents_unpriced_this_month() : 0;
	echo '<p class="sn-field-helper">' . esc_html__( 'Agents, not counted against the cap', 'signal-and-noise-tools' ) . ': $' . esc_html( number_format_i18n( $agents, $agents > 0 && $agents < 0.01 ? 4 : 2 ) ) . '</p>';
	echo '<p class="sn-field-helper">' . esc_html( sn_admin_ai_agents_note() ) . '</p>';
	if ( $unpriced > 0 ) {
		echo '<p class="sn-field-helper">' . esc_html( sn_admin_ai_agents_unpriced( $unpriced ) ) . '</p>';
	}
	echo '</div>';
}

/**
 * The Agents pricing note, the same on both twins.
 *
 * @return string
 */
function sn_admin_ai_agents_note() {
	return __( 'Agents are priced the same way, at list rates from the tokens each run reports. A station older than OpenStation 1.1.14 reports no usage for agent runs, so there every run counts as unpriced.', 'signal-and-noise-tools' );
}

/**
 * The Agents unpriced-runs hint, the same on both twins.
 *
 * @param int $count Unpriced agent runs this month.
 * @return string
 */
function sn_admin_ai_agents_unpriced( $count ) {
	/* translators: %s: agent runs. */
	return sprintf( _n( '%s agent run this month reported no usage, or a model with no list price on file, and is not in the dollar figure.', '%s agent runs this month reported no usage, or a model with no list price on file, and are not in the dollar figure.', $count, 'signal-and-noise-tools' ), number_format_i18n( $count ) );
}
