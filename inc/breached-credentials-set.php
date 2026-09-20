<?php
/**
 * Signal & Noise Tools — breached-credential rejection, Mode A: SET-TIME,
 * BLOCKING, FAIL-CLOSED (Phase 1 of
 * docs/proposals/breached-credential-check-2026-08-31.md).
 *
 * The one auth surface neither the edge guard nor two-factor can see: a
 * password that is already in a breach corpus, being SET. Plaintext exists
 * only at the three set-time hooks, so this is the only moment the check can
 * run against what the user typed; the stored value is a hash and can never be
 * checked at rest (that is Mode B's job, at login).
 *
 * Fail-CLOSED is the whole design, and it is affordable because of the event
 * rate: a single-author site sets a password a few times a year. "The breach
 * check could not be reached, so this password was not set — try again in a
 * minute" is an acceptable answer to something that happens twice a year, and
 * it is the safe direction. UNAVAILABLE must never render as "not breached".
 *
 * What leaves the origin: the first five hex characters of the SHA-1, and
 * nothing else (inc/breached-credentials.php). The plaintext is read from the
 * request, handed to the client, and never stored, logged or echoed — the
 * error message carries a COUNT, never the password.
 *
 * Hooks:
 *   user_profile_update_errors  — profile screen / Users > Add New (pass1)
 *   validate_password_reset     — the reset-password form (pass1)
 *   rest_dispatch_request       — POST/PUT/PATCH on the users controller
 *                                 (password); the native profile window
 *                                 saves here, and neither classic hook fires
 *                                 on that path (17.2.2: the refusal was
 *                                 classic-only). NOT rest_pre_insert_user:
 *                                 core's users controller never reads a
 *                                 WP_Error back from it, so a refusal there
 *                                 becomes a silent 200 that drops the save.
 * NOT registration_errors: core registration takes no password (the user sets
 * one through the reset link, which lands on validate_password_reset).
 *
 * Kill switch: define( 'SN_HIBP_SET_DISABLED', true ) in wp-config.php. A
 * constant, not an option — an attacker with an admin session should not be
 * able to switch the check off from the screen the check protects.
 *
 * Telemetry for Phase 3: a small option counts breached rejections and
 * fail-closed (unavailable) rejections, with the last unavailable timestamp,
 * so a degrading API shows as a climbing unavailable count rather than as a
 * site quietly refusing legitimate password changes.
 *
 * @package SignalNoiseTools
 * @since 13.58.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_HIBP_SET_STATS_OPTION = 'sn_hibp_set_stats';

/** The kill switch: a constant, never an option. */
function sn_hibp_set_disabled() {
	return defined( 'SN_HIBP_SET_DISABLED' ) && SN_HIBP_SET_DISABLED;
}

/**
 * PURE decision over the client's verdict. null = allow; otherwise
 * {code, message} to attach to the errors object.
 *
 * FAIL-CLOSED lives here: UNAVAILABLE is an error, not an allow. Only an
 * explicit NOT_BREACHED lets the password through — an unknown verdict
 * string (a future client change, a typo) is refused too, because the safe
 * default for a value this function does not recognise is "not set".
 *
 * @param array{verdict:string,count:int} $result From sn_hibp_check_password().
 * @return array{code:string,message:string}|null
 */
function sn_hibp_set_time_decision( $result ) {
	$verdict = is_array( $result ) ? (string) ( $result['verdict'] ?? '' ) : '';
	$count   = is_array( $result ) ? (int) ( $result['count'] ?? 0 ) : 0;

	if ( SN_HIBP_NOT_BREACHED === $verdict ) {
		return null;
	}
	if ( SN_HIBP_BREACHED === $verdict ) {
		return array(
			'code'    => 'sn_hibp_breached',
			'message' => sprintf(
				/* translators: %s: number of times the password appears in known breaches. */
				__( 'This password appears %s times in known data breaches, so it was not set. Choose one that has never been used anywhere.', 'signal-and-noise-tools' ),
				number_format_i18n( $count )
			),
		);
	}
	return array(
		'code'    => 'sn_hibp_unavailable',
		'message' => __( 'The breach check could not be reached, so this password was not set. Try again in a minute — a password is never set without the check.', 'signal-and-noise-tools' ),
	);
}

/**
 * Record one rejection. Counts only; never the password, never the prefix.
 *
 * @param string $code sn_hibp_breached | sn_hibp_unavailable.
 */
function sn_hibp_set_record( $code ) {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$stats = get_option( SN_HIBP_SET_STATS_OPTION, array() );
	$stats = is_array( $stats ) ? $stats : array();
	if ( 'sn_hibp_breached' === $code ) {
		$stats['breached_count'] = (int) ( $stats['breached_count'] ?? 0 ) + 1;
		$stats['last_breached_at'] = time();
	} else {
		$stats['unavailable_count']   = (int) ( $stats['unavailable_count'] ?? 0 ) + 1;
		$stats['last_unavailable_at'] = time();
	}
	update_option( SN_HIBP_SET_STATS_OPTION, $stats, false );
}

/**
 * The stats, shaped for a reader. Zeros are real here: the option counts
 * events and starts empty.
 *
 * @return array{breached_count:int,unavailable_count:int,last_breached_at:int,last_unavailable_at:int}
 */
