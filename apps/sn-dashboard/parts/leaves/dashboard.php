<?php
/**
 * S&N Dashboard — the Dashboard tab, painted from the kit.
 *
 * The same data the classic tab paints (`snt_dashboard_tab_data()`: verdict,
 * exceptions, signals, the 30-day series, the systems wall, the ops panels,
 * the attention items, the overrides), in the shell's idiom: a display
 * headline, stats, a histogram, a systems grid, four lists, a maintenance
 * cluster and a disclosure. Every reading the classic page shows is here.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A classic admin URL into our own pages, as a `go` target — tab, sub, anchor.
 *
 * @param string $href Admin URL.
 * @return array<string,string>|null Null when the URL is not one of ours.
 */
function go_target( $href ) {
	$query = array();
	$parts = wp_parse_url( (string) $href );
	if ( ! is_array( $parts ) || false === strpos( (string) ( $parts['query'] ?? '' ), 'page=sn-theme-options' ) ) {
		return null;
	}
	parse_str( (string) $parts['query'], $query );
	return array(
		'tab'    => (string) ( $query['tab'] ?? 'dashboard' ),
		'sub'    => (string) ( $query['sub'] ?? '' ),
		'anchor' => (string) ( $parts['fragment'] ?? '' ),
	);
}

/**
 * The stat grid: signals, then every system with a reading.
 *
 * @param array<int,array<string,mixed>> $signals From sn_dash_signals_from_measurement().
 * @return string
 */
function signals_html( array $signals ) {
	$out = '';
	foreach ( $signals as $sig ) {
		if ( ! is_array( $sig ) ) {
			continue;
		}
		$compare = (string) ( $sig['compare'] ?? '' );
		$caption = '' !== $compare ? $compare : __( 'no prior period', 'signal-and-noise-tools' );
		$attrs   = array( 'class' => 'snt-signal' . ( empty( $sig['measured'] ) ? ' snt-signal--unmeasured' : '' ), 'data-dir' => (string) ( $sig['dir'] ?? '' ) );
		$out    .= \snt_kit_stat( (string) ( $sig['value'] ?? '' ), (string) ( $sig['label'] ?? '' ), $caption, '', $attrs );
	}
	return '<div class="snt-stats">' . $out . '</div>';
}

/**
 * Views per day as a histogram: one bucket per day, one series.
 *
 * @param array<int,array{day:string,views:int}> $series Daily rows.
 * @return string
 */
function trend_html( array $series ) {
	$series = array_values( $series );
	if ( count( $series ) < 2 ) {
		return '';
	}
	$columns = array();
	$peak    = 0;
	foreach ( $series as $row ) {
		$views     = (int) ( $row['views'] ?? 0 );
		$columns[] = array( $views );
		$peak      = max( $peak, $views );
	}
	$first  = strtotime( (string) ( $series[0]['day'] ?? '' ) . ' 00:00:00 UTC' );
	$last   = strtotime( (string) ( $series[ count( $series ) - 1 ]['day'] ?? '' ) . ' 00:00:00 UTC' );
	$latest = (int) ( $series[ count( $series ) - 1 ]['views'] ?? 0 );
	$meta   = sprintf(
		/* translators: 1: latest day's views, 2: the peak day's views */
		__( '%1$s latest · peak %2$s', 'signal-and-noise-tools' ),
		number_format_i18n( $latest ),
		number_format_i18n( $peak )
	);
	return \snt_kit_section(
		__( 'Views · 30 days', 'signal-and-noise-tools' ),
		\snt_kit_histogram(
			array( array( 'key' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ), 'tone' => 'accent' ) ),
			$columns,
			array(
				'start'  => false !== $first ? $first : null,
				'end'    => false !== $last ? $last + DAY_IN_SECONDS : null,
				'height' => 150,
				'empty'  => __( 'No views in the window.', 'signal-and-noise-tools' ),
			)
		),
		$meta
	);
}

