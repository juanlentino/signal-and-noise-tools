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

// ── v9.81.0: deterministic redirect-target suggestion (classical distance) ──
echo "\n";
$cands = array( '/notes/design-tokens', '/notes/pillar-essays', '/about', '/uses' );
ok( '/notes/design-tokens' === sn_404_suggest_target( '/notes/desing-tokens', $cands ),
	'suggest: a transposed slug resolves to the published slug (levenshtein rank)' );
ok( '/about' === sn_404_suggest_target( '/abuot', $cands ), 'suggest: a short typo\'d path resolves' );
ok( '' === sn_404_suggest_target( '/totally-unrelated-zzz', $cands ),
	'suggest: nothing clears the similarity floor -> empty (an empty box beats a wrong guess)' );
ok( '' === sn_404_suggest_target( '/notes/design-tokens', $cands ),
	'suggest: a path identical to a candidate suggests nothing (it would 404 the same)' );
ok( '' === sn_404_suggest_target( '/', $cands ), 'suggest: the root path suggests nothing' );
ok( '' === sn_404_suggest_target( '/x', array() ), 'suggest: an empty candidate set suggests nothing' );

// ── v9.81.0: the readonly get-404-log ability ──
$GLOBALS['__abilities'] = array();
$GLOBALS['__actions']   = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
function add_action( $tag, $cb = null ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function get_posts( $args = array() ) { return array(); } // ability path: suggestion candidates come from here.
function get_permalink( $id ) { return ''; }

// Re-include registers the hook now that add_action exists (the first include
// ran before the stub, so the registrar call was skipped as undefined).
// Registration itself is exercised directly:
snt_abilities_404_log_register();
$ab = $GLOBALS['__abilities']['signal-noise/get-404-log'] ?? null;
ok( is_array( $ab ), 'ability: signal-noise/get-404-log registered (no bare REST route)' );
ok( array( 'object', 'null' ) === ( $ab['input_schema']['type'] ?? null ),
	'ability: input schema types the [object,null] union (bodyless GET law)' );
ok( true === ( $ab['meta']['annotations']['readonly'] ?? null ), 'ability: annotated readonly' );
ok( 'snt_ability_perm_manage_options' === ( $ab['permission_callback'] ?? '' ), 'ability: manage_options-gated' );

$GLOBALS['__opts'] = array();
sn_404_log_record( '/first-broken', 'https://ref.example/a' );
sn_404_log_record( '/second-broken', '' );
sn_404_log_record( '/second-broken', '' );
$res = snt_ability_get_404_log( null );
ok( 2 === ( $res['total'] ?? 0 ), 'ability: total counts the actionable log' );
ok( 2 === count( $res['entries'] ?? array() ), 'ability: entries returned' );
$paths = array_map( static function ( $r ) { return $r['path']; }, $res['entries'] );
ok( in_array( '/first-broken', $paths, true ) && in_array( '/second-broken', $paths, true ), 'ability: rows carry the broken paths' );
$row0 = $res['entries'][0];
ok( isset( $row0['count'], $row0['first_seen'], $row0['last_seen'], $row0['referer'], $row0['suggested'] ),
	'ability: each row carries count/first_seen/last_seen/referer/suggested' );

// Cap: never more than SN_404_LOG_ABILITY_MAX entries.
$GLOBALS['__opts'] = array();
for ( $i = 0; $i < SN_404_LOG_ABILITY_MAX + 20; $i++ ) { sn_404_log_record( '/cap-miss-' . $i, '' ); }
$res = snt_ability_get_404_log( null );
ok( SN_404_LOG_ABILITY_MAX === count( $res['entries'] ), 'ability: entries capped at SN_404_LOG_ABILITY_MAX' );
ok( $res['total'] >= SN_404_LOG_ABILITY_MAX, 'ability: total still reports the full actionable size' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
