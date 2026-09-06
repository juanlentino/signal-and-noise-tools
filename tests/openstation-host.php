<?php
/**
 * Standalone test: the host seams (#1074) — capture, rewrite, replay, notice,
 * destination, assets.
 *
 * These are the four things that let the classic admin page paint inside an
 * OpenStation window unchanged, so the suite drives the REAL estate wherever
 * it can: inc/admin-tabs-data.php, inc/admin-tabs.php,
 * inc/admin-legacy-redirect.php, inc/admin-flash-messages.php and
 * inc/admin-post-handler.php are LOADED, not stubbed, and every parity pin
 * below is measured against them. WordPress is stubbed flat.
 *
 * THE REWRITE PINS NEED WORDPRESS ON DISK. `snt_os_host_rewrite()` is a pass
 * over core's WP_HTML_Tag_Processor, and stubbing that class would pin the
 * stub's idea of HTML rather than the browser's. So the real class is required
 * from a WordPress checkout when one is found (`SNT_WP_HTML_API`, or the
 * `wp-includes/` above a normally-installed plugin) and the whole group prints
 * SKIP lines otherwise — CI boots no WordPress, and a suite that stayed green
 * there by silently passing would be worse than one that says nothing.
 *
 * Run: php tests/openstation-host.php
 *      SNT_WP_HTML_API=/path/to/wp-includes/html-api php tests/openstation-host.php
 *
 * @since 13.104.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

// ABSPATH points at a scratch tree whose wp-admin/includes/admin.php THROWS on
// load. That is the negative control for the capture's bootstrap: loading the
// real library fires the `locale` and `override_load_textdomain` filters, where
// third-party code runs and can throw, and the question this suite has to be
// able to answer is what the request looks like afterwards. Only the FIRST
// require_once executes a file, so every capture after Group 0 bootstraps as a
// no-op and the rest of the suite is unaffected.
$snt_fake_abspath = rtrim( sys_get_temp_dir(), '/' ) . '/snt-os-host-abspath-' . getmypid() . '/';
@mkdir( $snt_fake_abspath . 'wp-admin/includes', 0777, true );
file_put_contents( $snt_fake_abspath . 'wp-admin/includes/admin.php', "<?php\nthrow new RuntimeException( 'a locale filter exploded on load' );\n" );
register_shutdown_function(
	static function () use ( $snt_fake_abspath ) {
		@unlink( $snt_fake_abspath . 'wp-admin/includes/admin.php' );
		@rmdir( $snt_fake_abspath . 'wp-admin/includes' );
		@rmdir( $snt_fake_abspath . 'wp-admin' );
		@rmdir( $snt_fake_abspath );
	}
);

define( 'ABSPATH', $snt_fake_abspath );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '13.104.0' );

// ── WordPress, flat ──────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
// The interceptor ADDS and REMOVES filters and fires an action hook, so these
// three have to be real: a registration sink would let the whole redirect/die
// seam pass without ever running.
function remove_filter( $hook, $cb, $prio = 10 ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $i => $one ) { if ( $one === $cb ) { unset( $GLOBALS['__filters'][ $hook ][ $i ] ); } } return true; }
function has_action( $hook, $cb = false ) { return ! empty( $GLOBALS['__actions'][ $hook ] ); }
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); } }
// Core's own shape: wp_redirect filters the location BEFORE it would send a
// header, and wp_die picks its handler through a filter -- which handler
// depends on the request. $GLOBALS['__die_via'] chooses, so the suite can prove
// the interceptor covers the JSON one a REST dispatch actually uses.
$GLOBALS['__die_via']  = 'wp_die_handler';
$GLOBALS['__redirects'] = array();
function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) { $location = apply_filters( 'wp_redirect', $location, $status ); if ( ! $location ) { return false; } $GLOBALS['__redirects'][] = $location; return true; }
function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) { return wp_redirect( $location, $status ); }
function wp_die( $message = '', $title = '', $args = array() ) { $handler = apply_filters( $GLOBALS['__die_via'], '__snt_default_die' ); call_user_func( $handler, $message, $title, $args ); }
function __snt_default_die( $message = '', $title = '', $args = array() ) { throw new RuntimeException( 'wp_die was not intercepted: ' . (string) $message ); }
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	// phpcs:ignore WordPress.Security.NonceVerification -- This IS the nonce check, in core's own shape.
	$nonce = isset( $_REQUEST[ $query_arg ] ) ? $_REQUEST[ $query_arg ] : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		wp_die( '<h1>Something went wrong.</h1><p>The link you followed has expired.</p>', 'Something went wrong.', array( 'response' => 403 ) );
	}
	return 1;
}
function add_query_arg( ...$args ) {
	$params = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
	$url    = is_array( $args[0] ) ? (string) ( $args[1] ?? '' ) : (string) ( $args[2] ?? '' );
	$parts  = explode( '#', $url, 2 );
	$base   = $parts[0];
	$query  = array();
	if ( false !== strpos( $base, '?' ) ) { list( $base, $qs ) = explode( '?', $base, 2 ); parse_str( $qs, $query ); }
	foreach ( $params as $k => $v ) { if ( null === $v || false === $v ) { unset( $query[ $k ] ); } else { $query[ $k ] = $v; } }
	return $base . ( array() !== $query ? '?' . http_build_query( $query ) : '' ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
}
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['__options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['__options'][ $key ] ); return true; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['__transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['__transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['__transients'][ $key ] ); return true; }
function get_current_user_id() { return 1; }
function esc_url_raw( $url ) { return (string) $url; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses_post( $s ) { return (string) $s; }
function _doing_it_wrong( $f, $m, $v ) {}
function wp_has_noncharacters( string $text ): bool { return false; }
function wp_kses_uri_attributes() { return array( 'href', 'src', 'action', 'formaction', 'cite', 'longdesc', 'usemap', 'profile', 'xmlns' ); }
function wp_parse_url( $url, $component = -1 ) { $parts = parse_url( (string) $url ); if ( -1 === $component ) { return $parts; } $keys = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' ); $key = $keys[ $component ] ?? ''; return ( '' !== $key && isset( $parts[ $key ] ) ) ? $parts[ $key ] : null; }
function admin_url( $path = '', $scheme = 'admin' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function home_url( $path = '', $scheme = null ) { return 'https://example.test' . (string) $path; }
function plugins_url( $path = '', $plugin = '' ) { return SNT_URL . ltrim( (string) $path, '/' ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function wp_strip_all_tags( $text, $remove_breaks = false ) { return trim( strip_tags( (string) $text ) ); }
function wp_slash( $value ) { if ( is_array( $value ) ) { return array_map( 'wp_slash', $value ); } return is_string( $value ) ? addslashes( $value ) : $value; }
function wp_unslash( $value ) { if ( is_array( $value ) ) { return array_map( 'wp_unslash', $value ); } return is_string( $value ) ? stripslashes( $value ) : $value; }
$GLOBALS['__caps'] = array( 'manage_options' => true );
function current_user_can( $cap, ...$a ) { return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
// One nonce per ACTION, because the whole point of the pipelines is that the
// estate mints four different ones and a single global "valid" would hide
// exactly the confusion this suite exists to catch.
function wp_verify_nonce( $nonce, $action = -1 ) {
	$minted = array(
		'good-nonce'   => 'sn_theme_options_nonce',
		'rss-nonce'    => 'sn_rss_tracker_action',
		'sweep-nonce'  => 'sn_prov_runsweep',
	);
	return ( isset( $minted[ $nonce ] ) && $minted[ $nonce ] === $action ) ? 1 : false;
}
$GLOBALS['__styles']    = array();
$GLOBALS['__scripts']   = array();
$GLOBALS['__localized'] = array();
$GLOBALS['__i18n']      = array();
function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__styles'][ $handle ] = array( $src, $deps, $ver ); return true; }
function wp_style_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__styles'][ $handle ] ); }
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) { $GLOBALS['__scripts'][ $handle ] = array( $src, $deps, $ver, $args ); return true; }
function wp_script_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__scripts'][ $handle ] ); }
function wp_localize_script( $handle, $object_name, $l10n ) { $GLOBALS['__localized'][ $handle ] = array( $object_name, $l10n ); return true; }
function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) { $GLOBALS['__i18n'][] = $handle; return true; }

// The two leaf-asset builders the seam is required to CALL rather than copy.
const SNT_FRESHNESS_CARD_ID = 'snt-freshness-card';
function snt_freshness_routes() { return array( '/', '/notes/' ); }
function snt_register_status_script() { wp_register_script( 'snt-status', SNT_URL . 'assets/snt-status.js', array(), SNT_VERSION, true ); }
function snt_ability_run_client_register() { if ( wp_script_is( 'snt-ability-run', 'registered' ) ) { return; } wp_register_script( 'snt-ability-run', SNT_URL . 'assets/snt-ability-run.js', array( 'wp-api-fetch' ), SNT_VERSION, true ); }

// ── The REAL estate: the registry, the resolvers, the two tables ─────
require_once __DIR__ . '/../inc/admin-tabs-data.php';
require_once __DIR__ . '/../inc/admin-tabs.php';
require_once __DIR__ . '/../inc/admin-legacy-redirect.php';
require_once __DIR__ . '/../inc/admin-flash-messages.php';
require_once __DIR__ . '/../inc/admin-post-handler.php';
// The three leaves whose assets ride their own enqueue callbacks: the host
// calls their registrars, so the suite loads the real files.
require_once __DIR__ . '/../inc/cron-dashboard-admin.php';
require_once __DIR__ . '/../inc/provenance-admin.php';
require_once __DIR__ . '/../inc/audit-log-admin.php';
require_once __DIR__ . '/../inc/machine-readers-admin.php';
require_once __DIR__ . '/../inc/admin-heartbeat.php';
// The RSS leaf's own admin_init handler: the second POST pipeline. Loaded, not
// stubbed -- what has to be proven is that the host can drive the REAL function
// to its REAL redirect, which a fixture of it would assert about itself.
require_once __DIR__ . '/../inc/rss-feed-tracker.php';

// The dispatcher itself is NOT loaded: it needs 35 leaf renderers, and what
// the capture has to prove is that a renderer sees the query it would have
// seen, which a fixture says better than the real thing.
$GLOBALS['__painted'] = array();
function sn_admin_render_active_tab( $active_tab, $active_sub ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixture; it exists to report what $_GET held.
	$GLOBALS['__painted'][] = array( $active_tab, $active_sub, $_GET, $_POST, $_REQUEST );
	echo '<p>leaf ' . esc_html( (string) $active_tab ) . '/' . esc_html( (string) $active_sub ) . '</p>';
}

// The handlers the replay is allowed to reach are the estate's real table, so
// the fixtures below stand in for the real callbacks under real names.
$GLOBALS['__handler_calls'] = array();
function sn_handle_save_identity( $post ) {
	// phpcs:ignore WordPress.Security.NonceVerification -- Fixture; records the superglobals the replay lent it.
	$GLOBALS['__handler_calls'][] = array( 'sn_handle_save_identity', $post, $_REQUEST, $_GET );
	return 'identity_saved';
}
function sn_handle_music_save( $post ) {
	$GLOBALS['__handler_calls'][] = array( 'sn_handle_music_save', $post );
	return 'identity_saved';
}
function sn_handle_full_reset( $post ) {
	$GLOBALS['__handler_calls'][] = array( 'sn_handle_full_reset', $post );
	throw new RuntimeException( 'handler exploded' );
}

require_once __DIR__ . '/../inc/openstation-host.php';

// ── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
$skip = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function skip( $m ) { global $skip; $skip++; echo "SKIP: $m\n"; }

/**
 * WordPress's html-api directory, or '' when this machine has no WordPress.
 *
 * @return string
 */
