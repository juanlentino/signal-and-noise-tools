<?php
/**
 * S&N Dashboard — Tools → Reports, painted from the kit.
 *
 * The classic leaf (inc/admin-render-sections.php:87-105,
 * `sn_admin_render_health_reports_section()`, delegating to
 * inc/health-render-reports.php `sn_health_render_reports_section()` +
 * inc/health-render-contrast.php + inc/health-render-motion.php) reads the
 * last Health scan's `integrity` surface, keeps only the checks carrying a
 * `report` payload (a REGISTRY dispatch keyed by check, never an if-chain),
 * and prints one card per report: label, a neutral "report" pill, the
 * coverage sentence, then the report's own detail view (contrast_tokens:
 * usage tier + collapsed arithmetic tier; link_isolation: isolated-note
 * table; motion_scan: coverage headline + collapsed uncovered table) or a
 * degrading "no detail view yet" fallback for any other report-only check.
 * Two plain-text fallbacks precede all of that: no scan yet, and a scan with
 * no reports. This leaf paints the SAME reads, in the shell's idiom.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/tools-reports-parts.php';

/**
 * The leaf's data: which of the three states, and the reports to paint.
 *
 * Mirrors `sn_admin_render_health_reports_section()` line for line — same
 * reads, same surface, same "has a report" filter — without its echoing.
 *
 * @return array{state:string,reports?:array<string,array>}
 */
function tools_reports_data() {
	$scan = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	if ( ! is_array( $scan ) ) {
		return array( 'state' => 'no_scan' );
	}
	$integrity = function_exists( 'sn_health_checks_for_surface' ) ? sn_health_checks_for_surface( $scan, 'integrity' ) : array();
	$reports   = array();
	foreach ( (array) $integrity as $key => $check ) {
		if ( function_exists( 'sn_health_check_has_report' ) && sn_health_check_has_report( $check ) ) {
			$reports[ $key ] = $check;
		}
	}
	if ( ! $reports ) {
		return array( 'state' => 'no_reports' );
	}
	return array(
		'state'   => 'reports',
		'reports' => $reports,
	);
}

/**
 * check key => local renderer, mirroring `sn_health_report_renderers()`'s
 * three built-in entries. The classic registry is filterable
 * (`sn_health_report_renderers`) so a module can add its own echoing
 * renderer; that filter is not replayed here — a callback registered for the
 * classic echo-based renderer would not paint kit markup, so applying it
 * would leak wp-admin HTML into this window. Any report-only check outside
 * these three still gets the same degrading fallback the classic registry
 * gives an unknown key, so nothing that ships later renders as broken —
 * only, like the classic page, without a detail view until it is added here.
 *
 * @return array<string,callable>
 */
function tools_reports_renderers() {
	return array(
		'contrast_tokens' => __NAMESPACE__ . '\\tools_reports_render_contrast',
		'link_isolation'  => __NAMESPACE__ . '\\tools_reports_render_link_isolation',
		'motion_scan'     => __NAMESPACE__ . '\\tools_reports_render_motion',
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_reports( array $ctx ) {
	unset( $ctx );
	$data = tools_reports_data();
	if ( 'no_scan' === $data['state'] ) {
		return '<p class="snt-prose">' . \snt_kit_esc( __( 'No scan yet — run one from Measurement → Health.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	if ( 'no_reports' === $data['state'] ) {
		return '<p class="snt-prose">' . \snt_kit_esc( __( 'The last scan produced no reports.', 'signal-and-noise-tools' ) ) . '</p>';
	}

	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Checks that measure and publish rather than flag. Nothing here is a defect list — read the coverage line before reading the numbers.', 'signal-and-noise-tools' ) ) . '</p>';

	$renderers = tools_reports_renderers();
	foreach ( (array) $data['reports'] as $key => $check ) {
		$report = isset( $check['report'] ) && is_array( $check['report'] ) ? $check['report'] : array();
		$label  = (string) ( $check['label'] ?? $key );

		// The classic card puts a neutral "report" pill on the heading row
		// itself; `<os-section>`'s heading is plain text (escaped as an
		// attribute, no inner markup), so the pill moves to the top of the
		// body instead of sitting beside the title.
		$body = \snt_kit_badge( '', __( 'Report', 'signal-and-noise-tools' ) );
		if ( ! empty( $report['coverage'] ) ) {
			$body .= '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'What this covers:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $report['coverage'] ) . '</p>';
		}
		if ( isset( $renderers[ $key ] ) && is_callable( $renderers[ $key ] ) ) {
			$body .= call_user_func( $renderers[ $key ], $report );
		} else {
			$body .= '<p class="snt-hint">' . \snt_kit_esc( __( 'This report has no detail view yet — its payload is available through the health-scan ability.', 'signal-and-noise-tools' ) ) . '</p>';
		}
		$out .= \snt_kit_section( $label, $body );
	}

	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/reports'] = __NAMESPACE__ . '\\paint_tools_reports';
		return $painters;
	}
);