/**
 * The systems wall: every check and every fleet component as a cell.
 *
 * @param array<int,array<string,mixed>> $checks     Cards.
 * @param array<int,array<string,mixed>> $components Fleet cards.
 * @param string                         $tab        The painting tab.
 * @return string
 */
function systems_html( array $checks, array $components, $tab ) {
	$cells = '';
	foreach ( array_merge( array_values( $checks ), array_values( $components ) ) as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';
		$state = ( 'ok' !== $kind && \sn_admin_card_wants_attention( $card ) ) ? $kind : '';
		$value = (string) ( $card['value'] ?? '' );
		$go    = go_target( (string) ( $card['href'] ?? '' ) );
		$body  = null !== $go
			? \snt_kit_go( $value, $go + array( 'current' => $tab ), array( 'class' => 'snt-sys__v' ) )
			: '<span class="snt-sys__v">' . \snt_kit_esc( $value ) . '</span>';
		$pill  = (string) ( $card['pill']['text'] ?? '' );
		$cells .= '<div class="snt-sys' . ( '' !== $state ? ' snt-sys--' . \snt_kit_esc( $state ) : '' ) . '"' . ( '' !== $state ? ' data-tone="' . \snt_kit_tone( $state ) . '"' : '' ) . '>'
			. '<span class="snt-sys__k">' . \snt_kit_esc( (string) ( $card['label'] ?? '' ) ) . '</span>'
			. $body
			. ( '' !== $pill && 'ok' !== $kind ? \snt_kit_badge( $kind, $pill ) : '' )
			. ( '' !== (string) ( $card['meta_html'] ?? '' ) ? '<span class="snt-sys__meta">' . (string) $card['meta_html'] . '</span>' : '' )
			. '</div>';
	}
	$parts = array();
	if ( ! empty( $checks ) ) {
		/* translators: %d health checks on the wall */
		$parts[] = sprintf( _n( '%d check', '%d checks', count( $checks ), 'signal-and-noise-tools' ), count( $checks ) );
	}
	if ( ! empty( $components ) ) {
		/* translators: %d fleet components on the wall */
		$parts[] = sprintf( _n( '%d component', '%d components', count( $components ), 'signal-and-noise-tools' ), count( $components ) );
	}
	return \snt_kit_section( __( 'Systems', 'signal-and-noise-tools' ), '<div class="snt-systems">' . $cells . '</div>', implode( ' · ', $parts ) );
}

/**
 * The ops wall: one list per panel; an absent source says it is not measured.
 *
 * @param array<int,array<string,mixed>> $panels From sn_dash_ops_panels().
 * @return string
 */
function detail_html( array $panels ) {
	$cols = '';
	foreach ( $panels as $panel ) {
		if ( ! is_array( $panel ) ) {
			continue;
		}
		$rows  = array_key_exists( 'rows', $panel ) ? $panel['rows'] : null;
		$inner = null === $rows
			? '<p class="snt-list__empty">' . \snt_kit_esc( (string) ( $panel['unmeasured'] ?? '' ) ) . '</p>'
			: \snt_kit_list( (array) $rows, array( 'empty' => (string) ( $panel['empty'] ?? '' ) ) );
		$cols .= '<section class="snt-col"><h3 class="snt-col__h">' . \snt_kit_esc( (string) ( $panel['title'] ?? '' ) ) . '</h3>' . $inner . '</section>';
	}
	return \snt_kit_section( __( 'Detail', 'signal-and-noise-tools' ), '<div class="snt-cols">' . $cols . '</div>' );
}

/**
 * The maintenance cluster: the same four actions, the same handlers.
 *
 * @param string $check_updates_url The nonce'd admin-post URL.
 * @return string
 */
