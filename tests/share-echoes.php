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
$two = sn_share_echoes_rows( array( $raw( 'slack', '/notes/x/', $t0 ), $raw( 'linkedin', '/notes/x/', $t0 + 3600 ) ) );
$b   = sn_share_echo_annotate( array( $ses( '/notes/x/', $t0 + 2 * 3600 ) ), $two );
ok( 'LinkedIn' === $b[0]['share']['app'] && 3600 === $b[0]['share']['gap_s'], 'two echoes before one visit: the later one' );
$codes = sn_share_echoes_rows( array( $raw( 'meta', '/r/jill', $t0 ), $raw( 'meta', '/r/jon/', $t0 + 10 ) ) );
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

echo "\nGroup: honest wording\n";
$copy = file_get_contents( __DIR__ . '/../inc/analytics-view-shares.php' ) . file_get_contents( __DIR__ . '/../inc/share-echoes.php' ) . file_get_contents( __DIR__ . '/../inc/abilities-analytics-sessions.php' );
ok( false === stripos( $copy, 'from your share' ) && false !== strpos( $copy, 'after a share' ), 'the copy says "after a share", never "from your share"' );
$privacy = file_get_contents( __DIR__ . '/../inc/privacy-exporters.php' );
ok( false !== strpos( $privacy, 'The site notes when link-preview services fetch a page, and keeps the time, page and service only.' ), 'the privacy policy text carries the sentence' );

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
