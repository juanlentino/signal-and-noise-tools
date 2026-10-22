<?php
/**
 * Standalone tests for the rate-limit warning email (inc/api-rate-monitor.php):
 * HTML with its plain text as the alternative, the hook removed after, the
 * recipient, trigger and once-a-day throttle unchanged.
 *
 * Run: php tests/api-rate-monitor-mail.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function apply_filters( $t, $v ) { return $v; }
function add_filter() {}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $k ) ); }
$GLOBALS['__st'] = array();
function get_site_transient( $k ) { return $GLOBALS['__st'][ $k ] ?? false; }
function set_site_transient( $k, $v, $t = 0 ) { $GLOBALS['__st'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return 'admin_email' === $k ? 'owner@example.com' : $d; }
function is_email( $e ) { return false !== strpos( (string) $e, '@' ); }
function human_time_diff( $a, $b = 0 ) { return '40 minutes'; }
function get_bloginfo( $k = '' ) { return 'Test & Site'; }
function wp_specialchars_decode( $s, $q = 0 ) { return $s; }
require __DIR__ . '/lib/mail-capture-stub.php';
$GLOBALS['__mail'] = array();
function wp_mail( $to, $subject, $body, $headers = '' ) {
	$GLOBALS['__mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers, 'alt' => snt_test_mail_alt() );
	return true;
}
require __DIR__ . '/../inc/api-rate-monitor.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nTest: the rate-limit warning\n";
snt_rate_limit_maybe_warn( 'api.github.com', array( 'label' => 'GitHub API', 'remaining' => 4000, 'limit' => 5000, 'reset_at' => time() + 2400 ) );
ok( array() === $GLOBALS['__mail'], 'control: a healthy host (80% remaining) mails nothing' );
snt_rate_limit_maybe_warn( 'api.github.com', array( 'label' => 'GitHub API', 'remaining' => 3, 'limit' => 60, 'reset_at' => time() + 2400 ) );
ok( 1 === count( $GLOBALS['__mail'] ) && 'owner@example.com' === $GLOBALS['__mail'][0]['to'], 'below 10% remaining: one warning, to the admin address' );
$m = $GLOBALS['__mail'][0];
ok( in_array( 'Content-Type: text/html; charset=UTF-8', (array) $m['headers'], true ) && 0 === strpos( $m['body'], '<!DOCTYPE html><html lang="en">' ), 'the body is an HTML document in English' );
ok( 0 === strpos( $m['alt'], "Signal & Noise rate-limit monitor:\n\nHost: GitHub API (api.github.com)\nRemaining: 3 / 60 (5%)\n" ), 'the plain-text part is the warning text, unchanged' );
ok( false !== strpos( $m['body'], '>Host</th><td style="vertical-align:top;padding:2px 0;">GitHub API (api.github.com)</td>' ) && false !== strpos( $m['body'], '>Mitigation</h2>' ) && 2 === substr_count( $m['body'], '<li>' ), 'the HTML reads the facts as labeled rows and the mitigations as a list' );
ok( false !== strpos( $m['body'], '<title>[Test &amp; Site] GitHub API rate limit low: 5% remaining</title>' ), 'the subject is the escaped document title' );
ok( array() === snt_test_mail_hooks(), 'the plain-text hook is removed, so it cannot leak into another email' );
snt_rate_limit_maybe_warn( 'api.github.com', array( 'label' => 'GitHub API', 'remaining' => 1, 'limit' => 60, 'reset_at' => time() + 2400 ) );
ok( 1 === count( $GLOBALS['__mail'] ), 'the once-a-day throttle still holds' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
