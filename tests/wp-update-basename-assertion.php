<?php
/**
 * Standalone test: the plugin must notice when its hardcoded basename stops
 * matching where WordPress actually loaded it from.
 * Run: php tests/wp-update-basename-assertion.php
 *
 * WHY THIS EXISTS (2026-08-24)
 *
 * SN_GH_PLUGIN_BASENAME is a hardcoded constant. Every update entry we write
 * into WP's `update_plugins` transient is keyed by it, and the
 * upgrader_source_selection / upgrader_pre_install / upgrader_post_install
 * filters all gate on it. If the plugin is ever installed to a directory with
 * a different name, that constant names a plugin WordPress does not have:
 *
 *   - the update row appears NOWHERE, because WP looks entries up by the
 *     basename of each INSTALLED plugin and finds no match;
 *   - clearing caches cannot help, since the transient is rebuilt with the
 *     same wrong key;
 *   - the only way to get new code is delete-and-reinstall, which is exactly
 *     the path that produces a wrongly-named directory in the first place.
 *
 * That last part is what makes it self-perpetuating. GitHub's tag archive
 * unpacks to `signal-and-noise-tools-<version>/`, and the rename filter that
 * fixes this gates on $hook_extra['plugin'] — unset for a manual Upload
 * Plugin. So a hand-upload can land the plugin at the version-suffixed
 * directory and silently sever the updater forever.
 *
 * A mismatch is invisible by construction. This makes it loud.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SNT_VERSION', '12.24.1' );
define( 'SNT_PATH', '/wp-content/plugins/signal-and-noise-tools/' );

// ── WP seams ───────────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $h, $c = null, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; }
function add_filter( $h, $c = null, $p = 10, $a = 1 ) {}

// The discriminator under test: what WP says the real basename is.
$GLOBALS['__real_basename'] = 'signal-and-noise-tools/signal-and-noise-tools.php';
function plugin_basename( $file ) { return $GLOBALS['__real_basename']; }

$GLOBALS['__can'] = true;
function current_user_can( $c ) { return ! empty( $GLOBALS['__can'] ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
$GLOBALS['__site_trans'] = array();
function get_site_transient( $k ) { return $GLOBALS['__site_trans'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__site_trans'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['__site_trans'][ $k ] ); return true; }
function wp_clean_plugins_cache( $c = true ) {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	private $code; private $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses_post( $s ) { return $s; }
function __( $s, $d = null ) { return $s; }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/x/' . ltrim( $path, '/' ); }
function wp_remote_get( $u, $a = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( array( 'name' => 'v12.24.1' ) ) ) ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }

require __DIR__ . '/../inc/wp-update-integration.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( function_exists( 'sn_plugin_basename_mismatch' ), 'the mismatch check exists at all' );
if ( ! function_exists( 'sn_plugin_basename_mismatch' ) ) {
	echo "\n$pass passed, $fail failed\n";
	exit( 1 );
}

// ── 1. Correct install: silent ─────────────────────────────────────────────
$GLOBALS['__real_basename'] = SN_GH_PLUGIN_BASENAME;
ok( '' === sn_plugin_basename_mismatch(), 'matching basename reports no mismatch' );

// ── 2. The real failure: a version-suffixed directory from a hand-upload ───
$GLOBALS['__real_basename'] = 'signal-and-noise-tools-12.24.1/signal-and-noise-tools.php';
ok(
	'signal-and-noise-tools-12.24.1/signal-and-noise-tools.php' === sn_plugin_basename_mismatch(),
	'a version-suffixed directory is reported, and names the ACTUAL basename'
);

// ── 3. It has a door: a notice a human will actually see ───────────────────
ok( isset( $GLOBALS['__actions']['admin_notices'] ), 'the check is wired to a visible surface (admin_notices)' );

if ( isset( $GLOBALS['__actions']['admin_notices'] ) ) {
	$render = function () {
		ob_start();
		foreach ( $GLOBALS['__actions']['admin_notices'] as $cb ) { call_user_func( $cb ); }
		return (string) ob_get_clean();
	};

	$GLOBALS['__real_basename'] = 'signal-and-noise-tools-12.24.1/signal-and-noise-tools.php';
	$GLOBALS['__can']           = true;
	$out                        = $render();
	ok( '' !== trim( $out ), 'mismatch: the notice renders' );
	ok( false !== strpos( $out, 'signal-and-noise-tools-12.24.1' ), 'mismatch: the notice quotes the WRONG directory it actually found' );
	ok( false !== strpos( $out, SN_GH_PLUGIN_BASENAME ), 'mismatch: the notice states the directory it EXPECTED' );

	$GLOBALS['__real_basename'] = SN_GH_PLUGIN_BASENAME;
	ok( '' === trim( $render() ), 'correct install: the notice stays silent' );

	$GLOBALS['__real_basename'] = 'signal-and-noise-tools-12.24.1/signal-and-noise-tools.php';
	$GLOBALS['__can']           = false;
	ok( '' === trim( $render() ), 'a user who cannot update plugins is not shown the notice' );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
