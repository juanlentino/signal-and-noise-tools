<?php
/**
 * Signal & Noise — the ops wall's panel builder.
 *
 * Owner brief, verbatim (2026-08-19): "command center/mission control isn't
 * modest or anything like that. It's everything without bloating."
 *
 * TWO RULES FOLLOW FROM THAT SENTENCE.
 *
 * "Everything" — a source that is absent still gets its panel, saying it is not
 * measured. Omitting it would make the wall silently smaller on the exact day
 * something stopped reporting, which is the worst possible day to look complete.
 *
 * "Without bloating" — every panel here reads an accessor that ALREADY EXISTS.
 * This file adds no data layer, no query, no option and no cron. It is a
 * projection of facts the plugin already computes into one shape.
 *
 * Values arrive already-fetched rather than being pulled in here, so the guards
 * that decide "absent" live at the call site and this stays a pure transform —
 * which is what makes the three states (never-fetched / fetched-empty / rows)
 * testable at all.
 *
 * @package SignalNoiseTools
 * @since 11.29.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shorten a repo to the word a human uses for it.
 *
 * @since 11.29.2
 * @param string $repo owner/name.
 * @return string
 */
function sn_dash_ops_repo_label( $repo ) {
	$name = (string) $repo;
	if ( false !== strpos( $name, '/' ) ) {
		$parts = explode( '/', $name );
		$name  = (string) end( $parts );
	}
	if ( '' !== $name && str_ends_with( $name, '-tools' ) ) {
		return 'plugin';
	}
	return '' === $name ? '—' : $name;
}

/**
 * Build the wall.
 *
 * @since 11.29.2
 * @param array<string,mixed> $data deploys, pages, sources, queries, api — each
 *                                  an array when fetched (possibly empty), or
 *                                  absent/NULL when the source never reported.
 * @return array<int,array<string,mixed>>
 */
function sn_dash_ops_panels( array $data ) {
	$get = function ( $key ) use ( $data ) {
		// array_key_exists, not isset: an explicit NULL is the caller SAYING
		// "never fetched", and isset() would erase that into the same branch as
		// a missing key. Both mean null here, but for stated reasons.
		return array_key_exists( $key, $data ) && is_array( $data[ $key ] ) ? $data[ $key ] : null;
	};

	$panels = array();

	$deploys = $get( 'deploys' );
	$panels[] = array(
		'title'      => __( 'Recent deploys', 'signal-and-noise-tools' ),
		'rows'       => null === $deploys ? null : array_values( array_map(
			function ( $run ) {
				$run = is_array( $run ) ? $run : array();
				$c   = (string) ( $run['conclusion'] ?? '' );
				return array(
					'label' => sn_dash_ops_repo_label( $run['repo'] ?? '' ) . '  ' . (string) ( $run['ref'] ?? '' ),
					'value' => function_exists( 'snt_dashboard_relative_time' )
						? snt_dashboard_relative_time( (string) ( $run['created_at'] ?? '' ) )
						: '',
					'href'  => (string) ( $run['html_url'] ?? '' ),
					// Monochrome first: a healthy row carries no dot at all, so
					// the one that does is the one you see.
					'dot'   => 'success' === $c ? '' : ( '' === $c ? 'unknown' : 'err' ),
				);
			},
			$deploys
		) ),
		'empty'      => __( 'No deploys recorded yet', 'signal-and-noise-tools' ),
		'unmeasured' => __( 'Not measured — no deploy history', 'signal-and-noise-tools' ),
	);

	$pages = $get( 'pages' );
	$panels[] = array(
		'title'      => __( 'Top pages', 'signal-and-noise-tools' ),
		'rows'       => null === $pages ? null : array_values( array_map(
			function ( $row ) {
				$row = is_array( $row ) ? $row : array();
				return array(
					'label' => (string) ( $row['path'] ?? '' ),
					'value' => number_format_i18n( (int) ( $row['views'] ?? 0 ) ),
				);
			},
			$pages
		) ),
		'empty'      => __( 'No views in the window', 'signal-and-noise-tools' ),
		'unmeasured' => __( 'Not measured — analytics is not configured', 'signal-and-noise-tools' ),
	);

	$sources = $get( 'sources' );
	$panels[] = array(
		'title'      => __( 'Top sources', 'signal-and-noise-tools' ),
		'rows'       => null === $sources ? null : array_values( array_map(
			function ( $row ) {
				$row = is_array( $row ) ? $row : array();
				return array(
					'label' => (string) ( $row['label'] ?? ( $row['referrer'] ?? '' ) ),
					'value' => number_format_i18n( (int) ( $row['visits'] ?? 0 ) ),
				);
			},
			$sources
		) ),
		'empty'      => __( 'No referrers in the window', 'signal-and-noise-tools' ),
		'unmeasured' => __( 'Not measured — analytics is not configured', 'signal-and-noise-tools' ),
	);

	$queries = $get( 'queries' );
	$panels[] = array(
		'title'      => __( 'Top queries', 'signal-and-noise-tools' ),
		'rows'       => null === $queries ? null : array_values( array_map(
			function ( $row ) {
				$row = is_array( $row ) ? $row : array();
				return array(
					'label' => (string) ( $row['query'] ?? '' ),
					'value' => number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ),
				);
			},
			$queries
		) ),
		'empty'      => __( 'No queries in the stored window', 'signal-and-noise-tools' ),
		'unmeasured' => __( 'Not measured — Search Console has never synced', 'signal-and-noise-tools' ),
	);

	$api = $get( 'api' );
	$rows = null;
	if ( null !== $api ) {
		$rows = array();
		foreach ( $api as $host => $st ) {
			$st     = is_array( $st ) ? $st : array();
			$limit  = (int) ( $st['limit'] ?? 0 );
			$left   = (int) ( $st['remaining'] ?? 0 );
			$rows[] = array(
				'label' => (string) $host,
				'value' => $limit > 0 ? number_format_i18n( $left ) . ' / ' . number_format_i18n( $limit ) : '—',
				// Same rule: a host with headroom is not news.
				'dot'   => 'ok' === (string) ( $st['kind'] ?? 'ok' ) ? '' : (string) $st['kind'],
			);
		}
	}
	$panels[] = array(
		'title'      => __( 'API limits', 'signal-and-noise-tools' ),
		// v11.29.2: shown ALWAYS. It used to render only when a host was warn
		// or crit — the collapse rule again, and the reason the page was empty
		// on a healthy site. A limit at 99% is the answer to "is it fine?".
		'rows'       => $rows,
		'empty'      => __( 'No hosts monitored', 'signal-and-noise-tools' ),
		'unmeasured' => __( 'Not measured — no rate-limit monitor', 'signal-and-noise-tools' ),
	);

	return $panels;
}
