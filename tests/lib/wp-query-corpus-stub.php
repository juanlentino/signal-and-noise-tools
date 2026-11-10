<?php
/**
 * A WP_Query stand-in over $GLOBALS['__posts'] for the corpus suites.
 *
 * Models only what the corpus resolvers ask core for: post_type, post_status,
 * one date_query clause (column + `after` as the array form + inclusive),
 * orderby as an array of column => direction, offset, posts_per_page, fields
 * and found_posts. Core quirks kept on purpose: `modified` sorts on the
 * site-local post_modified column, and found_posts stays 0 when the page is
 * empty (WP_Query::set_found_posts() bails on an empty result).
 *
 * Every args array is appended to $GLOBALS['__wp_query_args'] so a suite can
 * pin what was asked, not only what came back.
 */

/**
 * The date_query filter both this class and a suite's get_posts() stub share.
 *
 * @param object|array $row  A post-shaped row.
 * @param array        $args Query args.
 * @return bool Whether the row passes the date_query (true when there is none).
 */
function tf_date_query_pass( $row, array $args ) {
	foreach ( (array) ( $args['date_query'] ?? array() ) as $clause ) {
		if ( ! is_array( $clause ) || empty( $clause['after'] ) ) { continue; }
		$a      = array_map( 'intval', (array) $clause['after'] );
		$cutoff = sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $a['year'], $a['month'], $a['day'], $a['hour'], $a['minute'], $a['second'] );
		$col    = (string) ( $clause['column'] ?? 'post_date' );
		$val    = (string) ( is_array( $row ) ? ( $row[ $col ] ?? '' ) : ( $row->$col ?? '' ) );
		if ( ! empty( $clause['inclusive'] ) ? $val < $cutoff : $val <= $cutoff ) { return false; }
	}
	return true;
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts       = array();
		public $found_posts = 0;

		public function __construct( $args = array() ) {
			$GLOBALS['__wp_query_args'][] = $args;
			$columns = array( 'modified' => 'post_modified', 'date' => 'post_date', 'ID' => 'ID' );
			$rows    = array();
			foreach ( $GLOBALS['__posts'] as $p ) {
				if ( $p->post_type !== ( $args['post_type'] ?? 'post' ) ) { continue; }
				if ( ! in_array( $p->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) { continue; }
				if ( ! tf_date_query_pass( $p, $args ) ) { continue; }
				$rows[] = $p;
			}
			$orderby = (array) ( $args['orderby'] ?? array( 'date' => 'DESC' ) );
			usort( $rows, static function ( $a, $b ) use ( $orderby, $columns ) {
				foreach ( $orderby as $key => $dir ) {
					$col = $columns[ $key ];
					$cmp = 'ID' === $col ? ( (int) $a->ID <=> (int) $b->ID ) : strcmp( (string) $a->$col, (string) $b->$col );
					if ( 0 !== $cmp ) { return 'DESC' === strtoupper( $dir ) ? -$cmp : $cmp; }
				}
				return 0;
			} );
			$per  = (int) ( $args['posts_per_page'] ?? 10 );
			$page = $per > 0 ? array_slice( $rows, max( 0, (int) ( $args['offset'] ?? 0 ) ), $per ) : $rows;
			if ( empty( $args['no_found_rows'] ) && array() !== $page ) {
				$this->found_posts = count( $rows );
			}
			$this->posts = 'ids' === ( $args['fields'] ?? '' ) ? array_map( static function ( $p ) { return (int) $p->ID; }, $page ) : $page;
		}
	}
}
