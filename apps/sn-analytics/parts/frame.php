<?php
/**
 * S&N Analytics — the tab frame: what every view tab paints around its body.
 *
 * The thirteen views are the framework's tabs (Overview is the main view),
 * each its own session holding the page's nine parameters. A tab paints, in
 * the classic page's order: the notice, the AE diagnostic, the insights band,
 * the header region (controls, the Overview panel, the rail), the drill-down
 * panel, the view body, the empty note — each piece through its kit painter,
 * registered under `chrome/<piece>` or `view/<slug>` through the
 * `snt_os_analytics_painters` filter. A view without a painter yet paints the
 * classic capture as scaffold (tests/openstation-app-analytics.php counts
 * what is left).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics;

use OpenStation\App\Os;
use OpenStation\App\State;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The view a dispatch came from: the framework's view slug, `main` being Overview.
 *
 * @param Os $os Host.
 * @return string
 */
function view_slug( Os $os, ?State $state = null ) {
	$view = isset( $os->view ) ? (string) $os->view : '';
	if ( '' === $view ) {
		$current = null !== $state ? (string) $state->get( 'view' ) : '';
		return '' !== $current ? $current : 'overview';
	}
	return 'main' === $view ? 'overview' : $view;
}

/**
 * Painters keyed `chrome/<piece>` or `view/<slug>`.
 *
 * @return array<string,callable>
 */
function painters() {
	return (array) apply_filters( 'snt_os_analytics_painters', array() );
}

/**
 * Everything a painter needs: the resolved window, the class, the compare
 * basis, the drill, the page's own resolvers' verdicts.
 *
 * @param string $view  View slug.
 * @param State  $state Session state.
 * @param Os     $os    Host.
 * @return array<string,mixed>
 */
function context( $view, State $state, Os $os ) {
	$get   = \snt_os_analytics_get( $state );
	$range = (string) $state->get( 'range' );
	$from  = (string) $state->get( 'from' );
	$to    = (string) $state->get( 'to' );
	if ( function_exists( 'snt_analytics_resolve_window' ) ) {
		list( $range, $from, $to ) = snt_analytics_resolve_window( $range, $from, $to );
	}
	$days        = max( 1, (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1 );
	$granularity = function_exists( 'sn_analytics_granularity' ) ? sn_analytics_granularity( $days ) : 'day';
	$drill       = (string) $state->get( 'drill' );
	return array(
		'view'        => $view,
		'range'       => (string) $range,
		'from'        => (string) $from,
		'to'          => (string) $to,
		'class'       => (string) $state->get( 'class' ),
		'compare'     => (string) $state->get( 'compare' ),
		'drill'       => '' !== $drill && function_exists( 'sn_analytics_drilldown_parse' ) ? sn_analytics_drilldown_parse( $drill ) : null,
		'drill_raw'   => $drill,
		'event_prop'  => (string) $state->get( 'event_prop' ),
		'lg_range'    => (int) $state->get( 'lg_range' ),
		'days'        => $days,
		'granularity' => $granularity,
		'configured'  => function_exists( 'sn_analytics_config' ) && (bool) sn_analytics_config(),
		'owns_chrome' => function_exists( 'snt_analytics_view_owns_chrome' ) && snt_analytics_view_owns_chrome( $view ),
		'get'         => $get,
		'query'       => \snt_os_analytics_query( $state ),
		'state'       => $state,
		'os'          => $os,
	);
}

/**
 * Run one painter. A painter returns a string, or `array( 'html' => …, … )`
 * with extra facts the frame reads (the header returns `totals`).
 *
 * @param string $key Registry key.
 * @param array  $ctx Context.
 * @return array{html:string,facts:array}
 */
function paint_piece( $key, array $ctx ) {
	$painters = painters();
	if ( ! isset( $painters[ $key ] ) || ! is_callable( $painters[ $key ] ) ) {
		return array( 'html' => '', 'facts' => array() );
	}
	$out = call_user_func( $painters[ $key ], $ctx );
	if ( is_array( $out ) ) {
		$html = (string) ( $out['html'] ?? '' );
		unset( $out['html'] );
		return array( 'html' => $html, 'facts' => $out );
	}
	return array( 'html' => (string) $out, 'facts' => array() );
}

/**
 * The notice the last write produced.
 *
 * @param mixed $notice `[ severity, html ]` or null.
 * @return string
 */
function notice_html( $notice ) {
	if ( ! is_array( $notice ) || ! isset( $notice[0], $notice[1] ) ) {
		return '';
	}
	return \snt_kit_notice( (string) $notice[0], (string) $notice[1], true );
}

/**
 * A view tab's callable.
 *
 * @param string $view View slug.
 * @return callable
 */
function tab_view( $view ) {
	return static function ( State $state, Os $os ) use ( $view ) {
		$state->set( 'view', $view );
		$ctx      = context( $view, $state, $os );
		$painters = painters();
		echo '<div class="snt-app" data-snt-view="' . \snt_kit_esc( $view ) . '" data-snt-query="' . \snt_kit_esc( $ctx['query'] ) . '">';
		echo notice_html( $state->get( 'notice' ) );
		if ( ! isset( $painters[ 'view/' . $view ] ) ) {
			echo '<div class="snt-classic">' . dashboard_html( $state ) . '</div></div>';
			return;
		}
		if ( ! $ctx['configured'] ) {
			echo paint_piece( 'chrome/empty', $ctx )['html'] . '</div>';
			return;
		}
		echo paint_piece( 'chrome/error', $ctx )['html'];
		$totals = array();
		if ( ! $ctx['owns_chrome'] ) {
			if ( 'edge' !== $view ) {
				echo paint_piece( 'chrome/insights', $ctx )['html'];
			}
			echo paint_piece( 'chrome/controls', $ctx )['html'];
			$header = paint_piece( 'chrome/header', $ctx );
			echo $header['html'];
			$totals = (array) ( $header['facts']['totals'] ?? array() );
		} elseif ( 'login-defense' === $view ) {
			echo paint_piece( 'chrome/login-header', $ctx )['html'];
		}
		echo '<div class="snt-view">';
		if ( is_array( $ctx['drill'] ) ) {
			echo paint_piece( 'chrome/drilldown', $ctx )['html'];
		}
		echo paint_piece( 'view/' . $view, $ctx )['html'];
		echo '</div>';
		if ( ! $ctx['owns_chrome'] && array() !== $totals && 0 === (int) ( $totals['views'] ?? 0 ) ) {
			echo '<p class="snt-hint snt-empty-note">' . \snt_kit_esc( __( 'No analytics data in this range yet. New data appears within ~15 minutes of a visit once the worker is live.', 'signal-and-noise-tools' ) ) . '</p>';
		}
		echo '</div>';
	};
}
