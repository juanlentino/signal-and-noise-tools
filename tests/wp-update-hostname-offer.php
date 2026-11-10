<?php
/**
 * Standalone test: the update offer rides core's `update_plugins_{hostname}`
 * hook, the one core runs for a plugin that declares an Update URI.
 * Run: php tests/wp-update-hostname-offer.php
 *
 * The driver below is the Update URI loop of wp_update_plugins()
 * (wp-includes/update.php, WordPress 7.1.4, from `foreach ( $plugins as
 * $plugin_file => $plugin_data )` to the payload sanitizer), copied so the
 * assertions read what core files under `response` and `no_update`, not what
 * the callback returns. The hook name is derived from the plugin's real
 * `Update URI` header, as core derives it.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SNT_VERSION', '2026.11.19' );
define( 'SNT_PATH', '/wp-content/plugins/signal-and-noise-tools/' );

$GLOBALS['__filters'] = array();
function add_action( $h, $c = null, $p = 10, $a = 1 ) {}
function add_filter( $h, $c = null, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = array( $c, $a ); }
function apply_filters( $h, $value, ...$rest ) {
	foreach ( $GLOBALS['__filters'][ $h ] ?? array() as list( $cb, $n ) ) {
		$value = $cb( ...array_slice( array_merge( array( $value ), $rest ), 0, $n ) );
	}
	return $value;
}
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $autoload = null ) { return true; }
$GLOBALS['__site_trans'] = array();
function get_site_transient( $k ) { return $GLOBALS['__site_trans'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__site_trans'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['__site_trans'][ $k ] ); return true; }
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
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function wp_remote_retrieve_response_message( $r ) { return ''; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function plugin_basename( $file ) { return 'signal-and-noise-tools/signal-and-noise-tools.php'; }
function sanitize_url( $u ) { return (string) $u; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

// GitHub's tags answer: a tag name, or null for a failed read.
$GLOBALS['__tag'] = 'v2026.11.20';
function wp_remote_get( $url, $args = array() ) {
	if ( null === $GLOBALS['__tag'] ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( array( 'name' => 'v2026.9.30' ), array( 'name' => $GLOBALS['__tag'] ) ) ) );
}

require __DIR__ . '/../inc/wp-update-integration.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// The real header, as get_plugin_data() reads it.
preg_match( '/^\s*\*\s*Update URI:\s*(\S+)/m', (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' ), $hm );
$update_uri = $hm[1] ?? '';
ok( 'github.com' === parse_url( $update_uri, PHP_URL_HOST ), 'the plugin header names github.com as its Update URI host' );

/**
 * wp_update_plugins() after a 200 from api.wordpress.org: the .org payload,
 * then the Update URI loop and the sanitizer, verbatim.
 */
function core_update_loop( array $plugins, array $org ) {
	$updates               = new stdClass();
	$updates->last_checked = time();
	$updates->response     = $org['plugins'] ?? array();
	$updates->translations = array();
	$updates->no_update    = $org['no_update'] ?? array();
	foreach ( $plugins as $file => $p ) {
		$updates->checked[ $file ] = $p['Version'];
	}
	$locales = array();

	foreach ( $plugins as $plugin_file => $plugin_data ) {
		if ( ! $plugin_data['UpdateURI'] || isset( $updates->response[ $plugin_file ] ) ) {
			continue;
		}

		$hostname = wp_parse_url( sanitize_url( $plugin_data['UpdateURI'] ), PHP_URL_HOST );

		$update = apply_filters( "update_plugins_{$hostname}", false, $plugin_data, $plugin_file, $locales );

		if ( ! $update ) {
			continue;
		}

		$update = (object) $update;

		// Is it valid? We require at least a version.
		if ( ! isset( $update->version ) ) {
			continue;
		}

		// These should remain constant.
		$update->id     = $plugin_data['UpdateURI'];
		$update->plugin = $plugin_file;

		// WordPress needs the version field specified as 'new_version'.
		if ( ! isset( $update->new_version ) ) {
			$update->new_version = $update->version;
		}

		unset( $updates->no_update[ $plugin_file ], $updates->response[ $plugin_file ] );

		if ( version_compare( $update->new_version, $plugin_data['Version'], '>' ) ) {
			$updates->response[ $plugin_file ] = $update;
		} else {
			$updates->no_update[ $plugin_file ] = $update;
		}
	}

	$sanitize_plugin_update_payload = static function ( &$item ) {
		$item = (object) $item;

		unset( $item->translations, $item->compatibility );

		return $item;
	};

	array_walk( $updates->response, $sanitize_plugin_update_payload );
	array_walk( $updates->no_update, $sanitize_plugin_update_payload );

	return $updates;
}

