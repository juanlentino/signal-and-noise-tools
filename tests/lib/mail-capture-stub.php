<?php
/**
 * Test helper: what core's wp_mail() does with phpmailer_init, without core.
 *
 * Recording add_action()/remove_action() (only when the suite has none), and
 * snt_test_mail_alt(), which runs the phpmailer_init hooks against a fake
 * mailer the way wp_mail() does mid-send, so a suite's wp_mail stub can record
 * the plain-text part the sender set. After the send, snt_test_mail_hooks()
 * must be empty: a hook left behind would leak into the next email.
 */
$GLOBALS['__hooks'] = $GLOBALS['__hooks'] ?? array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook = '', $cb = null ) { $GLOBALS['__hooks'][ $hook ][] = $cb; return true; }
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $cb ) { $GLOBALS['__hooks'][ $hook ] = array_values( array_filter( $GLOBALS['__hooks'][ $hook ] ?? array(), static fn( $c ) => $c !== $cb ) ); return true; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
/** The AltBody the phpmailer_init hooks set, as wp_mail() would see it. */
function snt_test_mail_alt() {
	$mailer          = new stdClass();
	$mailer->AltBody = '';
	foreach ( $GLOBALS['__hooks']['phpmailer_init'] ?? array() as $cb ) {
		$cb( $mailer );
	}
	return $mailer->AltBody;
}
/** phpmailer_init hooks still registered (must be none after a send). */
function snt_test_mail_hooks() {
	return $GLOBALS['__hooks']['phpmailer_init'] ?? array();
}
