<?php
/**
 * S&N Analytics — view/content.
 *
 * Classic: snt_analytics_render_view_content() in inc/analytics-view-content.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_view_content( array $ctx ) {
	$from  = (string) $ctx['from'];
	$to    = (string) $ctx['to'];
	$class = (string) $ctx['class'];
	$paths = function_exists( 'sn_analytics_top_paths' ) ? sn_analytics_top_paths( $from, $to, $class, 25 ) : array();
	$refs  = function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, $class, 10 ) : array();
	$entry = function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $from, $to, 25 ) : array();
	$exit  = function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $from, $to, 25 ) : array();
	$low   = function_exists( 'sn_analytics_low_engagement_paths' ) ? sn_analytics_low_engagement_paths( $from, $to, $class ) : array();
	$path_rows = array();
	foreach ( (array) $paths as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$path_rows[] = array(
			'value'  => (string) ( $row['path'] ?? '' ),
			'views'  => $row['views'] ?? 0,
			'visits' => $row['visits'] ?? 0,
		);
	}
	$out  = '<div class="snt-grid">';
	$out .= dim_table( __( 'Top pages', 'signal-and-noise-tools' ), $path_rows, __( 'No page views in this range.', 'signal-and-noise-tools' ) );
	$out .= dim_table( __( 'Top sources', 'signal-and-noise-tools' ), $refs, __( 'No referrers in this range.', 'signal-and-noise-tools' ), 'referrer' );
	$out .= '</div>';
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Journeys & diagnostics: entry/exit are human only', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= '<div class="snt-grid">';
	$out .= dim_table( __( 'Entry pages', 'signal-and-noise-tools' ), $entry, __( 'No entry pages in this range yet.', 'signal-and-noise-tools' ) );
	$out .= dim_table( __( 'Exit pages', 'signal-and-noise-tools' ), $exit, __( 'No exit pages in this range yet.', 'signal-and-noise-tools' ) );
	$out .= dim_table( __( 'Low engagement', 'signal-and-noise-tools' ), $low, __( 'No low-engagement paths in this range.', 'signal-and-noise-tools' ) );
	return $out . '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/content'] = __NAMESPACE__ . '\\paint_view_content';
		return $painters;
	}
);
