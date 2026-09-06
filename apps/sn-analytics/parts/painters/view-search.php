<?php
/**
 * S&N Analytics — view/search.
 *
 * Classic: snt_analytics_render_view_search() in inc/analytics-view-search.php.
 * Reports Google's rolling window, not the range control above.
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
function paint_view_search( array $ctx ) {
	unset( $ctx );
	$url = function_exists( 'snt_analytics_settings_url' ) ? snt_analytics_settings_url() : '';
	$door = '' !== (string) $url ? \snt_kit_door( __( 'Measurement → Search Console', 'signal-and-noise-tools' ), $url ) : '';
	if ( ! function_exists( 'snt_gsc_credential_is_configured' ) || ! snt_gsc_credential_is_configured() ) {
		return \snt_kit_empty( __( 'Search', 'signal-and-noise-tools' ), __( 'No Search Console credential yet. Add one under Measurement → Search Console.', 'signal-and-noise-tools' ) ) . $door;
	}
	if ( function_exists( 'sn_setting' ) && '' === (string) sn_setting( 'search_console.property', '' ) ) {
		return \snt_kit_empty( __( 'Search', 'signal-and-noise-tools' ), __( 'The credential is stored, but no property is selected yet. Open Measurement → Search Console, run Test connection, then choose the property to read.', 'signal-and-noise-tools' ) ) . $door;
	}
	$data = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	if ( null === $data || ! is_array( $data ) ) {
		return \snt_kit_empty( __( 'Search', 'signal-and-noise-tools' ), __( 'A property is selected but nothing has synced yet. Open Measurement → Search Console and choose Sync now.', 'signal-and-noise-tools' ) ) . $door;
	}
	$window = (array) ( $data['window'] ?? array() );
	$meta   = \snt_kit_esc( (string) ( $data['property'] ?? '' ) ) . ' · '
		. \snt_kit_esc( (string) ( $window['start'] ?? '' ) ) . ' – ' . \snt_kit_esc( (string) ( $window['end'] ?? '' ) );
	$kpis   = (array) ( $data['kpis'] ?? $data['totals'] ?? array() );
	$cards  = array();
	foreach ( array( 'clicks' => __( 'Clicks', 'signal-and-noise-tools' ), 'impressions' => __( 'Impressions', 'signal-and-noise-tools' ), 'ctr' => __( 'CTR', 'signal-and-noise-tools' ), 'position' => __( 'Position', 'signal-and-noise-tools' ) ) as $key => $label ) {
		if ( isset( $kpis[ $key ] ) ) {
			$cards[] = array( 'l' => $label, 'n' => (string) $kpis[ $key ] );
		}
	}
	$queries = (array) ( $data['queries'] ?? $data['top_queries'] ?? array() );
	$pages   = (array) ( $data['pages'] ?? $data['top_pages'] ?? array() );
	return \snt_kit_section( __( 'Window', 'signal-and-noise-tools' ), '<p class="snt-prose">' . $meta . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( "This view reports Google's own rolling window and does NOT follow the date range selected above — Search Console data is fetched on a schedule, not queried per range.", 'signal-and-noise-tools' ) ) . '</p>'
		. stats( $cards ) )
		. '<div class="snt-grid">'
		. dim_table( __( 'Queries', 'signal-and-noise-tools' ), $queries, __( 'No queries in this Search Console window.', 'signal-and-noise-tools' ) )
		. dim_table( __( 'Pages', 'signal-and-noise-tools' ), $pages, __( 'No pages in this Search Console window.', 'signal-and-noise-tools' ) )
		. '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/search'] = __NAMESPACE__ . '\\paint_view_search';
		return $painters;
	}
);
