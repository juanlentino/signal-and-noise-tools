<?php
/**
 * Link scans after a share (inc/share-echoes-scan.php): a suspect, one-view
 * session on /r/CODE within 60 seconds after LinkedIn's verified fetch of the
 * same code is LinkedIn checking the link, and leaves the suspect list.
 * Run: php tests/share-echoes-scan.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; $c ? ++$pass : ++$fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; }
function add_action() {}
function sn_link_code_from_path( $p ) { return 1 === preg_match( '#^/r/([a-z0-9-]+)/?$#', $p, $m ) ? $m[1] : ''; }
require __DIR__ . '/../inc/share-echoes.php';
require __DIR__ . '/../inc/share-echoes-scan.php';

// The 2026-11-17 /r/angela burst, as the worker answered it.
$raw = array();
foreach ( array( '15:46:54.843', '15:46:56.333', '15:47:00.672', '15:47:01.928', '15:47:01.937' ) as $t ) {
	$raw[] = array( 'family' => 'linkedin', 'path' => '/r/angela', 'observed_at' => "2026-11-17T{$t}Z", 'verified' => '1', 'via' => 'agent' );
}
$echoes = sn_share_echoes_rows( $raw );
$angela = array( 'start' => '2026-11-17T10:47:48-05:00', 'pageviews' => 1, 'link_code' => 'angela' );

ok( 'LinkedIn' === sn_share_echo_link_scan( $echoes, $angela ), 'the Boydton browser 47 s after LinkedIn\'s verified fetch is LinkedIn\'s scan' );
ok( null === sn_share_echo_link_scan( $echoes, array( 'start' => '2026-11-17T10:48:03-05:00' ) + $angela ), '62 s after the last fetch is not' );
ok( null === sn_share_echo_link_scan( $echoes, array( 'start' => '2026-11-17T10:46:50-05:00' ) + $angela ), 'a visit before the fetch is not' );
ok( null === sn_share_echo_link_scan( $echoes, array( 'pageviews' => 2 ) + $angela ), 'two page views is a reader, not a scan' );
ok( null === sn_share_echo_link_scan( $echoes, array( 'link_code' => 'reign' ) + $angela ), 'another code is not' );
ok( null === sn_share_echo_link_scan( $echoes, array( 'link_code' => '' ) + $angela ), 'a plain page is not' );
$tg = sn_share_echoes_rows( array( array( 'family' => 'telegram', 'path' => '/r/angela', 'observed_at' => '2026-11-17T15:47:01.937Z' ) ) );
ok( null === sn_share_echo_link_scan( $tg, $angela ), 'only an app whose scan has been seen (LinkedIn)' );
$unverified = sn_share_echoes_rows( array( array( 'family' => 'linkedin', 'path' => '/r/angela', 'observed_at' => '2026-11-17T15:47:01.937Z', 'verified' => '0' ) ) );
ok( null === sn_share_echo_link_scan( $unverified, $angela ), 'an unverified LinkedIn claim anchors nothing' );

$split = sn_share_echo_split_scans( array( $angela, array( 'pageviews' => 2 ) + $angela ), $echoes );
ok( 1 === $split['scans'] && 1 === count( $split['rows'] ) && 2 === $split['rows'][0]['pageviews'], 'the scan leaves the list and is counted apart' );
$none = sn_share_echo_split_scans( array( $angela ), null );
ok( null === $none['scans'] && 1 === count( $none['rows'] ), 'shares not read: every row stays, scans unknown' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