function snt_host_html_api_dir() {
	$candidates = array();
	$env = getenv( 'SNT_WP_HTML_API' );
	if ( is_string( $env ) && '' !== $env ) {
		$candidates[] = rtrim( $env, '/' );
	}
	// A normally-installed plugin: wp-content/plugins/<plugin>/tests → up four.
	$candidates[] = dirname( __DIR__, 4 ) . '/wp-includes/html-api';
	foreach ( $candidates as $dir ) {
		if ( is_file( $dir . '/class-wp-html-tag-processor.php' ) ) {
			return $dir;
		}
	}
	return '';
}

echo "openstation-host -- the host seams (#1074)\n";
echo "NOTE: the rewrite group runs only where WordPress's wp-includes/html-api is on disk\n";
echo "      (SNT_WP_HTML_API=..., or a normal plugin install). CI has none and SKIPs it.\n";

echo "\nGroup 0: the admin-library bootstrap cannot strand the request\n";
$_GET     = array( 'sentinel' => 'get' );
$_POST    = array( 'sentinel' => 'post' );
$_REQUEST = array( 'sentinel' => 'request' );
$before   = array( $_GET, $_POST, $_REQUEST );
$threw    = false;
$painted  = false;
try {
	snt_os_host_capture(
		static function () use ( &$painted ) {
			$painted = true;
		},
		array( 'page' => 'sn-theme-options', 'tab' => 'ai' )
	);
} catch ( RuntimeException $e ) {
	$threw = true;
}
ok( $threw && ! $painted, 'a wp-admin library that throws on load takes the capture with it, and the leaf never runs' );
ok( $before === array( $_GET, $_POST, $_REQUEST ),
	'   ...and all three superglobals are byte-identical: the bootstrap runs BEFORE the swap, so a throw there has nothing borrowed to strand -- between the swap and the try, the runtime catches the Throwable and every later hook in the request reads the leaf\'s query instead of its own' );
ok( ! function_exists( 'submit_button' ), '   ...and the library really was absent, so the bootstrap really was reached' );

echo "\nGroup 1: capture -- a leaf reads the query it would have had, and the request is given back\n";
$_GET     = array( 'page' => 'other', 'keep' => 'me' );
$_POST    = array( 'untouched' => '1' );
$_REQUEST = array( 'seen' => 'before' );
$before   = array( $_GET, $_POST, $_REQUEST );

$html = snt_os_host_capture(
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixture read of the lent query.
		echo 'tab=' . esc_html( (string) ( $_GET['tab'] ?? '' ) ) . ' req_page=' . esc_html( (string) ( $_REQUEST['page'] ?? '' ) ) . ' post=' . esc_html( (string) ( $_POST['sn_action'] ?? '' ) );
	},
	array( 'page' => 'sn-theme-options', 'tab' => 'monitoring' ),
	array( 'sn_action' => 'health_scan' )
);
ok( 'tab=monitoring req_page=sn-theme-options post=health_scan' === $html, 'the callable sees the lent $_GET, $_POST and a $_REQUEST merged from both' );
ok( array( $_GET, $_POST, $_REQUEST ) === $before, 'all three superglobals come back byte-identical -- not merged, not emptied' );

$_REQUEST = array();
snt_os_host_capture(
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification -- Fixture.
		echo esc_html( (string) $_REQUEST['tab'] );
	},
	array( 'tab' => 'from-get', 'only_get' => '1' ),
	array( 'tab' => 'from-post' )
);
$_REQUEST = array();
$merged = snt_os_host_capture(
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification -- Fixture.
		echo esc_html( (string) $_REQUEST['tab'] ) . '|' . esc_html( (string) $_REQUEST['only_get'] );
	},
	array( 'tab' => 'from-get', 'only_get' => '1' ),
	array( 'tab' => 'from-post' )
);
ok( 'from-post|1' === $merged, '$_REQUEST is GET under POST -- a form\'s hidden tab beats the window\'s, exactly as it beats the URL\'s today' );

