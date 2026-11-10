<?php
/**
 * The RSS tracker's three writes are admin-post.php handlers (2026-11-10,
 * core-first audit index 28): Save settings, Purge now and Reset to Defaults
 * each run on their own `admin_post_sn_rss_<op>` hook, under
 * check_admin_referer( 'sn_rss_<op>' ) and manage_options, the shape of every
 * other SN admin write since #1614. Before, one admin_init handler read
 * `sn_rss_action`, checked one shared nonce by hand and returned silently on a
 * stale one, so the click did nothing and said nothing.
 *
 * Pinned here: the hooks; a bad or borrowed nonce dies with core's refusal and
 * writes nothing; a missing capability dies with 403; the stored option name,
 * keys and values are what the old handler stored; and every outcome lands on
 * the same URL with the same ?sn_rss_ok flash (purged-N included).
 *
 * Run: php tests/rss-tracker-admin-post.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
ini_set( 'error_log', '/dev/null' ); // the save/purge error branches log by design.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__actions'] = array();
function add_action( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; }
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function do_action( $h, ...$args ) { foreach ( $GLOBALS['__actions'][ $h ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); } }
$GLOBALS['__can'] = true;
function current_user_can( $c ) { return 'manage_options' === $c && $GLOBALS['__can']; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $v ) { return $v; }
function esc_url_raw( $u ) { return trim( (string) $u ); }
function esc_html__( $s, $d = null ) { return $s; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v ) {
	if ( array_key_exists( $k, $GLOBALS['__options'] ) && $GLOBALS['__options'][ $k ] === $v ) { return false; } // core: unchanged
	if ( '' !== $GLOBALS['wpdb']->last_error ) { return false; }
	$GLOBALS['__options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }

class SNT_Test_RSS_Post_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $result     = 0;
	public $queries    = array();
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function query( $q ) { $this->queries[] = $q; return $this->result; }
}
$GLOBALS['wpdb'] = new SNT_Test_RSS_Post_Wpdb();

// Core's shapes, as values: a redirect or a die ends the handler.
class SNT_Test_Redirect extends Exception {}
class SNT_Test_Die extends Exception { public $response = 0; }
function wp_safe_redirect( $url ) { throw new SNT_Test_Redirect( $url ); }
function wp_die( $message = '', $title = '', $args = array() ) {
	$e           = new SNT_Test_Die( (string) $message );
	$e->response = (int) ( $args['response'] ?? 0 );
	throw $e;
}
// One nonce per action, minted as 'nonce-<action>'; core's refusal on a miss.
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( ( $_REQUEST[ $query_arg ] ?? '' ) !== 'nonce-' . $action ) {
		wp_die( 'The link you followed has expired.', 'Something went wrong.', array( 'response' => 403 ) );
	}
	return 1;
}

require __DIR__ . '/../inc/rss-feed-tracker.php';

/** Submit one form to admin-post.php: what core's admin-post.php does with it. */
function rss_post( $hook_action, array $fields, $nonce_for ) {
	$_POST    = $fields + array( 'action' => $hook_action, '_wpnonce' => 'nonce-' . $nonce_for );
	$_REQUEST = $_POST;
	$none     = array( 'url' => '', 'flash' => null, 'message' => '', 'response' => 0 );
	try {
		do_action( 'admin_post_' . $hook_action );
		return array( 'outcome' => 'returned' ) + $none;
	} catch ( SNT_Test_Redirect $r ) {
		parse_str( (string) parse_url( $r->getMessage(), PHP_URL_QUERY ), $q );
		return array( 'outcome' => 'redirect', 'url' => $r->getMessage(), 'flash' => $q['sn_rss_ok'] ?? null ) + $none;
	} catch ( SNT_Test_Die $d ) {
		return array( 'outcome' => 'died', 'message' => $d->getMessage(), 'response' => $d->response ) + $none;
	}
}

$stored = array( 'enabled' => false, 'collector_url' => 'https://sn-px.workers.dev/_sn/px', 'event_name' => 'RSS Feed Request', 'log_retention_days' => 90 );

echo "Group 1: three admin-post hooks, no admin_init handler\n";
foreach ( array( 'save_settings', 'purge_log', 'reset_defaults' ) as $op ) {
	ok( ! empty( $GLOBALS['__actions'][ 'admin_post_sn_rss_' . $op ] ), "admin_post_sn_rss_$op is hooked (registered at load: a window replays inside a REST request and asks has_action() first)" );
}
ok( empty( $GLOBALS['__actions']['admin_init'] ), 'nothing reads the RSS forms on admin_init any more' );
ok( ! defined( 'SN_RSS_TRACKER_NONCE' ), 'the shared nonce is gone: each action mints its own' );

