<?php
/**
 * CLI fixture for inc/redirects-404-log.php — the front-end 404 capture log (B2).
 * Pure data layer: junk filter, aggregating record, cap, delete, clear.
 * Run: php tests/redirects-404-log.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

require __DIR__ . '/../inc/redirects-store.php'; // shared path normalizer
require __DIR__ . '/../inc/redirects-404-log.php';

// ── junk filter (bot-probe suppression) ──
ok( sn_404_should_capture( '/missing-article/' ) === true, 'filter: a real-looking content path is captured' );
ok( sn_404_should_capture( '/' ) === false, 'filter: the site root is never logged' );
ok( sn_404_should_capture( '/wp-login.php' ) === false, 'filter: wp-login probe suppressed' );
ok( sn_404_should_capture( '/xmlrpc.php' ) === false, 'filter: xmlrpc probe suppressed' );
ok( sn_404_should_capture( '/.env' ) === false, 'filter: .env probe suppressed' );
ok( sn_404_should_capture( '/vendor/phpunit/eval-stdin.php' ) === false, 'filter: vendor/phpunit RCE probe suppressed' );
ok( sn_404_should_capture( '/backup.sql' ) === false, 'filter: .sql extension probe suppressed' );
ok( sn_404_should_capture( '/.htaccess' ) === false, 'filter: .htaccess probe suppressed' );
ok( sn_404_should_capture( '/.DS_Store' ) === false, 'filter: .DS_Store probe suppressed (case-insensitive)' );
ok( sn_404_should_capture( '/wp-json/wp/v2/users' ) === false, 'filter: /wp-json REST probe suppressed' );

// ── record + aggregate ──
ok( sn_404_log_record( '/missing/', 'https://google.com/' ) === true, 'record: captures a real 404' );
$log = sn_404_log_all();
ok( isset( $log['/missing'] ), 'record: key is the normalized path' );
ok( $log['/missing']['count'] === 1, 'record: first hit count is 1' );
ok( $log['/missing']['referer'] === 'https://google.com/', 'record: stores referer' );

sn_404_log_record( '/missing', 'https://bing.com/' );
$log = sn_404_log_all();
ok( count( $log ) === 1, 'record: repeat hit aggregates (no duplicate key)' );
ok( $log['/missing']['count'] === 2, 'record: repeat hit increments count' );
ok( $log['/missing']['referer'] === 'https://bing.com/', 'record: latest referer wins' );

ok( sn_404_log_record( '/wp-login.php' ) === false, 'record: junk path is not logged' );
ok( count( sn_404_log_all() ) === 1, 'record: junk path did not grow the log' );

// ── delete one ──
sn_404_log_record( '/other', '' );
ok( sn_404_log_delete( '/other/' ) === true, 'delete: removes a single entry (normalized match)' );
ok( ! isset( sn_404_log_all()['/other'] ), 'delete: entry gone' );
ok( sn_404_log_delete( '/never' ) === false, 'delete: missing entry returns false' );

// ── clear all ──
ok( sn_404_log_clear() === true, 'clear: succeeds' );
ok( sn_404_log_all() === array(), 'clear: log is empty' );

// ── cap ──
$GLOBALS['__opts'] = array();
for ( $i = 0; $i < SN_404_LOG_MAX + 10; $i++ ) {
	sn_404_log_record( '/miss' . $i, '' );
}
$log = sn_404_log_all();
ok( count( $log ) === SN_404_LOG_MAX, 'cap: log is bounded at SN_404_LOG_MAX' );
ok( ! isset( $log['/miss0'] ), 'cap: FIFO drops the oldest distinct path' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
