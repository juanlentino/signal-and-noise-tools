<?php
/**
 * S&N Dashboard — Connections → Cron, painted from the kit.
 *
 * The classic leaf (inc/cron-dashboard-admin.php, `snt_cron_render_admin_tab()`,
 * hooked to `sn_admin_cron_tab`) has NO forms and NO `sn_action` at all: every
 * mutating control (Run now, Unschedule, the per-row history fetch) is a plain
 * client-side JS call against the `run-cron-event` / `get-cron-history` /
 * `unschedule-cron-event` REST abilities, never `sn_handle_admin_post()`. This
 * window's action set is fixed to `go` / `post` / `door` / `refresh` /
 * `reopen` (apps/sn-dashboard/sn-dashboard.os.php) and a leaf painter cannot
 * add a new one, so those three controls cannot dispatch here; they paint as
 * the SAME per-row facts the classic buttons' enabled/disabled/title state
 * already encodes (which action is available, and why not), as read-only
 * text instead of a control. See the report for exactly what changed shape.
 *
 * Same readers as the classic leaf: `snt_cron_get_events_impl()` for the rows,
 * `snt_cron_glance_cards()` (inc/cron-dashboard-admin.php) for the hero.
 *
 * The per-row "history" toggle stays unported for a stronger reason than a
 * missing action: `<os-table>`'s sub-table is a JS-function property
 * (`table.subTable = (row) => …`, openstation-src os-table.ts), and `os-prop-*`
 * only assigns *parsed JSON* to a property (app-framework.md, "The view
 * vocabulary") — a function cannot travel through it. Painting the toggle
 * would need either an inline `<script>` (never runs in a window) or a
 * client view (`.os.ts`) for this leaf, both out of a server-view painter's
 * reach. `snt_cron_history_for_hook()` being plain PHP does not change that:
 * the gap is in how the property gets to the element, not in the data.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Run-now state for one row: classic's three mutually exclusive button
 * states (disabled/no-handler, disabled/sn-internal, enabled), as a status
 * string instead of a click target.
 *
 * @param array $row A snt_cron_get_events_impl() row.
 * @return string
 */
function cron_run_state( array $row ) {
	if ( empty( $row['has_handler'] ) ) {
		return __( 'No handler — schedule will fire to nothing', 'signal-and-noise-tools' );
	}
	if ( str_starts_with( (string) ( $row['hook'] ?? '' ), 'sn_' ) ) {
		return __( 'Not runnable here — dispatched on its own schedule', 'signal-and-noise-tools' );
	}
	return __( 'Available', 'signal-and-noise-tools' );
}

/**
 * The Unschedule state for one row: classic's two mutually exclusive states.
 *
 * @param array $row A snt_cron_get_events_impl() row.
 * @return string
 */
function cron_unschedule_state( array $row ) {
	if ( ! empty( $row['is_sn_owned'] ) ) {
		return __( 'Locked — disable the owning module instead', 'signal-and-noise-tools' );
	}
	return __( 'Available', 'signal-and-noise-tools' );
}

/**
 * One classic row, as an `<os-table>` row: every column the classic table
 * prints, folded into plain strings (a data-driven table takes scalar cells).
 *
 * @param array $row A snt_cron_get_events_impl() row.
 * @return array<string,string>
 */
function cron_row_data( array $row ) {
	$tags = array();
	if ( ! empty( $row['is_sn_owned'] ) ) {
		$tags[] = __( 'SN', 'signal-and-noise-tools' );
	}
	if ( empty( $row['has_handler'] ) ) {
		$tags[] = __( 'orphan', 'signal-and-noise-tools' );
	}
	$hook = (string) ( $row['hook'] ?? '' ) . ( ! empty( $tags ) ? ' [' . implode( ', ', $tags ) . ']' : '' );

	$next_ts = (int) ( $row['next_run_ts'] ?? 0 );
	/* translators: %s is a human-readable relative time, e.g., "5 mins" */
	$next = wp_date( 'Y-m-d H:i:s', $next_ts ) . ' (' . sprintf( __( 'in %s', 'signal-and-noise-tools' ), human_time_diff( time(), $next_ts ) ) . ')';

	if ( ! empty( $row['schedule'] ) ) {
		$recurrence = (string) $row['schedule'];
		if ( ! empty( $row['interval_s'] ) ) {
			$recurrence .= ' (' . human_time_diff( 0, (int) $row['interval_s'] ) . ')';
		}
	} else {
		$recurrence = __( 'single event', 'signal-and-noise-tools' );
	}

	$last_ts = (int) ( $row['last_fired_ts'] ?? 0 );
	/* translators: %s is a human-readable relative time, e.g., "5 mins" */
	$last = $last_ts ? wp_date( 'Y-m-d H:i:s', $last_ts ) . ' (' . sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( $last_ts, time() ) ) . ')' : '—';

	$args = ! empty( $row['args'] ) ? (string) wp_json_encode( $row['args'] ) : '—';

	return array(
		'hook'       => $hook,
		'next_run'   => $next,
		'recurrence' => $recurrence,
		'last_fired' => $last,
		'args'       => $args,
		'run'        => cron_run_state( $row ),
		'unschedule' => cron_unschedule_state( $row ),
	);
}

