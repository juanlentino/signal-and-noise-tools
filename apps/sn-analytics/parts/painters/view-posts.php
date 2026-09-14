<?php
/**
 * S&N Analytics — view/posts.
 *
 * Classic: snt_analytics_render_posts_signals_view() in inc/analytics-posts-signals-admin.php.
 * Reached only by a partial host: the native window paints the classic body
 * through canonical_piece().
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
function paint_view_posts( array $ctx ) {
	unset( $ctx );
	$signals = function_exists( 'sn_analytics_posts_signals' ) ? sn_analytics_posts_signals() : null;
	if ( ! is_array( $signals ) || empty( $signals['rows'] ) ) {
		return \snt_kit_empty( __( 'Posts', 'signal-and-noise-tools' ), __( 'No published notes yet: this view reads each note against Search Console, the coverage inspection, the link graph and the provenance chain once you publish.', 'signal-and-noise-tools' ) );
	}
	$c     = (array) $signals['counts'];
	$cards = array(
		array( 'l' => __( 'Notes', 'signal-and-noise-tools' ), 'n' => num( $c['total'] ) ),
		array( 'l' => __( 'Indexed', 'signal-and-noise-tools' ), 'n' => num( $c['indexed'] ) ),
		array( 'l' => __( 'Not indexed', 'signal-and-noise-tools' ), 'n' => num( $c['not_indexed'] ) ),
		array( 'l' => __( 'Stale crawls', 'signal-and-noise-tools' ), 'n' => num( $c['stale_crawl'] ) ),
		array( 'l' => __( 'Zero inbound links', 'signal-and-noise-tools' ), 'n' => num( $c['zero_inbound'] ) ),
	);
	$board = array();
	foreach ( (array) $signals['rows'] as $row ) {
		if ( ! in_array( true, (array) $row['flags'], true ) ) {
			continue;
		}
		$board[] = array(
			'value'  => (string) $row['title'],
			'views'  => implode( ', ', array_keys( array_filter( (array) $row['flags'] ) ) ),
			'visits' => null,
		);
	}
	return stats( $cards ) . dim_table( __( 'Queue', 'signal-and-noise-tools' ), $board, __( 'No note carries a flag.', 'signal-and-noise-tools' ) );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/posts'] = __NAMESPACE__ . '\\paint_view_posts';
		return $painters;
	}
);
