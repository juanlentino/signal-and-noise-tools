<?php
/**
 * Signal & Noise Tools: the alert, in the app (20.9.0).
 *
 * The hourly alert run mails what fired. This keeps the same headline where
 * the OpenStation app can read it, so an open app (desktop or phone) shows a
 * system notification through wp.os.notify. OpenStation v1 has no Web Push,
 * so a closed app is not woken: the email is the channel that reaches it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ALERTS_NOTICE_OPT = 'snt_alerts_notice';

/**
 * The notice an alert run leaves for the app. PURE.
 *
 * @param array<int,array<string,mixed>> $alerts  From snt_alerts_evaluate().
 * @param string                         $subject The email subject.
 * @param string                         $body    The email body; its alert lines are reused.
 * @param int                            $now     Unix time.
 * @return array{id:int,title:string,body:string,app:string}|null Null when nothing fired.
 */
function snt_alerts_notice_build( array $alerts, $subject, $body, $now ) {
	if ( array() === $alerts ) {
		return null;
	}
	$lines = array_values( array_filter( explode( "\n", (string) $body ), static fn( $l ) => 1 === preg_match( '/^(SPIKE|BREAK|CACHE): /', $l ) ) );
	$first = (string) ( $lines[0] ?? '' );
	$more  = count( $lines ) - 1;
	$only  = array_unique( array_column( $alerts, 'kind' ) );
	return array(
		'id'    => (int) $now,
		'title' => trim( (string) preg_replace( '/^\[[^\]]*\]\s*/', '', (string) $subject ) ),
		'body'  => substr( $first, 0, 240 ) . ( $more > 0 ? sprintf( ' (+%d more)', $more ) : '' ),
		// Where a tap lands: a refused cache refresh is an attention row; a
		// spike or a break is read in Analytics.
		'app'   => array( 'cache' ) === array_values( $only ) ? 'signal-noise' : 'sn-analytics',
	);
}

/**
 * The stored notice while it is under a day old, else null.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return array{id:int,title:string,body:string,app:string}|null
 */
function snt_alerts_notice( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$n   = get_option( SNT_ALERTS_NOTICE_OPT, array() );
	if ( ! is_array( $n ) || empty( $n['id'] ) || (int) $n['id'] < $now - DAY_IN_SECONDS || '' === (string) ( $n['title'] ?? '' ) ) {
		return null;
	}
	return array( 'id' => (int) $n['id'], 'title' => (string) $n['title'], 'body' => (string) ( $n['body'] ?? '' ), 'app' => (string) ( $n['app'] ?? 'sn-analytics' ) );
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', static function () {
		register_rest_route( 'signal-noise/v1', '/desktop/alert-notice', array(
			'methods'             => 'GET',
			'callback'            => static fn() => array( 'notice' => snt_alerts_notice() ),
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
		) );
	} );
}
