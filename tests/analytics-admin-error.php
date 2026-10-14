<?php
/**
 * The "Analytics read failed" diagnostic says the same sentence on both
 * twins: the classic notice (inc/analytics-admin-error.php) and the native
 * window's chrome/error painter. Code and age, never the request URL.
 *
 * Run: php tests/analytics-admin-error.php
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$GLOBALS['__err']   = null;
$GLOBALS['__admin'] = true;
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function current_user_can( $c ) { return $GLOBALS['__admin']; }
function human_time_diff( $a, $b = 0 ) { return intdiv( $b - $a, 60 ) . ' mins'; }
function sn_analytics_last_error( $since = 0 ) { return $GLOBALS['__err']; }
function wp_admin_notice( $body, $args ) { echo '<div class="notice">' . $body . '</div>'; }
function snt_kit_esc( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function snt_kit_notice( $kind, $inner, $d = false ) { return '<os-notice tone="' . $kind . '">' . $inner . '</os-notice>'; }

require __DIR__ . '/../inc/analytics-admin-error.php';
require __DIR__ . '/../apps/sn-analytics/parts/painters/chrome-status.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}
$classic = static function () { ob_start(); snt_analytics_render_error(); return (string) ob_get_clean(); };
$native  = static fn() => \SignalNoise\OpenStationHost\Analytics\Painters\paint_chrome_error( array() );

ok( '' === $classic() && '' === $native(), 'no stored error: neither twin paints a notice' );

$GLOBALS['__err'] = array( 'code' => 422, 'message' => 'Input was invalid', 'url' => 'https://api.cloudflare.com/client/v4/accounts/SECRETACCT/analytics_engine/sql', 'when' => time() - 180 );
$c = $classic();
$n = $native();
$sentence = 'HTTP 422, recorded 3 mins ago.';
ok( false !== strpos( $c, 'Analytics read failed.</strong> ' . $sentence ), 'classic: the code and how long ago it was recorded' );
ok( false !== strpos( $n, 'Analytics read failed.</b> ' . $sentence ), 'native: the same sentence' );
ok( false !== strpos( $c, '<br>Input was invalid' ) && false !== strpos( $n, '<br>Input was invalid' ), 'both: then the message' );
ok( false === strpos( $c . $n, 'SECRETACCT' ) && false === strpos( $c . $n, 'from' ), 'neither twin prints the request URL (it holds the account id)' );

$GLOBALS['__err'] = array( 'code' => 0, 'message' => 'timed out', 'url' => '', 'when' => 0 );
ok( 'Network error, when it was recorded is not known.' === snt_analytics_render_error_data()['detail'], 'no HTTP code and no time: "Network error" and the unknown age, in words' );

$GLOBALS['__admin'] = false;
ok( '' === $classic() && '' === $native(), 'a viewer who cannot manage options sees neither twin' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
