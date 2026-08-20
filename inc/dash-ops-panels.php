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

// snt_dashboard_short_repo() is the repo→role mapper this file used to
// duplicate — worse: the duplicate fell through to the bare repo NAME for
// anything not ending in `-tools`, so the wall read "signal-and-noise v11.12.3"
// beside "plugin v11.31.1". Required explicitly, not left to loader order: four
// test files pull this builder in without the deploy rows, and a
// function_exists() guard here would degrade the label back to the bug.
require_once __DIR__ . '/dash-deploy-rows.php';

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
					'label' => ( snt_dashboard_short_repo( (string) ( $run['repo'] ?? '' ) ) ?: '—' )
						. '  ' . (string) ( $run['ref'] ?? '' ),
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
					// `value` — sn_analytics_top_sources() canonicalises the
					// referrer into that key. `label`/`referrer` were invented
					// by the first version of this file and rendered blank.
					'label' => (string) ( $row['value'] ?? '' ),
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
					// `key` — snt_gsc_top_queries() passes Google's rows through
					// with the dimension in `key`, not `query`.
					'label' => (string) ( $row['key'] ?? '' ),
					'value' => number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ),
				);
			},
			$queries
		) ),
		'empty'      => __( 'No queries in the stored window', 'signal-and-noise-tools' ),
		'unmeasured' => __( 'Not measured — Search Console has never synced', 'signal-and-noise-tools' ),
	);

	$api  = $get( 'api' );
	$rows = null;
	if ( null !== $api ) {
		$rows = array();
		foreach ( $api as $host => $st ) {
			$st = is_array( $st ) ? $st : array();

			// SHAPE, FROM snt_rate_limit_all_statuses() — read, not assumed:
			//   [ host => [ 'label' => 'GitHub API', 'snapshot' => array|null ] ]
			// The numbers live INSIDE `snapshot`; the state is derived by
			// snt_rate_limit_state(). v11.30.0 read $st['limit'] / $st['remaining']
			// / $st['kind'] and the array KEY for a label, so every row rendered a
			// raw hostname against an em dash.
			$label = (string) ( $st['label'] ?? $host );
			$snap  = isset( $st['snapshot'] ) && is_array( $st['snapshot'] ) ? $st['snapshot'] : null;

			if ( null === $snap ) {
				// No request to this host has been observed. Not measured — and
				// emphatically not a healthy limit.
				$rows[] = array(
					'label' => $label,
					'value' => __( 'not seen yet', 'signal-and-noise-tools' ),
					'dot'   => 'unknown',
				);
				continue;
			}

			$limit = (int) ( $snap['limit'] ?? 0 );
			$left  = (int) ( $snap['remaining'] ?? 0 );
			$state = function_exists( 'snt_rate_limit_state' ) ? (string) snt_rate_limit_state( $snap ) : 'unknown';

			// snt_rate_limit_state() speaks ok|warn|crit|unknown; the wall's dot
			// vocabulary is ''|warn|err|unknown. Mapped explicitly rather than
			// passed through, because "crit" would silently paint nothing.
			$dot = 'ok' === $state ? '' : ( 'crit' === $state ? 'err' : $state );

			$rows[] = array(
				'label' => $label,
				'value' => $limit > 0
					? number_format_i18n( $left ) . ' / ' . number_format_i18n( $limit )
					: number_format_i18n( $left ),
				'dot'   => $dot,
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
