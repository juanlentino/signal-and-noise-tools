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
	return dim_table( __( 'Links', 'signal-and-noise-tools' ), $rows, __( 'No coded link was opened in this range. Send /r/CODE, for example /r/hera, and it shows here once opened.', 'signal-and-noise-tools' ) );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/links'] = __NAMESPACE__ . '\\paint_view_links';
		return $painters;
	}
);