$ours  = SN_GH_PLUGIN_BASENAME;
$check = static function ( $installed, $tag ) use ( $update_uri, $ours ) {
	$GLOBALS['__site_trans'] = array();
	$GLOBALS['__tag']        = $tag;
	return core_update_loop(
		array(
			$ours                 => array( 'Name' => 'Signal & Noise Tools', 'Version' => $installed, 'UpdateURI' => $update_uri ),
			'neighbor/neighbor.php' => array( 'Name' => 'Neighbor', 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/someone/neighbor' ),
			'akismet/akismet.php' => array( 'Name' => 'Akismet', 'Version' => '5.0', 'UpdateURI' => '' ),
			'hello.php'           => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2', 'UpdateURI' => '' ),
		),
		array(
			'plugins'   => array( 'akismet/akismet.php' => (object) array( 'slug' => 'akismet', 'new_version' => '5.3', 'package' => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip' ) ),
			'no_update' => array( 'hello.php' => (object) array( 'slug' => 'hello-dolly', 'new_version' => '1.7.2' ) ),
		)
	);
};
$others_untouched = static function ( $u ) {
	return ! isset( $u->response['neighbor/neighbor.php'] ) && ! isset( $u->no_update['neighbor/neighbor.php'] )
		&& '5.3' === ( $u->response['akismet/akismet.php']->new_version ?? '' )
		&& 'https://downloads.wordpress.org/plugin/akismet.5.3.zip' === ( $u->response['akismet/akismet.php']->package ?? '' )
		&& '1.7.2' === ( $u->no_update['hello.php']->new_version ?? '' );
};

// ── 1. An older installed version is offered the tag ───────────────────────
$u     = $check( '2026.11.19', 'v2026.11.20' );
$offer = $u->response[ $ours ] ?? null;
ok( is_object( $offer ) && ! isset( $u->no_update[ $ours ] ), 'older installed version: core files an offer under response' );
ok( '2026.11.20' === ( $offer->new_version ?? '' ) && '2026.11.20' === ( $offer->version ?? '' ), 'offer: the new version is the tag without its v' );
ok( 'https://github.com/juanlentino/signal-and-noise-tools/archive/refs/tags/v2026.11.20.zip' === ( $offer->package ?? '' ), 'offer: the package is the tag archive' );
ok( $ours === ( $offer->plugin ?? '' ) && $update_uri === ( $offer->id ?? '' ) && 'signal-and-noise-tools' === ( $offer->slug ?? '' ), 'offer: plugin, id and slug are the ones core and the installer key on' );
ok( 'https://github.com/juanlentino/signal-and-noise-tools' === ( $offer->url ?? '' ), 'offer: the details URL is the repository' );
ok( is_array( $offer->icons ?? null ) && ! empty( $offer->icons['default'] ) && ! empty( $offer->icons['svg'] ) && is_array( $offer->banners ?? null ), 'offer: icons (default set) and banners ride along' );
ok( ! empty( $offer->tested ) && ! empty( $offer->requires ) && ! empty( $offer->requires_php ), 'offer: tested, requires and requires_php are set for the Updates screen' );
ok( $others_untouched( $u ), 'offer: the github.com neighbor and both wordpress.org entries are untouched' );

// ── 2. The installed version is the tag: no offer ──────────────────────────
$u = $check( '2026.11.20', 'v2026.11.20' );
ok( ! isset( $u->response[ $ours ] ) && '2026.11.20' === ( $u->no_update[ $ours ]->new_version ?? '' ), 'same version: no offer, filed under no_update' );
ok( $others_untouched( $u ), 'same version: other plugins untouched' );

// ── 3. The installed version is newer than the tag: no offer ───────────────
$u = $check( '2026.11.21', 'v2026.11.20' );
ok( ! isset( $u->response[ $ours ] ) && isset( $u->no_update[ $ours ] ), 'newer installed version: no offer' );

// ── 4. Date versions compare by number, not by string ──────────────────────
$u = $check( '2026.9.30', 'v2026.11.2' );
ok( '2026.11.2' === ( $u->response[ $ours ]->new_version ?? '' ), '2026.9.30 installed, v2026.11.2 tagged: offered (11 > 9)' );
$u = $check( '2026.11.20', 'v2026.11.3' );
ok( ! isset( $u->response[ $ours ] ), '2026.11.20 installed, v2026.11.3 tagged: not offered (20 > 3)' );

// ── 5. A failed GitHub tag read: no offer, no entry, no fatal ──────────────
$u = $check( '2026.11.19', null );
ok( ! isset( $u->response[ $ours ] ) && ! isset( $u->no_update[ $ours ] ), 'failed tag read: nothing is filed for the plugin' );
ok( $others_untouched( $u ), 'failed tag read: other plugins untouched' );

// ── 6. The filter leaves every other github.com plugin's answer alone ──────
$theirs = array( 'slug' => 'neighbor', 'version' => '9.9.9', 'package' => 'https://example.test/neighbor.zip' );
$GLOBALS['__tag'] = 'v2026.11.20';
ok( $theirs === apply_filters( 'update_plugins_github.com', $theirs, array( 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/someone/neighbor' ), 'neighbor/neighbor.php', array() ), 'another plugin on github.com: its own update data passes through unchanged' );
ok( false === apply_filters( 'update_plugins_github.com', false, array( 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/someone/neighbor' ), 'neighbor/neighbor.php', array() ), 'another plugin on github.com: false stays false' );

// ── 7. One writer: the raw transient filter is gone ────────────────────────
ok( empty( $GLOBALS['__filters']['pre_set_site_transient_update_plugins'] ), 'nothing writes the offer through pre_set_site_transient_update_plugins' );
ok( in_array( 'sn_plugin_update_drop_stale', array_column( $GLOBALS['__filters']['site_transient_update_plugins'] ?? array(), 0 ), true ), 'the read-time stale guard is still registered' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
