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
 * One check's finding/advisory table: subject, note, edit — capped at 50 rows
 * with the same "+N more" hint the classic table carries.
 *
 * @param array $check       Check envelope.
 * @param bool  $is_advisory Advisory tier (only changes the "+N more" noun).
 * @return string
 */
function health_finding_table_html( array $check, $is_advisory ) {
	$findings = isset( $check['findings'] ) && is_array( $check['findings'] ) ? $check['findings'] : array();
	$visible  = array_slice( $findings, 0, 50 );
	$hidden   = count( $findings ) - count( $visible );

	$rows = array();
	foreach ( $visible as $f ) {
		$rows[] = array(
			'subject' => (string) ( $f['subject_label'] ?? '' ),
			'note'    => (string) ( $f['note'] ?? '' ),
			'edit'    => (string) ( $f['edit_url'] ?? '' ),
		);
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'subject', 'label' => __( 'Subject', 'signal-and-noise-tools' ) ),
			array( 'key' => 'note', 'label' => __( 'Note', 'signal-and-noise-tools' ) ),
			array( 'key' => 'edit', 'label' => __( 'Edit', 'signal-and-noise-tools' ) ),
		),
		$rows,
		array( 'empty' => __( 'No rows.', 'signal-and-noise-tools' ) )
	);
	if ( $hidden > 0 ) {
		$table .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: 1: hidden row count, 2: "findings" or "advisories" */
				__( '+%1$d more %2$s: re-run scan after fixing the top batch.', 'signal-and-noise-tools' ),
				$hidden,
				$is_advisory ? __( 'advisories', 'signal-and-noise-tools' ) : __( 'findings', 'signal-and-noise-tools' )
			)
		) . '</p>';
	}
	return $table;
}

/**
 * One check's card: label, count badge, optional fix hint, the table (an
 * advisory's table sits behind a disclosure, matching the classic `<details>`).
 *
 * @param string $key         Check key.
 * @param array  $check       Check envelope.
 * @param bool   $is_advisory Advisory tier.
 * @return string
 */
function health_finding_card_html( $key, array $check, $is_advisory ) {
	$count = (int) ( $check['count'] ?? 0 );
	$label = (string) ( $check['label'] ?? $key );
	$badge = $is_advisory
		/* translators: %d: advisory count */
		? \snt_kit_badge( 'info', sprintf( _n( '%d advisory', '%d advisories', $count, 'signal-and-noise-tools' ), $count ) )
		/* translators: %d: finding count */
		: \snt_kit_badge( 'warn', sprintf( _n( '%d finding', '%d findings', $count, 'signal-and-noise-tools' ), $count ) );

	$out = '<div class="snt-check"><h3 class="snt-check__h">' . \snt_kit_esc( $label ) . ' ' . $badge . '</h3>';
	if ( ! empty( $check['fix_hint'] ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( (string) $check['fix_hint'] ) . '</p>';
	}
	$table = health_finding_table_html( $check, $is_advisory );
	$out  .= $is_advisory
		? \snt_kit_tag(
			'os-disclosure',
			/* translators: %d: advisory count */
			array( 'heading' => sprintf( _n( 'Show %d advisory', 'Show %d advisories', $count, 'signal-and-noise-tools' ), $count ) ),
			$table
		)
		: $table;
	$out .= '</div>';
	return $out;
}

/**
 * The Findings section: faults grouped by family, advisories folded under
 * their own subhead — same shape as `sn_health_render_findings_section()`.
 *
 * @param array<string,array> $faults     Non-advisory checks with findings.
 * @param array<string,array> $advisories Advisory-tier checks with findings.
 * @return string
 */
function health_findings_html( array $faults, array $advisories ) {
	if ( empty( $faults ) && empty( $advisories ) ) {
		return '';
	}
	$inner   = '';
	$grouped = function_exists( 'sn_health_group_checks_by_family' )
		? sn_health_group_checks_by_family( $faults )
		: array( 'other' => array( 'label' => __( 'Other checks', 'signal-and-noise-tools' ), 'checks' => $faults ) );
	foreach ( $grouped as $family ) {
		if ( empty( $family['checks'] ) ) {
			continue;
		}
		$inner .= '<h3 class="snt-subhead">' . \snt_kit_esc( (string) $family['label'] ) . '</h3>';
		foreach ( $family['checks'] as $key => $check ) {
			$inner .= health_finding_card_html( $key, $check, false );
		}
	}
	if ( ! empty( $advisories ) ) {
		$inner .= '<h3 class="snt-subhead">' . \snt_kit_esc( __( 'Advisories', 'signal-and-noise-tools' ) ) . '</h3>';
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Surfaced, never alarming: these do not count toward the findings total above, and a clean site can carry them indefinitely.', 'signal-and-noise-tools' ) ) . '</p>';
		foreach ( $advisories as $key => $check ) {
			$inner .= health_finding_card_html( $key, $check, true );
		}
	}
	return \snt_kit_section( __( 'Findings', 'signal-and-noise-tools' ), $inner );
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
		$inner .= '<div class="snt-check"><h3 class="snt-check__h">' . \snt_kit_esc( (string) ( $check['label'] ?? $key ) ) . ' ' . \snt_kit_badge( 'neutral', __( 'report', 'signal-and-noise-tools' ) ) . '</h3>';
		$inner .= ! empty( $report['coverage'] )
			? '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'What this covers:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $report['coverage'] ) . '</p>'
			: '<p class="snt-hint">' . \snt_kit_esc( __( 'This report has no detail view yet — its payload is available through the health-scan ability.', 'signal-and-noise-tools' ) ) . '</p>';
		$inner .= '</div>';
	}
	return \snt_kit_section( __( 'Reports', 'signal-and-noise-tools' ), $inner );
}

/**
 * The collapsed passing disclosure: the summary line + names by family.
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
		$inner .= '<h4 class="snt-subhead">' . \snt_kit_esc( (string) $family['label'] ) . '</h4><p class="snt-chips">';
		foreach ( $family['checks'] as $check ) {
			$inner .= \snt_kit_chip( (string) ( $check['label'] ?? '' ) );
		}
		$inner .= '</p>';
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