/**
 * The events table.
 *
 * @param array<int,array<string,mixed>> $rows From snt_cron_get_events_impl().
 * @return string
 */
function cron_table_html( array $rows ) {
	$columns = array(
		array( 'key' => 'hook', 'label' => __( 'Hook', 'signal-and-noise-tools' ), 'filter' => 'text' ),
		array( 'key' => 'next_run', 'label' => __( 'Next run', 'signal-and-noise-tools' ) ),
		array( 'key' => 'recurrence', 'label' => __( 'Recurrence', 'signal-and-noise-tools' ) ),
		array( 'key' => 'last_fired', 'label' => __( 'Last fired', 'signal-and-noise-tools' ) ),
		array( 'key' => 'args', 'label' => __( 'Args', 'signal-and-noise-tools' ) ),
		array( 'key' => 'run', 'label' => __( 'Run now', 'signal-and-noise-tools' ) ),
		array( 'key' => 'unschedule', 'label' => __( 'Unschedule', 'signal-and-noise-tools' ) ),
	);
	$table_rows = array_map(
		static function ( $row ) {
			return cron_row_data( (array) $row );
		},
		$rows
	);
	return \snt_kit_section(
		__( 'Scheduled events', 'signal-and-noise-tools' ),
		\snt_kit_table( $columns, $table_rows, array( 'empty' => __( 'No scheduled events.', 'signal-and-noise-tools' ) ) ),
		__( 'Scheduled cron events with next run time, recurrence, last-fired timestamp, arguments, and per-event actions.', 'signal-and-noise-tools' )
	);
}

/**
 * The glance hero: the same three cards the classic leaf builds
 * (snt_cron_glance_cards(), inc/cron-dashboard-admin.php), as stat tiles.
 *
 * @param array<int,array<string,mixed>> $rows From snt_cron_get_events_impl().
 * @return string
 */
function cron_glance_html( array $rows ) {
	$cards = function_exists( 'snt_cron_glance_cards' ) ? \snt_cron_glance_cards( $rows ) : array();
	$out   = '';
	foreach ( (array) $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind    = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		// `meta_html` arrives pre-escaped (a contract of snt_cron_glance_cards()):
		// decode + strip it back to plain text before handing it to
		// snt_kit_stat()'s caption, which escapes attributes itself — otherwise
		// any future card whose meta_html carries an entity or a tag would be
		// escaped twice, or render literal markup.
		$caption = html_entity_decode( strip_tags( (string) ( $card['meta_html'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $caption && isset( $card['pill']['text'] ) ) {
			$caption = (string) $card['pill']['text'];
		}
		$out .= \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), $caption, $kind );
	}
	return \snt_kit_section( __( 'Cron at a glance', 'signal-and-noise-tools' ), '<div class="snt-stats">' . $out . '</div>' );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_cron( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'You do not have permission to view this page.', 'signal-and-noise-tools' ) );
	}

	$rows = function_exists( 'snt_cron_get_events_impl' ) ? snt_cron_get_events_impl() : array();

	if ( empty( $rows ) ) {
		// Classic runs this sentence through wp_kses_post() so the four hook
		// names render as <code>; os-empty-state's description prop is a
		// plain-text attribute (escaped, no HTML), so the names are painted
		// as a separate paragraph after it instead of folded into the prop.
		$out  = \snt_kit_empty( __( 'No scheduled events.', 'signal-and-noise-tools' ) );
		$out .= '<p class="snt-prose">' . sprintf(
			/* translators: 1-4: the core cron hook names WordPress schedules at install */
			\snt_kit_esc( "This is unusual. WordPress core typically schedules %1\$s, %2\$s, %3\$s, and %4\$s at install. If your cron is empty, something has cleared it. Check your hosting provider's cron configuration." ),
			\snt_kit_code( 'wp_version_check', false ),
			\snt_kit_code( 'wp_update_plugins', false ),
			\snt_kit_code( 'wp_update_themes', false ),
			\snt_kit_code( 'wp_scheduled_delete', false )
		) . '</p>';
		return $out;
	}

	$count = count( $rows );
	$out   = cron_glance_html( $rows );
	$out  .= '<p class="snt-hint">' . sprintf(
		\snt_kit_esc( _n( '%s scheduled event. Signal & Noise–owned events pinned at top.', '%s scheduled events. Signal & Noise–owned events pinned at top.', $count, 'signal-and-noise-tools' ) ),
		\snt_kit_esc( number_format_i18n( $count ) )
	) . '</p>';
	$out  .= cron_table_html( $rows );
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/cron'] = __NAMESPACE__ . '\\paint_connections_cron';
		return $painters;
	}
);