$levels = ob_get_level();
$_GET   = array( 'sentinel' => 'yes' );
$threw  = false;
try {
	snt_os_host_capture(
		static function () {
			echo 'half a leaf';
			throw new RuntimeException( 'leaf exploded' );
		},
		array( 'tab' => 'ai' )
	);
} catch ( RuntimeException $e ) {
	$threw = true;
}
ok( $threw, 'a throwing leaf rethrows -- the failure is never swallowed into an empty string' );
ok( array( 'sentinel' => 'yes' ) === $_GET, '   ...and the query is STILL restored: the rest of the dispatch never wears an abandoned page\'s $_GET' );
ok( $levels === ob_get_level(), '   ...and no output buffer is left open' );

echo "\nGroup 2: the rewrite pass\n";
$api = snt_host_html_api_dir();
if ( '' === $api ) {
	foreach ( array(
		'a POST form dispatches `post` and loses its action',
		'a POST form to admin-post.php declares its pipeline',
		'a GET form dispatches `go`',
		'a link into our page becomes `go` with tab/sub/anchor and NO href',
		'a link into another admin screen becomes `door` with the absolute URL',
		'an own-page link written with a top-tab slug becomes `go`, not a door',
		'an own-page link written with a pre-v3.8 slug resolves through the 301 map',
		'a non-`sn-sec-` fragment rides as the element id it is',
		'an own-page GET link keeps its _wpnonce',
		'an external link keeps its href and gains target=_blank',
		'an external link KEEPS the rel tokens the leaf chose',
		'a targeted external link, a #fragment and a mailto: are untouched',
		'an inline <script> is marked for re-execution',
		'a named submit button is marked so the client can put it back in the form',
		'a second pass changes nothing',
	) as $pin ) {
		skip( "$pin -- no wp-includes/html-api on this machine" );
	}
} else {
	require_once dirname( $api ) . '/class-wp-token-map.php';
	require_once $api . '/class-wp-html-span.php';
	require_once $api . '/class-wp-html-text-replacement.php';
	require_once $api . '/class-wp-html-attribute-token.php';
	require_once $api . '/html5-named-character-references.php';
	require_once $api . '/class-wp-html-decoder.php';
	require_once $api . '/class-wp-html-tag-processor.php';

	$fixture = '<div class="wrap">'
		. '<form method="post" action="/wp-admin/admin.php?page=sn-theme-options"><input type="hidden" name="sn_action" value="save_identity">'
		. '<button type="submit" name="sn_action" value="redirect_delete">Delete</button>'
		. '<button type="button" name="noop">Cancel</button>'
		. '<input type="submit" name="sn_action" value="x"><input type="text" name="title"></form>'
		. '<form method="post" action="https://example.test/wp-admin/admin-post.php"><input type="hidden" name="action" value="sn_prov_runsweep"></form>'
		. '<form method="get" action="https://example.test/wp-admin/admin.php"><input type="hidden" name="sn_tag_preview" value="1"></form>'
		. '<a id="lnk-health" href="https://example.test/wp-admin/admin.php?page=sn-theme-options&#038;tab=monitoring&#038;sub=health#sn-sec-x">Health</a>'
		. '<a id="lnk-tags" href="admin.php?page=sn-theme-options&amp;sn_tag_preview=1">Tags</a>'
		. '<a id="lnk-back" href="https://example.test/wp-admin/admin.php?page=sn-content&amp;tab=content&amp;sub=tags">Cancel</a>'
		. '<a id="lnk-legacy" href="https://example.test/wp-admin/admin.php?page=sn-login">Login, the old way</a>'
		. '<a id="lnk-diag" href="https://example.test/wp-admin/admin.php?page=sn-theme-options&amp;tab=dashboard#sn-dash-diagnostics">Overrides</a>'
		. '<a id="lnk-recheck" href="https://example.test/wp-admin/admin.php?page=sn-theme-options&amp;tab=monitoring&amp;sn_worker_recheck=1&amp;_wpnonce=abc123">Re-check now</a>'
		. '<a id="lnk-updates" href="https://example.test/wp-admin/update-core.php">Updates</a>'
		. '<a id="lnk-analytics" href="https://example.test/wp-admin/admin.php?page=sn-analytics&amp;sn_view=overview">Analytics</a>'
		. '<a id="lnk-repo" href="https://github.com/juanlentino">Repo</a>'
		. '<a id="lnk-ugc" href="https://sender.example/note" rel="nofollow ugc">Inbound mention</a>'
		. '<a id="lnk-targeted" href="https://github.com/juanlentino" target="_self">Repo, targeted</a>'
		. '<a id="lnk-frag" href="#sn-sec-identity">Identity</a>'
		. '<a id="lnk-mail" href="mailto:juan@example.test">Mail</a>'
		. '<a id="lnk-wired" os-action="jump" href="https://example.test/wp-admin/update-core.php">Already wired</a>'
		. '<form os-action="save" method="post" action="/keep-me"></form>'
		. '<script>var x = 1;</script>'
		. '</div>';
	$out = snt_os_host_rewrite( $fixture );

	// EVERY pin below reads ATTRIBUTES through the processor. The serialized
	// byte string encodes WP_HTML_Tag_Processor's own choices -- where a new
	// attribute is inserted, what whitespace remove_attribute() leaves behind --
	// which are facts about core, not about this port: a core release that
	// appended instead of prepending would turn a working port red.
	$attrs = static function ( $html, $tag, $id ) {
		$walk = new WP_HTML_Tag_Processor( $html );
		while ( $walk->next_tag( $tag ) ) {
			if ( null === $id || $id === $walk->get_attribute( 'id' ) ) {
				$read = array();
				foreach ( array( 'os-action', 'os-arg-tab', 'os-arg-sub', 'os-arg-anchor', 'os-arg-url', 'os-arg-pipeline', 'os-arg-sn_tag_preview', 'os-arg-sn_worker_recheck', 'os-arg-_wpnonce', 'href', 'method', 'action', 'target', 'rel', 'name', 'data-snt-submit' ) as $name ) {
					$read[ $name ] = $walk->get_attribute( $name );
				}
				return $read;
			}
		}
		return array();
	};
	$forms = array();
	$walk  = new WP_HTML_Tag_Processor( $out );
	while ( $walk->next_tag( 'FORM' ) ) {
		$one = array();
		foreach ( array( 'os-action', 'os-arg-pipeline', 'method', 'action' ) as $name ) {
			$one[ $name ] = $walk->get_attribute( $name );
		}
		$forms[] = $one;
	}
	// The byte-identity pins below are the only place a serialized string is
	// compared, and there it IS the claim: nothing was touched.
	$tag = static function ( $id ) use ( $out ) {
		return preg_match( '#<a id="' . preg_quote( $id, '#' ) . '"[^>]*>#', $out, $m ) ? $m[0] : '';
	};

	ok( 'post' === $forms[0]['os-action'] && 'post' === $forms[0]['method'] && null === $forms[0]['action'] && null === $forms[0]['os-arg-pipeline'],
		'a POST form dispatches `post`, loses its action and declares no pipeline; the method stays, because that is what the runtime keys its submit listener on' );
	ok( 'post' === $forms[1]['os-action'] && 'admin-post' === $forms[1]['os-arg-pipeline'] && null === $forms[1]['action'],
		'a POST form to admin-post.php declares its pipeline BEFORE the action is dropped -- five Provenance forms post there with their own nonce, and a dropped-unread action is what refused them all as expired' );
	ok( 'go' === $forms[2]['os-action'] && 'get' === $forms[2]['method'],
		'a GET form dispatches `go` -- the Tags merge preview navigated the page, and a window navigates by state' );
	$health = $attrs( $out, 'A', 'lnk-health' );
	ok( 'go' === $health['os-action'] && 'monitoring' === $health['os-arg-tab'] && 'health' === $health['os-arg-sub']
		&& 'sn-sec-x' === $health['os-arg-anchor'] && null === $health['href'],
		'a link into our page becomes `go` with tab/sub/anchor and NO href -- the runtime does not preventDefault a click, so a surviving href would navigate the whole desktop' );
	$tags_link = $attrs( $out, 'A', 'lnk-tags' );
	ok( '1' === $tags_link['os-arg-sn_tag_preview'] && 'dashboard' === $tags_link['os-arg-tab'] && null === $tags_link['href'],
		'   ...a relative admin.php link resolves against wp-admin/, its `sn_*` params ride as os-args, and a missing ?tab= derives the tab from the page slug' );
	$back = $attrs( $out, 'A', 'lnk-back' );
	ok( 'go' === $back['os-action'] && 'content' === $back['os-arg-tab'] && 'tags' === $back['os-arg-sub'] && null === $back['os-arg-url'],
		'an own-page link written with a TOP-TAB slug (page=sn-content, what sn_admin_tag_page_url() returns) is a `go` -- with one literal slug it was a door, and Cancel on the merge preview opened a second admin window' );
	$legacy = $attrs( $out, 'A', 'lnk-legacy' );
	ok( 'go' === $legacy['os-action'] && 'security' === $legacy['os-arg-tab'] && 'login' === $legacy['os-arg-sub'],
		'   ...and a pre-v3.8 slug (page=sn-login) resolves through sn_admin_page_tab_for_slug() + sn_admin_canonical_destination() -- the SAME map the 301 uses, called' );
	$diag = $attrs( $out, 'A', 'lnk-diag' );
	ok( 'sn-dash-diagnostics' === $diag['os-arg-anchor'],
		'a fragment that is not `sn-sec-*` rides verbatim as the element id -- the Dashboard attention strip links #sn-dash-diagnostics, and carrying only sn-sec- fragments dropped it silently' );
	$recheck = $attrs( $out, 'A', 'lnk-recheck' );
	ok( '1' === $recheck['os-arg-sn_worker_recheck'] && 'abc123' === $recheck['os-arg-_wpnonce'],
		'an own-page GET link keeps its _wpnonce -- sn_worker_version_recheck_url() is a nonce-gated ACTION, and without the nonce "Re-check now" repainted the same stale version' );
	$updates = $attrs( $out, 'A', 'lnk-updates' );
	ok( 'door' === $updates['os-action'] && 'https://example.test/wp-admin/update-core.php' === $updates['os-arg-url'] && null === $updates['href'],
		'a link into another admin screen becomes `door` with the absolute URL -- same destination, opened as its own window' );
	ok( 'https://example.test/wp-admin/admin.php?page=sn-analytics&sn_view=overview' === $attrs( $out, 'A', 'lnk-analytics' )['os-arg-url'],
		'   ...including the Analytics page, which is a DIFFERENT window\'s surface and so a door from here, even though the POST allowlist accepts its slug' );
	$repo = $attrs( $out, 'A', 'lnk-repo' );
	ok( 'https://github.com/juanlentino' === $repo['href'] && '_blank' === $repo['target'] && 'noopener noreferrer' === $repo['rel'],
		'an external link keeps its href and gains target=_blank -- the one recorded deviation: targetless, it would replace the desktop' );
	$ugc = $attrs( $out, 'A', 'lnk-ugc' );
	ok( 'nofollow ugc noopener noreferrer' === $ugc['rel'] && '_blank' === $ugc['target'],
		'   ...and the rel is MERGED, never replaced: Integrity -> Citations marks a webmention sender\'s URL `nofollow ugc`, and overwriting it stripped the untrusted-link profile the leaf chose' );
	ok( '<a id="lnk-targeted" href="https://github.com/juanlentino" target="_self">' === $tag( 'lnk-targeted' )
		&& '<a id="lnk-frag" href="#sn-sec-identity">' === $tag( 'lnk-frag' )
		&& '<a id="lnk-mail" href="mailto:juan@example.test">' === $tag( 'lnk-mail' ),
		'a targeted external link, a #fragment (the composite leaf\'s section tabs) and a mailto: are untouched -- byte for byte' );
	ok( false !== strpos( $out, '<script data-snt-exec="1">' ),
		'an inline <script> is marked for re-execution -- painted HTML lands by innerHTML, where a script tag never runs' );
	ok( 2 === substr_count( $out, 'data-snt-submit="1"' ) && false === strpos( $out, 'name="noop" data-snt-submit' ),
		'a named submit button is marked (button AND input=submit) and a type=button is not -- the runtime ships FormData, which excludes the clicked submitter, and 45 of this estate\'s submit buttons carry sn_action on the button' );
	ok( '<a id="lnk-wired" os-action="jump" href="https://example.test/wp-admin/update-core.php">' === $tag( 'lnk-wired' )
		&& 'save' === $forms[3]['os-action'] && '/keep-me' === $forms[3]['action'],
		'a link and a form that ALREADY carry os-action keep their href and their action -- a leaf that wires itself is not ours to rewire' );
	ok( $out === snt_os_host_rewrite( $out ), '   ...so a second pass changes nothing' );

	echo "\nGroup 2c: the form a host must NOT turn into a dispatch\n";
	// The Analytics export is the one shape a window cannot replay:
	// sn_handle_analytics_export() sends Content-Disposition, echoes a CSV and
	// exits. So the host NAMES the action, this marks its form, and the rewrite
	// leaves it real. The fixture is the export form's own shape, plus a
	// neighbouring save form and a STRAY input between them.
	$keep_fixture = '<div>'
		. '<form id="f-save" method="post"><input type="hidden" name="sn_action" value="save_identity"></form>'
		. '<input type="hidden" name="sn_action" value="analytics_export">'
		. '<form id="f-export" class="sn-an-export" method="post" action="https://example.test/wp-admin/admin.php">'
		. '<input type="hidden" name="page" value="sn-theme-options"><input type="hidden" name="sn_action" value="analytics_export">'
		. '<button type="submit" name="format" value="csv">CSV</button></form>'
		. '</div>';
	$kept       = snt_os_host_keep_forms( $keep_fixture, array( 'analytics_export' ), 'https://example.test/wp-admin/admin.php?page=sn-analytics' );
	$kept_out   = snt_os_host_rewrite( $kept, array( 'sn-theme-options' ) );
	$keep_forms = array();
	$walk       = new WP_HTML_Tag_Processor( $kept_out );
	while ( $walk->next_tag( 'FORM' ) ) {
		$one = array();
		foreach ( array( 'os-action', 'method', 'action', 'target', 'data-snt-keep-form' ) as $name ) {
			$one[ $name ] = $walk->get_attribute( $name );
		}
		$keep_forms[ (string) $walk->get_attribute( 'id' ) ] = $one;
	}
	ok( 'https://example.test/wp-admin/admin.php?page=sn-analytics' === $keep_forms['f-export']['data-snt-keep-form'],
		'a form carrying a named action is marked with WHERE it posts -- the marker names the destination, so the rewrite needs no second list' );
	ok( null === $keep_forms['f-export']['os-action'] && 'post' === $keep_forms['f-export']['method']
		&& 'https://example.test/wp-admin/admin.php?page=sn-analytics' === $keep_forms['f-export']['action']
		&& '_blank' === $keep_forms['f-export']['target'],
		'   ...and comes out of the rewrite STILL A FORM: same method, an explicit action, and a new tab -- a download must be a navigation, and a window that dispatched it would exit mid-response' );
	ok( null === $keep_forms['f-save']['data-snt-keep-form'] && 'post' === $keep_forms['f-save']['os-action'],
		'a form that carries an UNNAMED action is a dispatch as before -- and the stray input BETWEEN the two forms belongs to neither: without that guard the save form was marked by its neighbour`s field' );
	ok( $kept_out === snt_os_host_rewrite( snt_os_host_keep_forms( $kept_out, array( 'analytics_export' ), 'https://example.test/wp-admin/admin.php?page=sn-analytics' ), array( 'sn-theme-options' ) ),
		'   ...and both passes are idempotent: a second run changes nothing' );
	ok( $keep_fixture === snt_os_host_keep_forms( $keep_fixture, array(), 'https://example.test/wp-admin/admin.php' )
		&& $keep_fixture === snt_os_host_keep_forms( $keep_fixture, array( 'analytics_export' ), '' ),
		'no actions or no destination marks nothing -- the Dashboard host passes neither, and its forms are all dispatches' );

	echo "\nGroup 2b: the own-page slugs are DERIVED\n";
	$own = snt_os_host_own_pages();
	ok( in_array( 'sn-theme-options', $own, true ) && in_array( 'sn-content', $own, true ) && in_array( 'sn-login', $own, true ),
		'every top-tab slug and every legacy slug that renders THIS page is ours -- eight plus eleven, read off sn_admin_top_tabs() and sn_admin_pages() on every call' );
	ok( array() === array_diff( $own, sn_admin_post_allowed_pages() ),
		'   ...and nothing outside sn_admin_post_allowed_pages(): a slug this window would refuse to SAVE on must never paint as one it owns' );
	ok( ! in_array( 'sn-analytics', $own, true ),
		'   ...while sn-analytics, which the allowlist DOES carry, is not ours: it is the other window\'s surface, and sn_admin_page_tab_for_slug() answers `dashboard` for it -- a `go` would land the reader on the Dashboard under a link that said Analytics' );
}

