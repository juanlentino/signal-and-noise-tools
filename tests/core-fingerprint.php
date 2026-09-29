<?php
/**
 * Guard: the core fingerprint (CVE-2026-87902 follow-up, 2026-09-29).
 *
 * Pins inc/core-fingerprint.php and the `core` / `runtime` keys it feeds into
 * signal-noise/get-deploy-status:
 *   1. every emoji hook core registers is removed (front, admin, embed, feed, mail);
 *   2. a core asset's ver=$wp_version becomes an opaque salted token, and a
 *      plugin/theme ver or a non-matching ver is left alone;
 *   3. the_generator is empty for every type core prints;
 *   4. the REAL deploy-status builder returns core {current, latest, state,
 *      auto_updates, reason} read from the CACHED update_core transient only,
 *      and runtime {php, register_argc_argv}; the remote twin drops runtime.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$GLOBALS['wp_version']  = '7.1.2';
$GLOBALS['__cf_hooks']  = array(); // hook => list of [cb, priority]
$GLOBALS['__cf_removed'] = array(); // "hook|cb|priority"
$GLOBALS['__cf_transient'] = false;
$GLOBALS['__cf_net']    = 0;       // any live update check would bump this
$GLOBALS['__ab']        = array();

function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__cf_hooks'][ $h ][] = array( $cb, $p ); return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { return add_action( $h, $cb, $p, $a ); }
function remove_action( $h, $cb, $p = 10 ) { $GLOBALS['__cf_removed'][] = "$h|$cb|$p"; return true; }
function remove_filter( $h, $cb, $p = 10 ) { return remove_action( $h, $cb, $p ); }
function apply_filters( $h, $v, ...$args ) {
	foreach ( $GLOBALS['__cf_hooks'][ $h ] ?? array() as $row ) { $v = call_user_func( $row[0], $v, ...$args ); }
	return $v;
}
function __return_false() { return false; }
function __return_empty_string() { return ''; }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s; }
function get_site_transient( $k ) { return 'update_core' === $k ? $GLOBALS['__cf_transient'] : false; }
function get_site_option( $k, $d = false ) { return $d; }
function get_option( $k, $d = false ) { return $d; }
function wp_version_check() { $GLOBALS['__cf_net']++; }
function wp_remote_get() { $GLOBALS['__cf_net']++; return array(); }
function wp_register_ability( $slug, $cfg ) { $GLOBALS['__ab'][ $slug ] = $cfg; return true; }
function snt_deploy_status_for( $pkg ) { return array( 'current' => '1.0.0', 'latest' => '1.0.0', 'state' => 'ok' ); }
function snt_deploy_workers_status( $o = array() ) { return array(); }
function sn_remote_analytics_allows( $s ) { return true; }
function current_user_can( $c ) { return true; }
class WP_Error { public function __construct( $c = '', $m = '', $d = array() ) {} }

require_once __DIR__ . '/../inc/core-fingerprint.php';
require_once __DIR__ . '/../inc/deploy-core-status.php';
require_once __DIR__ . '/../inc/abilities-system.php';
require_once __DIR__ . '/../inc/abilities-remote-set.php';
foreach ( $GLOBALS['__cf_hooks']['wp_abilities_api_init'] ?? array() as $row ) { $row[0](); }

$pass = 0;
$fail = 0;
function cf_ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; }
}
function cf_hooked( $hook, $cb ) {
	foreach ( $GLOBALS['__cf_hooks'][ $hook ] ?? array() as $row ) { if ( $row[0] === $cb ) { return true; } }
	return false;
}

echo "core fingerprint\n\n";

// 1. Emoji. The removals run on init AND admin_init: admin-filters.php loads
// after init in wp-admin, so an init-only removal leaves the admin hooks on.
cf_ok( cf_hooked( 'init', 'snt_core_fp_unhook' ), 'the unhook runs on init' );
cf_ok( cf_hooked( 'admin_init', 'snt_core_fp_unhook' ), 'the unhook runs on admin_init (admin-filters.php loads after init)' );
if ( function_exists( 'snt_core_fp_unhook' ) ) { snt_core_fp_unhook(); }
foreach ( array(
	'wp_head|print_emoji_detection_script|7',
	'embed_head|print_emoji_detection_script|10',
	'admin_print_scripts|print_emoji_detection_script|10',
	'wp_enqueue_scripts|wp_enqueue_emoji_styles|10',
	'admin_enqueue_scripts|wp_enqueue_emoji_styles|10',
	'enqueue_embed_scripts|wp_enqueue_emoji_styles|10',
	'wp_print_styles|print_emoji_styles|10',
	'admin_print_styles|print_emoji_styles|10',
	'the_content_feed|wp_staticize_emoji|10',
	'comment_text_rss|wp_staticize_emoji|10',
	'wp_mail|wp_staticize_emoji_for_email|10',
	'wp_head|wp_generator|10',
) as $want ) {
	cf_ok( in_array( $want, $GLOBALS['__cf_removed'], true ), "removed $want" );
}
cf_ok( false === apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/17.0.2/svg/' ), 'emoji_svg_url is false' );
$mce = apply_filters( 'tiny_mce_plugins', array( 'wordpress', 'wpemoji', 'wplink' ) );
cf_ok( ! in_array( 'wpemoji', $mce, true ) && in_array( 'wplink', $mce, true ), 'tiny_mce_plugins drops wpemoji and nothing else' );

// 2. ver on core assets.
$js = 'https://juanlentino.com/wp-includes/js/wp-emoji-release.min.js?ver=7.1.2';
$tok = apply_filters( 'script_loader_src', $js, 'x' );
cf_ok( false === strpos( $tok, '7.1.2' ), 'a core script ver=7.1.2 no longer carries the version' );
cf_ok( 1 === preg_match( '/\?ver=[0-9a-f]{10}$/', $tok ), 'it carries an opaque token instead (not empty)' );
$css = apply_filters( 'style_loader_src', 'https://juanlentino.com/wp-admin/css/forms.min.css?ver=7.1.2&x=1', 'y' );
cf_ok( false === strpos( $css, '7.1.2' ) && false !== strpos( $css, '&x=1' ), 'a wp-admin style is tokenised, other args kept' );
$load = 'https://juanlentino.com/wp-admin/load-scripts.php?c=0&load%5Bchunk_0%5D=jquery-core,utils&ver=7.1.2';
cf_ok( false === strpos( apply_filters( 'script_loader_src', $load, 'z' ), '7.1.2' ), 'the admin concat loader is tokenised' );
foreach ( array(
	'https://juanlentino.com/wp-content/plugins/signal-and-noise-tools/assets/admin.js?ver=7.1.2' => 'plugin ver equal to core stays',
	'https://juanlentino.com/wp-content/themes/signal-and-noise/style.css?ver=13.4.0'             => 'theme ver stays',
	'https://juanlentino.com/wp-includes/js/jquery/jquery.min.js?ver=3.7.1'                       => 'non-matching core ver stays',
	'https://juanlentino.com/wp-includes/js/x.js?ver=7.1.20'                                      => 'a longer version that starts with 7.1.2 stays',
) as $src => $label ) {
	cf_ok( $src === apply_filters( 'script_loader_src', $src, 'h' ), $label );
}
$GLOBALS['wp_version'] = '7.1.3';
$tok2 = apply_filters( 'script_loader_src', 'https://juanlentino.com/wp-includes/js/wp-emoji-release.min.js?ver=7.1.3', 'x' );
cf_ok( $tok2 !== $tok && 1 === preg_match( '/\?ver=[0-9a-f]{10}$/', $tok2 ), 'the token changes when core changes (browsers refetch)' );
$GLOBALS['wp_version'] = '7.1.2';

// 2b. Concat URLs read default_version, not the src filters.
foreach ( array( 'wp_default_scripts' => 'WP_Scripts', 'wp_default_styles' => 'WP_Styles' ) as $hook => $cls ) {
	$deps = (object) array( 'default_version' => '7.1.2' );
	foreach ( $GLOBALS['__cf_hooks'][ $hook ] ?? array() as $row ) { call_user_func( $row[0], $deps ); }
	cf_ok( 1 === preg_match( '/^[0-9a-f]{10}$/', (string) $deps->default_version ), "$cls default_version reads the token after $hook" );
}

// 3. the_generator, for every type core prints.
foreach ( array( 'html', 'xhtml', 'rss2', 'atom', 'rdf', 'comment', 'export' ) as $type ) {
	cf_ok( '' === apply_filters( 'the_generator', '<meta name="generator" content="WordPress 7.1.2" />', $type ), "the_generator is empty for $type" );
}

// 4. The REAL builder.
function cf_offer( $response, $version ) { return (object) array( 'response' => $response, 'version' => $version, 'current' => $version ); }
function cf_core( $transient ) {
	$GLOBALS['__cf_transient'] = $transient;
	$out = snt_ability_get_deploy_status( null );
	return is_array( $out ) ? ( $out['core'] ?? array() ) : array();
}
$c = cf_core( false );
cf_ok( array( 'current', 'latest', 'state', 'offer', 'auto_updates', 'reason' ) === array_keys( $c ), 'core carries exactly the six keys' );
cf_ok( 'unknown' === ( $c['state'] ?? '' ) && '' === ( $c['offer'] ?? 'x' ) && '' !== ( $c['reason'] ?? '' ), 'missing transient: unknown, offer empty, with a reason' );
cf_ok( '7.1.2' === ( $c['current'] ?? '' ), 'current is $wp_version' );
$c = cf_core( (object) array( 'updates' => array( cf_offer( 'latest', '7.1.2' ) ) ) );
cf_ok( 'ok' === ( $c['state'] ?? '' ) && '' === ( $c['offer'] ?? 'x' ) && '7.1.2' === ( $c['latest'] ?? '' ), 'none newer: ok, offer empty, latest = current' );
$c = cf_core( (object) array( 'updates' => array( cf_offer( 'upgrade', '7.2' ), cf_offer( 'latest', '7.1.2' ) ) ) );
cf_ok( 'behind' === ( $c['state'] ?? '' ) && 'major' === ( $c['offer'] ?? '' ) && '7.2' === ( $c['latest'] ?? '' ), 'only an upgrade offer: behind/major, latest 7.2' );
$c = cf_core( (object) array( 'updates' => array( cf_offer( 'autoupdate', '7.1.3' ) ) ) );
cf_ok( 'behind' === ( $c['state'] ?? '' ) && 'point' === ( $c['offer'] ?? '' ) && SNT_CORE_POINT_REASON === ( $c['reason'] ?? '' ), 'only a point offer: behind/point, with the owner\'s reason text' );
$c = cf_core( (object) array( 'updates' => array( cf_offer( 'upgrade', '7.2' ), cf_offer( 'autoupdate', '7.1.3' ) ) ) );
cf_ok( 'behind' === ( $c['state'] ?? '' ) && 'point' === ( $c['offer'] ?? '' ) && '7.2' === ( $c['latest'] ?? '' ), 'both offers: behind/point wins, latest is the highest offer' );
$c = cf_core( (object) array( 'updates' => array( cf_offer( 'autoupdate', '7.1.2' ) ) ) );
cf_ok( 'ok' === ( $c['state'] ?? '' ), 'an offer equal to current is not an update' );
cf_ok( 0 === $GLOBALS['__cf_net'], 'no live update check was made' );
cf_ok( in_array( $c['auto_updates'] ?? '', array( 'minor', 'all', 'off' ), true ), 'auto_updates is minor|all|off' );

$full = snt_ability_get_deploy_status( null );
cf_ok( isset( $full['runtime'] ) && PHP_VERSION === ( $full['runtime']['php'] ?? '' ), 'runtime.php is PHP_VERSION' );
cf_ok( is_bool( $full['runtime']['register_argc_argv'] ?? null ), 'runtime.register_argc_argv is a bool' );

// auto_updates derivation: constants cannot vary in one process, so the pure
// mapping is pinned directly.
cf_ok( 'off' === snt_core_auto_updates_mode( false, false, true, true ), 'DISALLOW_FILE_MODS: off' );
cf_ok( 'off' === snt_core_auto_updates_mode( true, true, true, true ), 'updater disabled: off' );
cf_ok( 'all' === snt_core_auto_updates_mode( true, false, true, true ), 'major + minor: all' );
cf_ok( 'minor' === snt_core_auto_updates_mode( true, false, false, true ), 'minor only: minor' );
cf_ok( 'off' === snt_core_auto_updates_mode( true, false, false, false ), 'neither: off' );
$GLOBALS['__cf_hooks']['allow_minor_auto_core_updates'][] = array( '__return_false', 10 );
cf_ok( 'off' === snt_core_auto_updates(), 'the allow_minor_auto_core_updates filter is honoured' );

// Remote twin: core rides, runtime does not.
$remote = $GLOBALS['__ab']['signal-noise/remote-get-deploy-status'] ?? array();
$rout   = isset( $remote['execute_callback'] ) ? call_user_func( $remote['execute_callback'], null ) : array();
cf_ok( isset( $rout['core'] ) && ! array_key_exists( 'runtime', $rout ), 'remote twin returns core and never runtime' );
cf_ok( isset( $remote['output_schema']['properties']['core'] ) && ! isset( $remote['output_schema']['properties']['runtime'] ), 'remote schema declares core, not runtime' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
