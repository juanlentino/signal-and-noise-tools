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
 * Search Console rows keep a different contract from first-party dimensions:
 * their label is `key`, and their measures are clicks/impressions/CTR/
 * position. Never pass them through dim_table(), whose contract is
 * label + views + visits.
 *
 * @param array<int|string,array<string,mixed>> $rows Stored GSC rows.
 * @return array<int,array<string,string>>
 */
function gsc_metric_rows( array $rows ) {
	$out = array();
	foreach ( $rows as $stored_key => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$key = isset( $row['key'] ) ? (string) $row['key'] : ( is_string( $stored_key ) ? $stored_key : '' );
		if ( '' === trim( $key ) ) {
			continue;
		}
		$clicks      = (int) ( $row['clicks'] ?? 0 );
		$impressions = (int) ( $row['impressions'] ?? 0 );
		$ctr         = array_key_exists( 'ctr', $row )
			? (float) $row['ctr']
			: ( $impressions > 0 ? $clicks / $impressions : 0.0 );
		$out[] = array(
			'value'       => $key,
			'clicks'      => num( $clicks ),
			'impressions' => num( $impressions ),
			'ctr'         => number_format_i18n( 100 * $ctr, 1 ) . '%',
			'position'    => number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ),
		);
	}
	return $out;
}

/**
 * One real Search Console metrics table.
 *
 * @param string                              $title Section heading.
 * @param array<int|string,array<string,mixed>> $rows Stored GSC rows.
 * @param string                              $empty Empty-state copy.
 * @return string
 */
function gsc_metric_table( $title, array $rows, $empty ) {
	$rows = array_slice( gsc_metric_rows( $rows ), 0, 25 );
	if ( array() === $rows ) {
		return \snt_kit_section( (string) $title, \snt_kit_empty( '', (string) $empty ) );
	}
	return \snt_kit_section(
		(string) $title,
		\snt_kit_table(
			array(
				array( 'key' => 'value', 'label' => (string) $title, 'stack' => 'title' ),
				array( 'key' => 'clicks', 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'impressions', 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'ctr', 'label' => __( 'CTR', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'position', 'label' => __( 'Avg position', 'signal-and-noise-tools' ), 'align' => 'end' ),
			),
			$rows,
			array( 'empty' => (string) $empty )
		)
	);
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
	$meta   = '<strong>' . \snt_kit_esc( (string) ( $data['property'] ?? '' ) ) . '</strong> · '
		. \snt_kit_esc( (string) ( $window['start'] ?? '' ) ) . ' – ' . \snt_kit_esc( (string) ( $window['end'] ?? '' ) );
	if ( isset( $data['synced_at'] ) && (int) $data['synced_at'] > 0 && function_exists( 'human_time_diff' ) ) {
		$meta .= ' · ' . \snt_kit_esc(
			sprintf(
				/* translators: %s: human-readable age. */
				__( 'synced %s ago', 'signal-and-noise-tools' ),
				human_time_diff( (int) $data['synced_at'], time() )
			)
		);
	}
	$totals = function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null;
	$cards  = array();
	if ( is_array( $totals ) ) {
		$impressions = (int) ( $totals['impressions'] ?? 0 );
		$clicks      = (int) ( $totals['clicks'] ?? 0 );
		$days        = (int) ( $totals['days'] ?? 0 );
		$cards[] = array(
			'l'   => __( 'Impressions', 'signal-and-noise-tools' ),
			'n'   => num( $impressions ),
			'sub' => ! empty( $totals['capped'] )
				? sprintf( __( 'floor across %d days', 'signal-and-noise-tools' ), $days )
				: sprintf( __( 'across %d days', 'signal-and-noise-tools' ), $days ),
		);
		$cards[] = array(
			'l'   => __( 'Clicks', 'signal-and-noise-tools' ),
			'n'   => num( $clicks ),
			'sub' => $impressions > 0
				? sprintf( __( '%s CTR', 'signal-and-noise-tools' ), number_format_i18n( 100 * $clicks / $impressions, 1 ) . '%' )
				: __( 'No impressions to divide by', 'signal-and-noise-tools' ),
		);
	}
	$queries = (array) ( $data['queries'] ?? $data['top_queries'] ?? array() );
	$pages   = (array) ( $data['pages'] ?? $data['top_pages'] ?? array() );
	return \snt_kit_section( __( 'Search Console window', 'signal-and-noise-tools' ), '<p class="snt-prose">' . $meta . '</p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( "This view reports Google's own rolling window and does NOT follow the first-party analytics range — Search Console data is fetched on a schedule, not queried per range.", 'signal-and-noise-tools' ) ) . '</p>'
		. stats( $cards ) )
		. '<div class="snt-report-columns">'
		. gsc_metric_table( __( 'Top queries', 'signal-and-noise-tools' ), $queries, __( 'No queries in this Search Console window.', 'signal-and-noise-tools' ) )
		. gsc_metric_table( __( 'Top pages', 'signal-and-noise-tools' ), $pages, __( 'No pages in this Search Console window.', 'signal-and-noise-tools' ) )
		. '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/search'] = __NAMESPACE__ . '\\paint_view_search';
		return $painters;
	}
);