echo "\nGroup 2: the nonce is core's check, and it is per action\n";
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ] = $stored;
$r = rss_post( 'sn_rss_save_settings', array( 'enabled' => '1', 'event_name' => 'X', 'log_retention_days' => '30', '_wpnonce' => 'stale' ), 'unused' );
ok( 'died' === $r['outcome'] && false !== strpos( (string) $r['message'], 'link you followed has expired' ) && 403 === $r['response'], 'a stale nonce shows core\'s standard refusal (it used to return silently, so the save vanished)' );
ok( $stored === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ], '   ...and writes nothing' );
$GLOBALS['wpdb']->queries = array();
$r = rss_post( 'sn_rss_purge_log', array( 'purge_days' => '30' ), 'sn_rss_save_settings' );
ok( 'died' === $r['outcome'] && array() === $GLOBALS['wpdb']->queries, 'the Save form\'s nonce does not authorize Purge: no DELETE runs' );
$r = rss_post( 'sn_rss_reset_defaults', array(), 'sn_rss_purge_log' );
ok( 'died' === $r['outcome'] && $stored === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ], 'the Purge form\'s nonce does not authorize Reset: the settings stand' );

echo "\nGroup 3: manage_options, after the nonce\n";
$GLOBALS['__can'] = false;
foreach ( array( 'save_settings', 'purge_log', 'reset_defaults' ) as $op ) {
	$GLOBALS['wpdb']->queries = array();
	$r = rss_post( 'sn_rss_' . $op, array( 'enabled' => '1', 'purge_days' => '30' ), 'sn_rss_' . $op );
	ok( 'died' === $r['outcome'] && 403 === $r['response'] && $stored === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ] && array() === $GLOBALS['wpdb']->queries, "$op without manage_options dies 403 and touches nothing" );
}
$GLOBALS['__can'] = true;

echo "\nGroup 4: Save stores what it always stored, and says so\n";
$r = rss_post( 'sn_rss_save_settings', array( 'enabled' => '1', 'event_name' => ' Feed hit ', 'log_retention_days' => '9999' ), 'sn_rss_save_settings' );
ok( array( 'enabled' => true, 'collector_url' => 'https://sn-px.workers.dev/_sn/px', 'event_name' => 'Feed hit', 'log_retention_days' => 365 ) === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ], 'same option, same four keys: enabled a boolean, the STORED collector kept, the event name cleaned, retention clamped to 365' );
ok( 'redirect' === $r['outcome'] && 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=rss&sn_rss_ok=saved' === $r['url'], 'redirects to the RSS leaf with ?sn_rss_ok=saved (the URL the classic page always landed on): ' . ( $r['url'] ?? '' ) );
$r = rss_post( 'sn_rss_save_settings', array( 'enabled' => '1', 'event_name' => 'Feed hit', 'log_retention_days' => '365' ), 'sn_rss_save_settings' );
ok( 'unchanged' === $r['flash'], 'the same values again report unchanged, not saved' );
$GLOBALS['wpdb']->last_error = 'disk full';
$r = rss_post( 'sn_rss_save_settings', array( 'event_name' => 'Other' ), 'sn_rss_save_settings' );
ok( 'save-error' === $r['flash'], 'a database failure reports save-error, never saved' );
$GLOBALS['wpdb']->last_error = '';
$r = rss_post( 'sn_rss_save_settings', array( 'log_retention_days' => '1' ), 'sn_rss_save_settings' );
ok( false === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['enabled'] && 7 === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ]['log_retention_days'] && 'saved' === $r['flash'], 'an unchecked box stores enabled false, and retention is floored at 7' );

echo "\nGroup 5: Purge now\n";
$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->result  = 12;
$r = rss_post( 'sn_rss_purge_log', array( 'purge_days' => '3' ), 'sn_rss_purge_log' );
ok( 1 === count( $GLOBALS['wpdb']->queries ) && array( 7 ) === $GLOBALS['wpdb']->queries[0][1] && false !== strpos( $GLOBALS['wpdb']->queries[0][0], 'DELETE FROM wp_rss_feed_log' ), 'one DELETE on the log table, the day count clamped to 7' );
ok( 'purged-12' === $r['flash'] && false !== strpos( $r['url'], 'page=sn-theme-options&tab=rss&sn_rss_ok=purged-12' ), 'the flash carries the count: ?sn_rss_ok=purged-12' );
$GLOBALS['wpdb']->result = false;
$r = rss_post( 'sn_rss_purge_log', array( 'purge_days' => '30' ), 'sn_rss_purge_log' );
ok( 'purge-error' === $r['flash'], 'a failed DELETE reports purge-error, never "Purged 0"' );

echo "\nGroup 6: Reset to Defaults keeps the collector\n";
$GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ] = $stored;
$r = rss_post( 'sn_rss_reset_defaults', array(), 'sn_rss_reset_defaults' );
ok( array( 'collector_url' => 'https://sn-px.workers.dev/_sn/px' ) === $GLOBALS['__options'][ SN_RSS_TRACKER_SETTINGS_OPT ], 'the RSS settings go back to defaults and the custom collector stays' );
ok( 'reset' === $r['flash'] && false !== strpos( $r['url'], 'tab=rss&sn_rss_ok=reset' ), 'and lands with ?sn_rss_ok=reset' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
