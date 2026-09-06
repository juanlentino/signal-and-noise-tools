<?php
/**
 * S&N Dashboard — Monitoring → Machine Readers, painted from the kit.
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

/**
 * The hero: sensor pipeline pills, then (when the read succeeded) the
 * truncation caveat and the two KPI rows.
 *
 * @param array<string,mixed> $d machine_readers_data() output.
 * @return string
 */
function machine_readers_hero_html( array $d ) {
	$pills = function_exists( '\snt_mr_sensor_pills' ) ? \snt_mr_sensor_pills( $d['info'], $d['status'], $d['result'] ) : array();
	$out   = '<p class="snt-hint">' . \snt_kit_esc( __( 'Edge sensor → Analytics Engine → this tab. Presence checks only, secret values are never shown.', 'signal-and-noise-tools' ) ) . '</p>';
	$out  .= machine_readers_pills_html( $pills );
	if ( ! empty( $d['result']['ok'] ) ) {
		if ( ! empty( $d['result']['truncated'] ) ) {
			$out .= \snt_kit_notice( 'warn', \snt_kit_esc( __( 'The edge capped this read at its row limit, so every figure on this tab — the headline included — is a floor, not a count. A capped read does not look degraded; it looks like fewer machine reads. Narrow the window to get a complete one.', 'signal-and-noise-tools' ) ) );
		}
		$out .= machine_readers_summary_stats_html( $d['rows'], $d['days'], $d['feed_total'] );
		$out .= machine_readers_identity_stats_html( $d['rows'], $d['days'] );
	}
	return \snt_kit_section( __( 'Sensor status', 'signal-and-noise-tools' ), $out );
}

/**
 * The evidence column: what machine readers actually did.
 *
 * @param array<string,mixed> $d machine_readers_data() output.
 * @return string
 */
function machine_readers_evidence_html( array $d ) {
	$out = '<p class="snt-hint">' . \snt_kit_esc( __( 'The rights reservation rides every response, so declared AI-training crawlers receive it whether or not they fetch the rights files directly: a non-zero direct-fetch count means a crawler went looking for the declarations on purpose.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( empty( $d['result']['ok'] ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'No readership data yet: the Sensor status card above says why.', 'signal-and-noise-tools' ) ) . '</p>';
		return \snt_kit_section( __( 'What machine readers did', 'signal-and-noise-tools' ), $out );
	}
	if ( is_array( $d['rights_rows'] ?? null ) ) {
		$out .= machine_readers_rights_html( $d['rights_rows'] );
	}
	if ( ! empty( $d['cards'] ) ) {
		$out .= machine_readers_delta_cards_html( $d['cards'] );
	}
	if ( is_array( $d['unknown_rows'] ?? null ) ) {
		$out .= machine_readers_unknown_html( $d['unknown_rows'] );
	}
	return \snt_kit_section( __( 'What machine readers did', 'signal-and-noise-tools' ), $out );
}

/**
 * The reference column: folded lookup tables (when the read succeeded), the
 * feed table (always — local WP data, honest even with the sensor down), the
 * edge-worker readout, and the settings this leaf owns.
 *
 * @param array<string,mixed> $d machine_readers_data() output.
 * @return string
 */
function machine_readers_reference_html( array $d ) {
	$out = '';
	if ( ! empty( $d['result']['ok'] ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The same window, counted along every axis. Folded because these are lookups, not the headline.', 'signal-and-noise-tools' ) ) . '</p>';
		$out .= machine_readers_fold( __( 'By purpose', 'signal-and-noise-tools' ), machine_readers_purpose_table_html( $d['rows'], $d['days'] ) );
		$out .= machine_readers_fold( __( 'By vendor and purpose', 'signal-and-noise-tools' ), machine_readers_vendor_purpose_html( $d['rows'] ) );
		$out .= machine_readers_fold( __( 'By crawler family', 'signal-and-noise-tools' ), machine_readers_family_table_html( $d['rows'], $d['days'] ) );
		$out .= machine_readers_fold( __( 'By machine surface', 'signal-and-noise-tools' ), machine_readers_surface_table_html( $d['rows'] ) );
		$out .= machine_readers_fold( __( 'Declared-crawler compliance', 'signal-and-noise-tools' ), machine_readers_compliance_html( $d['rows'] ) );
		$out .= machine_readers_fold( __( 'AI-training reconciliation', 'signal-and-noise-tools' ), machine_readers_reconciliation_html( $d['rows'] ) );
	}
	$out .= machine_readers_fold( __( 'Feed fetches', 'signal-and-noise-tools' ), machine_readers_feed_table_html( $d['feed'] ) );

	$out .= \snt_kit_section(
		__( 'Edge sensor', 'signal-and-noise-tools' ),
		machine_readers_edge_readout_html( $d['info'] ),
		__( 'The deployed rights-signals Worker, from its version endpoint. Cached for up to 15 minutes, so a fresh deploy can take that long to appear here — purge caches to read it now.', 'signal-and-noise-tools' )
	);
	$out .= machine_readers_settings_html( $d );
	return \snt_kit_section( __( 'Reference', 'signal-and-noise-tools' ), $out );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_monitoring_machine_readers( array $ctx ) {
	unset( $ctx );
	$d = machine_readers_data();
	return machine_readers_hero_html( $d )
		. '<div class="snt-2up">'
		. '<div class="snt-col">' . machine_readers_evidence_html( $d ) . '</div>'
		. '<div class="snt-col">' . machine_readers_reference_html( $d ) . '</div>'
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['monitoring/machine-readers'] = __NAMESPACE__ . '\\paint_monitoring_machine_readers';
		return $painters;
	}
);
