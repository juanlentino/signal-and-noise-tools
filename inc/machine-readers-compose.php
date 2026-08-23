<?php
/**
 * Signal & Noise — Machine Readers leaf composition (pure).
 *
 * snt_mr_render_tab() used to fetch, decide and arrange in one 118-line
 * function full of echo, so the leaf's COMPOSITION could not be rendered or
 * asserted without a live sensor. This file holds the arrangement alone: it
 * takes already-fetched data plus two pre-rendered fragments and returns the
 * whole leaf as a string. The caller keeps the network and the capability check.
 *
 * The composition follows the house rule recorded in
 * docs/proposals/admin-leaf-composition-2026-08-23.md:
 *
 *   A RIGHT COLUMN EXISTS ONLY WHEN THE LEAF HAS A SECOND JOB.
 *
 * Machine Readers has two — it is a readout that owns its own configuration —
 * so it earns the 2-up. The primary job (evidence: who read the rights) takes
 * the wide side; the secondary (reference tables, then settings) takes the
 * narrow side FOLDED, which is what keeps the two columns the same order of
 * height. Before this, thirteen sections stacked in the left card against a
 * short form on the right, and the right side was empty for thousands of pixels.
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrap a rendered section in the house disclosure, so reference material can sit
 * in the narrow column without setting its height.
 *
 * Returns '' for empty content: a fold whose summary promises a table and then
 * opens on nothing is worse than no fold.
 *
 * @param string $summary Plain-text summary line (escaped here).
 * @param string $html    Already-escaped section markup.
 * @return string
 */
function snt_mr_fold( $summary, $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return '';
	}
	return '<details class="sn-disclosure sn-mr-fold"><summary>'
		. esc_html( (string) $summary )
		. '</summary>' . $html . '</details>';
}

/**
 * Compose the whole leaf.
 *
 * Every value arrives already fetched. Two members are pre-rendered fragments
 * rather than data — 'sensor_status_html' and 'settings_form_html' — because
 * they come from functions with WP dependencies; passing their output keeps this
 * function pure and lets a fixture render the real leaf.
 *
 * @param array $ctx {
 *     @type int    $days               Window length.
 *     @type bool   $ok                 Whether the aggregate read succeeded.
 *     @type array  $rows               Aggregate rows.
 *     @type array  $feed               Feed tracker stats.
 *     @type int    $feed_total         30d feed total, or null.
 *     @type array  $rights_rows        Rights-surface events.
 *     @type array  $unknown_rows       Unclassified-agent rows.
 *     @type array  $delta_cards        Family delta cards.
 *     @type string $sensor_status_html Rendered sensor pills.
 *     @type string $edge_readout_html  Rendered worker-version notice.
 *     @type string $settings_form_html Rendered settings form.
 * }
 * @return string
 */