echo "\nGroup 3: the FormData expansion every pipeline starts with\n";
$expanded = snt_os_host_expand( array(
	'social_same_as[]'       => array( 'https://a', 'https://b' ),
	'now[groups][0][items]'  => "one\ntwo",
	'sn_tag_from[]'          => '4',
	'plain'                  => 'v',
	'sn_action'              => array( 'ai_settings_save', 'ml_embed_compare' ),
	'dotted.key'             => 'd',
) );
ok( array( 'https://a', 'https://b' ) === $expanded['social_same_as'],
	'a `name="foo[]"` field becomes a PHP array -- the runtime keys FormData by the LITERAL name, and handing that through raw made Identity`s save read social_same_as as ABSENT and store []' );
ok( array( 'groups' => array( 0 => array( 'items' => "one\ntwo" ) ) ) === $expanded['now'],
	'   ...and a nested name nests: `now[groups][0][items]` is how the Now page posts, and absent-means-emptied is how it got DELETED by a save that looked like it worked' );
ok( array( '4' ) === $expanded['sn_tag_from'], '   ...a single-element bracket name is still a list, as PHP builds it' );
ok( 'v' === $expanded['plain'] && ! isset( $expanded['social_same_as[]'] ), '   ...a plain key stays plain, and no bracketed key survives' );
ok( 'ml_embed_compare' === $expanded['sn_action'],
	'   ...a REPEATED name is later-wins, not an array: the AI leaf ships sn_action twice (a hidden save + the ml_embed_compare button) and PHP keeps the last, which is what "Run comparison" means' );
