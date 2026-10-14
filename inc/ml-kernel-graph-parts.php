<?php
/**
 * Signal & Noise: ML kernel, the graph and cluster scorers (graph signals, the blended relatedness score, topic clusters and their labels, the median).
 *
 * Split from inc/ml-kernel.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'snt_ml_graph_signals' ) ) {
	/**
	 * Link/taxonomy graph signals between two posts.
	 *
	 * @param array $post_a { @type string[] $tags @type string[] $links_out (slugs) @type string $slug }
	 * @param array $post_b Same shape.
	 * @return array {
	 *     @type float $tag_overlap Jaccard of tag sets.
	 *     @type int   $direct_link 1 when either post links to the other's slug.
	 *     @type float $co_link     Jaccard of outbound-link sets.
	 * }
	 */
	function snt_ml_graph_signals( array $post_a, array $post_b ) {
		$tags_a  = isset( $post_a['tags'] ) && is_array( $post_a['tags'] ) ? $post_a['tags'] : array();
		$tags_b  = isset( $post_b['tags'] ) && is_array( $post_b['tags'] ) ? $post_b['tags'] : array();
		$links_a = isset( $post_a['links_out'] ) && is_array( $post_a['links_out'] ) ? $post_a['links_out'] : array();
		$links_b = isset( $post_b['links_out'] ) && is_array( $post_b['links_out'] ) ? $post_b['links_out'] : array();
		$slug_a  = isset( $post_a['slug'] ) ? (string) $post_a['slug'] : '';
		$slug_b  = isset( $post_b['slug'] ) ? (string) $post_b['slug'] : '';

		$direct = ( '' !== $slug_b && in_array( $slug_b, $links_a, true ) )
			|| ( '' !== $slug_a && in_array( $slug_a, $links_b, true ) );

		return array(
			'tag_overlap' => snt_ml_jaccard( $tags_a, $tags_b ),
			'direct_link' => $direct ? 1 : 0,
			'co_link'     => snt_ml_jaccard( $links_a, $links_b ),
		);
	}
}

if ( ! function_exists( 'snt_ml_related_score' ) ) {
	/**
	 * Blended relatedness: lexical cosine + graph signals.
	 *
	 * Weights arrive as a plain argument — the integration layer runs the
	 * filter and passes the result here; this fn stays pure.
	 *
	 * @param float      $cos     Lexical cosine (snt_ml_cosine).
	 * @param array      $signals snt_ml_graph_signals() output.
	 * @param array|null $weights Optional { lexical, tags, direct_link, co_link };
	 *                            missing keys fall back to the defaults below.
	 * @return float Weighted blend (0..1 under the default weights).
	 */
	function snt_ml_related_score( $cos, array $signals, $weights = null ) {
		$defaults = array(
			'lexical'     => 0.55,
			'tags'        => 0.25,
			'direct_link' => 0.15,
			'co_link'     => 0.05,
		);
		$w = is_array( $weights ) ? array_merge( $defaults, $weights ) : $defaults;

		return (float) $w['lexical'] * (float) $cos
			+ (float) $w['tags'] * (float) ( $signals['tag_overlap'] ?? 0.0 )
			+ (float) $w['direct_link'] * (float) ( $signals['direct_link'] ?? 0 )
			+ (float) $w['co_link'] * (float) ( $signals['co_link'] ?? 0.0 );
	}
}

