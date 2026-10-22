<?php
/**
 * Standalone tests for inc/mail-send.php: the one sender (HTML plus a
 * plain-text alternative, the hook removed after) and the HTML builders every
 * plugin email shares.
 *
 * Run: php tests/mail-send.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
require __DIR__ . '/lib/mail-capture-stub.php';
$GLOBALS['__mail']      = array();
$GLOBALS['__mail_mode'] = 'ok';
function wp_mail( $to, $subject, $body, $headers = '' ) {
	$alt = snt_test_mail_alt();
	if ( 'throw' === $GLOBALS['__mail_mode'] ) { throw new RuntimeException( 'mailer exploded' ); }
	$GLOBALS['__mail'][] = compact( 'to', 'subject', 'body', 'headers', 'alt' );
	return 'ok' === $GLOBALS['__mail_mode'];
}
require __DIR__ . '/../inc/mail-send.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nTest: the sender\n";
ok( true === sn_mail_send_html( 'a@b.test', 'S', '<p>x</p>', 'x' ), 'returns what wp_mail returned (true)' );
$m = $GLOBALS['__mail'][0];
ok( in_array( 'Content-Type: text/html; charset=UTF-8', (array) $m['headers'], true ), 'the body goes out as HTML' );
ok( 'x' === $m['alt'], 'the plain-text part is set as the alternative during the send' );
ok( array() === snt_test_mail_hooks(), 'the hook is removed after the send' );
$GLOBALS['__mail_mode'] = 'fail';
ok( false === sn_mail_send_html( 'a@b.test', 'S', 'h', 't' ) && array() === snt_test_mail_hooks(), 'a failed send returns false and still removes the hook' );
$GLOBALS['__mail_mode'] = 'throw';
$threw = false;
try { sn_mail_send_html( 'a@b.test', 'S', 'h', 't' ); } catch ( RuntimeException $e ) { $threw = true; }
ok( $threw && array() === snt_test_mail_hooks(), 'a throwing mailer propagates, and the hook is gone anyway (finally)' );
$GLOBALS['__mail_mode'] = 'ok';
$GLOBALS['__mail']      = array();
sn_mail_send_text( 'a@b.test', 'Subj <x>', "Title\n=====\n\nKey: value" );
$m = $GLOBALS['__mail'][0];
ok( "Title\n=====\n\nKey: value" === $m['alt'] && 0 === strpos( $m['body'], '<!DOCTYPE html><html lang="en">' ), 'send_text: the text is the alternative, the HTML is a document in English' );
ok( false !== strpos( $m['body'], '<title>Subj &lt;x&gt;</title>' ), 'send_text: the subject is the escaped document title' );

echo "\nTest: structured text reads as HTML\n";
$text = "Weekly <digest>\n" . str_repeat( '=', 20 ) . "\n\nQuiet week: no <script>alert(1)</script> blocks.\n\nLogin guard (edge layer)\n  Checked: 1,204\n  Blocked: 12 (1% of checked)\n\nMitigation:\n  - GitHub: define a token & retry.\n\nOpen the dashboard: https://example.test/wp-admin/admin.php?page=x&y=1\nSee https://core.trac.wordpress.org/ticket/1 for more.\nThis has a stop. Then: a colon later.";
$h    = sn_mail_text_to_html( $text );
ok( false !== strpos( $h, '<h1 style="font-size:20px;margin:0 0 12px;">Weekly &lt;digest&gt;</h1>' ), 'a line underlined with = is the h1, escaped' );
ok( false !== strpos( $h, '<h2 style="font-size:17px;margin:20px 0 8px;">Login guard (edge layer)</h2>' ) && false !== strpos( $h, '>Mitigation</h2>' ), 'a plain line over indented lines is an h2, its trailing colon dropped' );
ok( false !== strpos( $h, '<th scope="row"' ) && false !== strpos( $h, '>Checked</th><td style="vertical-align:top;padding:2px 0;">1,204</td>' ), '"Label: value" lines are rows with the label as a row header' );
ok( false !== strpos( $h, '&lt;script&gt;' ) && false === strpos( $h, '<script>' ), 'values are escaped' );
ok( false !== strpos( $h, '<li>GitHub: define a token &amp; retry.</li>' ), '"- " lines are list items' );
ok( false !== strpos( $h, '<p><a href="https://example.test/wp-admin/admin.php?page=x&amp;y=1">Open the dashboard</a></p>' ), 'a row whose value is only a URL is a link named by its label, href escaped' );
ok( false !== strpos( $h, '>core.trac.wordpress.org</a> for more.' ), 'a URL inside a sentence is a short link named by its host' );
ok( false !== strpos( $h, '<p>This has a stop. Then: a colon later.</p>' ), 'a sentence with a colon after a stop stays a paragraph' );
ok( false === strpos( strip_tags( $h ), 'https://' ), 'no raw URL shows as text' );

echo "\nTest: rows and links\n";
ok( '' === sn_mail_rows_html( array() ), 'no rows, no table' );
ok( '<a href="https://a.test/?q=1&amp;r=2">A &amp; B</a>' === sn_mail_link_html( 'A & B', 'https://a.test/?q=1&r=2' ), 'a link escapes its label and its href' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