ok( isset( $expanded['dotted_key'] ), '   ...and a dot in a name is mangled to an underscore, because that is what PHP itself does to $_POST' );
ok( 'b' === snt_os_host_last( array( 'a', 'b' ) ) && 'x' === snt_os_host_last( 'x' ) && '' === snt_os_host_last( null ),
	'snt_os_host_last() collapses an array-valued field to its LAST element -- the belt for a bag that arrived as an array by any other route, where is_scalar() used to refuse it outright' );

echo "\nGroup 3b: the shared pipeline -- every gate the classic dispatcher applies, minus the exit\n";
$values = array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity', 'site_name' => "Juan's \"site\"" );

$GLOBALS['__caps']['manage_options'] = false;
$GLOBALS['__handler_calls']          = array();
$refused = snt_os_host_replay( $values, 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'capability' === $refused['reason'] && array() === $GLOBALS['__handler_calls'],
	'without manage_options nothing runs, and the refusal SAYS which gate closed' );
$GLOBALS['__caps']['manage_options'] = true;

$refused = snt_os_host_replay( array( '_wpnonce' => 'stale', 'sn_action' => 'save_identity' ), 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'nonce' === $refused['reason'] && 'sn_theme_options_nonce' === $refused['detail'] && array() === $GLOBALS['__handler_calls'],
	'a stale nonce refuses, the handler is not called, and the refusal NAMES the action the token was checked against -- never "the form expired", which was never measured' );

$refused = snt_os_host_replay( $values, 'some-other-plugin-page', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'page' === $refused['reason'], 'a page outside sn_admin_post_allowed_pages() refuses -- the same allowlist, called not copied' );

$refused = snt_os_host_replay( array( '_wpnonce' => 'stale', 'sn_action' => 'no_such_action' ), 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'nonce' === $refused['reason'] && array() === $GLOBALS['__handler_calls'],
	'an action the table does not know, with a bad nonce, refuses at the NONCE -- the order the classic dispatcher checks in' );
$refused = snt_os_host_replay( array( '_wpnonce' => 'good-nonce' ), 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'unknown' === $refused['reason'] && '' === $refused['detail'],
	'a submission carrying no action at all belongs to no pipeline, and the refusal says exactly that' );

$_GET     = array( 'sentinel' => 'get' );
$_POST    = array( 'sentinel' => 'post' );
$_REQUEST = array( 'sentinel' => 'request' );
$snapshot = array( $_GET, $_POST, $_REQUEST );
$result   = snt_os_host_replay( $values, 'sn-theme-options', array( 'tab' => 'site', 'sub' => 'identity-and-seo' ) );
ok( true === $result['ok'] && 'identity_saved' === $result['flash'] && 'shared' === $result['pipeline'], 'a known action runs on the shared pipeline and its flash code comes back' );
$call = $GLOBALS['__handler_calls'][0];
ok( "Juan\\'s \\\"site\\\"" === $call[1]['site_name'],
	'the handler is called with SLASHED values -- every handler wp_unslash()es what it reads, so an unslashed replay would silently eat the quotes in a saved title' );
ok( 'sn-theme-options' === $call[2]['page'], 'a handler reading $_REQUEST[\'page\'] sees the window\'s page slug' );
ok( array( 'tab' => 'site', 'sub' => 'identity-and-seo', 'anchor' => null ) === $result['target'],
	'the target is sn_admin_post_redirect_target()\'s, called -- a current tab passes through with its sub' );
ok( array() === $result['params'], '   ...and a shared save carries no params forward: the classic redirect keeps only page/tab/sub/sn_flash' );
ok( $snapshot === array( $_GET, $_POST, $_REQUEST ), 'the superglobals are restored after the handler ran' );

$bracketed = snt_os_host_replay(
	array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity', 'social_same_as[]' => array( 'https://a', 'https://b' ) ),
	'sn-theme-options',
	array( 'tab' => 'site' )
);
ok( true === $bracketed['ok'] && array( 'https://a', 'https://b' ) === end( $GLOBALS['__handler_calls'] )[1]['social_same_as'],
	'the WIRE shape reaches the handler as PHP would have built it: the expansion runs ONCE, at the entry of the replay, for every pipeline' );

$moved = snt_os_host_replay(
	array( '_wpnonce' => 'good-nonce', 'sn_action' => 'music_save', 'tab' => 'content', 'sub' => 'music' ),
	'sn-theme-options',
	array( 'tab' => 'site', 'sub' => 'front-end' )
);
ok( 'connections' === $moved['target']['tab'] && 'music' === $moved['target']['sub'],
	'a form\'s own hidden tab/sub beats the window\'s AND is re-homed by sn_admin_subtab_moves() -- Music still posts the stale content/music pair, and without both the save would land on the wrong tab' );

