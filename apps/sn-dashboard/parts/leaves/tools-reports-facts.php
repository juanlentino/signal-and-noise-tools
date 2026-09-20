<?php
/**
 * S&N Dashboard, Integrity > Reports: the "Plugin environment" box (#1599).
 *
 * Two Site Health Info panels carried facts that reached no leaf. From
 * `snt_dashboard_debug_information()` (inc/dash-debug-info.php): which
 * plugin serves WordPress's own `wp-*` JS handles (the field
 * inc/script-package-origin.php bought with an incident) and the database
 * template and navigation override count. From
 * `sn_footprint_debug_information()` (inc/plugin-footprint.php): the
 * plugin-directory size and the last janitor sweep. Here they are facts
 * rows in one box, from the same readers the panels call. The footprint's
 * per-entry rows are private on the Info panel (filenames), so the leaf
 * paints the total, the legacy-leftover count and the sweep line only.
 * Nothing is computed here. Every row is optional: a reader that is not
 * loaded leaves no row (absent is not zero), and no reader means no box.
 *
 * @package SignalNoiseTools
 * @since 17.4.4
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The footprint rows: total size, legacy leftovers, the last sweep. Empty
 * when the module is absent. $base is only ever overridden by tests, as on
 * the Info panel; production resolves the plugin root.
 *
 * @param string|null $base Override for tests; defaults to the plugin root.
 * @return array<int,array<string,mixed>>
 */
function tools_reports_footprint_rows( $base = null ) {
	if ( ! function_exists( 'sn_footprint_scan' ) || ! function_exists( 'sn_footprint_format_bytes' ) ) {
		return array();
	}
	$base = null !== $base ? (string) $base : ( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __DIR__, 4 ) . '/' );
	// ponytail: one directory walk per paint, capped at the module's file budget, exactly what the Info panel costs on Site Health.
	$scan   = \sn_footprint_scan( rtrim( $base, '/' ) );
	$legacy = 0;
	foreach ( (array) ( $scan['entries'] ?? array() ) as $entry ) {
		if ( ! empty( $entry['is_legacy'] ) ) {
			++$legacy;
		}
	}
	$rows   = array(
		array(
			'label' => __( 'Total plugin-directory size', 'signal-and-noise-tools' ),
			'value' => \sn_footprint_format_bytes( (int) ( $scan['total_bytes'] ?? 0 ) )
				. ( ! empty( $scan['truncated'] ) ? ' (' . __( 'scan truncated at the file budget', 'signal-and-noise-tools' ) . ')' : '' ),
		),
		array(
			'label' => __( 'Legacy deploy leftovers', 'signal-and-noise-tools' ),
			'value' => number_format_i18n( $legacy ),
			'tone'  => $legacy > 0 ? 'warn' : null,
		),
	);
	$line = function_exists( 'sn_footprint_janitor_line' ) ? \sn_footprint_janitor_line( get_option( 'snt_janitor_log' ) ) : '';
	if ( '' !== $line ) {
		$rows[] = array(
			'label' => __( 'Last janitor sweep', 'signal-and-noise-tools' ),
			'value' => $line,
		);
	}
	return $rows;
}

/**
 * The box, or '' when no reader is loaded.
 *
 * @return string
 */
function tools_reports_facts_html() {
	$rows = array();
	if ( function_exists( 'snt_script_package_override_summary' ) ) {
		$rows[] = array(
			'label' => __( 'WordPress JS packages served by', 'signal-and-noise-tools' ),
			'value' => (string) \snt_script_package_override_summary(),
		);
	}
	if ( function_exists( 'snt_dashboard_override_count' ) ) {
		$rows[] = array(
			'label' => __( 'Database template/navigation overrides', 'signal-and-noise-tools' ),
			'value' => number_format_i18n( (int) \snt_dashboard_override_count() ),
		);
	}
	$rows = array_merge( $rows, tools_reports_footprint_rows() );
	if ( array() === $rows ) {
		return '';
	}
	return \snt_kit_section(
		__( 'Plugin environment', 'signal-and-noise-tools' ),
		\snt_kit_kv( $rows ),
		__( 'What Site Health\'s Info tab says about this plugin, from the same reads.', 'signal-and-noise-tools' )
	);
}
