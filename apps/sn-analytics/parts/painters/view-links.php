<?php
/**
 * S&N Analytics — view/links (coded résumé links, 2026-10-31).
 *
 * Classic: snt_analytics_render_view_links() in inc/analytics-view-links.php,
 * which canonical_piece() renders for this key.
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
function paint_view_links( array $ctx ) {
	$codes = function_exists( 'sn_link_codes_summary_read' ) ? \sn_link_codes_summary_read( (string) $ctx['from'], (string) $ctx['to'] ) : null;
	$rows  = is_array( $codes ) ? array_map( static fn( $c ) => array( 'value' => $c['code'], 'views' => $c['views'], 'visits' => $c['human'] + $c['suspect'] + $c['bot'] ), $codes ) : null;
	return dim_table( __( 'Links', 'signal-and-noise-tools' ), $rows, __( 'No coded link was opened in this range. Send /r/CODE, for example /r/hera, and it shows here once opened.', 'signal-and-noise-tools' ) )
		. paint_resume_lanes( $ctx );
}

/**
 * The owner-only résumé lanes panel, native twin of snt_analytics_render_resume_lanes().
 *
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_resume_lanes( array $ctx ) {
	$model = function_exists( 'sn_resume_lanes_read' ) ? \sn_resume_lanes_read( (string) $ctx['from'], (string) $ctx['to'] ) : null;
	if ( null === $model ) {
		return '';
	}
	$title = __( 'Résumé lanes', 'signal-and-noise-tools' );
	if ( is_string( $model ) ) {
		return \snt_kit_section( $title, \snt_kit_notice( 'warn', $model ) );
	}
	$keys    = array( 'lane', 'views', 'time', 'scroll', 'switches', 'hunks' );
	$columns = array();
	foreach ( \sn_resume_lanes_labels() as $i => $label ) {
		$columns[] = array( 'key' => $keys[ $i ], 'label' => $label ) + ( $i ? array( 'align' => 'end' ) : array() );
	}
	$rows = array_map( static fn( $r ) => array_combine( $keys, \sn_resume_lanes_cells( $r ) ), $model );
	return \snt_kit_section( $title, \snt_kit_table( $columns, $rows ) . '<p>' . esc_html( \sn_resume_lanes_note() ) . '</p>' );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/links'] = __NAMESPACE__ . '\\paint_view_links';
		return $painters;
	}
);