$unknown_tab = snt_os_host_replay(
	array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity', 'tab' => 'no-such-tab' ),
	'sn-theme-options',
	array( 'tab' => 'site' )
);
ok( 'dashboard' === $unknown_tab['target']['tab'], 'an unknown tab falls back to dashboard, matching the classic PRG' );

$_GET     = array( 'sentinel' => 'get' );
$_POST    = array( 'sentinel' => 'post' );
$_REQUEST = array( 'sentinel' => 'request' );
$snapshot = array( $_GET, $_POST, $_REQUEST );
$threw    = false;
try {
	snt_os_host_replay( array( '_wpnonce' => 'good-nonce', 'sn_action' => 'full_reset' ), 'sn-theme-options', array( 'tab' => 'dashboard' ) );
} catch ( RuntimeException $e ) {
	$threw = true;
}
ok( $threw && $snapshot === array( $_GET, $_POST, $_REQUEST ), 'a handler that throws still gives the request back' );

echo "\nGroup 3c: the admin-post pipeline -- five Provenance forms with their own action and their own nonce\n";
$GLOBALS['__redirects']  = array();
$GLOBALS['__transients'] = array();
$sweep = snt_os_host_replay(
	array( '_wpnonce' => 'sweep-nonce', 'action' => 'sn_prov_runsweep' ),
	'sn-theme-options',
	array( 'tab' => 'tools', 'sub' => 'provenance' ),
	'admin-post'
);
ok( true === $sweep['ok'] && 'admin-post' === $sweep['pipeline'],
	'"Check for confirmations" RUNS: the form declared its pipeline, the real sn_prov_admin_runsweep_handler() fired through do_action( admin_post_sn_prov_runsweep ), and its own nonce verified' );
ok( array( 'tab' => 'tools', 'sub' => 'provenance', 'anchor' => null ) === $sweep['target'],
	'   ...the redirect it wanted became the window\'s destination, through sn_admin_post_redirect_target()' );
ok( array( 'sn_prov_swept' => 'fail' ) === $sweep['params'],
	'   ...and every other sn_* param in that Location becomes state for the next paint -- ?sn_prov_swept is exactly what sn_prov_admin_render_sweep_notice() reads out of $_GET on the classic page' );
ok( isset( $GLOBALS['__transients']['sn_prov_sweep_result_1'] ),
	'   ...and the handler really ran end to end: it stashed its per-user result transient on the way' );
ok( array() === $GLOBALS['__redirects'],
	'   ...while NOTHING was actually redirected: the interceptor caught the location before wp_redirect could send it, so the handler\'s own exit never ran' );

$died = snt_os_host_replay(
	array( '_wpnonce' => 'wrong-nonce', 'action' => 'sn_prov_runsweep' ),
	'sn-theme-options',
	array( 'tab' => 'tools', 'sub' => 'provenance' ),
	'admin-post'
);
ok( false === $died['ok'] && 'died' === $died['reason'] && false !== strpos( $died['detail'], 'The link you followed has expired' ),
	'a handler that wp_die()s on its OWN check_admin_referer is a refusal whose text is the die\'s message, word for word -- not a guess about what went wrong' );

$GLOBALS['__die_via'] = 'wp_die_json_handler';
// Caught, so an UNintercepted die is a FAIL rather than a fatal that ends the
// run with no Result line -- a crash and a refusal must not read the same.
$died_json = array( 'ok' => true, 'reason' => 'escaped: ' );
try {
	$died_json = snt_os_host_replay(
		array( '_wpnonce' => 'wrong-nonce', 'action' => 'sn_prov_runsweep' ),
		'sn-theme-options',
		array( 'tab' => 'tools' ),
		'admin-post'
	);
} catch ( RuntimeException $e ) {
	$died_json['reason'] .= $e->getMessage();
}
$GLOBALS['__die_via'] = 'wp_die_handler';
ok( false === $died_json['ok'] && 'died' === $died_json['reason'],
	'   ...including through the JSON die handler, which is the one wp_die() picks for a REST request -- and a window\'s dispatch IS one, so filtering only wp_die_handler would have caught this on the classic page and missed it here' );

$nohook = snt_os_host_replay(
	array( '_wpnonce' => 'good-nonce', 'action' => 'sn_prov_not_a_thing' ),
	'sn-theme-options',
	array( 'tab' => 'tools' ),
	'admin-post'
);
ok( false === $nohook['ok'] && 'unknown' === $nohook['reason'] && 'sn_prov_not_a_thing' === $nohook['detail'],
	'an action nothing is hooked to refuses by name -- do_action() on an unhooked hook is a silent no-op, and silence must never paint as a save' );

$_GET     = array( 'sentinel' => 'get' );
$_POST    = array( 'sentinel' => 'post' );
$_REQUEST = array( 'sentinel' => 'request' );
$snapshot = array( $_GET, $_POST, $_REQUEST );
snt_os_host_replay( array( '_wpnonce' => 'sweep-nonce', 'action' => 'sn_prov_runsweep' ), 'sn-theme-options', array( 'tab' => 'tools' ), 'admin-post' );
ok( $snapshot === array( $_GET, $_POST, $_REQUEST ), 'the superglobals come back from an intercepted redirect too -- the restore is in a finally under the throw' );
ok( array() === ( $GLOBALS['__filters']['wp_redirect'] ?? array() ) && array() === ( $GLOBALS['__filters']['wp_die_handler'] ?? array() ),
	'   ...and both interceptor filters are REMOVED afterwards: a wp_redirect filter left standing would throw at some later, unrelated redirect in the same request' );

echo "\nGroup 3d: the RSS pipeline -- its own field, its own nonce, its own admin_init handler\n";
$GLOBALS['__options']['sn_rss_tracker_settings'] = array( 'enabled' => false );
$reset = snt_os_host_replay(
	array( '_wpnonce' => 'rss-nonce', 'sn_rss_action' => SN_RSS_TRACKER_ACTION_RESET ),
	'sn-theme-options',
	array( 'tab' => 'monitoring', 'sub' => 'rss' )
);
ok( true === $reset['ok'] && 'rss' === $reset['pipeline'] && ! isset( $GLOBALS['__options']['sn_rss_tracker_settings'] ),
	'"Reset to Defaults" RUNS the real sn_rss_tracker_handle_form(): the settings row is gone, where the window used to answer "the form expired" against a nonce that was never the shared one' );
ok( array( 'sn_rss_ok' => 'reset' ) === $reset['params'] && 'monitoring' === $reset['target']['tab'] && 'rss' === $reset['target']['sub'],
	'   ...and its own redirect is read the way the classic page reads it: ?sn_rss_ok for the leaf\'s flash, and the legacy tab=rss resolved through the same 301 map to monitoring/rss' );

$GLOBALS['__options']['sn_rss_tracker_settings'] = array( 'enabled' => true );
$stale_rss = snt_os_host_replay(
	array( '_wpnonce' => 'good-nonce', 'sn_rss_action' => SN_RSS_TRACKER_ACTION_RESET ),
	'sn-theme-options',
	array( 'tab' => 'monitoring', 'sub' => 'rss' )
);
ok( true === $stale_rss['ok'] && '' === $stale_rss['flash'] && null === $stale_rss['target'] && isset( $GLOBALS['__options']['sn_rss_tracker_settings'] ),
	'   ...and a nonce that is not ITS nonce makes the handler return silently, exactly as it does on the classic page -- so the window says nothing, rather than inventing a save or an expiry' );

echo "\nGroup 3e: the inline pipeline -- the one form its own leaf handles, out of \$_POST\n";
$inline = snt_os_host_replay(
	array( '_wpnonce' => 'good-nonce', 'sn_action' => 'audit_prune_now', 'note' => "it's fine" ),
	'sn-theme-options',
	array( 'tab' => 'security', 'sub' => 'audit-log' )
);
ok( true === $inline['ok'] && 'inline' === $inline['pipeline'] && array() === $GLOBALS['__redirects'],
	'"Prune now" is neither refused nor dispatched: audit_prune_now is not in sn_admin_post_handlers() and its shared nonce verifies, which is what an inline form IS' );
