<?php
/**
 * Guard: the core fingerprint (CVE-2026-87902 follow-up, 2026-09-29).
 *
 * Pins inc/core-fingerprint.php and the `core` / `runtime` keys it feeds into
 * signal-noise/get-deploy-status:
 *   1. emoji is stock on the public site (front, embed, feed, mail) and off in
 *      wp-admin (admin hooks + TinyMCE wpemoji); every emoji script URL core
 *      builds carries the token, not the version;
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
function get_option( $k, $d = false ) { return $GLOBALS['__cf_opt'][ $k ] ?? $d; }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__cf_opt'][ $k ] = $v; return true; } }
function wp_version_check() { $GLOBALS['__cf_net']++; }
$GLOBALS['__cf_sched'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h ) { return $GLOBALS['__cf_sched'][ $h ] ?? false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $t, $h ) { $GLOBALS['__cf_sched'][ $h ] = $t; return true; } }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function wp_remote_get() { $GLOBALS['__cf_net']++; return array(); }
function wp_register_ability( $slug, $cfg ) { $GLOBALS['__ab'][ $slug ] = $cfg; return true; }
function snt_deploy_status_for( $pkg ) { return array( 'current' => '1.0.0', 'latest' => '1.0.0', 'state' => 'ok' ); }
function snt_deploy_workers_status( $o = array() ) { return array(); }
function sn_remote_analytics_allows( $s ) { return true; }
function current_user_can( $c ) { return true; }
class WP_Error { public function __construct( $c = '', $m = '', $d = array() ) {} }

require_once __DIR__ . '/../inc/core-fingerprint.php';
require __DIR__ . '/lib/core-fingerprint-emoji-stubs.php';
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

// 1. Emoji: public site stock, wp-admin off.
cf_ok( cf_hooked( 'init', 'snt_core_fp_unhook' ), 'the generator unhook runs on init' );
cf_ok( cf_hooked( 'admin_init', 'snt_core_fp_admin_emoji_off' ), 'the admin emoji removal runs on admin_init (admin-filters.php loads after init)' );
cf_ok( ! cf_hooked( 'init', 'snt_core_fp_admin_emoji_off' ), 'the admin emoji removal does not run on init (would reach the front end)' );
if ( function_exists( 'snt_core_fp_unhook' ) ) { snt_core_fp_unhook(); }
$front_removed = $GLOBALS['__cf_removed'];
cf_ok( in_array( 'wp_head|wp_generator|10', $front_removed, true ), 'removed wp_head|wp_generator|10' );
foreach ( $front_removed as $gone ) {
	cf_ok( false === stripos( $gone, 'emoji' ), "front end removes no emoji hook ($gone)" );
}
if ( function_exists( 'snt_core_fp_admin_emoji_off' ) ) { snt_core_fp_admin_emoji_off(); }
$admin_removed = array_diff( $GLOBALS['__cf_removed'], $front_removed );
foreach ( array(
	'admin_print_scripts|print_emoji_detection_script|10',
	'admin_enqueue_scripts|wp_enqueue_emoji_styles|10',
	'admin_print_styles|print_emoji_styles|10',
) as $want ) {
	cf_ok( in_array( $want, $admin_removed, true ), "admin removes $want" );
}
cf_ok( 3 === count( $admin_removed ), 'admin removes exactly those three (feed, mail, front, embed stay stock)' );
foreach ( array( 'wp_head|print_emoji_detection_script|7', 'embed_head|print_emoji_detection_script|10', 'wp_enqueue_scripts|wp_enqueue_emoji_styles|10', 'the_content_feed|wp_staticize_emoji|10', 'comment_text_rss|wp_staticize_emoji|10', 'wp_mail|wp_staticize_emoji_for_email|10' ) as $keep ) {
	cf_ok( ! in_array( $keep, $GLOBALS['__cf_removed'], true ), "kept $keep" );
}
foreach ( array( 'emoji_svg_url', 'emoji_url' ) as $h ) {
	cf_ok( empty( $GLOBALS['__cf_hooks'][ $h ] ), "nothing hooks $h (stock s.w.org URL)" );
}
$mce = apply_filters( 'tiny_mce_plugins', array( 'wordpress', 'wpemoji', 'wplink' ) );
cf_ok( ! in_array( 'wpemoji', $mce, true ) && in_array( 'wplink', $mce, true ), 'tiny_mce_plugins drops wpemoji and nothing else' );

// 1b. The emoji script URLs, exactly as core 7.1 builds them in
// _print_emoji_detection_script() (wp-includes/formatting.php):
//   :6062  $version = 'ver=' . get_bloginfo( 'version' );
//   :6067  apply_filters( 'script_loader_src', includes_url( "js/wp-emoji.js?$version" ), 'wpemoji' )           (SCRIPT_DEBUG)
//   :6069  apply_filters( 'script_loader_src', includes_url( "js/twemoji.js?$version" ), 'twemoji' )            (SCRIPT_DEBUG)
//   :6074  apply_filters( 'script_loader_src', includes_url( "js/wp-emoji-release.min.js?$version" ), 'concatemoji' )
foreach ( array( 'wpemoji' => 'wp-emoji.js', 'twemoji' => 'twemoji.js', 'concatemoji' => 'wp-emoji-release.min.js' ) as $handle => $file ) {
	$u = apply_filters( 'script_loader_src', "https://juanlentino.com/wp-includes/js/$file?ver=7.1.2", $handle );
	cf_ok( false === strpos( $u, '7.1.2' ) && 1 === preg_match( '/\?ver=[0-9a-f]{10}$/', $u ), "emoji $handle URL carries the token" );
}

// 1c. Run core's REAL _print_emoji_detection_script(), lifted from the 7.1
// formatting.php into a namespace so its WP calls land on the stubs below.
// SCRIPT_DEBUG becomes a variable so both branches run in one process.
$fmt = getenv( 'SNT_WP_HTML_API' ) ? dirname( (string) getenv( 'SNT_WP_HTML_API' ) ) . '/formatting.php' : '';
if ( '' !== $fmt && is_readable( $fmt ) ) {
	$code = (string) file_get_contents( $fmt );
	preg_match( '/^function _print_emoji_detection_script\(\) \{.*?^\}/ms', $code, $m );
	cf_ok( ! empty( $m[0] ), 'core _print_emoji_detection_script() found in formatting.php' );
	eval( 'namespace cfcore; ' . str_replace( 'SCRIPT_DEBUG', '$GLOBALS[\'__cf_sd\']', $m[0] ?? '' ) );
	foreach ( array( false => 'concatemoji', true => 'wpemoji + twemoji' ) as $sd => $label ) {
		$GLOBALS['__cf_sd'] = (bool) $sd;
		ob_start();
		\cfcore\_print_emoji_detection_script();
		$out = (string) ob_get_clean();
		preg_match_all( '#/wp-includes/js/[^"?]+\?ver=([^"&]+)#', $out, $vers );
		cf_ok( count( $vers[1] ) === ( $sd ? 2 : 1 ) && ! array_diff( array_map( 'strlen', $vers[1] ), array( 10 ) ), "real core print ($label): every emoji URL carries the token" );
		cf_ok( false === strpos( $out, '7.1.2' ), "real core print ($label): 7.1.2 appears nowhere, emoji CDN paths included" );
	}
} elseif ( getenv( 'CI' ) ) {
	cf_ok( false, 'core formatting.php missing beside SNT_WP_HTML_API under CI (fetch it in the workflow)' );
} else {
	echo "  SKIP - real core emoji print: no formatting.php beside SNT_WP_HTML_API\n";
}

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

// Refill after the theme's purge (2026-10-01): the flush empties update_core; a one-off cron re-runs the check.
$hooked = array_column( $GLOBALS['__cf_hooks']['sn_after_full_cache_flush'] ?? array(), 0 );
cf_ok( in_array( 'snt_core_refill_after_flush', $hooked, true ) && in_array( 'snt_core_version_refill', array_column( $GLOBALS['__cf_hooks']['snt_core_version_refill'] ?? array(), 0 ), true ), 'refill: hooked to the purge, and the one-off event runs the refill' );
$GLOBALS['__cf_sched'] = array(); $net0 = $GLOBALS['__cf_net'];
snt_core_refill_after_flush( array( 'object_cache' => false ) );
cf_ok( array() === $GLOBALS['__cf_sched'], 'refill: a purge that did not flush the object cache schedules nothing' );
snt_core_refill_after_flush( array( 'object_cache' => true ) );
$t = $GLOBALS['__cf_sched']['snt_core_version_refill'] ?? 0;
$GLOBALS['__cf_sched']['snt_core_version_refill'] = 12345; // an event already queued
snt_core_refill_after_flush( array( 'object_cache' => true ) );
cf_ok( $t > time() && 12345 === $GLOBALS['__cf_sched']['snt_core_version_refill'] && $net0 === $GLOBALS['__cf_net'], 'refill: an object-cache flush schedules one check a minute out, once, without fetching in the purge request' );
snt_core_version_refill();
cf_ok( $net0 + 1 === $GLOBALS['__cf_net'], 'refill: the event runs WordPress\'s version check' );

// Guard (2026-10-02): Breeze's nightly purge reaches Cloudways' app purge, which clears Redis
// too, with no flush hook of ours on the way. Any emptier routes through one state: update_core
// missing. The 5-minute warm pass notices it and queues the same refill, at most once an hour.
cf_ok( in_array( array( 'snt_core_refill_guard', 5 ), $GLOBALS['__cf_hooks']['snt_deploy_workers_warm'] ?? array(), true ), 'guard: rides the 5-minute warm pass at priority 5, ahead of the worker probe' );
$GLOBALS['__cf_sched'] = array(); $GLOBALS['__cf_opt'] = array(); $net0 = $GLOBALS['__cf_net'];
$GLOBALS['__cf_transient'] = (object) array( 'updates' => array() );
snt_core_refill_guard();
cf_ok( array() === $GLOBALS['__cf_sched'], 'guard: update_core present schedules nothing' );
$GLOBALS['__cf_transient'] = false;
snt_core_refill_guard();
$t = $GLOBALS['__cf_sched']['snt_core_version_refill'] ?? 0;
cf_ok( $t > 0 && $t <= time() + 1 && $net0 === $GLOBALS['__cf_net'], 'guard: update_core missing queues the refill now, without fetching in the warm pass' );
unset( $GLOBALS['__cf_sched']['snt_core_version_refill'] );
snt_core_refill_guard();
cf_ok( ! isset( $GLOBALS['__cf_sched']['snt_core_version_refill'] ), 'guard: a second pass inside the hour queues nothing (at most one wordpress.org call an hour)' );
$GLOBALS['__cf_opt']['snt_core_refill_guard_at'] = time() - HOUR_IN_SECONDS - 1;
snt_core_refill_guard();
cf_ok( isset( $GLOBALS['__cf_sched']['snt_core_version_refill'] ), 'guard: after the hour, a still-missing update_core queues again' );
$GLOBALS['__cf_opt']['snt_core_refill_guard_at'] = 0;
$GLOBALS['__cf_sched']['snt_core_version_refill'] = 999;
snt_core_refill_guard();
cf_ok( 999 === $GLOBALS['__cf_sched']['snt_core_version_refill'] && 0 === $GLOBALS['__cf_opt']['snt_core_refill_guard_at'], 'guard: a refill already queued (the purge path) is left alone and not counted' );

// 2026-10-06: the guard and the Core row read the cache through ONE test. A
// cached value that is not a usable check (no updates list) made the row read
// "unknown" while the guard, which only asked "is it false?", stood down.
foreach ( array( 'an object with no updates list' => (object) array( 'last_checked' => time() ), 'a non-object' => 'stale-string', 'updates that is not a list' => (object) array( 'updates' => 'x' ) ) as $label => $shape ) {
	$GLOBALS['__cf_sched'] = array(); $GLOBALS['__cf_opt'] = array();
	$GLOBALS['__cf_transient'] = $shape;
	cf_ok( 'unknown' === snt_core_status()['state'], "row: $label reads unknown" );
	snt_core_refill_guard();
	cf_ok( isset( $GLOBALS['__cf_sched']['snt_core_version_refill'] ), "guard: $label queues the refill too, the same test the row uses" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