function snt_mr_compose_tab( $ctx ) {
	$ctx  = (array) $ctx;
	$days = (int) ( $ctx['days'] ?? 30 );
	$rows = is_array( $ctx['rows'] ?? null ) ? $ctx['rows'] : array();
	$ok   = ! empty( $ctx['ok'] );
	$feed = is_array( $ctx['feed'] ?? null ) ? $ctx['feed'] : array();

	$out = '<div class="sn-an-settings-leaf sn-mr-leaf">';

	// ── HERO, full width: connection state, then the numbers.
	// The KPI row moves here out of the left card. It is a summary of the whole
	// leaf, so it belongs beside the status line rather than at the top of one
	// of two columns (owner: "the box with all the numbers should be placed
	// somewhere else").
	$out .= '<div class="sn-fieldset sn-an-pipeline sn-mr-hero">';
	$out .= '<h3 class="sn-fieldset-h">' . esc_html__( 'Sensor status', 'signal-and-noise-tools' ) . '</h3>';
	$out .= '<p class="sn-an-settings-help">' . esc_html__( 'Edge sensor → Analytics Engine → this tab. Presence checks only, secret values are never shown.', 'signal-and-noise-tools' ) . '</p>';
	$out .= (string) ( $ctx['sensor_status_html'] ?? '' );
	if ( $ok ) {
		$out .= snt_mr_render_summary_chips( $rows, $days, $ctx['feed_total'] ?? null );
	}
	$out .= '</div>';

	$out .= '<div class="sn-2up sn-mr-2up">';

	// ── LEFT (wide): the evidence. What actually happened, newest first.
	$out .= '<div class="sn-fieldset sn-mr-evidence">';
	$out .= '<h3 class="sn-fieldset-h">' . esc_html__( 'What machine readers did', 'signal-and-noise-tools' ) . '</h3>';
	$out .= '<p class="sn-an-settings-help">' . esc_html__( 'The rights reservation rides every response, so declared AI-training crawlers receive it whether or not they fetch the rights files directly: a non-zero direct-fetch count means a crawler went looking for the declarations on purpose.', 'signal-and-noise-tools' ) . '</p>';
	if ( $ok ) {
		if ( is_array( $ctx['rights_rows'] ?? null ) ) {
			$out .= snt_mr_render_rights_detail( $ctx['rights_rows'] );
		}
		if ( ! empty( $ctx['delta_cards'] ) ) {
			$out .= snt_mr_render_delta_cards( $ctx['delta_cards'] );
		}
		if ( is_array( $ctx['unknown_rows'] ?? null ) ) {
			$out .= snt_mr_render_unknown_agents( $ctx['unknown_rows'] );
		}
	} else {
		$out .= '<p class="sn-mr-empty">' . esc_html__( 'No readership data yet: the Sensor status card above says why.', 'signal-and-noise-tools' ) . '</p>';
	}
	$out .= '</div>';

	// ── RIGHT (narrow): reference, folded, then the settings this leaf owns.
	$out .= '<div class="sn-fieldset sn-mr-reference">';
	$out .= '<h3 class="sn-fieldset-h">' . esc_html__( 'Reference', 'signal-and-noise-tools' ) . '</h3>';
	if ( $ok ) {
		$out .= '<p class="sn-an-settings-help">' . esc_html__( 'The same window, counted along every axis. Folded because these are lookups, not the headline.', 'signal-and-noise-tools' ) . '</p>';
		$out .= snt_mr_fold( __( 'By purpose', 'signal-and-noise-tools' ), snt_mr_render_purpose_table( $rows, $days ) );
		$out .= snt_mr_fold( __( 'By vendor and purpose', 'signal-and-noise-tools' ), snt_mr_render_vendor_purpose_table( $rows ) );
		$out .= snt_mr_fold( __( 'By crawler family', 'signal-and-noise-tools' ), snt_mr_render_family_table( $rows, $days ) );
		$out .= snt_mr_fold( __( 'By machine surface', 'signal-and-noise-tools' ), snt_mr_render_surface_table( $rows ) );
		$out .= snt_mr_fold( __( 'Declared-crawler compliance', 'signal-and-noise-tools' ), snt_mr_render_compliance( $rows ) );
		$out .= snt_mr_fold( __( 'AI-training reconciliation', 'signal-and-noise-tools' ), snt_mr_render_ai_reconciliation( $rows ) );
	}
	// The feed tracker is local WP data — it stays honest even when the edge
	// sensor is unreachable, so it is rendered on both branches.
	$out .= snt_mr_fold( __( 'Feed fetches', 'signal-and-noise-tools' ), snt_mr_render_feed_table( $feed ) );

	$out .= '<h3 class="sn-fieldset-h">' . esc_html__( 'Edge sensor', 'signal-and-noise-tools' ) . '</h3>';
	$out .= '<p class="sn-an-settings-help">' . esc_html__( 'The deployed rights-signals Worker, from its version endpoint. Cached for up to 15 minutes, so a fresh deploy can take that long to appear here — purge caches to read it now.', 'signal-and-noise-tools' ) . '</p>';
	$out .= (string) ( $ctx['edge_readout_html'] ?? '' );
	$out .= (string) ( $ctx['settings_form_html'] ?? '' );
	$out .= '</div>';

	$out .= '</div>'; // .sn-2up
	$out .= '</div>'; // .sn-an-settings-leaf
	return $out;
}
