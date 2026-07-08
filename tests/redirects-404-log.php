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

// ── v9.1.2: broadened junk filter ──
// CMS-location / backup / admin single-segment scanner guesses (exact match).
foreach ( array( '/wp', '/wordpress', '/backup', '/old', '/new', '/admin', '/administrator', '/login', '/phpmyadmin', '/db', '/config', '/setup', '/staging', '/test' ) as $guess ) {
	ok( sn_404_should_capture( $guess ) === false, "filter: scanner guess $guess suppressed" );
}
// Config-file probe whose extension the ext-strip didn't previously cover.
ok( sn_404_should_capture( '/web.config' ) === false, 'filter: /web.config (.config ext) suppressed' );
// Author-archive username enumeration.
ok( sn_404_should_capture( '/author/juanlentino' ) === false, 'filter: /author/<name> enumeration suppressed' );
ok( sn_404_should_capture( '/author/' ) === false, 'filter: /author/ prefix suppressed' );
// Browser/OS auto-requested assets: a missing one is not a human broken link.
ok( sn_404_should_capture( '/apple-touch-icon.png' ) === false, 'filter: apple-touch-icon auto-request suppressed' );
ok( sn_404_should_capture( '/apple-touch-icon-precomposed.png' ) === false, 'filter: apple-touch-icon-precomposed suppressed' );
ok( sn_404_should_capture( '/apple-touch-icon-120x120.png' ) === false, 'filter: sized apple-touch-icon suppressed' );
ok( sn_404_should_capture( '/favicon.ico' ) === false, 'filter: favicon.ico auto-request suppressed' );
ok( sn_404_should_capture( '/browserconfig.xml' ) === false, 'filter: browserconfig.xml auto-request suppressed' );

// ── CRITICAL false-positive guards: exact-match, NEVER substring ──
ok( sn_404_should_capture( '/news' ) === true, 'guard: /news is NOT suppressed by the /new guess (exact match)' );
ok( sn_404_should_capture( '/renew' ) === true, 'guard: /renew is NOT suppressed by /new' );
ok( sn_404_should_capture( '/older-posts' ) === true, 'guard: /older-posts is NOT suppressed by /old' );
ok( sn_404_should_capture( '/gold' ) === true, 'guard: /gold is NOT suppressed (no .old extension, not the /old guess)' );
ok( sn_404_should_capture( '/about-us' ) === true, 'guard: /about-us (the real broken link) is captured' );
ok( sn_404_should_capture( '/clients' ) === true, 'guard: /clients (content-shaped) is captured' );
ok( sn_404_should_capture( '/notes/deleted-hero.png' ) === true, 'guard: a real missing content image is still captured (only named auto-assets filtered)' );

// ── sn_404_log_actionable(): retroactively hides junk already in the store ──
$GLOBALS['__opts'] = array();
$__n = time();
$GLOBALS['__opts'][ SN_404_LOG_OPT ] = array(
	'/about-us'             => array( 'count' => 1, 'first_seen' => $__n, 'last_seen' => $__n, 'referer' => '' ),
	'/wp'                   => array( 'count' => 3, 'first_seen' => $__n, 'last_seen' => $__n, 'referer' => '' ),
	'/apple-touch-icon.png' => array( 'count' => 5, 'first_seen' => $__n, 'last_seen' => $__n, 'referer' => '' ),
	'/author/juanlentino'   => array( 'count' => 1, 'first_seen' => $__n, 'last_seen' => $__n, 'referer' => '' ),
);
$act = sn_404_log_actionable();
ok( isset( $act['/about-us'] ), 'actionable: keeps a real broken link' );
ok( ! isset( $act['/wp'], $act['/apple-touch-icon.png'], $act['/author/juanlentino'] ), 'actionable: hides junk already in the store (retroactive)' );
ok( count( $act ) === 1, 'actionable: only the real path survives' );
ok( count( sn_404_log_all() ) === 4, 'actionable: does NOT mutate the raw store on read' );

// ── self-prune: a new real record drops stale junk from the store on write ──
sn_404_log_record( '/genuinely-missing/', '' );
$raw = sn_404_log_all();
ok( isset( $raw['/about-us'], $raw['/genuinely-missing'] ), 'self-prune: real entries survive the write' );
ok( ! isset( $raw['/wp'], $raw['/apple-touch-icon.png'], $raw['/author/juanlentino'] ), 'self-prune: stale junk pruned on the next record write' );
$GLOBALS['__opts'] = array();

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
