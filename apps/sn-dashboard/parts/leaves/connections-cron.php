<?php
/**
 * S&N Dashboard — Connections → Cron, painted from the kit.
 *
 * The classic tab is the whole `sn_admin_cron_tab` hook, three callbacks. The
 * events table (inc/cron-dashboard-admin.php, `snt_cron_render_admin_tab()`,
 * priority 10) has no form and no `sn_action`: Run now, Unschedule and the
 * per-row history fetch are client-side JS calls against the
 * `run-cron-event` / `get-cron-history` / `unschedule-cron-event` abilities.
 * Run now and Unschedule paint here as per-row kit buttons on the app's own
 * `cron_run` / `cron_unschedule` actions (sn-dashboard.os.php, #1604), which
 * run the SAME registered abilities behind the shell's confirm dialog
 * (danger-styled on Unschedule). The action set is the app's declaration,
 * not a framework limit. A row that cannot take an action paints the button
 * disabled with the reason in its title, the classic button's state.
 *
 * The ledger is a `<ul class="snt-list snt-list--ledger">`, not an
 * `<os-table>` (a cell is scalar data with no slot for a control, upstream
 * #862), a grid of seven tracks with each row a subgrid (assets/os-app.css).
 * The classic `#sn-cron-filter` is cron_filter_html(); the Args clamp stays.
 *
 * The other two callbacks, `snt_morning_brief_render_settings()` (priority 20)
 * and `snt_scheduled_reads_render_settings()` (priority 30), each carry one
 * classic form and one `sn_action` (`morning_brief_save`, `scheduled_reads_save`).
 * They paint here with the same names through snt_kit_form(), one paired row
 * under the ledger: connections-cron-parts.php. Between the ledger and that
 * row sits the Action Scheduler backlog box (same file), the Site Health
 * reading on the leaf where scheduled jobs are asked about.
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

require_once __DIR__ . '/connections-cron-parts.php';

/**
 * Longest Args cell rendered verbatim, in characters. See cron_args_summary().
 */
if ( ! defined( 'SNT_CRON_ARGS_CELL_MAX' ) ) {
	define( 'SNT_CRON_ARGS_CELL_MAX', 72 );
}

/**
 * The Run now control for one row: the classic button's three mutually
 * exclusive states (disabled/no-handler, disabled/sn-internal, enabled), as
 * a kit button on the app's `cron_run` action.
 *
 * @param array $row A snt_cron_get_events_impl() row.
 * @return string
 */
