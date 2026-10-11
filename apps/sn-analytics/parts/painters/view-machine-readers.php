<?php
/**
 * S&N Analytics: view/machine-readers, who reads the site and for what.
 *
 * Owns its chrome (SN_ANALYTICS_OWNS_CHROME): a 7/14/30/90-day window over the
 * page's shared range, then the sections composed in
 * inc/analytics-machine-views.php. Classic twin:
 * inc/analytics-view-machine-readers.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The window control both machine views paint, and the note when the page's
 * range is longer than the sensor keeps.
 *
 * @param int    $days The window.
 * @param string $note snt_mr_view_window()'s note.
 * @return string
 */
function machine_window_control( $days, $note ) {
	$row = '<span class="snt-toolbar__k">' . \snt_kit_esc( __( 'Window', 'signal-and-noise-tools' ) ) . '</span>';
	foreach ( \SN_MR_VIEW_WINDOWS as $token ) {
		$row .= pick( $token . 'd', 'range', (string) $token, $token === (int) $days );
	}
	return '<div class="snt-toolbar"><div class="snt-toolbar__group">' . $row . '</div>'
		. \snt_kit_button( __( 'Refresh', 'signal-and-noise-tools' ), 'refresh', array( 'variant' => 'ghost', 'class' => 'snt-report-refresh' ) ) . '</div>'
		. ( '' !== $note ? '<p class="snt-hint">' . \snt_kit_esc( $note ) . '</p>' : '' );
}

/** The way to the sensor's configuration on the dashboard, with visible text. */
function machine_config_link() {
	return \snt_kit_door( __( 'Configure the edge sensor in S&N Dashboard', 'signal-and-noise-tools' ), function_exists( 'snt_desktop_admin_url' ) ? \snt_desktop_admin_url( 'sn-monitoring', 'machine-readers' ) : admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=machine-readers' ) );
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_view_machine_readers( array $ctx ) {
	if ( ! function_exists( 'snt_mr_view_machine_readers_html' ) ) {
		return '';
	}
	list( $days, $note ) = \snt_mr_view_window( (string) ( $ctx['range'] ?? '30' ) );
	return machine_window_control( $days, $note ) . \snt_mr_view_machine_readers_html(
		\snt_mr_view_data( $days ),
		'kit',
		array(
			'evidence' => view_door( __( 'How each count is known: Reader evidence', 'signal-and-noise-tools' ), 'reader-evidence' ),
			'config'   => machine_config_link(),
		)
	);
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/machine-readers'] = __NAMESPACE__ . '\\paint_view_machine_readers';
		return $painters;
	}
);