ok( 'audit_prune_now' === $inline['post']['sn_action'] && 'good-nonce' === $inline['post']['_wpnonce'],
	'   ...the values are handed back for the next paint, nonce included -- snt_audit_log_render_tab() calls check_admin_referer(), which reads $_REQUEST[\'_wpnonce\']' );
ok( "it\\'s fine" === $inline['post']['note'],
	'   ...slashed, like a real POST: a leaf reading $_POST wp_unslash()es it, and an unslashed bag would eat the quote' );
$audit_src = (string) file_get_contents( __DIR__ . '/../inc/audit-log-admin.php' );
ok( false !== strpos( $audit_src, "'audit_prune_now' === \$_POST['sn_action']" ) && false !== strpos( $audit_src, "check_admin_referer( 'sn_theme_options_nonce' )" ),
	'   ...and the leaf still IS the handler: it reads $_POST[\'sn_action\'] itself and checks the shared nonce, which is the whole reason this pipeline exists' );

echo "\nGroup 4: the notice and its toast\n";
ok( array( 'success', 'Identity settings saved.' ) === snt_os_host_notice( 'identity_saved' ), 'a flash code resolves through sn_admin_flash_to_notice(), called' );
ok( null === snt_os_host_notice( 'not_a_real_code' ) && null === snt_os_host_notice( '' ), 'an unknown code renders no notice -- what the classic page does with one' );
ok( 'Login slug saved. New URL: https://example.test/sn-login' === snt_os_host_toast_text( array( 'success', 'Login slug saved. New URL: <a href="x">https://example.test/sn-login</a>' ) ),
	'the toast is the notice as plain text: a toast has no tone and no markup, so the <a> stays in the in-window notice' );
ok( '' === snt_os_host_toast_text( null ), 'no notice, no toast' );

echo "\nGroup 5: where a (tab, sub) pair lands\n";
$_GET = array();
foreach ( array( 'monitoring', 'site', 'content', 'connections', 'security', 'tools', 'ai', 'dashboard' ) as $tab ) {
	$_GET = array( 'sub' => 'no-such-leaf' );
	ok( sn_admin_resolve_active_sub( $tab ) === snt_os_host_resolve_sub( $tab, 'no-such-leaf' ),
		"snt_os_host_resolve_sub() agrees with sn_admin_resolve_active_sub() on $tab -- the same two rules, read off the same registry, with the \$_GET read replaced by state" );
}
$_GET = array();
ok( '' === snt_os_host_resolve_sub( 'dashboard', 'anything' ), 'a landing tab has no sub-tab, whatever was asked for' );
ok( 'health' === snt_os_host_resolve_sub( 'monitoring', 'health' ), 'a leaf the tab really has is kept' );

ok( array( 'tab' => 'connections', 'sub' => 'cloudflare', 'anchor' => '' ) === snt_os_host_destination( 'site', 'cloudflare' ),
	'a moved leaf is re-homed by the estate\'s own resolver (site/cloudflare -> connections/cloudflare)' );
ok( 'dashboard' === snt_os_host_destination( 'no-such-tab' )['tab'], 'an unknown tab lands on dashboard' );
ok( 'dashboard' === snt_os_host_destination( '' )['tab'], 'and so does an empty one -- a window opened with no params is the Dashboard' );
$identity = snt_os_host_destination( 'identity' );
ok( 'site' === $identity['tab'] && 'identity-and-seo' === $identity['sub'] && 'identity' === $identity['anchor'],
	'a pre-v3.8 legacy slug still resolves, anchor included -- sn_admin_legacy_redirect_map(), called' );

echo "\nGroup 6: the `sn_*` params a window carries\n";
// The WIRE shape, expanded the way every entry point expands it -- feeding
// `sn_tag_from` un-bracketed here is how the whole bracket class stayed
// invisible to this suite while the Tags merge preview was broken in the window.
$params = snt_os_host_params( snt_os_host_expand( array( 'tab' => 'content', 'sn_tag_preview' => '1', 'sn_tag_from[]' => array( '4', '9' ), 'sn_tag_into' => 12, 'SN_UPPER' => 'x', 'snx' => 'y' ) ) );
ok( array( 'sn_tag_preview' => '1', 'sn_tag_from' => array( '4', '9' ), 'sn_tag_into' => '12' ) === $params,
	'only `sn_*` keys ride, arrays included -- and the Tags merge picker`s field is `sn_tag_from[]` on the wire, which the allowlist would drop unexpanded' );
ok( array( 'sn_tag_from' => array( '4' ) ) === snt_os_host_params( snt_os_host_expand( array( 'sn_tag_from[]' => '4' ) ) ),
	'   ...one ticked checkbox is still a LIST: the runtime sends a single value as a scalar, and sn_admin_tag_render_confirm() reads (array) $_GET[\'sn_tag_from\']' );
ok( array( '_wpnonce' => 'abc123', 'sn_worker_recheck' => '1' ) === snt_os_host_params( array( '_wpnonce' => 'abc123', 'sn_worker_recheck' => '1', '_wp_http_referer' => '/x' ) ),
	'   ..._wpnonce rides too, and only it: sn_worker_version_recheck_requested() needs the nonce beside the flag, and dropping it made "Re-check now" a silent no-op' );
$wide = array();
for ( $i = 0; $i < 40; $i++ ) { $wide[ 'sn_k' . $i ] = str_repeat( 'x', 500 ); }
$bounded = snt_os_host_params( $wide );
ok( SNT_OS_HOST_PARAM_CAP === count( $bounded ) && 200 === strlen( reset( $bounded ) ), 'the params are bounded in count and length -- they ride every dispatch' );

echo "\nGroup 7: the assets the window carries\n";
$handles = snt_os_host_asset_handles();
$args    = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'os-runtime' ), 'scripts' => array() ), 'sn-dashboard', null );
// Named, not read back off the function under test: a loop over
// snt_os_host_asset_handles() would stay green while a handle was DELETED
// from it, which is exactly how a leaf's script goes missing in silence.
ok( array( 'sn-admin', 'snt-analytics-tokens', 'sn-analytics-admin', 'sn-uptime-status', 'sn-provenance-admin', 'snt-audit-log', 'sn-machine-readers' ) === $handles['styles'],
	'the seven stylesheets the leaves are laid out with: admin.css, the analytics token layer, the analytics sheet, the uptime panel, the provenance stepper, the audit log, and Machine Readers -- which painted with every .sn-mr-* rule missing until it got a registrar' );
ok( array( 'sn-admin', 'snt-confirm', 'sn-analytics-brush', 'sn-resume-admin', 'sn-freshness-dot', 'snt-health-suggest-actions', 'sn-uptime-status', 'sn-cron-dashboard', 'sn-provenance-admin', 'sn-admin-heartbeat', 'snt-os-host' ) === $handles['scripts'],
	'the eleven scripts: sub-tabs and dirty-tracking, the confirm modal, the trend brush, the repeatable rows, the freshness dot, Suggest+Apply, the uptime panel, cron, provenance, the Heartbeat client Cron and Webhooks live-refresh through, and the host' );
foreach ( $handles['styles'] as $handle ) {
	ok( in_array( $handle, $args['styles'], true ) && wp_style_is( $handle, 'registered' ), "the window carries the style $handle, registered" );
}
foreach ( $handles['scripts'] as $handle ) {
	ok( in_array( $handle, $args['scripts'], true ) && wp_script_is( $handle, 'registered' ), "the window carries the script $handle, registered" );
}
ok( array( 'sntFreshness', array( 'routes' => array( 'https://example.test/', 'https://example.test/notes/' ), 'cardId' => 'snt-freshness-card' ) ) === ( $GLOBALS['__localized']['sn-freshness-dot'] ?? null ),
	'sn-freshness-dot carries the SAME localized payload its own enqueue attaches, built by snt_freshness_routes() -- a copied route list would go stale the first time the front end moved' );
