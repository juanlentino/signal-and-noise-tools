<?php
/**
 * S&N Dashboard — Monitoring → Health, painted from the kit.
 *
 * The classic leaf (inc/health-checks-admin.php, `sn_health_render_admin_tab()`,
 * hooked at `sn_admin_health_tab`) paints: a first-glance hero (findings,
 * checks-passed, last-scan age — `snt_health_glance_cards()`, reused as-is),
 * one form (`sn_action=health_scan`, no fields besides the shared nonce/action,
 * posts to the current URL through the shared sn_action table — same as
 * cf_save/pl_save), and — once a scan exists — the Findings section (faults by
 * family, then advisories folded), the Reports section (report-only checks;
 * always empty on THIS surface since contrast_tokens/motion_scan render on
 * Integrity — v11.13.0), the Passing disclosure, the Skipped disclosure, and
 * the "Also scanned, shown elsewhere" index. Same reads (the shared
 * `inc/health-summary.php` / `inc/health-check-families.php` /
 * `inc/health-check-surfaces.php` accessors every other surface uses), the
 * kit's parts instead of wp-admin's tables and fieldsets.
 *
 * NOT PORTED: the per-finding "Suggest" / "Apply" AI buttons. The classic
 * leaf's own docblock says they are plain `<button type="button">` wired to
 * client JS (assets/health-suggest-actions.js) hitting the REST Abilities
 * endpoint directly — not the sn_action replay pipeline a window understands,
 * and inline scripts never run in a window. See the report for the reasoning.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-health-parts.php';

/**
 * The module's state, read the way the classic leaf reads it — same accessors
 * (`inc/health-summary.php`, `inc/health-check-surfaces.php`,
 * `inc/health-check-families.php`), same three-way split (findings / reports /
 * passing), same "elsewhere" grouping. Mirrors
 * `sn_health_render_admin_tab()` line for line where it reads rather than echoes.
 *
 * @return array<string,mixed>
 */
function health_data() {
	$last_scan   = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	$health_scan = is_array( $last_scan ) ? $last_scan : null;
	if ( is_array( $health_scan ) && function_exists( 'sn_health_checks_for_surface' ) ) {
		$health_scan['checks'] = sn_health_checks_for_surface( $last_scan, 'health' );
	}
	$glance = function_exists( 'snt_health_glance_cards' ) ? snt_health_glance_cards( $health_scan ) : array();

	if ( ! is_array( $health_scan ) ) {
		return array( 'scan' => null, 'glance' => $glance );
	}

	$advisory_keys = function_exists( 'sn_health_advisory_checks' ) ? sn_health_advisory_checks() : array();
	$with_findings = array();
	foreach ( (array) $health_scan['checks'] as $key => $check ) {
		if ( function_exists( 'sn_health_check_has_report' ) && sn_health_check_has_report( $check ) ) {
			continue;
		}
		if ( (int) ( $check['count'] ?? 0 ) > 0 ) {
			$with_findings[ $key ] = $check;
		}
	}
	$faults     = array();
	$advisories = array();
	foreach ( $with_findings as $key => $check ) {
		if ( in_array( (string) $key, $advisory_keys, true ) ) {
			$advisories[ $key ] = $check;
		} else {
			$faults[ $key ] = $check;
		}
	}

	$elsewhere_groups = array(
		'integrity' => array( 'Integrity → Trust checks and Reports', 'proof and measurement: they publish rather than flag' ),
		'deploy'    => array( 'Deploy Status', "facts about a repo or worker, not this site's content" ),
		'worklist'  => array( 'the scan door and Analytics recommendations', 'opportunities that never resolve and never block — a queue, not a fault' ),
	);
	$elsewhere = array();
	foreach ( $elsewhere_groups as $surface => $meta ) {
		$checks = function_exists( 'sn_health_checks_for_surface' ) ? sn_health_checks_for_surface( $last_scan, $surface ) : array();
		if ( ! $checks ) {
			continue;
		}
		$labels = array();
		foreach ( $checks as $key => $check ) {
			$n        = (int) ( $check['count'] ?? 0 );
			$label    = (string) ( $check['label'] ?? $key );
			$labels[] = $n > 0 ? sprintf( '%s (%s)', $label, number_format_i18n( $n ) ) : $label;
		}
		sort( $labels );
		$elsewhere[] = array( 'title' => $meta[0], 'why' => $meta[1], 'labels' => $labels );
	}

	return array(
		'scan'         => $health_scan,
		'glance'       => $glance,
		'faults'       => $faults,
		'advisories'   => $advisories,
		'reports'      => function_exists( 'sn_health_report_checks' ) ? sn_health_report_checks( $health_scan ) : array(),
		'passing'      => function_exists( 'sn_health_passing_checks' ) ? sn_health_passing_checks( $health_scan ) : array(),
		'skipped'      => function_exists( 'sn_health_skipped_checks' ) ? sn_health_skipped_checks( $health_scan ) : array(),
		'check_total'  => function_exists( 'sn_health_check_total' ) ? sn_health_check_total( $health_scan ) : 0,
		'report_count' => function_exists( 'sn_health_report_checks' ) ? count( sn_health_report_checks( $health_scan ) ) : 0,
		'elsewhere'    => $elsewhere,
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_health( array $ctx ) {
	unset( $ctx );
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$data = health_data();

	$out  = health_hero_html( (array) $data['glance'] );
	$out .= health_scan_form_html( null !== $data['scan'] );
	if ( null === $data['scan'] ) {
		return $out;
	}

	$out .= health_findings_html( (array) $data['faults'], (array) $data['advisories'] );
	$out .= health_reports_html( (array) $data['reports'] );
	$out .= health_passing_html( (array) $data['passing'], (int) $data['check_total'], (int) $data['report_count'] );
	$out .= health_skipped_html( (array) $data['skipped'] );
	$out .= health_elsewhere_html( (array) $data['elsewhere'] );
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/health'] = __NAMESPACE__ . '\\paint_monitoring_health';
		return $painters;
	}
);
