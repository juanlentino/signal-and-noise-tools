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

/**
 * The hero stat row — same three (or one) cards `snt_health_glance_cards()`
 * builds for the classic tab.
 *
 * @param array<int,array<string,mixed>> $glance From snt_health_glance_cards().
 * @return string
 */
function health_hero_html( array $glance ) {
	$out = '';
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
		$out    .= \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), $caption, $kind );
	}
	return '<div class="snt-stats">' . $out . '</div>';
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
 * One check's card: label, count badge, the Suggest-all button when the AI
 * column shows (the classic gate: a provider, and a check with a Suggest
 * path), optional fix hint, the rows (an advisory's rows sit behind a
 * disclosure, matching the classic `<details>`).
 *
 * `<os-card>` with the title, badge and button in its header row (kit-help
 * "Card": the header slot is a flex row with 12px gap), the same
 * `<header><h3>` the Links and Cloudflare cards paint (#1600). The host keeps
 * `class="snt-check"`: health-suggest-actions.js walks
 * `closest('.sn-fieldset,.snt-check')` for Suggest all.
 *
 * @param string $key          Check key.
 * @param array  $check        Check envelope.
 * @param bool   $is_advisory  Advisory tier.
 * @param bool   $ai_available Whether an AI provider is configured.
 * @return string
 */
function health_finding_card_html( $key, array $check, $is_advisory, $ai_available ) {
	$count   = (int) ( $check['count'] ?? 0 );
	$show_ai = $ai_available && function_exists( 'sn_health_suggest_supported_checks' ) && in_array( $key, sn_health_suggest_supported_checks(), true );
	$label = (string) ( $check['label'] ?? $key );
	$badge = $is_advisory
		/* translators: %d: advisory count */
		? \snt_kit_badge( 'info', sprintf( _n( '%d advisory', '%d advisories', $count, 'signal-and-noise-tools' ), $count ) )
		/* translators: %d: finding count */
		: \snt_kit_badge( 'warn', sprintf( _n( '%d finding', '%d findings', $count, 'signal-and-noise-tools' ), $count ) );

	$out = '<header><h3>' . \snt_kit_esc( $label ) . '</h3>' . $badge . ( $show_ai ? health_suggest_all_html( $count ) : '' ) . '</header>';
	if ( ! empty( $check['fix_hint'] ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( (string) $check['fix_hint'] ) . '</p>';
	}
	$rows = health_finding_rows_html( $key, $check, $is_advisory, $show_ai );
	$out .= $is_advisory
		? \snt_kit_tag(
			'os-disclosure',
			/* translators: %d: advisory count */
			array( 'heading' => sprintf( _n( 'Show %d advisory', 'Show %d advisories', $count, 'signal-and-noise-tools' ), $count ) ),
			$rows
		)
		: $rows;
	return \snt_kit_tag( 'os-card', array( 'class' => 'snt-check' ), $out );
}

/**
 * The findings: faults grouped by family, advisories after them, the groups
 * `sn_health_render_findings_section()` paints. One `<os-section>` per family
 * and one for the advisories, its hint as the section's description: the
 * dashboard sheet boxes every section body, so a family section inside a
 * Findings section would be a box in a box (#1600).
 *
 * @param array<string,array> $faults       Non-advisory checks with findings.
 * @param array<string,array> $advisories   Advisory-tier checks with findings.
 * @param bool                $ai_available Whether an AI provider is configured.
 * @return string
 */
function health_findings_html( array $faults, array $advisories, $ai_available ) {
	if ( empty( $faults ) && empty( $advisories ) ) {
		return '';
	}
	$out     = '';
	$grouped = function_exists( 'sn_health_group_checks_by_family' )
		? sn_health_group_checks_by_family( $faults )
		: array( 'other' => array( 'label' => __( 'Other checks', 'signal-and-noise-tools' ), 'checks' => $faults ) );
	foreach ( $grouped as $family ) {
		if ( empty( $family['checks'] ) ) {
			continue;
		}
		$cards = '';
		foreach ( $family['checks'] as $key => $check ) {
			$cards .= health_finding_card_html( $key, $check, false, $ai_available );
		}
		$out .= \snt_kit_section( (string) $family['label'], $cards );
	}
	if ( ! empty( $advisories ) ) {
		$cards = '';
		foreach ( $advisories as $key => $check ) {
			$cards .= health_finding_card_html( $key, $check, true, $ai_available );
		}
		$out .= \snt_kit_section(
			__( 'Advisories', 'signal-and-noise-tools' ),
			$cards,
			__( 'Surfaced, never alarming: these do not count toward the findings total above, and a clean site can carry them indefinitely.', 'signal-and-noise-tools' )
		);
	}
	return $out;
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
 * The collapsed passing disclosure: the summary line + names by family. One
 * `<os-row>` per family, the family in the leaf's own column-header style
 * and its chips in an `<os-cluster>`, the shape the finding rows use (#1600).
 *
 * @param array<string,array> $passing      From sn_health_passing_checks().
 * @param int                 $check_total  From sn_health_check_total().
 * @param int                 $report_count Count of report-only checks.
 * @return string
 */
function health_passing_html( array $passing, $check_total, $report_count ) {
	if ( empty( $passing ) ) {
		return '';
	}
	$summary = function_exists( 'sn_health_passing_summary_text' )
		? sn_health_passing_summary_text( count( $passing ), $check_total, $report_count )
		/* translators: 1: passing count, 2: check total */
		: sprintf( __( '%1$d of %2$d checks passing', 'signal-and-noise-tools' ), count( $passing ), $check_total );

	$inner   = '';
	$grouped = function_exists( 'sn_health_group_checks_by_family' ) ? sn_health_group_checks_by_family( $passing ) : array();
	foreach ( $grouped as $family ) {
		$chips = '';
		foreach ( $family['checks'] as $check ) {
			$chips .= \snt_kit_chip( (string) ( $check['label'] ?? '' ) );
		}
		$inner .= '<os-row gap="12"><span col="3" class="snt-col__h">' . \snt_kit_esc( (string) $family['label'] ) . '</span><os-cluster col="9" gap="6">' . $chips . '</os-cluster></os-row>';
	}
	return \snt_kit_tag( 'os-disclosure', array( 'heading' => $summary, 'hint' => __( 'pass', 'signal-and-noise-tools' ) ), $inner );
}

/**
 * The skipped-checks disclosure: each check, its reason, its fix hint.
 *
 * @param array<string,array> $skipped From sn_health_skipped_checks().
 * @return string
 */
function health_skipped_html( array $skipped ) {
	if ( empty( $skipped ) ) {
		return '';
	}
	$count   = count( $skipped );
	$heading = sprintf(
		/* translators: %d: number of checks that could not run */
		_n( '%d check could not run', '%d checks could not run', $count, 'signal-and-noise-tools' ),
		$count
	);
	$inner = '<p class="snt-prose">' . \snt_kit_esc( __( 'These produced no evidence either way this scan. They are not counted as passed.', 'signal-and-noise-tools' ) ) . '</p><ul class="snt-plain">';
	foreach ( $skipped as $check ) {
		if ( ! is_array( $check ) ) {
			continue;
		}
		$inner .= '<li><b>' . \snt_kit_esc( (string) ( $check['label'] ?? '' ) ) . '</b> — ' . \snt_kit_esc( (string) ( $check['skipped'] ?? '' ) );
		$hint   = trim( (string) ( $check['fix_hint'] ?? '' ) );
		if ( '' !== $hint ) {
			$inner .= '<br><span class="snt-hint">' . \snt_kit_esc( $hint ) . '</span>';
		}
		$inner .= '</li>';
	}
	$inner .= '</ul>';
	return \snt_kit_tag( 'os-disclosure', array( 'heading' => $heading, 'hint' => __( 'not measured', 'signal-and-noise-tools' ) ), $inner );
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
