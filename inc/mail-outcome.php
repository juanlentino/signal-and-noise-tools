<?php
/**
 * Signal & Noise Tools: what the mail server said, from core's wp_mail hooks.
 *
 * 2026-10-24. wp_mail() reports its outcome on two core actions and nothing
 * kept it: wp_mail_failed carries a WP_Error, wp_mail_succeeded fires once
 * PHPMailer hands the message over. This keeps the last refusal (the time, the
 * error code, the message trimmed with any address taken out) and counts the
 * acceptances, in one small option with autoload off. Never the recipient,
 * the subject, the body or the headers: core puts them in the error data,
 * which is never read here.
 *
 * Accepted means the mail server took the message, not that it reached an
 * inbox; the weekly security digest words it that way.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MAIL_OUTCOME_OPTION = 'sn_mail_outcome_v1';

/**
 * The stored outcome.
 *
 * @return array{accepted:int,since:int,last_failure:?array{at:int,code:string,message:string}}
 */
function snt_mail_outcome_get() {
	$o = get_option( SN_MAIL_OUTCOME_OPTION, array() );
	$o = ( is_array( $o ) ? $o : array() ) + array( 'accepted' => 0, 'since' => 0, 'last_failure' => null );
	return array(
		'accepted'     => (int) $o['accepted'],
		'since'        => (int) $o['since'],
		'last_failure' => is_array( $o['last_failure'] ) ? $o['last_failure'] : null,
	);
}

/**
 * A mailer message, safe to keep: no tags, no address, at most 200 characters.
 * PHPMailer names the refused recipients in it ("The following recipients
 * failed: ...", "Invalid address: ...").
 * ponytail: anything shaped like local@domain counts as an address; a stricter
 * parse is not worth it for a diagnostic line.
 *
 * @param string $message The WP_Error message.
 * @return string
 */
function snt_mail_outcome_clean_message( $message ) {
	$text = strip_tags( (string) $message ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- plain text in, plain text out; no WP dependency for the standalone harness.
	$text = (string) preg_replace( '/[^\s<>()\[\]"\',;:]+@[^\s<>()\[\]"\',;:]+/', '[address]', $text );
	$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
}

/**
 * wp_mail_failed: keep the last refusal.
 *
 * @param WP_Error $error Core's error; its data (to, subject, message) is never read.
 */
function snt_mail_outcome_failed_cb( $error ) {
	if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_code' ) ) {
		return;
	}
	$o                 = snt_mail_outcome_get();
	$o['last_failure'] = array(
		'at'      => time(),
		'code'    => substr( (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) $error->get_error_code() ), 0, 64 ),
		'message' => snt_mail_outcome_clean_message( $error->get_error_message() ),
	);
	update_option( SN_MAIL_OUTCOME_OPTION, $o, false );
}
add_action( 'wp_mail_failed', 'snt_mail_outcome_failed_cb', 10, 1 );

/**
 * wp_mail_succeeded: count it. No arguments, so the mail data never arrives.
 */
function snt_mail_outcome_succeeded_cb() {
	$o              = snt_mail_outcome_get();
	$o['accepted'] += 1;
	$o['since']     = $o['since'] > 0 ? $o['since'] : time();
	update_option( SN_MAIL_OUTCOME_OPTION, $o, false );
}
add_action( 'wp_mail_succeeded', 'snt_mail_outcome_succeeded_cb', 10, 0 );
