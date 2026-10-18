<?php
/**
 * Signal & Noise Tools: installs per edge-errors day, on the local door only.
 *
 * The SN Systems card already counts a day's plugin and theme installs and
 * says whether they explain its 5xx (inc/desktop-mode-status-extra-edge-parts.php).
 * edge-errors-summary did not, so a 503-heavy day (2026-10-15: 58 against 7
 * installs) had to be rebuilt from git tag times. This adds the same count
 * and verdict to the admin ability, and so to sn-status{edge_errors}.
 *
 * LOCAL ONLY, like get-deploy-status's runtime: the remote twin keeps the
 * shared schema and callback, so the remote contract and the worker do not move.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The pure install rules: one copy, shared with the SN Systems card.
require_once __DIR__ . '/desktop-mode-status-extra-edge-parts.php';

/**
 * One row per day: installs recorded that UTC day and whether they explain
 * every 5xx on it. installs is null without a deploy history (unknown, not
 * zero). explained is null unless the day was read and had a 5xx. PURE given
 * its reads.
 *
 * @param array      $days    edge-errors days[] rows.
 * @param array|null $history Deploy history rows, or null when unavailable.
 * @return array<int,array{day:string,installs:int|null,explained:bool|null}>
 */
function snt_edge_errors_installs_by_day( array $days, $history ) {
	$out = array();
	foreach ( $days as $d ) {
		$day      = (string) ( $d['day'] ?? '' );
		$total    = (int) ( $d['total'] ?? 0 );
		$installs = is_array( $history ) ? snt_desktop_edge_installs_on( $day, $history ) : null;
		$explained = null;
		if ( null !== $installs && 'read' === ( $d['read'] ?? '' ) && $total > 0 ) {
			$rows      = function_exists( 'sn_edge_top_dim' ) ? sn_edge_top_dim( 'err_path_asker', $day, $day, 50 ) : null;
			$explained = snt_desktop_edge_install_explained( $total, $rows ? $rows : null, $installs );
		}
		$out[] = array( 'day' => $day, 'installs' => $installs, 'explained' => $explained );
	}
	return $out;
}

/**
 * The admin ability's output_schema: the shared one plus installs_by_day.
 *
 * @return array<string,mixed>
 */
function snt_edge_errors_local_output_schema() {
	$s = snt_edge_errors_output_schema();
	$s['properties']['installs_by_day'] = array(
		'type'        => 'array',
		'items'       => array(
			'type'       => 'object',
			'properties' => array(
				'day'       => array( 'type' => 'string' ),
				'installs'  => array( 'type' => array( 'integer', 'null' ), 'description' => 'Plugin and theme installs recorded that UTC day (deploy history). Null: no history, unknown.' ),
				'explained' => array( 'type' => array( 'boolean', 'null' ), 'description' => 'True when the installs account for every 5xx that day: each a 503 on the dashboard paths, at most ' . SNT_EDGE_5XX_PER_INSTALL . ' per install. Null when the day was not read or had none.' ),
			),
		),
		'description' => 'Local door only (2026-10-17): installs per day, oldest first, and whether WordPress maintenance during them explains the day\'s 5xx. Same rule as the SN Systems card.',
	);
	return $s;
}

/**
 * Admin execute: the shared summary plus installs_by_day.
 *
 * @param mixed $input Unused.
 * @return array<string,mixed>
 */
function snt_ability_edge_errors_summary_local( $input = null ) {
	$out     = snt_ability_edge_errors_summary( $input );
	$history = function_exists( 'snt_deploy_history_get' ) ? snt_deploy_history_get() : null;
	$out['installs_by_day'] = snt_edge_errors_installs_by_day( (array) $out['days'], is_array( $history ) ? $history : null );
	return $out;
}
