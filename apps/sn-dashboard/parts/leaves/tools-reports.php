<?php
/**
 * S&N Dashboard — Tools → Reports, painted from the kit.
 *
 * The classic leaf (`sn_admin_render_health_reports_section()`, through
 * inc/health-render-reports.php, -contrast.php and -motion.php) reads the last
 * Health scan's `integrity` surface, keeps the checks carrying a `report`
 * payload (a REGISTRY dispatch keyed by check), and prints one card per
 * report: label, a "report" pill, the coverage sentence, then the report's
 * own view, or a "no detail view yet" fallback; before that, the no-scan and
 * no-reports sentences. This leaf paints the SAME reads in the shell's idiom,
 * and since 2026-10-11 with no fold: every tier the classic collapses is an
 * open section here, its rows a capped ledger (owner rule).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/tools-reports-parts.php';
require_once __DIR__ . '/tools-reports-contrast.php';
require_once __DIR__ . '/tools-reports-usage.php';
require_once __DIR__ . '/tools-reports-placement.php';
require_once __DIR__ . '/tools-reports-motion.php';
require_once __DIR__ . '/tools-reports-facts.php';

/**
 * Rows a report's ledger shows before its counted "+N more" line. Every
 * ledger here is sorted worst-first, so ten is the end that matters; at the
 * shared classic caps (25, 60, 50) the leaf was about 120 open rows
 * (2026-10-12 audit). The classic page keeps its caps inside its folds.
 */
const TOOLS_REPORTS_ROWS = 10;

/**
 * The ledger options every report table shares: capped and counted by the
 * leaf, so the table shows every row it lists, never an inner scroller
 * (`os-table.snt-table-full`; live 2026-10-12, ten wrapped rows ran 662px
 * into a 440px scroll box).
 *
 * @param string $more The counted "+N more" sentence.
 * @return array<string,string>
 */
function tools_reports_ledger_opts( $more ) {
	return array( 'class' => 'snt-table-full', 'more' => (string) $more );
}

/**
 * The palette a list's rows share, or '' when they differ. One palette is
 * said once in the box's sentence, not in a column repeating it per row.
 *
 * @param array<int,array<string,mixed>> $rows Usage or conditional rows.
 * @return string
 */
function tools_reports_one_palette( array $rows ) {
	$palettes = array_values( array_unique( array_map( 'strval', array_column( $rows, 'palette' ) ) ) );
	return 1 === count( $palettes ) ? $palettes[0] : '';
}

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
 * A renderer returns its card's body, or (2026-10-11, no folds) a list whose
 * first item is the card's body and the rest open sections painted under it.
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
 * The leaf: the reports, then the environment facts box (#1599), which does
 * not depend on a scan. When the motion report closes the list, the two share
 * a row (measured live 2026-10-12: about 290px and 218px, stacked full width).
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_reports( array $ctx ) {
	$blocks = tools_reports_scan_blocks( $ctx );
	$facts  = tools_reports_facts_html();
	if ( '' !== $facts && 'motion_scan' === array_key_last( $blocks ) ) {
		$blocks['motion_scan'] = \snt_kit_grid( array( $blocks['motion_scan'], $facts ), 290, 18, array( 'class' => 'snt-reports-pair' ) );
		$facts                 = '';
	}
	return implode( '', $blocks ) . $facts;
}

/**
 * The scan's reports, one block per report keyed by check, or the sentence
 * that says why there are none.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return array<string,string>
 */
function tools_reports_scan_blocks( array $ctx ) {
	$data = tools_reports_data();
	if ( 'no_scan' === $data['state'] ) {
		// A link, as Trust checks has it (2026-10-12): plain text sent the reader hunting.
		return array(
			'none' => '<p class="snt-prose">' . sprintf(
				/* translators: %s: link to the Health leaf, already escaped HTML. */
				esc_html__( 'No scan yet: run one from %s.', 'signal-and-noise-tools' ),
				\snt_kit_go( __( 'Measurement → Health', 'signal-and-noise-tools' ), array( 'tab' => 'monitoring', 'sub' => 'health', 'current' => (string) ( $ctx['tab'] ?? 'tools' ) ) )
			) . '</p>',
		);
	}
	if ( 'no_reports' === $data['state'] ) {
		return array( 'none' => '<p class="snt-prose">' . \snt_kit_esc( __( 'The last scan produced no reports.', 'signal-and-noise-tools' ) ) . '</p>' );
	}

	$out = array( 'intro' => '<p class="snt-prose">' . \snt_kit_esc( __( 'Checks that measure and publish rather than flag. Nothing here is a defect list: read the coverage line before reading the numbers.', 'signal-and-noise-tools' ) ) . '</p>' );

	$renderers = tools_reports_renderers();
	foreach ( (array) $data['reports'] as $key => $check ) {
		$report = isset( $check['report'] ) && is_array( $check['report'] ) ? $check['report'] : array();
		$label  = (string) ( $check['label'] ?? $key );

		// The classic card puts a neutral "report" pill on the heading row
		// itself; `<os-section>`'s heading is plain text (escaped as an
		// attribute, no inner markup), so the pill moves to the top of the
		// body instead of sitting beside the title.
		$body = \snt_kit_badge( '', __( 'Report', 'signal-and-noise-tools' ) );
		// Looked up by key at paint time, never the sentence the scan froze.
		$coverage = function_exists( 'sn_health_report_coverage' ) ? \sn_health_report_coverage( (string) $key, $report ) : (string) ( $report['coverage'] ?? '' );
		if ( '' !== $coverage ) {
			$body .= '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'What this covers:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( $coverage ) . '</p>';
		}
		$parts = isset( $renderers[ $key ] ) && is_callable( $renderers[ $key ] )
			? (array) call_user_func( $renderers[ $key ], $report )
			: array( '<p class="snt-hint">' . \snt_kit_esc( __( 'This report has no detail view yet; its payload is available through the health-scan ability.', 'signal-and-noise-tools' ) ) . '</p>' );

		// One report under the next. 2026-10-12, measured live: the contrast
		// report's placement-dependent (653px) and arithmetic (620px) tiers
		// stacked full width; side by side they are within a box of each other.
		$first = array_shift( $parts );
		if ( 'contrast_tokens' === $key && count( $parts ) >= 2 ) {
			$pair    = array_splice( $parts, -2 );
			$parts[] = \snt_kit_grid( $pair, 290, 18, array( 'class' => 'snt-reports-pair' ) );
		}
		$out[ (string) $key ] = \snt_kit_section( $label, $body . $first ) . implode( '', $parts );
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
