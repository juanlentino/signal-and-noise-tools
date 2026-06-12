<?php
/**
 * Tests for inc/analytics-admin.php + inc/analytics-admin-render.php — the
 * Analytics tab orchestrator + partials. Behavioral: drives the render via
 * stubbed accessors and asserts on captured HTML.
 * Run: php tests/analytics-admin.php
 * @since plugin v5.0.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Escaping + i18n stubs (identity / minimal, enough to assert content + that escaping was applied).
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/wp-admin/admin.php?page=sn-theme-options&tab=dashboard'; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $q ? $parts[0] . '?' . http_build_query( $q ) : $parts[0];
}
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=sn-theme-options&tab=dashboard';
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function current_user_can( $c ) { return true; }

// Option store seam for the settings form.
$GLOBALS['__aa_opts'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['__aa_opts'] ) ? $GLOBALS['__aa_opts'][ $k ] : $default; }
function update_option( $k, $v, $autoload = true ) { $GLOBALS['__aa_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__aa_opts'][ $k ] ); return true; }

// Option-name constants (mirrors inc/analytics-api.php Task S1 additions).
if ( ! defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ) { define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' ); }
if ( ! defined( 'SN_CF_ACCOUNT_ID_OPT' ) )      { define( 'SN_CF_ACCOUNT_ID_OPT', 'sn_cf_account_id' ); }

// wp_nonce_field stub.
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" />'; }
}

// sn_mask_secret stub (matches inc/settings.php behaviour).
if ( ! function_exists( 'sn_mask_secret' ) ) {
	function sn_mask_secret( $v ) {
		$v = (string) $v;
		return '' === $v ? '' : ( strlen( $v ) <= 8 ? '••••••••' : '••••' . substr( $v, -4 ) );
	}
}

// AE config seam — toggled per test.
$GLOBALS['__aa_config'] = true;
function sn_analytics_config() { return $GLOBALS['__aa_config'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
$GLOBALS['__aa_error'] = null;
function sn_analytics_last_error() { return $GLOBALS['__aa_error']; }

// Accessor seams — return fixtures.
$GLOBALS['__aa'] = array( 'realtime' => null, 'totals' => array(), 'class_totals' => array(), 'series' => array(), 'paths' => array(), 'dim' => array() );
function sn_analytics_realtime( $class = 'human' ) { return $GLOBALS['__aa']['realtime']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__aa']['totals']; }
function sn_analytics_class_totals( $from, $to ) { return $GLOBALS['__aa']['class_totals']; }
function sn_analytics_daily_series( $from, $to, $class = 'human' ) { return $GLOBALS['__aa']['series']; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['paths']; }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['dim']; }

require_once __DIR__ . '/../inc/analytics-admin-render.php';
require_once __DIR__ . '/../inc/analytics-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function capture( $cb ) { ob_start(); $cb(); return ob_get_clean(); }

echo "Analytics admin tab\n\n";

echo "Group: range resolution\n";
ok( snt_analytics_resolve_range( '30' ) === 30, 'resolve_range: 30 → 30' );
ok( snt_analytics_resolve_range( '90' ) === 90, 'resolve_range: 90 → 90' );
ok( snt_analytics_resolve_range( '7' ) === 7, 'resolve_range: 7 → 7' );
ok( snt_analytics_resolve_range( '999' ) === 7, 'resolve_range: out-of-list → default 7' );
ok( snt_analytics_resolve_range( "7); DROP" ) === 7, 'resolve_range: junk → default 7' );

echo "\nGroup: class resolution\n";
ok( snt_analytics_resolve_class( 'bot' ) === 'bot', 'resolve_class: bot allowed' );
ok( snt_analytics_resolve_class( 'martian' ) === 'human', 'resolve_class: unknown → human' );

echo "\nGroup: date math\n";
list( $from, $to ) = snt_analytics_range_dates( 7, gmmktime( 0, 0, 0, 6, 11, 2026 ) );
ok( $to === '2026-06-11', 'range_dates: $to is the anchor day (UTC)' );
ok( $from === '2026-06-05', 'range_dates: 7-day window is inclusive (anchor-6)' );

echo "\nGroup: empty / config state\n";
$GLOBALS['__aa_config'] = false;
$html = capture( 'snt_analytics_render_admin_tab' );
ok( stripos( $html, 'not receiving data' ) !== false || stripos( $html, 'wp-config' ) !== false, 'render: unconfigured shows the config/empty state' );
ok( stripos( $html, 'SN_CF_ANALYTICS_TOKEN' ) !== false, 'render: names the wp-config constant to set' );

$GLOBALS['__aa_config'] = true;
$GLOBALS['__aa_error']  = array( 'code' => 401, 'url' => 'https://api.cloudflare.test/x', 'message' => 'bad token' );
$GLOBALS['__aa']['totals'] = array( 'views' => 0, 'visits' => 0, 'scroll_avg' => 0, 'time_avg' => 0 );
$html = capture( 'snt_analytics_render_admin_tab' );
ok( stripos( $html, 'bad token' ) !== false || stripos( $html, '401' ) !== false, 'render: surfaces the last AE error when present' );

echo "\nGroup: data render\n";
$GLOBALS['__aa_config'] = true;
$GLOBALS['__aa_error']  = null;
$GLOBALS['__aa']['realtime']     = 7;
$GLOBALS['__aa']['totals']       = array( 'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108.0 );
$GLOBALS['__aa']['class_totals'] = array( 'human' => array( 'views' => 1204, 'visits' => 389 ), 'bot' => array( 'views' => 268, 'visits' => 12 ), 'suspect' => array( 'views' => 44, 'visits' => 9 ) );
$GLOBALS['__aa']['series']       = array( array( 'day' => '2026-06-10', 'views' => 100, 'visits' => 40 ), array( 'day' => '2026-06-11', 'views' => 300, 'visits' => 90 ) );
$GLOBALS['__aa']['paths']        = array( array( 'path' => '/notes/x', 'views' => 412, 'visits' => 158, 'scroll_avg' => 71.0, 'time_avg' => 150.0 ) );
$GLOBALS['__aa']['dim']          = array( array( 'value' => 'news.ycombinator.com', 'views' => 312, 'visits' => 98 ) );
$html = capture( 'snt_analytics_render_admin_tab' );
ok( strpos( $html, '1,204' ) !== false, 'render: views stat card formatted' );
ok( strpos( $html, '<div class="n">7</div>' ) !== false, 'render: visitors-now card shows 7 (pinned to the card, not the 7d range label)' );
ok( strpos( $html, '312 automated filtered (268 bot · 44 suspect)' ) !== false, 'render: separation line total + bot + suspect counts (full-phrase pin)' );
ok( strpos( $html, '/notes/x' ) !== false, 'render: top path row present' );
ok( strpos( $html, 'news.ycombinator.com' ) !== false, 'render: top source row present' );
ok( substr_count( $html, 'sn-an-panel' ) === 4, 'render: four breakdown panels (pages/sources/countries/devices)' );
ok( substr_count( $html, 'sn-an-trend' ) === 1 && substr_count( $html, 'class="bar"' ) === 2, 'render: trend strip has one bar per series day' );

echo "\nGroup: escaping\n";
$GLOBALS['__aa']['paths'] = array( array( 'path' => '/x"<script>', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$html = capture( 'snt_analytics_render_admin_tab' );
ok( strpos( $html, '<script>' ) === false, 'render: path output is escaped (no raw <script>)' );

echo "\nGroup: class segmented control\n";
$GLOBALS['__aa']['paths'] = array();
$html = capture( 'snt_analytics_render_admin_tab' );
ok( substr_count( $html, 'sn-an-seg' ) >= 1 && strpos( $html, 'Human' ) !== false && strpos( $html, 'Bot' ) !== false, 'render: class segmented control rendered' );

echo "\nGroup: controls URL uses page=sn-theme-options (POST-dispatcher regression guard)\n";
// Regression guard: control links MUST use page=sn-theme-options so the SN
// admin POST dispatcher (inc/admin-post-handler.php) accepts them. Using
// page=sn-monitoring causes a silent no-op on Save/Test because sn-monitoring
// is not in sn_admin_pages() — the exact bug fixed in this commit.
$GLOBALS['__aa_config'] = true;
$GLOBALS['__aa_error']  = null;
$GLOBALS['__aa']['realtime']     = 1;
$GLOBALS['__aa']['totals']       = array( 'views' => 10, 'visits' => 5, 'scroll_avg' => 50.0, 'time_avg' => 30.0 );
$GLOBALS['__aa']['class_totals'] = array( 'human' => array( 'views' => 10, 'visits' => 5 ), 'bot' => array( 'views' => 0, 'visits' => 0 ), 'suspect' => array( 'views' => 0, 'visits' => 0 ) );
$GLOBALS['__aa']['series']       = array();
$GLOBALS['__aa']['paths']        = array();
$GLOBALS['__aa']['dim']          = array();
$html = capture( 'snt_analytics_render_admin_tab' );
ok( strpos( $html, 'page=sn-theme-options' ) !== false, 'controls: at least one link uses page=sn-theme-options' );
ok( strpos( $html, 'page=sn-monitoring' ) === false, 'controls: no link uses the wrong page=sn-monitoring slug' );

echo "\nGroup: settings render\n";

// --- UNCONFIGURED: config seam off, no options set.
$GLOBALS['__aa_config'] = false;
$GLOBALS['__aa_opts']   = array();
$html = capture( 'snt_analytics_render_admin_tab' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) !== false, 'settings render: unconfigured has account_id input' );
ok( strpos( $html, 'name="sn_cf_analytics_token"' ) !== false, 'settings render: unconfigured has analytics_token input' );
ok( strpos( $html, 'value="analytics_save"' ) !== false, 'settings render: unconfigured has analytics_save submit' );
ok( strpos( $html, 'value="analytics_test"' ) !== false, 'settings render: unconfigured has analytics_test submit' );
ok( strpos( $html, 'wrangler' ) !== false, 'settings render: unconfigured shows Worker-setup wrangler command' );
ok( strpos( $html, 'SN_PX_TOKEN' ) !== false, 'settings render: unconfigured shows SN_PX_TOKEN reference' );
ok( strpos( $html, 'value="analytics_test"' ) !== false && strpos( $html, 'disabled' ) !== false, 'render: Test button disabled when unconfigured' );

// --- CONFIGURED: config truthy + sample data → dashboard + collapsed settings wrapper.
$GLOBALS['__aa_config']          = true;
$GLOBALS['__aa_error']           = null;
$GLOBALS['__aa']['realtime']     = 3;
$GLOBALS['__aa']['totals']       = array( 'views' => 50, 'visits' => 20, 'scroll_avg' => 50.0, 'time_avg' => 60.0 );
$GLOBALS['__aa']['class_totals'] = array( 'human' => array( 'views' => 50, 'visits' => 20 ), 'bot' => array( 'views' => 0, 'visits' => 0 ), 'suspect' => array( 'views' => 0, 'visits' => 0 ) );
$GLOBALS['__aa']['series']       = array();
$GLOBALS['__aa']['paths']        = array( array( 'path' => '/x', 'views' => 10, 'visits' => 5, 'scroll_avg' => 50.0, 'time_avg' => 30.0 ) );
$GLOBALS['__aa']['dim']          = array();
$html = capture( 'snt_analytics_render_admin_tab' );
ok( strpos( $html, 'sn-an-cards' ) !== false, 'settings render: configured shows dashboard cards' );
ok( strpos( $html, '<details' ) !== false, 'settings render: configured wraps settings in <details>' );
ok( strpos( $html, 'value="analytics_save"' ) !== false, 'settings render: configured still exposes analytics_save form inside details' );

// --- ESCAPING: stored account value with <script> must not appear raw.
$GLOBALS['__aa_config'] = false;
$GLOBALS['__aa_opts']   = array( SN_CF_ACCOUNT_ID_OPT => 'acct"<script>' );
$html = capture( 'snt_analytics_render_admin_tab' );
ok( strpos( $html, '<script>' ) === false, 'settings render: account_id with <script> is escaped — no raw <script> in output' );

// Reset opts.
$GLOBALS['__aa_opts'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