function sn_hibp_set_stats() {
	$s = function_exists( 'get_option' ) ? get_option( SN_HIBP_SET_STATS_OPTION, array() ) : array();
	$s = is_array( $s ) ? $s : array();
	return array(
		'breached_count'      => (int) ( $s['breached_count'] ?? 0 ),
		'unavailable_count'   => (int) ( $s['unavailable_count'] ?? 0 ),
		'last_breached_at'    => (int) ( $s['last_breached_at'] ?? 0 ),
		'last_unavailable_at' => (int) ( $s['last_unavailable_at'] ?? 0 ),
	);
}

/**
 * The submitted new password, or '' when the form did not set one. Read
 * raw and unslashed: a password is a secret compared byte-for-byte, and
 * sanitising it would check a different string from the one WordPress will
 * hash. It is consumed in memory and never assigned anywhere else.
 *
 * @return string
 */
function sn_hibp_set_submitted_password() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the hooks this runs on sit behind core's own nonce checks; the value is a secret, compared not rendered.
	if ( ! isset( $_POST['pass1'] ) || ! is_string( $_POST['pass1'] ) ) {
		return '';
	}
	return function_exists( 'wp_unslash' ) ? (string) wp_unslash( $_POST['pass1'] ) : (string) $_POST['pass1'];
	// phpcs:enable
}

/**
 * The shared handler: check the submitted password, attach the decision.
 *
 * @param WP_Error $errors  Core's errors object for the form.
 * @param string   $password Plaintext; '' means "no change requested".
 * @return bool True when a rejection was attached.
 */
function sn_hibp_set_time_guard( $errors, $password ) {
	if ( sn_hibp_set_disabled() || '' === $password ) {
		return false;
	}
	if ( ! function_exists( 'sn_hibp_check_password' ) || ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
		return false; // No client loaded: nothing to decide with. (The client file is required before this one.)
	}
	$decision = sn_hibp_set_time_decision( sn_hibp_check_password( $password ) );
	if ( null === $decision ) {
		return false;
	}
	$errors->add( $decision['code'], $decision['message'], array( 'form-field' => 'pass1' ) );
	sn_hibp_set_record( $decision['code'] );
	return true;
}

/** user_profile_update_errors: ( WP_Error $errors, bool $update, stdClass $user ). */
function sn_hibp_on_profile_update_errors( $errors, $update = false, $user = null ) {
	unset( $update, $user );
	sn_hibp_set_time_guard( $errors, sn_hibp_set_submitted_password() );
}

/** validate_password_reset: ( WP_Error $errors, WP_User|WP_Error $user ). */
function sn_hibp_on_validate_password_reset( $errors, $user = null ) {
	unset( $user );
	sn_hibp_set_time_guard( $errors, sn_hibp_set_submitted_password() );
}

/**
 * rest_dispatch_request: ( mixed $result, WP_REST_Request $request, string $route, array $handler ).
 *
 * The native profile window saves through PUT wp/v2/users/{id}; core's users
 * controller hands the request's plaintext `password` to wp_update_user()
 * without the profile-form hooks. This filter runs after the route's
 * permission callback (an unauthenticated request never reaches the breach
 * client) and after core sanitised the params, and a non-null return is the
 * response core sends, so the refusal is a 400 with `params.password`
 * naming the field and the same message the classic screen shows.
 *
 * Only a write on the users controller carries a password to hash:
 * create_item, update_item, update_current_item. A read can carry
 * `?password=` in its query string and must not be judged on it.
 *
 * @param mixed  $result  Another filter's short-circuit, or null.
 * @param object $request WP_REST_Request (ArrayAccess, get_method()).
 * @param string $route   Matched route pattern (unused).
 * @param array  $handler Route handler; callback names the controller.
 * @return mixed
 */
function sn_hibp_on_rest_dispatch_request( $result, $request = null, $route = '', $handler = array() ) {
	unset( $route );
	if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
		return $result;
	}
	$callback = is_array( $handler ) && isset( $handler['callback'] ) ? $handler['callback'] : null;
	if ( ! is_array( $callback ) || ! isset( $callback[0] ) || ! ( $callback[0] instanceof WP_REST_Users_Controller ) ) {
		return $result;
	}
	if ( ! in_array( (string) $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		return $result;
	}
	if ( ! isset( $request['password'] ) || ! is_string( $request['password'] ) ) {
		return $result;
	}
	$errors = new WP_Error();
	if ( ! sn_hibp_set_time_guard( $errors, (string) $request['password'] ) ) {
		return $result;
	}
	$code    = (string) $errors->get_error_codes()[0];
	$message = (string) $errors->get_error_message( $code );
	return new WP_Error( $code, $message, array( 'status' => 400, 'params' => array( 'password' => $message ) ) );
}

add_action( 'user_profile_update_errors', 'sn_hibp_on_profile_update_errors', 10, 3 );
add_action( 'validate_password_reset', 'sn_hibp_on_validate_password_reset', 10, 2 );
add_filter( 'rest_dispatch_request', 'sn_hibp_on_rest_dispatch_request', 10, 4 );