function cron_run_button( array $row ) {
	$hook    = (string) ( $row['hook'] ?? '' );
	$why_not = '';
	if ( empty( $row['has_handler'] ) ) {
		$why_not = __( 'No handler: the schedule fires to nothing', 'signal-and-noise-tools' );
	} elseif ( str_starts_with( $hook, 'sn_' ) ) {
		$why_not = __( 'Not runnable here: dispatched on its own schedule', 'signal-and-noise-tools' );
	}
	return \snt_kit_button(
		__( 'Run now', 'signal-and-noise-tools' ),
		'cron_run',
		array(
			'args'          => array( 'hook' => $hook, 'args' => (string) wp_json_encode( is_array( $row['args'] ?? null ) ? $row['args'] : array() ) ),
			/* translators: %s: the cron hook name. */
			'confirm'       => sprintf( __( 'Run %s now? Its callbacks execute immediately.', 'signal-and-noise-tools' ), $hook ),
			'confirm_title' => __( 'Run now', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Run now', 'signal-and-noise-tools' ),
			'disabled'      => '' !== $why_not,
			'title'         => '' !== $why_not ? $why_not : null,
		)
	);
}

/**
 * The Unschedule control for one row: the classic button's two states, as a
 * danger-confirmed kit button on the app's `cron_unschedule` action.
 *
 * @param array $row A snt_cron_get_events_impl() row.
 * @return string
 */
function cron_unschedule_button( array $row ) {
	$hook   = (string) ( $row['hook'] ?? '' );
	$locked = ! empty( $row['is_sn_owned'] );
	return \snt_kit_button(
		__( 'Unschedule', 'signal-and-noise-tools' ),
		'cron_unschedule',
		array(
			'args'          => array( 'hook' => $hook, 'args' => (string) wp_json_encode( is_array( $row['args'] ?? null ) ? $row['args'] : array() ) ),
			/* translators: %s: the cron hook name. */
			'confirm'       => sprintf( __( 'Unschedule %s? Every pending run with these args is removed.', 'signal-and-noise-tools' ), $hook ),
			'confirm_title' => __( 'Unschedule', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Unschedule', 'signal-and-noise-tools' ),
			'danger'        => true,
			'disabled'      => $locked,
			'title'         => $locked ? __( 'Locked: disable the owning module instead', 'signal-and-noise-tools' ) : null,
		)
	);
}

/**
 * The Args cell, clamped to one readable line.
 *
 * The raw JSON is unbounded. One analytics rollup event on this site carries a
 * 1315-character payload, and in a table with `table-layout: auto` a single
 * unbreakable cell of that width claims the row: measured live 2026-09-10 in an
 * 1820px window, Args took 2668px of a 3369px table, squeezing every other column
 * to its minimum -- which is why the timestamps wrapped onto four lines while the
 * table scrolled sideways and the right half read as empty. It was the Args column.
 *
 * The list row's ellipsis bounds the cell on screen; the clamp keeps the
 * painted markup itself one line, and the elided length is reported so a
 * truncated value never looks complete.
 *
 * @param mixed $args The event's args array.
 * @return string One line, at most SNT_CRON_ARGS_CELL_MAX chars plus a count.
 */
function cron_args_summary( $args ) {
	$json = (string) wp_json_encode( $args );
	$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $json ) : strlen( $json );
	if ( $len <= SNT_CRON_ARGS_CELL_MAX ) {
		return $json;
	}
	$cut = function_exists( 'mb_substr' )
		? mb_substr( $json, 0, SNT_CRON_ARGS_CELL_MAX )
		: substr( $json, 0, SNT_CRON_ARGS_CELL_MAX );
	return rtrim( $cut ) . sprintf(
		/* translators: %s: full length of the elided JSON payload, in characters. */
		'… ' . __( '(%s chars)', 'signal-and-noise-tools' ),
		number_format_i18n( $len )
	);
}

/**
 * One classic row, cell by cell: every column the classic table prints, the
 * five readings as text and the two controls as painted buttons.
 *
 * @param array $row A snt_cron_get_events_impl() row.
 * @return array<string,string> hook..args plain text; run, unschedule markup.
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

	// #1596: the relative half ages live and is signed ("in 5 mins" / "5 mins
	// ago"), so the #1222 overdue reversal lives in the component; the classic
	// renderer keeps snt_cron_next_run_label().
	$next_ts = (int) ( $row['next_run_ts'] ?? 0 );
	$next    = \snt_kit_esc( wp_date( 'Y-m-d H:i:s', $next_ts ) ) . ' (' . \snt_kit_relative_time( $next_ts ) . ')';

	if ( ! empty( $row['schedule'] ) ) {
		$recurrence = (string) $row['schedule'];
		if ( ! empty( $row['interval_s'] ) ) {
			$recurrence .= ' (' . human_time_diff( 0, (int) $row['interval_s'] ) . ')';
		}
	} else {
		$recurrence = __( 'single event', 'signal-and-noise-tools' );
	}

	$last_ts = (int) ( $row['last_fired_ts'] ?? 0 );
	$last    = $last_ts ? \snt_kit_esc( wp_date( 'Y-m-d H:i:s', $last_ts ) ) . ' (' . \snt_kit_relative_time( $last_ts ) . ')' : '—';

	$args = ! empty( $row['args'] ) ? cron_args_summary( $row['args'] ) : '—';

	return array(
		'hook'       => $hook,
		'next_run'   => $next,
		'recurrence' => $recurrence,
		'last_fired' => $last,
		'args'       => $args,
		'run'        => cron_run_button( $row ),
		'unschedule' => cron_unschedule_button( $row ),
	);
}

/**
 * The events ledger: one header row and one row per event, on the list
 * vocabulary (`snt-list__label` for the flexible Hook cell, `snt-list__value`
 * for the rest), the two controls in the last two cells.
 *
 * @param array<int,array<string,mixed>> $rows From snt_cron_get_events_impl().
 * @return string
 */
function cron_table_html( array $rows ) {
	$labels = array(
		__( 'Hook', 'signal-and-noise-tools' ),
		__( 'Next run', 'signal-and-noise-tools' ),
		__( 'Recurrence', 'signal-and-noise-tools' ),
		__( 'Last fired', 'signal-and-noise-tools' ),
		__( 'Args', 'signal-and-noise-tools' ),
		__( 'Run now', 'signal-and-noise-tools' ),
		__( 'Unschedule', 'signal-and-noise-tools' ),
	);
	$head = '';
	foreach ( $labels as $i => $label ) {
		$head .= '<span class="' . ( 0 === $i ? 'snt-list__label' : 'snt-list__value' ) . '">' . \snt_kit_esc( $label ) . '</span>';
	}
	$out = '<li class="snt-list__row">' . $head . '</li>';
	if ( array() === $rows ) {
		$out .= '<li class="snt-list__empty">' . \snt_kit_esc( __( 'No hook matches the filter.', 'signal-and-noise-tools' ) ) . '</li>';
	}
	foreach ( $rows as $row ) {
		$cells = cron_row_data( (array) $row );
		$out  .= '<li class="snt-list__row" os-key="' . \snt_kit_esc( (string) ( $row['hook'] ?? '' ) . '|' . (string) ( $row['args_signature'] ?? '' ) ) . '">'
			. '<span class="snt-list__label" title="' . \snt_kit_esc( $cells['hook'] ) . '">' . \snt_kit_esc( $cells['hook'] ) . '</span>'
			. '<span class="snt-list__value">' . $cells['next_run'] . '</span>'
			. '<span class="snt-list__value">' . \snt_kit_esc( $cells['recurrence'] ) . '</span>'
			. '<span class="snt-list__value">' . $cells['last_fired'] . '</span>'
			. '<span class="snt-list__value" title="' . \snt_kit_esc( $cells['args'] ) . '">' . \snt_kit_esc( $cells['args'] ) . '</span>'
			. '<span class="snt-list__value">' . $cells['run'] . '</span>'
			. '<span class="snt-list__value">' . $cells['unschedule'] . '</span>'
			. '</li>';
	}
	return \snt_kit_section(
		__( 'Scheduled events', 'signal-and-noise-tools' ),
		'<div class="snt-ledger"><ul class="snt-list snt-list--ledger">' . $out . '</ul></div>',
		__( 'Scheduled cron events with next run time, recurrence, last-fired timestamp, arguments, and per-event actions.', 'signal-and-noise-tools' )
	);
}

/**
 * The classic `#sn-cron-filter` input (inc/cron-dashboard-admin.php): a
 * search field bound to the app's `filter` state key, so a keystroke is the
 * framework's built-in `set` (App Framework, Experimental at OpenStation
 * 1.1.10: `os-bind` writes the declared key, then repaints) and the painter
 * filters below. `clearable` is the search box's own clear button.
 *
 * @param string $filter The current filter text.
 * @return string
 */
function cron_filter_html( $filter ) {
	return '<div class="snt-ledger__filter">' . \snt_kit_tag(
		'os-text-field',
		array(
			'type'        => 'search',
			'label'       => __( 'Filter cron events by hook name', 'signal-and-noise-tools' ),
			'hide-label'  => true,
			'placeholder' => __( 'Filter by hook name', 'signal-and-noise-tools' ),
			'value'       => (string) $filter,
			'clearable'   => true,
			'os-bind'     => 'filter',
		)
	) . '</div>';
}

/**
 * The rows whose hook contains the filter, case-insensitively: the classic
 * keystroke handler's `hook.indexOf( needle ) === -1 ? hide : show`.
 *
 * @param array<int,array<string,mixed>> $rows   From snt_cron_get_events_impl().
 * @param string                         $filter The filter text; '' keeps every row.
 * @return array<int,array<string,mixed>>
 */
function cron_filter_rows( array $rows, $filter ) {
	$needle = strtolower( trim( (string) $filter ) );
	if ( '' === $needle ) {
		return $rows;
	}
	return array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $needle ) {
				return false !== strpos( strtolower( (string) ( $row['hook'] ?? '' ) ), $needle );
			}
		)
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
	$out   = array();
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
		$out[] = \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), $caption, $kind );
	}
	return \snt_kit_section( __( 'Cron at a glance', 'signal-and-noise-tools' ), \snt_kit_grid( $out, 160, 10 ) );
}

