<?php
/**
 * S&N Dashboard, AI > Models & Budget: the right column's readouts.
 *
 * The Jev meter (16.6.0) and the platform-reported spend box (17.3.x), both
 * readouts with no classic twin: the classic leaf carries the form and the
 * plugin's own estimate only (inc/admin-forms/ai-settings.php), and its
 * comment says the platform figures are never the estimate's business.
 *
 * @package SignalNoiseTools
 * @since 17.3.1
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * 16.6.0: the Jev meter. The site's own priced ledger per feature per credit
 * cycle, from the tokens each answer reports; a cache hit costs nothing.
 * Absent when the meter module is not loaded.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_jev_html( array $d ) {
	if ( ! is_array( $d['jev'] ) ) {
		return '';
	}
	$j = $d['jev'];
	if ( ! $d['jev_ready'] ) {
		return \snt_kit_section( __( 'Jev', 'signal-and-noise-tools' ), '<p class="snt-prose">' . \snt_kit_esc( __( 'Not connected. Install Connector for TypeSafe Jev and add the key under Settings › Connectors.', 'signal-and-noise-tools' ) ) . '</p>' );
	}
	$out = '<p class="snt-prose">' . sprintf(
		/* translators: 1: spent USD, 2: credit USD, 3: cycle start, 4: cycle end, 5: days left. */
		esc_html__( 'Spent this cycle: $%1$s of the $%2$s credit (%3$s to %4$s, %5$d days left).', 'signal-and-noise-tools' ),
		\snt_kit_esc( number_format_i18n( $j['spent'], $j['spent'] < 0.01 ? 4 : 2 ) ),
		\snt_kit_esc( number_format_i18n( $j['credit'], 2 ) ),
		\snt_kit_esc( $j['cycle']['start'] ),
		\snt_kit_esc( $j['cycle']['end'] ),
		(int) $j['cycle']['days_left']
	) . '</p>';
	if ( $j['credit'] > 0 ) {
		$pct  = (int) round( 100 * $j['spent'] / $j['credit'] );
		$out .= \snt_kit_tag( 'os-progress-bar', array( 'value' => (string) max( 0, min( 100, $pct ) ), 'max' => '100', 'tone' => $j['spent'] >= $j['credit'] ? 'danger' : 'default' ) );
	}
	$rows = array();
	foreach ( $j['by_feature'] as $feature => $r ) {
		$rows[] = array(
			'feature'  => (string) $feature,
			'requests' => number_format_i18n( (int) $r['requests'] ),
			'cached'   => number_format_i18n( (int) $r['cached'] ),
			'tokens'   => number_format_i18n( (int) $r['input_tokens'] ),
			'cost'     => '$' . number_format_i18n( (float) $r['cost'], (float) $r['cost'] < 0.01 ? 4 : 2 ),
		);
	}
	$inner = $out;
	if ( array() !== $rows ) {
		$inner .= \snt_kit_table( array(
			array( 'key' => 'feature', 'label' => __( 'Feature', 'signal-and-noise-tools' ) ),
			array( 'key' => 'requests', 'label' => __( 'Requests', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'cached', 'label' => __( 'Cached', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'tokens', 'label' => __( 'Input tokens', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'cost', 'label' => __( 'Cost', 'signal-and-noise-tools' ), 'align' => 'end' ),
		), $rows );
	} else {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'No request this cycle yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Priced here at $0.042 per million input tokens from what each answer reports; output is free. A cached request hit the connector\'s one-hour cache and cost nothing. Nothing is projected; the console at typesafe.ai is the bill.', 'signal-and-noise-tools' ) ) . ( $j['seeded'] ? ' ' . \snt_kit_esc( __( 'This cycle was seeded from the passes stored before the meter existed.', 'signal-and-noise-tools' ) ) : '' ) . '</p>';
	return \snt_kit_section( __( 'Jev, this cycle', 'signal-and-noise-tools' ), $inner );
}

/**
 * What GitHub and Anthropic themselves billed this month, from the
 * spend-watch readers (inc/spend-watch.php; keyring rows github_token and
 * anthropic_admin_key, each flushing its transient on rotation). Until 17.3.1
 * the only mount was the home widget's full renderer, which nothing calls
 * since 11.30.0; this box is where the figures read now. A row absent from
 * the keyring reads "not set" and a failed read
 * "unknown", never a zero; no "of N", the usage endpoint reports no quota.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_platform_html( array $d ) {
	$labels = array(
		'ai' => __( 'Anthropic bill (month to date)', 'signal-and-noise-tools' ),
		'gh' => __( 'GitHub Actions minutes (account, month to date)', 'signal-and-noise-tools' ),
	);
	$rows   = array();
	$failed = false;
	foreach ( $labels as $key => $label ) {
		$r = $d[ $key ];
		if ( null === $r ) {
			$rows[] = array( 'label' => $label, 'value' => __( 'not set', 'signal-and-noise-tools' ), 'tone' => 'warn' );
			continue;
		}
		if ( empty( $r['ok'] ) ) {
			$failed = true;
			$rows[] = array( 'label' => $label, 'value' => __( 'unknown, the billing read failed', 'signal-and-noise-tools' ), 'tone' => 'warn' );
			continue;
		}
		// Dollars already: sn_spend_ai_sum_amounts() converted cents once.
		$rows[] = array( 'label' => $label, 'value' => 'gh' === $key
			? sprintf(
				/* translators: 1: minutes used, 2: billed dollars */
				__( '%1$s min, $%2$s billed', 'signal-and-noise-tools' ),
				number_format_i18n( (int) $r['used'] ),
				number_format( (float) $r['billed'], 2 )
			)
			: '$' . number_format( (float) $r['total'], 2 ) );
	}
	$inner = $failed ? \snt_kit_notice( 'warn', \snt_kit_esc( __( 'A billing read failed: the key may be wrong or refused. Verify all under Connections › Credentials probes the GitHub token; the Anthropic key has no probe, and this row is its witness.', 'signal-and-noise-tools' ) ) ) : '';
	$inner .= \snt_kit_kv( $rows );
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Both keys are keyring rows; set or rotate under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'ai' ) ) . '. ' . \snt_kit_esc( __( 'Cached six hours; a rotated key drops the cache.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section(
		__( 'Platform-reported, this month', 'signal-and-noise-tools' ),
		$inner,
		__( 'What GitHub and Anthropic themselves report, never estimated. The figure above is this plugin\'s own token estimate.', 'signal-and-noise-tools' )
	);
}
