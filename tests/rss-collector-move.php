<?php
/**
 * Handler tests for the v10.46.0 collector-endpoint move
 * (Content → RSS  ⇒  Measurement → Analytics).
 *
 * The move has two halves and BOTH have to hold, or the setting silently
 * resets itself:
 *
 *   1. The new writer (sn_handle_analytics_collector_save) must MERGE into the
 *      RSS tracker's settings option, not replace it — the collector shares
 *      that option with enabled / event_name / log_retention_days.
 *
 *   2. The old form's save branch must stop resetting the key it no longer
 *      posts. It rebuilds the whole array from $_POST, so its `?? $defaults`
 *      fallback would rewrite a customised collector back to the site default
 *      on every RSS save. Half 2 is the half that is easy to forget, and it
 *      fails in a way nobody notices until the beacons stop arriving.
 *
 * Run: php tests/rss-collector-move.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['__options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['__options'][ $n ] ); return true; }
function get_bloginfo( $w ) { return ''; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function esc_url_raw( $u ) { return is_string( $u ) ? trim( $u ) : ''; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}
function sn_setting_update( $path, $value ) { $GLOBALS['__settings'][ $path ] = $value; return true; }

// The two pieces of the RSS tracker this move touches, reproduced at their real
// contract (the module itself pulls in the DB layer + cron wiring).
const SN_RSS_TRACKER_SETTINGS_OPT = 'sn_rss_tracker_settings';
function sn_rss_tracker_defaults() {
	return array(
		'enabled'            => true,
		'collector_url'      => home_url( '/_sn/px' ),
		'event_name'         => 'RSS Feed Request',
		'log_retention_days' => 90,
	);
}
function sn_rss_tracker_settings() {
	$stored = get_option( SN_RSS_TRACKER_SETTINGS_OPT, array() );
	return wp_parse_args( is_array( $stored ) ? $stored : array(), sn_rss_tracker_defaults() );
}

require __DIR__ . '/../inc/admin-post-actions.php';
require __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: dispatch map\n";
$map = sn_admin_post_handlers();
ok( isset( $map['analytics_collector_save'] ) && 'sn_handle_analytics_collector_save' === $map['analytics_collector_save'],
	'analytics_collector_save routed to its handler' );

echo "\nGroup: the new writer MERGES, never replaces\n";
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ] = array(
	'enabled'            => true,
	'collector_url'      => 'https://example.test/_sn/px',
	'event_name'         => 'RSS Feed Request',
	'log_retention_days' => 180,
);
$flash = sn_handle_analytics_collector_save( array( 'sn_an_collector_url' => 'https://sn-px.workers.dev/_sn/px' ) );
$after = sn_rss_tracker_settings();
ok( 'analytics_collector_saved' === $flash, 'a real change reports analytics_collector_saved' );
ok( 'https://sn-px.workers.dev/_sn/px' === $after['collector_url'], 'the collector URL is written' );
ok( 'RSS Feed Request' === $after['event_name'], 'event_name survives the collector save' );
ok( 180 === $after['log_retention_days'], 'log_retention_days survives the collector save (the merge, not a replace)' );
ok( true === $after['enabled'], 'enabled survives the collector save' );

echo "\nGroup: validation — a bad URL keeps the old endpoint\n";
foreach ( array(
	''                          => 'an empty URL',
	'not-a-url'                 => 'a bare string with no scheme',
	'javascript:alert(1)'       => 'a javascript: scheme',
	'ftp://example.test/_sn/px' => 'a non-http(s) scheme',
	'https://'                  => 'a scheme with no host',
) as $bad => $desc ) {
	$before = sn_rss_tracker_settings();
	$flash  = sn_handle_analytics_collector_save( array( 'sn_an_collector_url' => $bad ) );
	$now    = sn_rss_tracker_settings();
	ok( 'analytics_collector_invalid' === $flash && $before['collector_url'] === $now['collector_url'],
		"$desc is refused and the stored endpoint is untouched" );
}

echo "\nGroup: unchanged detection\n";
$flash = sn_handle_analytics_collector_save( array( 'sn_an_collector_url' => 'https://sn-px.workers.dev/_sn/px' ) );
ok( 'analytics_collector_unchanged' === $flash, 'saving the same URL reports unchanged, not saved' );

echo "\nGroup: THE REGRESSION — an RSS save must not reset the collector\n";
// Reproduce the RSS form's save branch exactly as inc/rss-feed-tracker.php runs
// it, with the POST the (now collector-less) form actually sends.
$posted = array( 'enabled' => '1', 'event_name' => 'RSS Feed Request', 'log_retention_days' => '180' );
$defaults = sn_rss_tracker_defaults();
$current  = sn_rss_tracker_settings();
$new      = array(
	'enabled'            => ! empty( $posted['enabled'] ),
	'collector_url'      => esc_url_raw( wp_unslash( $posted['collector_url'] ?? ( $current['collector_url'] ?? $defaults['collector_url'] ) ) ),
	'event_name'         => sanitize_text_field( wp_unslash( $posted['event_name'] ?? $defaults['event_name'] ) ),
	'log_retention_days' => max( 7, min( 365, (int) wp_unslash( $posted['log_retention_days'] ?? $defaults['log_retention_days'] ) ) ),
);
update_option( SN_RSS_TRACKER_SETTINGS_OPT, $new );
$after = sn_rss_tracker_settings();
ok( 'https://sn-px.workers.dev/_sn/px' === $after['collector_url'],
	'an RSS save with no collector field KEEPS the custom endpoint (a $defaults fallback here would silently reset it to the site default)' );
ok( home_url( '/_sn/px' ) !== $after['collector_url'],
	'…and specifically has not snapped back to home_url(/_sn/px)' );

// Pin that the shipped source really uses the stored-value fallback, so this
// cannot regress by someone "simplifying" the expression back to $defaults.
$src = (string) file_get_contents( __DIR__ . '/../inc/rss-feed-tracker.php' );
ok( false !== strpos( $src, "\$current['collector_url'] ?? \$defaults['collector_url']" ),
	'inc/rss-feed-tracker.php falls back to the STORED collector_url, not the default' );
ok( false === strpos( $src, "name=\"collector_url\"" ),
	'the RSS form no longer renders a collector_url input (single write surface)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