if ( ! function_exists( 'snt_ml_topic_clusters' ) ) {
	/**
	 * Deterministic topic clustering: connected components over the cosine
	 * graph (v10.21.0, pipeline #4). Two documents connect when their cosine
	 * meets the threshold (inclusive); a topic is a component, not a clique —
	 * A~B and B~C chain into one topic even when A and C sit apart. No k, no
	 * seeds, no randomness: the same corpus always yields the same partition.
	 *
	 * Singletons are excluded — a topic needs at least two notes.
	 *
	 * @param array<int,array<string,float>> $vectors   Sparse L2 vectors keyed by document id.
	 * @param float                          $threshold Cosine floor, inclusive.
	 * @return array<int,array<int,int>> Clusters: members ascending; list
	 *                                   ordered size-descending, then first
	 *                                   member ascending. Empty input => [].
	 */
	function snt_ml_topic_clusters( $vectors, $threshold = 0.35 ) {
		$ids = array_keys( (array) $vectors );
		sort( $ids ); // Canonical walk order — determinism does not depend on input order.
		$n = count( $ids );
		if ( $n < 2 ) {
			return array();
		}

		$parent = array();
		foreach ( $ids as $id ) {
			$parent[ $id ] = $id;
		}
		$find = static function ( $x ) use ( &$parent ) {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ]; // Path halving.
				$x            = $parent[ $x ];
			}
			return $x;
		};

		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( snt_ml_cosine( $vectors[ $ids[ $i ] ], $vectors[ $ids[ $j ] ] ) >= $threshold ) {
					$ra = $find( $ids[ $i ] );
					$rb = $find( $ids[ $j ] );
					if ( $ra !== $rb ) {
						$parent[ max( $ra, $rb ) ] = min( $ra, $rb );
					}
				}
			}
		}

		$components = array();
		foreach ( $ids as $id ) {
			$components[ $find( $id ) ][] = $id; // $ids sorted => members arrive ascending.
		}

		$clusters = array();
		foreach ( $components as $members ) {
			if ( count( $members ) >= 2 ) {
				$clusters[] = $members;
			}
		}
		usort( $clusters, static function ( $a, $b ) {
			$by_size = count( $b ) <=> count( $a );
			return 0 !== $by_size ? $by_size : ( $a[0] <=> $b[0] );
		} );
		return $clusters;
	}
}

if ( ! function_exists( 'snt_ml_cluster_label' ) ) {
	/**
	 * Deterministic cluster label: the top terms by summed member weight,
	 * middot-joined. Equal weights tie-break alphabetically, so a label can
	 * never flap between rebuilds of an unchanged corpus.
	 *
	 * @param array<int,array<string,float>> $vectors Sparse vectors keyed by document id.
	 * @param array<int,int>                 $members Cluster member ids.
	 * @param int                            $terms   Label term count.
	 * @return string '' when no member carries any term.
	 */
	function snt_ml_cluster_label( $vectors, $members, $terms = 2 ) {
		$sum = array();
		foreach ( (array) $members as $id ) {
			foreach ( (array) ( $vectors[ $id ] ?? array() ) as $term => $weight ) {
				$sum[ $term ] = ( $sum[ $term ] ?? 0.0 ) + (float) $weight;
			}
		}
		if ( array() === $sum ) {
			return '';
		}
		uksort( $sum, static function ( $a, $b ) use ( $sum ) {
			if ( $sum[ $a ] === $sum[ $b ] ) {
				return strcmp( $a, $b );
			}
			return $sum[ $b ] <=> $sum[ $a ];
		} );
		return implode( ' · ', array_slice( array_keys( $sum ), 0, max( 1, (int) $terms ) ) );
	}
}

if ( ! function_exists( 'snt_ml_median' ) ) {
	/**
	 * Median of a numeric list (even counts average the two middle values).
	 *
	 * @since 10.32.0
	 * @param array<int,int|float> $values Numbers; non-numerics are dropped.
	 * @return float|null Null when nothing numeric was supplied.
	 */
	function snt_ml_median( $values ) {
		$nums = array();
		foreach ( (array) $values as $v ) {
			if ( is_numeric( $v ) ) {
				$nums[] = (float) $v;
			}
		}
		if ( array() === $nums ) {
			return null;
		}
		sort( $nums );
		$n   = count( $nums );
		$mid = intdiv( $n, 2 );
		return 0 === $n % 2 ? ( $nums[ $mid - 1 ] + $nums[ $mid ] ) / 2.0 : $nums[ $mid ];
	}
}

