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

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '13.104.0' );

// ── WordPress, flat ──────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
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
function wp_verify_nonce( $nonce, $action = -1 ) { return ( 'good-nonce' === $nonce && 'sn_theme_options_nonce' === $action ) ? 1 : false; }
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
		'a GET form dispatches `go`',
		'a link into our page becomes `go` with tab/sub/anchor and NO href',
		'a link into another admin screen becomes `door` with the absolute URL',
		'an external link keeps its href and gains target=_blank',
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
		. '<form method="get" action="https://example.test/wp-admin/admin.php"><input type="hidden" name="sn_tag_preview" value="1"></form>'
		. '<a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&#038;tab=monitoring&#038;sub=health#sn-sec-x">Health</a>'
		. '<a href="admin.php?page=sn-theme-options&amp;sn_tag_preview=1">Tags</a>'
		. '<a href="https://example.test/wp-admin/update-core.php">Updates</a>'
		. '<a href="https://example.test/wp-admin/admin.php?page=sn-analytics&amp;sn_view=overview">Analytics</a>'
		. '<a href="https://github.com/juanlentino">Repo</a>'
		. '<a href="https://github.com/juanlentino" target="_self">Repo, targeted</a>'
		. '<a href="#sn-sec-identity">Identity</a>'
		. '<a href="mailto:juan@example.test">Mail</a>'
		. '<a os-action="jump" href="https://example.test/wp-admin/update-core.php">Already wired</a>'
		. '<form os-action="save" method="post" action="/keep-me"></form>'
		. '<script>var x = 1;</script>'
		. '</div>';
	$out = snt_os_host_rewrite( $fixture );
	// The tag processor decides its own attribute ORDER, which is not a fact
	// about the port; every pin below reads the one anchor it is about.
	$tag = static function ( $label ) use ( $out ) {
		return preg_match( '#<a [^>]*>' . preg_quote( $label, '#' ) . '</a>#', $out, $m ) ? $m[0] : '';
	};

	ok( false !== strpos( $out, '<form os-action="post" method="post" >' ) && false === strpos( $out, 'action="/wp-admin/admin.php?page=sn-theme-options"' ),
		'a POST form dispatches `post` and loses its action; the method stays, because that is what the runtime keys its submit listener on' );
	ok( false !== strpos( $out, '<form os-action="go" method="get"' ),
		'a GET form dispatches `go` -- the Tags merge preview navigated the page, and a window navigates by state' );
	$health = $tag( 'Health' );
	ok( false !== strpos( $health, 'os-action="go"' ) && false !== strpos( $health, 'os-arg-tab="monitoring"' )
		&& false !== strpos( $health, 'os-arg-sub="health"' ) && false !== strpos( $health, 'os-arg-anchor="x"' )
		&& false === strpos( $health, 'href' ),
		'a link into our page becomes `go` with tab/sub/anchor and NO href -- the runtime does not preventDefault a click, so a surviving href would navigate the whole desktop' );
	$tags_link = $tag( 'Tags' );
	ok( false !== strpos( $tags_link, 'os-arg-sn_tag_preview="1"' ) && false !== strpos( $tags_link, 'os-arg-tab="dashboard"' ) && false === strpos( $tags_link, 'href' ),
		'   ...a relative admin.php link resolves against wp-admin/, its `sn_*` params ride as os-args, and a missing ?tab= derives the tab from the page slug' );
	$updates = $tag( 'Updates' );
	ok( false !== strpos( $updates, 'os-action="door"' ) && false !== strpos( $updates, 'os-arg-url="https://example.test/wp-admin/update-core.php"' ) && false === strpos( $updates, 'href' ),
		'a link into another admin screen becomes `door` with the absolute URL -- same destination, opened as its own window' );
	ok( false !== strpos( $tag( 'Analytics' ), 'os-arg-url="https://example.test/wp-admin/admin.php?page=sn-analytics&amp;sn_view=overview"' ),
		'   ...including the Analytics page, which is a DIFFERENT window\'s surface and so a door from here' );
	$repo = $tag( 'Repo' );
	ok( false !== strpos( $repo, 'href="https://github.com/juanlentino"' ) && false !== strpos( $repo, 'target="_blank"' ) && false !== strpos( $repo, 'rel="noopener noreferrer"' ),
		'an external link keeps its href and gains target=_blank -- the one recorded deviation: targetless, it would replace the desktop' );
	ok( '<a href="https://github.com/juanlentino" target="_self">Repo, targeted</a>' === $tag( 'Repo, targeted' )
		&& '<a href="#sn-sec-identity">Identity</a>' === $tag( 'Identity' )
		&& '<a href="mailto:juan@example.test">Mail</a>' === $tag( 'Mail' ),
		'a targeted external link, a #fragment (the composite leaf\'s section tabs) and a mailto: are untouched -- byte for byte' );
	ok( false !== strpos( $out, '<script data-snt-exec="1">' ),
		'an inline <script> is marked for re-execution -- painted HTML lands by innerHTML, where a script tag never runs' );
	ok( 2 === substr_count( $out, 'data-snt-submit="1"' ) && false === strpos( $out, 'name="noop" data-snt-submit' ),
		'a named submit button is marked (button AND input=submit) and a type=button is not -- the runtime ships FormData, which excludes the clicked submitter, and 45 of this estate\'s submit buttons carry sn_action on the button' );
	ok( '<a os-action="jump" href="https://example.test/wp-admin/update-core.php">Already wired</a>' === $tag( 'Already wired' )
		&& false !== strpos( $out, '<form os-action="save" method="post" action="/keep-me">' ),
		'a link and a form that ALREADY carry os-action keep their href and their action -- a leaf that wires itself is not ours to rewire' );
	ok( $out === snt_os_host_rewrite( $out ), '   ...so a second pass changes nothing' );
}

