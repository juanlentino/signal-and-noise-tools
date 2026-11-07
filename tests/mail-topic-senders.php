<?php
/**
 * The contact form's topic addresses, mapped onto the Google connection
 * (inc/mail-topic-senders.php).
 *
 * Run: php tests/mail-topic-senders.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$GLOBALS['filters'] = array();
function add_filter( $hook, $cb ) { $GLOBALS['filters'][ $hook ] = $cb; }

require_once __DIR__ . '/../inc/mail-topic-senders.php';

$settings = array(
	'mappings'    => array( 'info@juanlentino.com' => 'abc' ),
	'connections' => array( 'abc' => array( 'provider_settings' => array( 'provider' => 'gmail', 'sender_email' => 'info@juanlentino.com' ) ) ),
	'misc'        => array( 'default_connection' => 'abc' ),
);

echo "the filter is on the option FluentSMTP reads\n";
ok( 'sn_mail_topic_mappings' === ( $GLOBALS['filters']['option_fluentmail-settings'] ?? '' ), 'hooked to option_fluentmail-settings' );

echo "every topic address the form sends from maps to the default connection\n";
$out = sn_mail_topic_mappings( $settings );
foreach ( array( 'research', 'press', 'speaking', 'music', 'consulting', 'role' ) as $topic ) {
	ok( 'abc' === ( $out['mappings'][ $topic . '@juanlentino.com' ] ?? '' ), $topic . '@ maps to the connection' );
}
ok( 'abc' === $out['mappings']['info@juanlentino.com'], 'the sender itself stays mapped' );
ok( $settings['connections'] === $out['connections'], 'connections are untouched' );
ok( $settings['misc'] === $out['misc'], 'misc is untouched' );

echo "a mapping the owner already has wins\n";
$own = $settings;
$own['mappings']['press@juanlentino.com'] = 'xyz';
$out = sn_mail_topic_mappings( $own );
ok( 'xyz' === $out['mappings']['press@juanlentino.com'], 'an existing mapping is kept' );
ok( 'abc' === $out['mappings']['music@juanlentino.com'], 'the others still map' );

echo "nothing to map onto, nothing changes\n";
$none = $settings;
unset( $none['misc']['default_connection'] );
ok( $none === sn_mail_topic_mappings( $none ), 'no default connection' );
$gone = $settings;
$gone['misc']['default_connection'] = 'deleted';
ok( $gone === sn_mail_topic_mappings( $gone ), 'a default connection that no longer exists' );
ok( false === sn_mail_topic_mappings( false ), 'FluentSMTP never set up: get_option() returned false' );

echo "$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
