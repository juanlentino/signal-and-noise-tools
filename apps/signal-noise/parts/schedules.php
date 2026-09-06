<?php
/**
 * Signal & Noise app — the Scheduled fragments section.
 *
 * The scheduled-content queue (inc/schedule-engine.php), FRAGMENTS only: the
 * rows mirrored from a post's `sn/scheduled` blocks, which is the only
 * target_type anything writes. The queue's other shapes — page, theme_block,
 * swap — are named in the engine's docblock and written by nothing, so folding
 * them in would paint a folder whose contents are always the same subset under
 * a label that promised more. The label says fragments; the query says
 * fragments.
 *
 * Ordered soonest first by `starts_at`, with a row that has no start LAST.
 * An empty string sorts FIRST in every string comparison, which is exactly
 * backwards: a fragment with no start is not the most imminent thing in the
 * queue, it is the one with no boundary to wait for. The comparator says so.
 *
 * READ-ONLY BY CONSTRUCTION, like Citations: `kind: entry`, no `restPath`, no
 * `edit_url`, no `hasDossier`. The leaf's three operations (run now, re-purge,
 * run swap now) stay in the leaf for this phase; when they come they come
 * gated the way the leaf gates them, on manage_options.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The four states, or an empty list when the engine is not loaded.
 *
 * @return array<int,string>
 */
function schedule_statuses() {
	return defined( 'SN_SCHEDULE_STATUSES' ) ? array_values( (array) \SN_SCHEDULE_STATUSES ) : array();
}

/**
 * One stored UTC boundary in the site's timezone, or '' when it has none.
 *
 * @param mixed $gmt Stored 'Y-m-d H:i:s' UTC value, or null/''.
 * @return string
 */
function schedule_fmt( $gmt ) {
	$gmt = (string) $gmt;
	if ( '' === $gmt ) {
		return '';
	}
	return function_exists( 'sn_admin_schedule_fmt_gmt' ) ? (string) \sn_admin_schedule_fmt_gmt( $gmt ) : $gmt;
}

/**
 * The door to Connections → Scheduled content, or '' without the admin dock.
 *
 * @return string
 */
function schedules_door() {
	return function_exists( 'snt_desktop_admin_url' ) ? (string) \snt_desktop_admin_url( 'sn-connections', 'scheduled-content' ) : '';
}

/**
 * Soonest first; a row with no start last; ties broken by id.
 *
 * @param array<string,mixed> $a Row.
 * @param array<string,mixed> $b Row.
 * @return int
 */