function toolbar_html( $check_updates_url ) {
	$buttons = \snt_kit_action_button( __( 'Purge all caches', 'signal-and-noise-tools' ), 'purge_caches' )
		. \snt_kit_action_button( __( 'Clear overrides', 'signal-and-noise-tools' ), 'clear_overrides' );
	if ( '' !== (string) $check_updates_url ) {
		$buttons .= \snt_kit_door( __( 'Check for updates', 'signal-and-noise-tools' ), (string) $check_updates_url, array( 'variant' => 'secondary' ) );
	}
	$buttons .= \snt_kit_action_button(
		__( 'Full reset', 'signal-and-noise-tools' ),
		'full_reset',
		array(
			'variant'       => 'danger',
			'confirm'       => __( 'Reset every Signal & Noise setting to its default? This cannot be undone.', 'signal-and-noise-tools' ),
			'confirm_title' => __( 'Full reset', 'signal-and-noise-tools' ),
			'danger'        => true,
		)
	);
	return \snt_kit_section( __( 'Maintenance', 'signal-and-noise-tools' ), '<os-cluster gap="8">' . $buttons . '</os-cluster>' );
}

/**
 * The Dashboard tab.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_dashboard( array $ctx ) {
	if ( ! function_exists( 'snt_dashboard_tab_data' ) ) {
		return \snt_kit_empty( __( 'The Dashboard is not available.', 'signal-and-noise-tools' ) );
	}
	$data = \snt_dashboard_tab_data();
	if ( null === $data ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$tab     = (string) ( $ctx['tab'] ?? 'dashboard' );
	$verdict = (array) $data['verdict'];
	$state   = (string) ( $verdict['state'] ?? 'ok' );
	$out     = '<header class="snt-verdict" data-state="' . \snt_kit_esc( $state ) . '">'
		. '<os-display size="xl" align="start" value="' . \snt_kit_esc( (string) ( $verdict['headline'] ?? '' ) ) . '"></os-display>'
		. ( '' !== (string) $data['subline'] ? '<p class="snt-verdict__sub">' . \snt_kit_esc( (string) $data['subline'] ) . '</p>' : '' )
		. '</header>';
	foreach ( (array) ( $verdict['exceptions'] ?? array() ) as $ex ) {
		$out .= \snt_kit_notice( (string) ( $ex['kind'] ?? 'warn' ), '<b>' . \snt_kit_esc( (string) ( $ex['label'] ?? '' ) ) . '</b> ' . \snt_kit_esc( (string) ( $ex['detail'] ?? '' ) ) );
	}
	if ( ! empty( $data['attention'] ) ) {
		$links = array();
		foreach ( (array) $data['attention'] as $item ) {
			$go      = go_target( (string) ( $item['href'] ?? '' ) );
			$links[] = null !== $go
				? \snt_kit_go( (string) ( $item['text'] ?? '' ), $go + array( 'current' => $tab ) )
				: \snt_kit_esc( (string) ( $item['text'] ?? '' ) );
		}
		$out .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'Needs attention:', 'signal-and-noise-tools' ) ) . '</b> ' . implode( ' · ', $links ) );
	}
	$out .= signals_html( (array) $data['signals'] );
	$out .= trend_html( (array) $data['series'] );
	$out .= systems_html( (array) $data['checks'], (array) $data['components'], $tab );
	$out .= detail_html( (array) $data['panels'] );
	$out .= toolbar_html( (string) $data['check_updates_url'] );
	if ( ! empty( $data['overrides'] ) ) {
		$names = array_map( '\snt_kit_esc', (array) $data['overrides'] );
		$out  .= \snt_kit_tag(
			'os-disclosure',
			array(
				'heading' => sprintf( /* translators: %d database overrides */ _n( '%d database override', '%d database overrides', count( $names ), 'signal-and-noise-tools' ), count( $names ) ),
				'open'    => true,
				'id'      => 'sn-dash-diagnostics',
			),
			'<ul class="snt-plain">' . implode( '', array_map( static function ( $name ) { return '<li><os-code>' . $name . '</os-code></li>'; }, $names ) ) . '</ul>'
		);
	}
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['dashboard/'] = __NAMESPACE__ . '\\paint_dashboard';
		return $painters;
	}
);
