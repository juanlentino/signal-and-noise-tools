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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
