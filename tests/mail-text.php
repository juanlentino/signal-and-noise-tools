<?php
/**
 * Standalone tests for inc/mail-text.php: the sentence join and the link
 * renderers every plugin email shares.
 *
 * Run: php tests/mail-text.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

require __DIR__ . '/../inc/mail-text.php';

$pass = 0; $fail = 0;
function ok( $condition, $message ) { global $pass, $fail; if ( $condition ) { $pass++; echo "  PASS: $message\n"; } else { $fail++; echo "  FAIL: $message\n"; } }

echo "\nTest: sentence join never doubles punctuation\n";
ok( 'One. Two.' === sn_mail_sentences( array( 'One', 'Two.' ) ), 'a missing stop is added, a present one is kept' );
ok( 'Click Request indexing. Read it here.' === sn_mail_sentences( array( 'Click Request indexing.', 'Read it here' ) ), 'the brief\'s "Request indexing.." case joins once' );
ok( 'Done? Yes! "Quoted."' === sn_mail_sentences( array( 'Done?', 'Yes!', '"Quoted."' ) ), 'question, exclamation and a closing quote count as an ending' );
ok( 'A. B.' === sn_mail_sentences( array( '  A.  ', '', 'B' ) ), 'whitespace is trimmed and empty parts are dropped' );
ok( 'Wait.' === sn_mail_sentence( 'Wait..' ), 'a doubled stop collapses to one' );
ok( 'And so...' === sn_mail_sentence( 'And so...' ), 'a real ellipsis is left alone' );
ok( '' === sn_mail_sentence( '   ' ), 'a blank part stays blank' );

echo "\nTest: plain-text links move off the sentence\n";
$t = sn_mail_text_links( 'Read https://example.com/a?b=1&c=2. Then https://x.test/y.' );
ok( false === strpos( strtok( $t, "\n" ), 'https://' ), 'the sentence line carries no raw URL' );
ok( false !== strpos( $t, 'Read [1]. Then [2].' ), 'each URL becomes a numbered marker, its punctuation kept' );
ok( false !== strpos( $t, "\n  [1] https://example.com/a?b=1&c=2\n" ) && false !== strpos( $t, "\n  [2] https://x.test/y" ), 'each URL sits on its own short line, without the sentence\'s period' );
ok( 'No links here.' === sn_mail_text_links( 'No links here.' ), 'text without a URL is unchanged' );

echo "\nTest: HTML links get a short label, everything escaped\n";
$h = sn_mail_html_links( 'See <b> https://core.trac.wordpress.org/ticket/65551.' );
ok( false !== strpos( $h, '&lt;b&gt;' ), 'the text around the link is escaped' );
ok( false !== strpos( $h, '<a href="https://core.trac.wordpress.org/ticket/65551">core.trac.wordpress.org</a>.' ), 'the URL is a link labeled by its host, the period left outside' );
ok( false === strpos( strip_tags( $h ), 'https://' ), 'no raw URL shows as text' );
$q = sn_mail_html_links( 'Go https://a.test/?x=1&y=2' );
ok( false !== strpos( $q, 'href="https://a.test/?x=1&amp;y=2"' ), 'the href is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
