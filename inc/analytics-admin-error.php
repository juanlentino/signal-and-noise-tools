<?php
/**
 * S&N Analytics: the "Analytics read failed" diagnostic (admins only), one
 * verdict for both twins. The classic page prints it with
 * snt_analytics_render_error(); the native window's chrome/error painter
 * (apps/sn-analytics/parts/painters/chrome-status.php) reads the same
 * snt_analytics_render_error_data() and the same sentence.
 *
 * The stored error is site-wide and lives 5 minutes, and this notice belongs
 * to no single read, so it says how long ago the error was recorded instead
 * of claiming it as this page's. The code and the message only: the request
 * URL carries the account id.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The last recorded Analytics Engine error, for admins, in words. Null when
 * none is stored or the viewer cannot manage options.
 *
 * @return array{code: string, message: string, detail: string}|null
 */
function snt_analytics_render_error_data() {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'sn_analytics_last_error' ) ) {
		return null;
	}
	$err = sn_analytics_last_error();
	if ( ! is_array( $err ) ) {
		return null;
	}
	$code = isset( $err['code'] ) && (int) $err['code'] > 0 ? 'HTTP ' . (int) $err['code'] : __( 'Network error', 'signal-and-noise-tools' );
	$when = (int) ( $err['when'] ?? 0 );
	$age  = $when > 0
		/* translators: %s: how long ago, e.g. "3 mins". */
		? sprintf( __( 'recorded %s ago', 'signal-and-noise-tools' ), human_time_diff( $when, time() ) )
		: __( 'when it was recorded is not known', 'signal-and-noise-tools' );
	return array(
		'code'    => $code,
		'message' => (string) ( $err['message'] ?? '' ),
		// The one sentence both twins print after "Analytics read failed.".
		'detail'  => $code . ', ' . $age . '.',
	);
}

/**
 * Surface the last AE read error (admins only) so a blank dashboard is debuggable.
 *
 * @return void
 */
function snt_analytics_render_error() {
	$data = snt_analytics_render_error_data();
	if ( null === $data ) {
		return;
	}
	$body = '<strong>' . esc_html__( 'Analytics read failed.', 'signal-and-noise-tools' ) . '</strong> ' . esc_html( $data['detail'] );
	if ( '' !== $data['message'] ) {
		$body .= '<br>' . esc_html( $data['message'] );
	}
	wp_admin_notice( $body, array( 'type' => 'error', 'additional_classes' => array( 'notice-alt', 'inline' ) ) );
}
