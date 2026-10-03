<?php
/**
 * Guard: the PDF engine line and the Dompdf bump watch (20.6.0).
 *
 * Reads the real vendored tree (the version and the patch are facts about
 * what ships), then the line's three states and the watch's ripeness.
 *
 * Run: php tests/pdf-engine-status.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'MINUTE_IN_SECONDS', 60 );
define( 'SNT_GITHUB_TOKEN', 'tok' );
$GLOBALS['opt'] = array(); $GLOBALS['http'] = array(); $GLOBALS['resp'] = null;
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function wp_remote_get( $u, $a ) { $GLOBALS['http'][] = $a; return $GLOBALS['resp']; }
function is_wp_error( $x ) { return null === $x; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../inc/resume-pdf/engine-status.php';

echo "What ships\n";
ok( '3.1.6' === snt_pdf_engine_installed(), 'the vendored Dompdf reads as 3.1.6 (' . snt_pdf_engine_installed() . ')' );
ok( snt_pdf_engine_patched(), 'the vendored Cpdf carries our patch' );
ok( ! snt_pdf_engine_patched( '<?php class Cpdf {}' ), 'control: an unpatched Cpdf reads as unpatched' );
ok( '' === snt_pdf_engine_installed( '{"packages":[]}' ) && '' === snt_pdf_engine_installed( 'not json' ), 'no dompdf package, or no JSON, reads as unknown' );

echo "\nThe line\n";
ok( false !== strpos( snt_pdf_engine_line( '3.1.6', true, '3.1.6' ), 'Up to date.' ), 'same version: up to date' );
$l = snt_pdf_engine_line( '3.1.6', true, '3.1.7' );
ok( false !== strpos( $l, 'Dompdf 3.1.7 is out' ), 'a newer release is named' );
ok( false !== strpos( snt_pdf_engine_line( '3.1.6', false, '3.1.6' ), 'WITHOUT our text fix' ), 'a missing patch is said, loudly' );
ok( false !== strpos( snt_pdf_engine_line( '3.1.6', true, '' ), 'not read yet' ), 'an unread latest says so, never "up to date"' );
ok( false === strpos( $l . snt_pdf_engine_line( '', true, '' ), "\u{2014}" ), 'no em dashes in the copy' );

echo "\nThe watch\n";
ok( snt_watch_ripe_dompdf( array(), 0, array( 'installed' => '3.1.6', 'latest' => '3.1.7' ) )['ripe'], 'ripe when a newer release exists' );
ok( ! snt_watch_ripe_dompdf( array(), 0, array( 'installed' => '3.1.6', 'latest' => '3.1.6' ) )['ripe'], 'quiet on the same version' );
ok( ! snt_watch_ripe_dompdf( array(), 0, array( 'installed' => '3.1.6', 'latest' => '' ) )['ripe'], 'quiet when the latest was never read (absence is not a finding)' );
$reg = (string) file_get_contents( __DIR__ . '/../inc/watches.php' );
ok( false !== strpos( $reg, "'ripe'      => 'snt_watch_ripe_dompdf'" ), 'the watch is registered' );

echo "\nThe producer and the readers\n";
ok( '' === snt_pdf_engine_latest() && array() === $GLOBALS['http'], 'never read: the reader says unknown and makes no request' );
$GLOBALS['resp'] = array( 'code' => 200, 'body' => '{"tag_name":"v3.1.7"}' );
ok( '3.1.7' === snt_pdf_engine_refresh() && '3.1.7' === snt_pdf_engine_latest(), 'the daily producer stores the latest release' );
$a = $GLOBALS['http'][0];
ok( 'Bearer tok' === ( $a['headers']['Authorization'] ?? '' ) && 0 === $a['redirection'], 'the configured token is sent, with redirects off' );
$GLOBALS['resp'] = array( 'code' => 403, 'body' => '' );
ok( '3.1.7' === snt_pdf_engine_refresh() && '3.1.7' === snt_pdf_engine_latest(), 'a failed read writes nothing: the last answer stands' );
$n = count( $GLOBALS['http'] );
snt_watch_ripe_dompdf( array(), 0 );
ok( count( $GLOBALS['http'] ) === $n, 'the watch reads the stored answer and makes no request' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
