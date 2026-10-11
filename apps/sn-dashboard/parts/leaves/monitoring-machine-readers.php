<?php
/**
 * S&N Dashboard — Monitoring → Machine Readers, painted from the kit.
 *
 * Configuration only since the evidence-basis arc: the readings (who reads,
 * for what, how each count is known) are S&N Analytics views, Machine readers
 * and Reader evidence (inc/analytics-machine-views.php). This leaf keeps the
 * sensor's status, the Site Health verdicts, the worker readout, the settings
 * form and the monthly rights evidence, and links to the views.
 *
 * The classic leaf (inc/machine-readers-admin.php, `snt_mr_render_tab()`, plus
 * its composer inc/machine-readers-compose.php and renderers in
 * inc/machine-readers-render.php / -render-taxonomy.php / -insights.php) reads
 * the sensor once, decides the sensor-pipeline pills, and lays out a hero
 * (pills + KPI chips), an evidence column (rights log, delta cards, unknown
 * agents) and a reference column (six folded lookup tables, the feed table,
 * the edge-worker readout, and the settings form). Same reads, same one form
 * (`sn_action=machine_readers_save`), same fields — the kit's parts instead of
 * wp-admin's widefat tables and `.sn-fieldset` cards.
 *
 * Helpers live in monitoring-machine-readers-parts.php to keep this file (the
 * composition) separate from the presenters (the tables and cards).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-machine-readers-parts.php';
require_once __DIR__ . '/monitoring-machine-readers-health.php';
require_once __DIR__ . '/monitoring-machine-readers-evidence.php';

/**
 * The way to the readings, in S&N Analytics, with visible text.
 *
 * @return string
 */
function machine_readers_analytics_links() {
	$door = static function ( $label, $view ) {
		return \snt_kit_door( $label, function_exists( 'snt_mr_analytics_view_url' ) ? \snt_mr_analytics_view_url( $view ) : admin_url( 'admin.php?page=sn-analytics&sn_view=' . $view ) );
	};
	return '<os-cluster gap="8">' . $door( __( 'Machine readers in S&N Analytics', 'signal-and-noise-tools' ), 'machine-readers' )
		. $door( __( 'Reader evidence in S&N Analytics', 'signal-and-noise-tools' ), 'reader-evidence' ) . '</os-cluster>';
}

/**
 * The hero: the sensor pipeline pills, then the way to the readings.
 *
 * @param array<string,mixed> $d machine_readers_data() output.
 * @return string
 */
function machine_readers_hero_html( array $d ) {
	$pills = function_exists( '\snt_mr_sensor_pills' ) ? \snt_mr_sensor_pills( $d['info'], $d['status'], $d['result'] ) : array();
	$out   = '<p class="snt-hint">' . \snt_kit_esc( __( 'Edge sensor → Analytics Engine → S&N Analytics. Presence checks only, secret values are never shown. Who reads, for what, and how each count is known are read in S&N Analytics.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section( __( 'Sensor status', 'signal-and-noise-tools' ), $out . machine_readers_pills_html( $pills ) . machine_readers_analytics_links() );
}

/**
 * The leaf: configuration only. The sensor's status, the two Site Health
 * verdicts, the worker readout beside the settings form, and the monthly
 * rights evidence (it posts its own forms).
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_machine_readers( array $ctx ) {
	unset( $ctx );
	$d    = machine_readers_data();
	$edge = \snt_kit_section(
		__( 'Edge sensor', 'signal-and-noise-tools' ),
		machine_readers_edge_readout_html( $d['info'] ),
		__( 'The deployed rights-signals Worker, from its version endpoint. Cached for up to 15 minutes, so a fresh deploy can take that long to appear here — purge caches to read it now.', 'signal-and-noise-tools' )
	);
	return machine_readers_hero_html( $d )
		. machine_readers_health_row_html()
		. \snt_kit_grid( array( $edge, machine_readers_settings_html( $d ) ) )
		. machine_readers_rights_evidence_html();
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/machine-readers'] = __NAMESPACE__ . '\\paint_monitoring_machine_readers';
		return $painters;
	}
);
