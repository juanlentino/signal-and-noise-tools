<?php
/**
 * Share echoes (owner decision 2026-11-14): the worker's rows validated, the
 * join by page and time (40 minutes yes, 80 hours no, the later of two
 * echoes, /r/ codes match only their own code), the Shares list, the durable
 * fold and the honest wording.
 * Run: php tests/share-echoes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }

require __DIR__ . '/../inc/share-echoes.php';
require_once __DIR__ . '/../inc/link-codes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$t0  = strtotime( '2026-11-14T11:54:00Z' );
$iso = static fn( $ts ) => gmdate( 'Y-m-d\TH:i:s\Z', $ts );
$raw = static fn( $family, $path, $ts, $verified = '1' ) => array( 'family' => $family, 'path' => $path, 'observed_at' => gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ), 'verified' => $verified, 'hits' => 1 );
$ses = static fn( $entry, $ts, array $o = array() ) => $o + array( 'start' => ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'Y-m-d\TH:i:sP' ), 'entry' => $entry, 'link_code' => '', 'pages' => array( array( 'path' => $entry, 'time_ms' => 0, 'scroll_pct' => null, 'read' => false ) ) );

echo "Group: the worker's rows, untrusted\n";
$rows = sn_share_echoes_rows( array(
	$raw( 'whatsapp', '/notes/x/', $t0 ),
	$raw( 'whatsapp', '/notes/x/', $t0 + 20 ), // the same app fetching again: one share
	array( 'family' => 'openai', 'path' => '/notes/x/', 'observed_at' => $iso( $t0 ) ),
	array( 'family' => 'slack', 'path' => '/a"><script>', 'observed_at' => $iso( $t0 ) ),
	array( 'family' => 'slack', 'path' => '/b/', 'observed_at' => 'yesterday-ish' . "\x00" ),
	array( 'surface' => 'html', 'family' => 'meta-ai', 'day' => '2026-11-14', 'hits' => 9 ), // an older worker's aggregate row
) );
ok( 1 === count( $rows ) && 'whatsapp' === $rows[0]['family'], 'only well-formed echoes on the fixed list survive, repeat fetches within a minute collapse: ' . count( $rows ) );
ok( '/notes/x/' === sn_share_echo_key( '/notes/x' ) && 'r:jill' === sn_share_echo_key( '/r/jill' ) && 'r:jill' === sn_share_echo_key( '/r/jill/' ) && '' === sn_share_echo_key( '(unmatched)' ), 'page keys: a trailing slash, /r/x and /r/x/ alike, no key for an unmatched page' );

$gate = sn_share_echoes_rows( array( $raw( 'discord', '/notes/x/', $t0, '0' ), $raw( 'linkedin', '/notes/x/', $t0 + 100, '1' ), $raw( 'apple-messages', '/notes/y/', $t0, '0' ), $raw( 'whatsapp', '/notes/z/', $t0, '0' ) ) );
$fams = array_column( $gate, 'family' );
sort( $fams );
ok( array( 'apple-messages', 'linkedin', 'whatsapp' ) === $fams, 'an unverified Discordbot is not a share; a verified LinkedInBot is; WhatsApp and Apple Messages (built on the sender\'s phone) need no verification' );
$meta = sn_share_echoes_rows( array( $raw( 'meta', '/notes/x/', $t0 ), $raw( 'meta', '/r/jill', $t0 ) ) );
ok( array( 'r:jill' ) === array_column( $meta, 'key' ), 'a Meta fetch is a share only on an /r/ link (it crawls notes on its own schedule)' );

echo "\nGroup: the join, by page and time only\n";
$echoes = sn_share_echoes_rows( array( $raw( 'whatsapp', '/notes/x/', $t0 ) ) );
$a      = sn_share_echo_annotate( array( $ses( '/notes/x/', $t0 + 40 * 60, array( 'pages' => array( array( 'path' => '/notes/x/', 'time_ms' => 30000, 'scroll_pct' => null, 'read' => true ) ) ) ) ), $echoes );
ok( is_array( $a[0]['share'] ) && 'WhatsApp' === $a[0]['share']['app'] && 2400 === $a[0]['share']['gap_s'] && true === $a[0]['share']['read'], 'a visit 40 minutes after an echo on the same page is annotated' );
ok( 'after a share via WhatsApp, 40 min, read' === sn_share_echo_text( $a[0]['share'] ), 'it reads: "' . sn_share_echo_text( $a[0]['share'] ) . '"' );
$late = sn_share_echo_annotate( array( $ses( '/notes/x/', $t0 + 80 * 3600 ) ), $echoes );
ok( null === $late[0]['share'], 'a visit 80 hours after is not' );
$before = sn_share_echo_annotate( array( $ses( '/notes/x/', $t0 - 60 ) ), $echoes );
ok( null === $before[0]['share'], 'a visit before the echo is not' );
$other = sn_share_echo_annotate( array( $ses( '/notes/y/', $t0 + 60 ) ), $echoes );
ok( null === $other[0]['share'], 'a visit to another page is not' );
ok( array() === sn_share_echoes_rows( array( array( 'family' => 'whatsapp', 'path' => '/r/x', 'observed_at' => '' ), array( 'family' => 'whatsapp', 'path' => '/r/y', 'observed_at' => 'now' ), array( 'family' => 'whatsapp', 'path' => '/r/z', 'observed_at' => 'tomorrow' ) ) ), 'a time that is not the worker\'s ISO stamp is no share, never "now"' );
$two = sn_share_echoes_rows( array( $raw( 'slack', '/notes/x/', $t0 ), $raw( 'linkedin', '/notes/x/', $t0 + 3600 ) ) );
$b   = sn_share_echo_annotate( array( $ses( '/notes/x/', $t0 + 2 * 3600 ) ), $two );
ok( 'LinkedIn' === $b[0]['share']['app'] && 3600 === $b[0]['share']['gap_s'], 'two echoes before one visit: the later one' );
$codes = sn_share_echoes_rows( array( $raw( 'meta', '/r/jill', $t0 ), $raw( 'meta', '/r/jon/', $t0 + 120 ) ) ); // two sends, two minutes apart
$c     = sn_share_echo_annotate( array( $ses( '/resume/', $t0 + 600, array( 'link_code' => 'jill' ) ), $ses( '/resume/', $t0 + 900, array( 'link_code' => 'rylo' ) ) ), $codes );
ok( 'Meta' === $c[0]['share']['app'] && null === $c[1]['share'], 'an /r/ echo matches only the same code' );

echo "\nGroup: the Shares list\n";
$list = sn_share_echoes_list( sn_share_echoes_rows( array( $raw( 'whatsapp', '/notes/x/', $t0 ), $raw( 'apple-messages', '/r/jill', $t0 + 3600 ) ) ), array( $ses( '/notes/x/', $t0 + 40 * 60 ) ) );
ok( 2 === count( $list ) && 'Apple Messages' === $list[0]['app'] && array() === $list[0]['visits'], 'newest first; a share no one opened yet is listed with no visits' );
ok( 1 === count( $list[1]['visits'] ) && 2400 === $list[1]['visits'][0]['gap_s'] && false === $list[1]['visits'][0]['read'], 'what followed: the visit, its gap and whether it was a read' );
ok( '40 min, not read' === sn_share_echo_follow_text( 2400, false ) && '3 h, read' === sn_share_echo_follow_text( 3 * 3600, true ) && 'under a minute, read' === sn_share_echo_follow_text( 20, true ), 'gaps read in minutes, then hours' );

echo "\nGroup: durable counts\n";
$f = sn_share_echoes_fold( sn_share_echoes_rows( array( $raw( 'whatsapp', '/notes/x/', $t0 ), $raw( 'slack', '/notes/x/', $t0 + 7200 ), $raw( 'slack', '/notes/x/', $t0 + 9000 ) ) ), array( '2026-11-13', '2026-11-14' ), 'America/New_York' );
ok( 1 === $f['2026-11-14']['whatsapp']['/notes/x/'] && 2 === $f['2026-11-14']['slack']['/notes/x/'] && array() === $f['2026-11-13'], 'echoes per site day, app and page; a covered day without any is measured with none' );
$fr = sn_share_echoes_fold( sn_share_echoes_rows( array( $raw( 'apple-messages', '/r/jill', $t0 ), $raw( 'apple-messages', '/r/jill/', $t0 + 7200 ) ) ), array( '2026-11-14' ), 'America/New_York' );
ok( array( 'r:jill' => 2 ) === $fr['2026-11-14']['apple-messages'], 'a coded link counts as one page with or without its trailing slash' );

echo "\nGroup: honest wording\n";
$copy = file_get_contents( __DIR__ . '/../inc/analytics-view-shares.php' ) . file_get_contents( __DIR__ . '/../inc/share-echoes.php' ) . file_get_contents( __DIR__ . '/../inc/abilities-analytics-sessions.php' );
ok( false === stripos( $copy, 'from your share' ) && false !== strpos( $copy, 'after a share' ), 'the copy says "after a share", never "from your share"' );
$privacy = file_get_contents( __DIR__ . '/../inc/privacy-exporters.php' );
ok( false !== strpos( $privacy, 'The site notes when link-preview services fetch a page, and keeps the time, page and service only.' ), 'the privacy policy text carries the sentence' );

echo "\nGroup: a link typed into the app is one share (2026-11-14 owner test)\n";
$at = static fn( $sec, $ms = 0 ) => gmdate( 'Y-m-d\TH:i:s', $sec ) . sprintf( '.%03dZ', $ms );
$tg = array();
foreach ( array( array( '/', 4, 0 ), array( '/notes/', 4, 10 ), array( '/r/te', 9, 0 ), array( '/r/tel', 9, 100 ), array( '/r/telegra', 10, 0 ), array( '/r/tes', 13, 0 ), array( '/r/prue', 16, 100 ), array( '/r/prueb', 16, 200 ), array( '/r/prueba', 16, 300 ) ) as $x ) {
	$tg[] = array( 'family' => 'telegram', 'path' => $x[0], 'observed_at' => $at( $t0 + $x[1], $x[2] ), 'verified' => '0' );
}
$wa = array(
	array( 'family' => 'whatsapp', 'path' => '/', 'observed_at' => $at( $t0 + 86, 0 ), 'verified' => '0' ),
	array( 'family' => 'whatsapp', 'path' => '/r/pru', 'observed_at' => $at( $t0 + 90, 0 ), 'verified' => '0' ),
	array( 'family' => 'whatsapp', 'path' => '/r/prueba', 'observed_at' => $at( $t0 + 91, 0 ), 'verified' => '0' ),
);
$typed = sn_share_echoes_rows( array_merge( array_reverse( $tg ), $wa ) ); // the worker answers newest first
ok( array( 'telegram /r/prueba', 'whatsapp /r/prueba' ) === array_map( static fn( $e ) => $e['family'] . ' ' . $e['path'], $typed ), 'the owner\'s test reads as two shares, each the link actually sent: ' . json_encode( array_column( $typed, 'path' ) ) );
ok( $t0 + 16 === $typed[0]['ts'], 'credited at the last fetch of the burst' );
$two = sn_share_echoes_rows( array( $raw( 'whatsapp', '/r/a', $t0 ), $raw( 'whatsapp', '/r/b', $t0 + 3600 ) ) );
ok( 2 === count( $two ), 'two sends an hour apart are two shares' );

echo "\nGroup: the read\n";
$GLOBALS['__mr'] = array( 'ok' => true, 'rows' => array( $raw( 'whatsapp', '/notes/x/', $t0 ) ), 'truncated' => true );
function snt_mr_fetch() { return $GLOBALS['__mr']; }
ok( null === sn_share_echoes_read( 14 ), 'a read that reached its row cap is not every share: null, never joined or stored' );
$GLOBALS['__mr']['truncated'] = false;
ok( 1 === count( (array) sn_share_echoes_read( 14 ) ), 'a complete read answers its shares' );
$GLOBALS['__mr']['rows'] = array( array( 'surface' => 'html', 'family' => 'meta-ai', 'day' => '2026-11-14', 'hits' => 9 ) );
ok( null === sn_share_echoes_read( 14 ), 'a worker that answers rows that are not echoes reads as a failed read' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