/**
 * The leaf.
 *
 * The classic Heartbeat client's last-fired refresh is the runtime's own
 * `os-poll` here (snt_kit_watch_bar(), #1607), gated on `sn_watch`: the two
 * settings forms under the ledger carry kit checkboxes whose `checked` the
 * morph re-syncs on every tick, focused or not, so the leaf polls only while
 * those forms are folded away.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_cron( array $ctx ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'You do not have permission to view this page.', 'signal-and-noise-tools' ) );
	}

	$rows     = function_exists( 'snt_cron_get_events_impl' ) ? snt_cron_get_events_impl() : array();
	$watching = \snt_kit_watching( $ctx );
	// The live snapshot: the runtime's poll while watching, in place of the
	// classic Heartbeat client (whose selectors the kit never paints).
	$refresh = \snt_kit_watch_bar( 'cron', $watching, __( 'the ledger', 'signal-and-noise-tools' ) );
	// do_action paints the two settings callbacks whether or not cron has rows;
	// the backlog box sits above them on both branches. While watching, the
	// settings forms are folded (see above).
	$settings = cron_backlog_html() . ( $watching ? '' : cron_settings_row_html() );

	if ( empty( $rows ) ) {
		// Classic runs this sentence through wp_kses_post() so the four hook
		// names render as <code>; os-empty-state's description prop is a
		// plain-text attribute (escaped, no HTML), so the names are painted
		// as a separate paragraph after it instead of folded into the prop.
		$out  = $refresh . \snt_kit_empty( __( 'No scheduled events.', 'signal-and-noise-tools' ) );
		$out .= '<p class="snt-prose">' . sprintf(
			/* translators: 1-4: the core cron hook names WordPress schedules at install */
			\snt_kit_esc( "This is unusual. WordPress core typically schedules %1\$s, %2\$s, %3\$s, and %4\$s at install. If your cron is empty, something has cleared it. Check your hosting provider's cron configuration." ),
			\snt_kit_code( 'wp_version_check', false ),
			\snt_kit_code( 'wp_update_plugins', false ),
			\snt_kit_code( 'wp_update_themes', false ),
			\snt_kit_code( 'wp_scheduled_delete', false )
		) . '</p>';
		return $out . $settings;
	}

	$count = count( $rows );
	$out   = $refresh . cron_glance_html( $rows );
	$out  .= '<p class="snt-hint">' . sprintf(
		\snt_kit_esc( _n( '%s scheduled event. Signal & Noise–owned events pinned at top.', '%s scheduled events. Signal & Noise–owned events pinned at top.', $count, 'signal-and-noise-tools' ) ),
		\snt_kit_esc( number_format_i18n( $count ) )
	) . '</p>';
	$filter = isset( $ctx['state'] ) && is_object( $ctx['state'] ) ? (string) $ctx['state']->get( 'filter' ) : '';
	$out   .= cron_filter_html( $filter ) . cron_table_html( cron_filter_rows( $rows, $filter ) );
	return $out . $settings;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/cron'] = __NAMESPACE__ . '\\paint_connections_cron';
		return $painters;
	}
);
