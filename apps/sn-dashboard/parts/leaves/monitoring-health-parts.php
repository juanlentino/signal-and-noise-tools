<?php
/**
 * S&N Dashboard — Monitoring → Health: painting helpers.
 *
 * Split out of monitoring-health.php to keep that file under the house line
 * cap. Every function here is prefixed `health_` (unique across leaves, per
 * the port brief).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-health-rows.php';
require_once __DIR__ . '/monitoring-health-sections.php'; // the check sections, open (2026-10-11: no folds)

/**
 * The hero stat row — same three (or one) cards `snt_health_glance_cards()`
 * builds for the classic tab.
 *
 * @param array<int,array<string,mixed>> $glance From snt_health_glance_cards().
 * @return string
 */
function health_hero_html( array $glance ) {
	$out = array();
	foreach ( $glance as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind      = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		$pill_text = (string) ( $card['pill']['text'] ?? '' );
		// snt_health_glance_cards() already ran meta_html through esc_html(); decode
		// it back to plain text so the kit helper (which escapes on emission) does
		// not double-encode it.
		$meta    = html_entity_decode( (string) ( $card['meta_html'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$caption = '' !== $pill_text ? trim( $meta . ( '' !== $meta ? ' · ' : '' ) . $pill_text ) : $meta;
		$out[]    = \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), $caption, $kind );
	}
	return \snt_kit_grid( $out, 160, 10 );
}

/**
 * The "Run scan" card: one form, no fields, `sn_action=health_scan`.
 *
 * @param bool $has_scan Whether a scan already exists (relabels the submit).
 * @return string
 */
function health_scan_form_html( $has_scan ) {
	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'Sweeps posts, media, and links for content issues; AI-assisted fixes appear inline when a provider is configured. Results persist until the next scan.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section(
		__( 'Run scan', 'signal-and-noise-tools' ),
		\snt_kit_form( 'health_scan', $body, array( 'submit' => $has_scan ? __( 'Re-run scan', 'signal-and-noise-tools' ) : __( 'Run scan', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * The Reports section: one card per report-only check. On THIS surface the
 * bespoke contrast/motion renderers never fire (those checks render on
 * Integrity, v11.13.0) — this always degrades to the coverage sentence + the
 * generic "no detail view yet" fallback, exactly as the classic dispatcher
 * does for any report without a registered renderer.
 *
 * @param array<string,array> $reports From sn_health_report_checks().
 * @return string
 */
function health_reports_html( array $reports ) {
	if ( empty( $reports ) ) {
		return '';
	}
	$inner = '<p class="snt-prose">' . \snt_kit_esc( __( 'Checks that measure and publish rather than flag. Nothing here is a defect list — read the coverage line before reading the numbers.', 'signal-and-noise-tools' ) ) . '</p>';
	foreach ( $reports as $key => $check ) {
		$report = isset( $check['report'] ) && is_array( $check['report'] ) ? $check['report'] : array();
		$card   = '<header><h3>' . \snt_kit_esc( (string) ( $check['label'] ?? $key ) ) . '</h3>' . \snt_kit_badge( 'neutral', __( 'report', 'signal-and-noise-tools' ) ) . '</header>';
		$card  .= ! empty( $report['coverage'] )
			? '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'What this covers:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $report['coverage'] ) . '</p>'
			: '<p class="snt-hint">' . \snt_kit_esc( __( 'This report has no detail view yet — its payload is available through the health-scan ability.', 'signal-and-noise-tools' ) ) . '</p>';
		$inner .= \snt_kit_tag( 'os-card', array( 'class' => 'snt-check' ), $card );
	}
	return \snt_kit_section( __( 'Reports', 'signal-and-noise-tools' ), $inner );
}

/**
 * "Also scanned, shown elsewhere": the short index of checks that run but
 * render on another surface.
 *
 * @param array<int,array{title:string,why:string,labels:string[]}> $groups From health_data().
 * @return string
 */
function health_elsewhere_html( array $groups ) {
	if ( empty( $groups ) ) {
		return '';
	}
	$inner = '<ul class="snt-plain">';
	foreach ( $groups as $g ) {
		$inner .= '<li><b>' . \snt_kit_esc( (string) $g['title'] ) . '</b> — ' . \snt_kit_esc( (string) $g['why'] ) . ': ' . \snt_kit_esc( implode( ', ', (array) $g['labels'] ) ) . '</li>';
	}
	$inner .= '</ul>';
	return \snt_kit_section(
		__( 'Also scanned, shown elsewhere', 'signal-and-noise-tools' ),
		$inner,
		__( 'These still run on every scan. They are not defects, so they do not belong to a number that should read zero — but nothing here is hidden.', 'signal-and-noise-tools' )
	);
}
