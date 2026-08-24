<?php
/**
 * Standalone test: the update-cache watchdog must be reachable outside wp-admin.
 * Run: php tests/wp-update-cache-watchdog.php
 *
 * WHY THIS EXISTS (2026-08-24)
 *
 * The version-change watchdog — the block that clears SN_GH_PLUGIN_CACHE_KEY,
 * WP's `update_plugins` transient, the parsed-plugin-header cache and the
 * `plugin_information_<slug>` transient after an install — was registered on
 * `admin_init`. WP-CLI is not an admin request and wp-cron is not an admin
 * request, so neither ever fired it. A maintainer who drives updates from the
 * CLI got none of the invalidation and read whatever the object cache last
 * held: up to SN_GH_PLUGIN_CACHE_TTL for the tag, up to 12h for update_plugins.
 *
 * On this site that is not academic — a persistent object cache (Redis) keeps
 * site transients out of the options table, so `wp transient delete --all`
 * cannot clear them either. The self-healing existed but was unreachable from
 * the way the plugin is actually operated.
 *
 * `init` fires in every context that matters: wp-admin, front-end, wp-cron and
 * WP-CLI. Admin requests fire `init` before `admin_init`, so one registration
 * strictly dominates the old one.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SNT_VERSION', '12.24.1' );
define( 'SNT_PATH', '/wp-content/plugins/signal-and-noise-tools/' );

// ── WP seams. Only the seams: the module under test is the real one. ────────
$GLOBALS['__actions'] = array();
function add_action( $h, $c = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; }
function add_filter( $h, $c = null, $p = 10, $a = 1 ) {}

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }

$GLOBALS['__site_trans'] = array();
$GLOBALS['__deleted']    = array();
function get_site_transient( $k ) { return $GLOBALS['__site_trans'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__site_trans'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { $GLOBALS['__deleted'][] = $k; unset( $GLOBALS['__site_trans'][ $k ] ); return true; }

$GLOBALS['__clean_plugins_cache'] = 0;
function wp_clean_plugins_cache( $clear_update_cache = true ) { $GLOBALS['__clean_plugins_cache']++; }

function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	private $code; private $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return $s; }
function current_user_can( $c ) { return false; }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( $path, '/' ); }
function wp_remote_get( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( array( 'name' => 'v12.24.1' ) ) ) ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function plugin_basename( $file ) { return 'signal-and-noise-tools/signal-and-noise-tools.php'; }

require __DIR__ . '/../inc/wp-update-integration.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── 1. Reachability: the hook it registers on ──────────────────────────────
ok(
	isset( $GLOBALS['__actions']['init'] ) && ! empty( $GLOBALS['__actions']['init'] ),
	'watchdog registers on init (fires under WP-CLI, wp-cron and the front end)'
);

// ── 2. It is a named function, so a caller can drive it directly ───────────
ok(
	function_exists( 'sn_plugin_update_version_watchdog' ),
	'watchdog is a named function, not an anonymous closure'
);

if ( ! function_exists( 'sn_plugin_update_version_watchdog' ) ) {
	echo "\n$pass passed, $fail failed\n";
	exit( $fail > 0 ? 1 : 0 );
}

// ── 3. On a version change it performs the full invalidation ───────────────
$GLOBALS['__options'][ SN_GH_PLUGIN_LAST_SEEN_OPT ] = '12.23.0';
$GLOBALS['__deleted']             = array();
$GLOBALS['__clean_plugins_cache'] = 0;

sn_plugin_update_version_watchdog();

ok( in_array( SN_GH_PLUGIN_CACHE_KEY, $GLOBALS['__deleted'], true ), 'version change: clears the GitHub tag cache' );
ok( in_array( 'update_plugins', $GLOBALS['__deleted'], true ), "version change: clears WP's update_plugins transient" );
ok( in_array( 'plugin_information_' . SN_GH_PLUGIN_SLUG, $GLOBALS['__deleted'], true ), 'version change: clears the View Details cache' );
ok( 1 === $GLOBALS['__clean_plugins_cache'], 'version change: cleans the parsed plugin-header cache' );
ok( SNT_VERSION === ( $GLOBALS['__options'][ SN_GH_PLUGIN_LAST_SEEN_OPT ] ?? '' ), 'version change: records the new version as last-seen' );

// ── 4. Idempotent: a second run must not re-clear anything ─────────────────
$GLOBALS['__deleted']             = array();
$GLOBALS['__clean_plugins_cache'] = 0;

sn_plugin_update_version_watchdog();

ok( array() === $GLOBALS['__deleted'], 'no version change: deletes nothing (safe to run on every request)' );
ok( 0 === $GLOBALS['__clean_plugins_cache'], 'no version change: does not touch the plugin-header cache' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
