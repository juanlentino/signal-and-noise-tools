<?php
/**
 * S&N Analytics — view/posts.
 *
 * Classic: snt_analytics_render_posts_view() in inc/analytics-posts-admin.php.
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
	$bundle = function_exists( 'sn_analytics_posts_bundle' ) ? sn_analytics_posts_bundle() : null;
	if ( ! is_array( $bundle ) || empty( $bundle['subject'] ) ) {
		return \snt_kit_empty( __( 'Posts', 'signal-and-noise-tools' ), __( 'No published posts yet: this view tracks each Note over its lifetime once you publish and traffic arrives.', 'signal-and-noise-tools' ) );
	}
	$subject = (array) $bundle['subject'];
	$cards   = array(
		array( 'l' => __( 'Latest Note', 'signal-and-noise-tools' ), 'n' => (string) ( $subject['title'] ?? $subject['name'] ?? '' ) ),
		array( 'l' => __( 'Views since publish', 'signal-and-noise-tools' ), 'n' => num( $subject['views'] ?? $subject['views_since_publish'] ?? 0 ) ),
	);
	$board = array();
	foreach ( (array) ( $bundle['leaderboard'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$board[] = array(
			'value'  => (string) ( $row['title'] ?? '' ),
			'views'  => $row['views'] ?? 0,
			'visits' => $row['velocity'] ?? null,
		);
	}
	$shape = array( 'sustained' => 0, 'cooling' => 0, 'spike' => 0 );
	foreach ( (array) ( $bundle['leaderboard'] ?? array() ) as $row ) {
		$d = (string) ( $row['decay'] ?? '' );
		if ( isset( $shape[ $d ] ) ) {
			++$shape[ $d ];
		}
	}
	$decay = array();
	foreach ( $shape as $label => $count ) {
		$decay[] = array( 'label' => ucfirst( $label ), 'views' => $count );
	}
	return stats( $cards )
		. dim_table( __( 'Catalog', 'signal-and-noise-tools' ), $board, __( 'No post traffic yet.', 'signal-and-noise-tools' ) )
		. distribution_table( __( 'Evergreen vs spike', 'signal-and-noise-tools' ), $decay, __( 'No shape data yet.', 'signal-and-noise-tools' ) );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/posts'] = __NAMESPACE__ . '\\paint_view_posts';
		return $painters;
	}
);
