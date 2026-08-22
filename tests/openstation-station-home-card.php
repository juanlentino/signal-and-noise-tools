<?php
/**
 * Tests for inc/openstation-station-home-card.php — the S&N Analytics card on
 * OpenStation's Station Home.
 *
 * The card exists because v12.10.0 moved the Analytics screen off the WordPress
 * Dashboard menu (fixing its reachability inside the shell) and thereby took it
 * off the shell's launch surface. These pins hold the two properties that make
 * it safe: it must not care whether OpenStation is installed, and it must never
 * report a number it does not have.
 * Run: php tests/openstation-station-home-card.php
 * @since plugin v12.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function current_time( $f ) { return gmdate( $f, 1787000000 ); }

$GLOBALS['__sh_actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__sh_actions'][ $hook ][] = $cb; }

// The upstream seam. Absent by default — the plugin must survive that.
$GLOBALS['__sh_registered'] = array();
$GLOBALS['__sh_upstream']   = false;
function snt_os_register_station_home_card( $slug, $args = array() ) {
	if ( ! $GLOBALS['__sh_upstream'] ) { return null; }
	$GLOBALS['__sh_registered'][ $slug ] = $args;
	return true;
}

// Analytics seams.
$GLOBALS['__sh_configured'] = true;
$GLOBALS['__sh_deltas']     = array( 'views' => array( 'current' => 1234 ) );
function sn_analytics_config() { return $GLOBALS['__sh_configured']; }
function sn_analytics_period_deltas( $f, $t, $c ) { return $GLOBALS['__sh_deltas']; }
function snt_analytics_page_url( $args = array() ) { return 'https://example.test/wp-admin/admin.php?page=sn-analytics'; }

require_once __DIR__ . '/../inc/openstation-station-home-card.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Station Home card\n\nGroup: registration\n";
ok( isset( $GLOBALS['__sh_actions']['init'] ), 'registers on init — upstream\'s documented contract, after OpenStation has loaded' );

// OpenStation ABSENT is the default state and must be a no-op, not a fatal.
$GLOBALS['__sh_upstream'] = false;
snt_station_home_register_card();
ok( array() === $GLOBALS['__sh_registered'], 'OpenStation absent: registers nothing and does not fatal — this plugin must not care whether the shell is installed' );

$GLOBALS['__sh_upstream'] = true;
snt_station_home_register_card();
$card = $GLOBALS['__sh_registered'][ SNT_STATION_HOME_CARD_ID ] ?? array();
ok( array() !== $card, 'OpenStation present: the card registers' );
ok( false !== strpos( SNT_STATION_HOME_CARD_ID, 'signal-and-noise' ), 'the card id is namespaced, so it cannot collide with another plugin\'s card' );
ok( 'S&N Analytics' === ( $card['label'] ?? '' ), 'label matches the menu it opens' );
ok( true === ( $card['default_enabled'] ?? null ), 'starts ENABLED — the screen moved because its metrics were not at hand; the user can still opt out' );
ok( array( 'manage_options' ) === ( $card['capabilities'] ?? array() ), 'gated on manage_options' );
ok( is_callable( $card['callback'] ?? null ), 'carries a callable data callback' );

echo "\nGroup: the payload never invents a number\n";
$GLOBALS['__sh_configured'] = true;
$d = snt_station_home_analytics_card_data();
ok( is_array( $d ) && '1,234' === $d['value'], 'configured: reports the 7-day human views' );
ok( false !== strpos( (string) $d['url'], 'admin.php?page=sn-analytics' ), 'links through the accessor to the top-level menu, never a rebuilt index.php URL' );
ok( '' !== (string) ( $d['action_label'] ?? '' ), 'carries an action label' );

$GLOBALS['__sh_configured'] = false;
ok( null === snt_station_home_analytics_card_data(), 'UNCONFIGURED: returns null so the card is omitted — a card reading 0 on a site that never measured anything is a false statement, not an empty state' );

$GLOBALS['__sh_configured'] = true;
$GLOBALS['__sh_deltas']     = array();
ok( null === snt_station_home_analytics_card_data(), 'accessor returned no views: omitted rather than defaulted to zero' );
$GLOBALS['__sh_deltas'] = array( 'views' => array( 'current' => 0 ) );
ok( is_array( snt_station_home_analytics_card_data() ), 'but a MEASURED zero still renders — zero and unknown are different answers' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
