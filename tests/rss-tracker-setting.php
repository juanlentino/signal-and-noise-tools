<?php
/**
 * Standalone test: sn_rss_tracker_settings is declared to core
 * (register_setting with a sanitize_callback), so every writer lands a
 * sanitized array: the RSS form, the Analytics collector save, the reset, and
 * anything that calls update_option() directly (WP-CLI, a migration).
 *
 * The hard rule pinned here: the callback never changes what today's writers
 * already store. Same option name, same keys, same values.
 *
 * Run: php tests/rss-tracker-setting.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__actions']  = array();
$GLOBALS['__settings'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['__settings'][ $name ] = array( 'group' => $group ) + $args; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) ) : ''; }
// Core's esc_url_raw() drops a URL whose scheme is not allowed; modeled for the two cases used.
function esc_url_raw( $u ) { return is_string( $u ) && preg_match( '~^https?://~i', trim( $u ) ) ? trim( $u ) : ''; }

const SN_RSS_TRACKER_SETTINGS_OPT = 'sn_rss_tracker_settings';
require __DIR__ . '/../inc/rss-feed-tracker-setting.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group 1: declared to core, on init\n";
ok( in_array( 'sn_rss_tracker_register_setting', $GLOBALS['__actions']['init'] ?? array(), true ), 'registered on init (admin_init never fires for WP-CLI or cron)' );
sn_rss_tracker_register_setting();
$reg = $GLOBALS['__settings']['sn_rss_tracker_settings'] ?? array();
ok( 'sn_rss_tracker_sanitize_settings' === ( $reg['sanitize_callback'] ?? '' ), 'the option name is unchanged and carries the sanitize_callback' );
ok( 'sn_private' === ( $reg['group'] ?? '' ) && false === ( $reg['show_in_rest'] ?? null ), 'private group (no options.php form posts it), not in REST' );
ok( ! array_key_exists( 'default', $reg ), 'no default: readers merge sn_rss_tracker_defaults() themselves' );

echo "\nGroup 2: what today's writers store passes through byte for byte\n";
$form = array( 'enabled' => true, 'collector_url' => 'https://sn-px.workers.dev/_sn/px', 'event_name' => 'RSS Feed Request', 'log_retention_days' => 90 );
ok( $form === sn_rss_tracker_sanitize_settings( $form ), 'the RSS form save (four keys, already clean)' );
$off = array( 'enabled' => false, 'collector_url' => 'https://example.test/_sn/px', 'event_name' => 'Feed', 'log_retention_days' => 7 );
ok( $off === sn_rss_tracker_sanitize_settings( $off ), 'enabled false and the 7-day floor' );
$reset = array( 'collector_url' => 'https://sn-px.workers.dev/_sn/px' );
ok( $reset === sn_rss_tracker_sanitize_settings( $reset ), 'the reset write (collector_url only): no key is added' );
ok( $form === sn_rss_tracker_sanitize_settings( sn_rss_tracker_sanitize_settings( $form ) ), 'idempotent: update_option() may run it on an already sanitized value' );
$extra = $form + array( 'future_key' => array( 'x' => 1 ) );
ok( $extra === sn_rss_tracker_sanitize_settings( $extra ), 'not a whitelist: an unknown key passes through as is' );

echo "\nGroup 3: a writer that skipped the form is held to the same floor\n";
$dirty = sn_rss_tracker_sanitize_settings( array( 'enabled' => '1', 'collector_url' => 'javascript:alert(1)', 'event_name' => "<b>Feed</b>\n hit ", 'log_retention_days' => '99999' ) );
ok( true === $dirty['enabled'], 'enabled is a boolean' );
ok( '' === $dirty['collector_url'], 'a collector URL core will not store is emptied, as the form does' );
ok( 'Feed hit' === $dirty['event_name'], 'event_name is plain text' );
ok( 365 === $dirty['log_retention_days'], 'retention is clamped to 365' );
ok( 7 === sn_rss_tracker_sanitize_settings( array( 'log_retention_days' => 0 ) )['log_retention_days'], '...and floored at 7' );
ok( array() === sn_rss_tracker_sanitize_settings( 'not-an-array' ), 'a non-array becomes an empty array, which readers treat as "all defaults"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