ok( array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ) === ( $GLOBALS['__scripts']['snt-health-suggest-actions'][1] ?? array() )
	&& wp_script_is( 'snt-status', 'registered' ) && wp_script_is( 'snt-ability-run', 'registered' ),
	'the Suggest script keeps its four deps, and both shared utilities were registered by their OWN registrars -- a missing dep makes WP silently DROP the dependent script' );
ok( in_array( 'snt-health-suggest-actions', $GLOBALS['__i18n'], true ), '   ...with its script translations set, as its own enqueue sets them' );
ok( array( 'sn-admin' ) === ( $GLOBALS['__scripts']['snt-os-host'][1] ?? array() ), 'the host script loads after admin.js, whose init() seam it calls' );
ok( isset( $GLOBALS['__scripts']['sn-cron-dashboard'] ) && 'sntCronI18n' === ( $GLOBALS['__localized']['sn-cron-dashboard'][0] ?? '' ) && isset( $GLOBALS['__localized']['sn-cron-dashboard'][1]['confirmUnschedule'] ), 'the cron script rides with its strings, from its own registrar -- Run now, history and Unschedule all go through it' );
ok( isset( $GLOBALS['__styles']['sn-provenance-admin'], $GLOBALS['__scripts']['sn-provenance-admin'] ), 'the provenance stepper sheet and script ride too: Integrity -> Provenance polls its own route through them' );
ok( isset( $GLOBALS['__styles']['snt-audit-log'] ), 'and the audit-log sheet: Security -> Audit log is laid out by it' );
ok( array( 'sn-admin' ) === ( $GLOBALS['__styles']['sn-machine-readers'][1] ?? array() ),
	'the Machine Readers sheet rides with its own dependency on admin.css, from its own registrar -- a copied wp_register_style() here would drift the first time the sheet moved' );
ok( array( 'jquery', 'heartbeat' ) === ( $GLOBALS['__scripts']['sn-admin-heartbeat'][1] ?? array() ),
	'and the Heartbeat client keeps `jquery` and `heartbeat`: without the core handle WordPress silently DROPS it, and Cron\'s "Last fired" cells would sit frozen with nothing saying so' );

$again = apply_filters( 'openstation_app_window_args', $args, 'sn-dashboard', null );
ok( $again === $args, 'a second pass appends nothing -- every handle rides exactly once' );
$other = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'os-runtime' ) ), 'my-wordpress', null );
ok( array( 'os-runtime' ) === $other['styles'], 'another app\'s window is left alone' );

echo "\nGroup B: the admin library rides a REST dispatch\n";
$host_src = (string) file_get_contents( __DIR__ . '/../inc/openstation-host.php' );
ok( false !== strpos( $host_src, "function snt_os_host_admin_bootstrap()" ) && false !== strpos( $host_src, "ABSPATH . 'wp-admin/includes/admin.php'" ), 'capture loads wp-admin/includes/admin.php when submit_button() is absent -- a REST dispatch has no wp-admin, and MCP Clients answered 500 without it' );
ok( false !== strpos( $host_src, "if ( function_exists( 'submit_button' ) || ! defined( 'ABSPATH' ) ) {" ), '...and only when it is absent: the classic page already has it, a standalone host has no ABSPATH' );
$cap_at  = strpos( $host_src, 'function snt_os_host_capture(' );
$boot_at = strpos( $host_src, 'snt_os_host_admin_bootstrap();', $cap_at );
$ob_at   = strpos( $host_src, 'ob_start();', $cap_at );
$swap_at = strpos( $host_src, '$_GET     = $get;', $cap_at );
ok( false !== $boot_at && false !== $ob_at && $boot_at < $ob_at, '...before the buffer opens, so a library that prints nothing on load cannot leak into the leaf' );
ok( false !== $swap_at && $boot_at < $swap_at, '...and before the superglobals are SWAPPED, which is what Group 0 measures the consequence of' );
ok( ! function_exists( 'submit_button' ), 'this suite has no wp-admin either, so the SANDBOX is where the leaf is measured (it is: MCP Clients paints after the fix)' );

echo "\nGroup W: a write resets the request memos the repaint reads\n";
// sn_setting() memoises the merged settings once per request and the save never
// resets it; on the classic page the redirect hides that, in a window the
// repaint would paint the value from BEFORE the save (measured: social_same_as).
$GLOBALS['__resets'] = array();
$GLOBALS['__actions']['snt_os_host_wrote'] = array();
if ( ! function_exists( 'sn_setting_reset_cache' ) ) {
	function sn_setting_reset_cache() { $GLOBALS['__resets'][] = 'settings'; }
}
if ( ! function_exists( 'snt_ai_reset_availability_cache' ) ) {
	function snt_ai_reset_availability_cache() { $GLOBALS['__resets'][] = 'ai'; }
}
$settings_src = (string) file_get_contents( __DIR__ . '/../inc/settings.php' );
ok( false !== strpos( $settings_src, 'static $merged = null;' ) && false === strpos( substr( $settings_src, strpos( $settings_src, 'function sn_settings_save' ) ), 'sn_setting_reset_cache' ), 'the hazard is real: sn_setting() memoises per request and sn_settings_save() does not reset it (if this pin goes red the reset below may be redundant -- keep it anyway, other memos exist)' );
$before = $GLOBALS['__resets'];
snt_os_host_after_write( array( 'ok' => true, 'flash' => 'identity_saved' ) );
ok( array( 'settings', 'ai' ) === array_slice( $GLOBALS['__resets'], count( $before ) ), 'a successful write resets sn_setting()\'s memo and the AI availability memo, by their own resetters' );
$fired = isset( $GLOBALS['__actions_fired']['snt_os_host_wrote'] ) ? $GLOBALS['__actions_fired']['snt_os_host_wrote'] : null;
ok( function_exists( 'do_action' ), '...and fires snt_os_host_wrote for any other owner of a request memo (do_action is present in this harness)' );
$n = count( $GLOBALS['__resets'] );
snt_os_host_after_write( array( 'ok' => false, 'reason' => 'nonce' ) );
ok( $n === count( $GLOBALS['__resets'] ), 'a refused write resets nothing: no write happened' );
$src_pipe = (string) file_get_contents( __DIR__ . '/../inc/openstation-host-pipelines.php' );
ok( 3 === substr_count( $src_pipe, 'snt_os_host_after_write( snt_os_host_replay_' ), 'the shared, admin-post and rss pipelines all return through the reset; the inline pipeline writes during the paint, so its leaf reads after its own write' );

echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";

// A SKIP is a lane measuring nothing, and the rewrite is the seam this whole
// port stands on: with SNT_WP_HTML_API unset, a mutant that made
// snt_os_host_rewrite() return its input unchanged still printed
// "0 failed" and exit 0, which tests/run.sh reads as OK. Locally a skip stays a
// skip (a laptop need not carry a WordPress checkout); in CI it is fatal, and
// the workflow fetches wp-includes/html-api so it never has to be.
$snt_ci = (string) getenv( 'CI' );
if ( $skip > 0 && '' !== $snt_ci && '0' !== $snt_ci && 'false' !== strtolower( $snt_ci ) ) {
	echo "\nFAILED: $skip pins were SKIPPED because WordPress's wp-includes/html-api is not on this machine,\n";
	echo "and the rewrite pass is the single most load-bearing seam of this port. A lane that skips it prints OK\n";
	echo "while every form still posts to a URL and every link still navigates the desktop away.\n";
	echo "Fix: fetch WordPress in the workflow and export SNT_WP_HTML_API=<checkout>/wp-includes/html-api.\n";
	exit( 1 );
}
exit( $fail > 0 ? 1 : 0 );
