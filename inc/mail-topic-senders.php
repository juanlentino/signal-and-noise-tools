<?php
/**
 * Signal & Noise Tools: the contact form's topic addresses, on the one Google
 * connection.
 *
 * FluentSMTP sends a Gmail connection as its one sender and rewrites any other
 * From, unless the address is mapped to that connection. It offers extra
 * senders only for Amazon SES and toSend, so a form rule's From address never
 * reached Google. All the topic addresses are aliases on one Workspace account
 * and each is in that account's "Send mail as" list, so mapping them is enough.
 *
 * A mapping already stored wins, so an address given its own connection keeps
 * it. FluentSMTP saves what it reads, so its next write (a Google token refresh
 * is enough) stores these mappings, and from then on they stay on that connection
 * even if another becomes the default. Delete the old connection to clear them.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MAIL_TOPIC_SENDERS = array(
	'research@juanlentino.com',
	'press@juanlentino.com',
	'speaking@juanlentino.com',
	'music@juanlentino.com',
	'consulting@juanlentino.com',
	'role@juanlentino.com',
);

/**
 * FluentSMTP's settings with the topic addresses mapped to its default connection.
 *
 * @param mixed $settings The stored option; false when FluentSMTP was never set up.
 * @return mixed
 */
function sn_mail_topic_mappings( $settings ) {
	$connection = is_array( $settings ) ? (string) ( $settings['misc']['default_connection'] ?? '' ) : '';
	if ( '' === $connection || empty( $settings['connections'][ $connection ] ) ) {
		return $settings;
	}
	$settings['mappings'] = (array) ( $settings['mappings'] ?? array() ) + array_fill_keys( SN_MAIL_TOPIC_SENDERS, $connection );
	return $settings;
}
add_filter( 'option_fluentmail-settings', 'sn_mail_topic_mappings' );