echo "\nGroup 3: replay -- every gate the classic dispatcher applies, minus the exit\n";
$values = array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity', 'site_name' => "Juan's \"site\"" );

$GLOBALS['__caps']['manage_options'] = false;
$GLOBALS['__handler_calls']          = array();
$refused = snt_os_host_replay( $values, 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'capability' === $refused['reason'] && array() === $GLOBALS['__handler_calls'],
	'without manage_options nothing runs, and the refusal SAYS which gate closed' );
$GLOBALS['__caps']['manage_options'] = true;

$refused = snt_os_host_replay( array( '_wpnonce' => 'stale', 'sn_action' => 'save_identity' ), 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'nonce' === $refused['reason'] && array() === $GLOBALS['__handler_calls'], 'a stale nonce refuses, and the handler is not called' );

$refused = snt_os_host_replay( $values, 'some-other-plugin-page', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'page' === $refused['reason'], 'a page outside sn_admin_post_allowed_pages() refuses -- the same allowlist, called not copied' );

$refused = snt_os_host_replay( array( '_wpnonce' => 'good-nonce', 'sn_action' => 'no_such_action' ), 'sn-theme-options', array( 'tab' => 'site' ) );
ok( false === $refused['ok'] && 'unknown' === $refused['reason'] && array() === $GLOBALS['__handler_calls'], 'an action outside sn_admin_post_handlers() refuses, and NOTHING is called' );

$_GET     = array( 'sentinel' => 'get' );
$_POST    = array( 'sentinel' => 'post' );
$_REQUEST = array( 'sentinel' => 'request' );
$snapshot = array( $_GET, $_POST, $_REQUEST );
$result   = snt_os_host_replay( $values, 'sn-theme-options', array( 'tab' => 'site', 'sub' => 'identity-and-seo' ) );
ok( true === $result['ok'] && 'identity_saved' === $result['flash'], 'a known action runs and its flash code comes back' );
$call = $GLOBALS['__handler_calls'][0];
ok( "Juan\\'s \\\"site\\\"" === $call[1]['site_name'],
	'the handler is called with SLASHED values -- every handler wp_unslash()es what it reads, so an unslashed replay would silently eat the quotes in a saved title' );
ok( 'sn-theme-options' === $call[2]['page'], 'a handler reading $_REQUEST[\'page\'] sees the window\'s page slug' );
ok( array( 'tab' => 'site', 'sub' => 'identity-and-seo', 'anchor' => null ) === $result['target'],
	'the target is sn_admin_post_redirect_target()\'s, called -- a current tab passes through with its sub' );
ok( $snapshot === array( $_GET, $_POST, $_REQUEST ), 'the superglobals are restored after the handler ran' );

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
$params = snt_os_host_params( array( 'tab' => 'content', 'sn_tag_preview' => '1', 'sn_tag_from' => array( '4', '9' ), 'sn_tag_into' => 12, 'SN_UPPER' => 'x', 'snx' => 'y' ) );
ok( array( 'sn_tag_preview' => '1', 'sn_tag_from' => array( '4', '9' ), 'sn_tag_into' => '12' ) === $params,
	'only `sn_*` keys ride, arrays included (the Tags merge picker posts sn_tag_from[]); everything else is dropped' );
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
ok( array( 'sn-admin', 'snt-analytics-tokens', 'sn-analytics-admin', 'sn-uptime-status', 'sn-provenance-admin', 'snt-audit-log' ) === $handles['styles'],
	'the four stylesheets the leaves are laid out with: admin.css, the analytics token layer, the analytics sheet, the uptime panel' );
ok( array( 'sn-admin', 'snt-confirm', 'sn-analytics-brush', 'sn-resume-admin', 'sn-freshness-dot', 'snt-health-suggest-actions', 'sn-uptime-status', 'sn-cron-dashboard', 'sn-provenance-admin', 'snt-os-host' ) === $handles['scripts'],
	'the eight scripts: sub-tabs and dirty-tracking, the confirm modal, the trend brush, the repeatable rows, the freshness dot, Suggest+Apply, the uptime panel, and the host' );
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

$again = apply_filters( 'openstation_app_window_args', $args, 'sn-dashboard', null );
ok( $again === $args, 'a second pass appends nothing -- every handle rides exactly once' );
$other = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'os-runtime' ) ), 'my-wordpress', null );
ok( array( 'os-runtime' ) === $other['styles'], 'another app\'s window is left alone' );

echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";
exit( $fail > 0 ? 1 : 0 );