function schedules_compare( array $a, array $b ) {
	$sa = (string) ( $a['starts_at'] ?? '' );
	$sb = (string) ( $b['starts_at'] ?? '' );
	if ( ( '' === $sa ) !== ( '' === $sb ) ) {
		return '' === $sa ? 1 : -1;
	}
	return strcmp( $sa, $sb ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
}

/**
 * Every scheduled fragment, soonest boundary first.
 *
 * @return array<int,array<string,mixed>>
 */
function schedules_items() {
	if ( ! function_exists( 'sn_schedule_all' ) ) {
		return array();
	}
	$rows = array();
	foreach ( (array) \sn_schedule_all() as $row ) {
		if ( is_array( $row ) && 'fragment' === (string) ( $row['target_type'] ?? '' ) ) {
			$rows[] = $row;
		}
	}
	usort( $rows, __NAMESPACE__ . '\schedules_compare' );
	$items = array();
	foreach ( array_slice( $rows, 0, (int) SN_OS_APP_ITEM_CAP ) as $row ) {
		$items[] = schedule_item( $row );
	}
	return $items;
}

/**
 * How many scheduled fragments there are, for the root folder tile.
 *
 * Counted in SQL: sn_schedule_all() is unbounded, and reading every column of
 * every row to produce one number is a table scan carried into PHP on every
 * root paint.
 *
 * @return int
 */
function schedules_count() {
	return function_exists( 'sn_schedule_count' ) ? (int) \sn_schedule_count( 'fragment' ) : 0;
}

/**
 * One scheduled fragment as the client sees it.
 *
 * @param array<string,mixed> $row A row of the schedules table.
 * @return array<string,mixed>
 */
function schedule_item( array $row ) {
	$never  = __( 'never', 'signal-and-noise-tools' );
	// The engine's, the leaf's and the dossier's word for an absent start: a
	// fragment with no start is open from the beginning, not never.
	$always = __( 'always', 'signal-and-noise-tools' );
	$ref   = (int) ( $row['target_ref'] ?? 0 );
	$title = $ref > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $ref ) : '';
	if ( '' === $title ) {
		// The leaf's wording: the host post is gone, or was never linked.
		$title = __( '(unlinked fragment)', 'signal-and-noise-tools' );
	}
	$action = (string) ( $row['action'] ?? '' );
	$status = (string) ( $row['status'] ?? '' );
	$starts = schedule_fmt( $row['starts_at'] ?? '' );
	$ends   = schedule_fmt( $row['ends_at'] ?? '' );
	$run    = schedule_fmt( $row['last_run'] ?? '' );
	$window = ( '' !== $starts ? $starts : $always ) . ' → ' . ( '' !== $ends ? $ends : $never );

	$urls = array();
	foreach ( (array) json_decode( (string) ( $row['purge_urls'] ?? '' ), true ) as $url ) {
		if ( is_string( $url ) && '' !== $url ) {
			$urls[] = $url;
		}
	}
	$blocks = array();
	if ( $urls ) {
		$rows_out = array();
		foreach ( $urls as $url ) {
			$rows_out[] = array( 'url' => $url );
		}
		$blocks[] = array(
			'heading' => __( 'Purge URLs', 'signal-and-noise-tools' ),
			'kind'    => 'table',
			'columns' => array( array( 'key' => 'url', 'label' => __( 'URL', 'signal-and-noise-tools' ) ) ),
			'rows'    => $rows_out,
		);
	}

	$actions = array();
	$door    = schedules_door();
	if ( '' !== $door ) {
		$actions[] = array( 'label' => __( 'Open Scheduled in S&N Dashboard', 'signal-and-noise-tools' ), 'url' => $door );
	}
	if ( $ref > 0 ) {
		$actions[] = array( 'label' => __( 'View the note', 'signal-and-noise-tools' ), 'url' => (string) get_permalink( $ref ) );
	}

	return array(
		'id'          => 's' . (string) ( $row['id'] ?? '' ),
		'title'       => $title,
		'subtitle'    => $action . ' · ' . $window,
		'thumbnail'   => '',
		'icon'        => 'dashicons-clock',
		'status'      => $status,
		'statusLabel' => ucfirst( $status ),
		'date'        => (string) ( $row['starts_at'] ?? '' ),
		'dateLabel'   => $starts,
		'badge'       => array(
			'text'  => $status,
			// The dossier's own reading of these states (note-dossier-state.php):
			// running is success, failed is a warning, waiting and finished are
			// neither good nor bad news.
			'tone'  => 'active' === $status ? 'success' : ( 'error' === $status ? 'warning' : 'neutral' ),
			'title' => $window,
		),
		// The same two the descriptor declares, and only those: a cell with no
		// column header paints nowhere, and Starts/Status are already the tile's
		// dateLabel and statusLabel.
		'columns'     => array(
			'action' => $action,
			'ends'   => '' !== $ends ? $ends : $never,
		),
		'detail'      => array(
			'hero'    => '',
			'facts'   => array(
				array( __( 'Target', 'signal-and-noise-tools' ), $title ),
				array( __( 'Action', 'signal-and-noise-tools' ), $action ),
				array( __( 'Starts', 'signal-and-noise-tools' ), '' !== $starts ? $starts : $never ),
				array( __( 'Ends', 'signal-and-noise-tools' ), '' !== $ends ? $ends : $never ),
				array( __( 'Status', 'signal-and-noise-tools' ), $status ),
				array( __( 'Last run', 'signal-and-noise-tools' ), '' !== $run ? $run : $never ),
				array( __( 'Purge URLs', 'signal-and-noise-tools' ), (string) count( $urls ) ),
			),
			'blocks'  => $blocks,
			'actions' => $actions,
		),
	);
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$statuses = array();
		foreach ( schedule_statuses() as $status ) {
			$statuses[] = array( 'value' => $status, 'label' => ucfirst( $status ) );
		}
		$sections[] = array(
			'id'             => 'schedules',
			'label'          => __( 'Scheduled fragments', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-clock',
			'kind'           => 'entry',
			'capability'     => 'manage_options',
			'position'       => 40,
			'statuses'       => $statuses,
			'default_status' => '',
			// The list view already paints Status and Date (the start) from
			// statusLabel and dateLabel; only what those do not carry is a column.
			'columns'        => array(
				array( 'key' => 'action', 'label' => __( 'Action', 'signal-and-noise-tools' ) ),
				array( 'key' => 'ends', 'label' => __( 'Ends', 'signal-and-noise-tools' ) ),
			),
			'count'          => __NAMESPACE__ . '\schedules_count',
			'items'          => __NAMESPACE__ . '\schedules_items',
		);
		return $sections;
	}
);
