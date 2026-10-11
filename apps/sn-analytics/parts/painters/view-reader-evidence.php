<?php
/**
 * S&N Analytics: view/reader-evidence, how each machine-reader count is known.
 *
 * Owns its chrome: the same window control as Machine readers, then the
 * sections composed in inc/analytics-machine-views.php. Classic twin:
 * inc/analytics-view-reader-evidence.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_view_reader_evidence( array $ctx ) {
	if ( ! function_exists( 'snt_mr_view_reader_evidence_html' ) ) {
		return '';
	}
	list( $days, $note ) = \snt_mr_view_window( (string) ( $ctx['range'] ?? '30' ) );
	return machine_window_control( $days, $note ) . \snt_mr_view_reader_evidence_html( \snt_mr_view_data( $days, true ), 'kit', array( 'config' => machine_config_link() ) );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/reader-evidence'] = __NAMESPACE__ . '\\paint_view_reader_evidence';
		return $painters;
	}
);
