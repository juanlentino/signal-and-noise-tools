<?php
/**
 * Standalone fixture tests for inc/desktop-mode-nav-ids.php — the one-time
 * carry of a user's shell placement from the auto-imported menu ids to the
 * app ids (v13.105.1).
 *
 * The owner's live report: Analytics had been moved to the desktop; after
 * v13.105.0 it sat in the dock. Measured in the sandbox: the preference was
 * keyed `toplevel_page_sn-analytics` (the menu's hook name, which is how the
 * shell keys an auto-imported tile) while the app entry is `sn-analytics`,
 * and the shell honours `navPlacement['sn-analytics']` as soon as it exists.
 *
 * The shell stubs below mirror what matters of the real sanitizer
 * (includes/os-settings.php in OpenStation 1.1.6): the getter returns a FULLY
 * SHAPED array, keys pass `sanitize_key()`, `navPlacement` values are the
 * four-value enum, the lists are deduplicated, and the saver is
 * sanitize-and-REPLACE (not merge). The shell itself is not in this repo;
 * the sandbox run in PR #1081 is the end-to-end check.
 *
 * Run: php tests/desktop-mode-nav-ids.php
 *
 * @since plugin v13.105.1
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'OPENSTATION_OS_SETTINGS_META_KEY', 'desktop_mode_os_settings' );

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][ $p ][] = $cb; }

$GLOBALS['__options']       = array();
$GLOBALS['__option_writes'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ]  = $value;
	$GLOBALS['__option_writes'][] = array( 'key' => $key, 'value' => $value, 'autoload' => $autoload );
	return true;
}

$GLOBALS['__meta'] = array();
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['__meta'][ $uid ][ $key ] ?? ( $single ? '' : array() ); }
function update_user_meta( $uid, $key, $val ) { $GLOBALS['__meta'][ $uid ][ $key ] = $val; return true; }

$GLOBALS['__get_users_calls'] = array();
function get_users( $args = array() ) {
	$GLOBALS['__get_users_calls'][] = $args;
	$out = array();
	foreach ( $GLOBALS['__meta'] as $uid => $keys ) {
		if ( isset( $keys[ $args['meta_key'] ?? '' ] ) ) { $out[] = $uid; }
	}
	return $out;
}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }

require SNT_PATH . 'inc/desktop-mode-nav-ids.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── A. Registration ──────────────────────────────────────────────────
ok( in_array( 'snt_os_nav_ids_maybe_migrate', $GLOBALS['__actions']['admin_init'][20] ?? array(), true ),
	'the sweep is registered on admin_init at priority 20 (after the shell\'s own runner at 10)' );
ok( ! in_array( 'snt_os_nav_ids_maybe_migrate', $GLOBALS['__actions']['admin_init'][10] ?? array(), true ),
	'CONTROL: it is not registered at priority 10' );
ok( ! isset( $GLOBALS['__actions']['init'] ), 'nothing is registered on init — the desktop and the phone shell are admin pages' );

// ── B. The map names real things ─────────────────────────────────────
$map = snt_os_nav_id_map();
$menu = (string) file_get_contents( SNT_PATH . 'inc/admin-menu.php' );
$analytics = (string) file_get_contents( SNT_PATH . 'inc/analytics-dashboard-page.php' );
ok( 1 === preg_match( "/add_menu_page\\(\\s*'Signal & Noise',\\s*'Signal & Noise',\\s*'manage_options',\\s*'sn-theme-options',/s", $menu ),
	'the Dashboard menu slug is sn-theme-options (inc/admin-menu.php)' );
ok( false !== strpos( $analytics, "const SNT_ANALYTICS_PAGE_SLUG = 'sn-analytics';" ),
	'the Analytics menu slug is sn-analytics (SNT_ANALYTICS_PAGE_SLUG)' );
ok( isset( $map['toplevel_page_sn-theme-options'], $map['toplevel_page_sn-analytics'] ),
	'the old ids are the two menus\' hook names, toplevel_page_<slug>' );
foreach ( array( 'sn-dashboard', 'sn-analytics' ) as $app ) {
	$src = (string) file_get_contents( SNT_PATH . "apps/$app/$app.os.php" );
	ok( false !== strpos( $src, "const APP_ID = '$app';" ), "apps/$app declares APP_ID '$app'" );
	ok( in_array( $app, $map, true ), "the map carries onto the app id '$app'" );
}
ok( 2 === count( $map ), 'the map has exactly the two hosts — nothing else was re-keyed' );

// ── C. The owner's fixture: the pure carry ───────────────────────────
function shaped( array $over = array() ) {
	return array_merge( array(
		'wallpaper'             => 'galaxy',
		'navPlacement'          => array(),
		'navOrder'              => array(),
		'mobileTabs'            => array(),
		'dockPromotedPositions' => array(),
	), $over );
}
$owner = shaped( array(
	'navPlacement' => array( 'toplevel_page_sn-analytics' => 'desktop' ),
	'navOrder'     => array( 'toplevel_page_sn-analytics', 'sn-dashboard' ),
) );
$out = snt_os_nav_ids_carry( $owner, $map );
ok( is_array( $out ), 'the owner\'s fixture changes' );
ok( 'desktop' === ( $out['navPlacement']['sn-analytics'] ?? null ), 'navPlacement: the app id gains the desktop placement' );
ok( 'desktop' === ( $out['navPlacement']['toplevel_page_sn-analytics'] ?? null ), 'navPlacement: the old key STAYS (unknown ids survive in the shell; the menu comes back if the apps go)' );
ok( array( 'sn-analytics', 'sn-dashboard' ) === $out['navOrder'], 'navOrder: the old id is replaced IN PLACE, so the item keeps its slot' );
ok( 'galaxy' === $out['wallpaper'] && array() === $out['mobileTabs'] && array() === $out['dockPromotedPositions'],
	'every other field is untouched' );

// ── D. A preference the new id already has wins ──────────────────────
$reset = shaped( array( 'navPlacement' => array( 'toplevel_page_sn-analytics' => 'desktop', 'sn-analytics' => 'rail' ) ) );
ok( null === snt_os_nav_ids_carry( $reset, $map ), 'navPlacement: a value the user set on the app id since the update is NOT overwritten (nothing moves)' );
$both = shaped( array( 'navOrder' => array( 'sn-analytics', 'toplevel_page_sn-analytics' ) ) );
ok( null === snt_os_nav_ids_carry( $both, $map ), 'navOrder: a list that already names the app id is left alone' );

// ── E. Positions and the phone tab bar ───────────────────────────────
$pos = shaped( array( 'dockPromotedPositions' => array( 'toplevel_page_sn-analytics' => array( 'x' => 240, 'y' => 120 ) ) ) );
$out = snt_os_nav_ids_carry( $pos, $map );
ok( array( 'x' => 240, 'y' => 120 ) === ( $out['dockPromotedPositions']['sn-analytics'] ?? null ), 'dockPromotedPositions: the dragged spot follows the id, so the icon does not reset to (0,0)' );
$pos2 = shaped( array( 'dockPromotedPositions' => array( 'toplevel_page_sn-analytics' => array( 'x' => 240, 'y' => 120 ), 'sn-analytics' => array( 'x' => 1, 'y' => 2 ) ) ) );
ok( null === snt_os_nav_ids_carry( $pos2, $map ), 'dockPromotedPositions: a spot the app id already has is kept' );
$tabs = shaped( array( 'mobileTabs' => array( 'sn-dashboard', 'toplevel_page_sn-analytics', 'posts' ) ) );
$out  = snt_os_nav_ids_carry( $tabs, $map );
ok( array( 'sn-dashboard', 'sn-analytics', 'posts' ) === $out['mobileTabs'], 'mobileTabs: a pinned phone tab keeps its slot under the app id' );

// ── F. Both hosts, and nothing to do ─────────────────────────────────
$dash = shaped( array( 'navPlacement' => array( 'toplevel_page_sn-theme-options' => 'both' ) ) );
$out  = snt_os_nav_ids_carry( $dash, $map );
ok( 'both' === ( $out['navPlacement']['sn-dashboard'] ?? null ), 'the Dashboard\'s old id carries too' );
ok( null === snt_os_nav_ids_carry( shaped(), $map ), 'a user with no preference on either old id: nothing moves' );
ok( null === snt_os_nav_ids_carry( 'not-an-array', $map ), 'a non-array is refused' );
ok( null === snt_os_nav_ids_carry( shaped( array( 'navPlacement' => 'garbage' ) ), $map ), 'a malformed map field is refused, not iterated' );

// ── G. The sweep waits for the shell ─────────────────────────────────
snt_os_nav_ids_maybe_migrate();
ok( array() === $GLOBALS['__get_users_calls'], 'without the shell\'s settings functions no users are queried' );
ok( array() === $GLOBALS['__option_writes'], 'and the option is NOT written — the sweep is not marked done on a site that has not activated the shell' );
ok( false === snt_os_nav_ids_migrate_user( 1 ), 'the per-user carry reports false without the shell' );

// ── H. The sweep with the shell present ──────────────────────────────
// Declared at runtime, after G, so "absent" above was real.
if ( ! function_exists( 'openstation_get_os_settings' ) ) {
	$GLOBALS['__saves'] = array();
	function os_sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = shaped();
		$clean['wallpaper'] = isset( $raw['wallpaper'] ) ? (string) $raw['wallpaper'] : 'galaxy';
		foreach ( (array) ( $raw['navPlacement'] ?? array() ) as $k => $v ) {
			if ( is_string( $k ) && in_array( $v, array( 'both', 'rail', 'desktop', 'hidden' ), true ) ) { $clean['navPlacement'][ sanitize_key( $k ) ] = $v; }
		}
		foreach ( (array) ( $raw['dockPromotedPositions'] ?? array() ) as $k => $v ) {
			if ( is_string( $k ) && is_array( $v ) && isset( $v['x'], $v['y'] ) ) { $clean['dockPromotedPositions'][ sanitize_key( $k ) ] = array( 'x' => (int) $v['x'], 'y' => (int) $v['y'] ); }
		}
		foreach ( array( 'navOrder', 'mobileTabs' ) as $f ) {
			foreach ( (array) ( $raw[ $f ] ?? array() ) as $id ) {
				$id = sanitize_key( $id );
				if ( '' !== $id && ! in_array( $id, $clean[ $f ], true ) ) { $clean[ $f ][] = $id; }
			}
		}
		return $clean;
	}
	function openstation_get_os_settings( $uid ) { return os_sanitize( get_user_meta( (int) $uid, OPENSTATION_OS_SETTINGS_META_KEY, true ) ); }
	function openstation_save_os_settings( $uid, $settings ) { $GLOBALS['__saves'][] = (int) $uid; return update_user_meta( (int) $uid, OPENSTATION_OS_SETTINGS_META_KEY, os_sanitize( $settings ) ); }
}

$GLOBALS['__meta'][1][ OPENSTATION_OS_SETTINGS_META_KEY ] = array(
	'wallpaper'             => 'dark',
	'navPlacement'          => array( 'toplevel_page_sn-analytics' => 'desktop' ),
	'navOrder'              => array( 'toplevel_page_sn-analytics', 'sn-dashboard' ),
	'dockPromotedPositions' => array( 'toplevel_page_sn-analytics' => array( 'x' => 240, 'y' => 120 ) ),
);
$GLOBALS['__meta'][2][ OPENSTATION_OS_SETTINGS_META_KEY ] = array( 'wallpaper' => 'dark' );      // has the meta, nothing to carry
$GLOBALS['__meta'][3]['unrelated_meta']                    = 'x';                                  // no shell meta at all
$GLOBALS['__meta'][4][ OPENSTATION_OS_SETTINGS_META_KEY ] = array( 'navPlacement' => array( 'toplevel_page_sn-analytics' => 'desktop', 'sn-analytics' => 'rail' ) );

snt_os_nav_ids_maybe_migrate();
ok( 1 === count( $GLOBALS['__get_users_calls'] ), 'with the shell present the users are queried once' );
$q = $GLOBALS['__get_users_calls'][0] ?? array();
ok( 'ID' === ( $q['fields'] ?? null ) && OPENSTATION_OS_SETTINGS_META_KEY === ( $q['meta_key'] ?? null ) && 'EXISTS' === ( $q['meta_compare'] ?? null ),
	'only users who HAVE the shell meta are swept (fields ID, meta EXISTS), like the shell\'s own migrations' );
ok( array( 1 ) === $GLOBALS['__saves'], 'exactly the user with something to carry is saved (user 2 has nothing, 3 has no meta, 4 already chose)' );
$m1 = $GLOBALS['__meta'][1][ OPENSTATION_OS_SETTINGS_META_KEY ];
ok( 'desktop' === ( $m1['navPlacement']['sn-analytics'] ?? null ) && 'desktop' === ( $m1['navPlacement']['toplevel_page_sn-analytics'] ?? null ),
	'user 1: the placement is carried and the old key kept, through the shell\'s saver' );
ok( array( 'sn-analytics', 'sn-dashboard' ) === $m1['navOrder'], 'user 1: navOrder rewritten in place' );
ok( array( 'x' => 240, 'y' => 120 ) === ( $m1['dockPromotedPositions']['sn-analytics'] ?? null ), 'user 1: the desktop position follows' );
ok( 'dark' === $m1['wallpaper'], 'user 1: the rest of the meta survives a sanitize-and-replace save' );
ok( ! isset( $GLOBALS['__meta'][3][ OPENSTATION_OS_SETTINGS_META_KEY ] ), 'user 3: no meta is created for a user who never had the shell\'s' );
ok( 'rail' === $GLOBALS['__meta'][4][ OPENSTATION_OS_SETTINGS_META_KEY ]['navPlacement']['sn-analytics'], 'user 4: the choice made on the app id since the update stands' );
ok( array( array( 'key' => SNT_OS_NAV_ID_MIGRATION_OPTION, 'value' => SNT_OS_NAV_ID_MIGRATION_VERSION, 'autoload' => true ) ) === $GLOBALS['__option_writes'],
	'the option records the completed version, autoloaded (it is read on every admin request)' );

// ── I. Once per version ──────────────────────────────────────────────
snt_os_nav_ids_maybe_migrate();
ok( 1 === count( $GLOBALS['__get_users_calls'] ), 'a second admin_init does not sweep again' );
ok( 1 === count( $GLOBALS['__option_writes'] ), 'and does not rewrite the option' );
$GLOBALS['__options'][ SNT_OS_NAV_ID_MIGRATION_OPTION ] = SNT_OS_NAV_ID_MIGRATION_VERSION + 1;
snt_os_nav_ids_maybe_migrate();
ok( 1 === count( $GLOBALS['__get_users_calls'] ), 'a site recorded at a HIGHER version is left alone' );
$GLOBALS['__options'][ SNT_OS_NAV_ID_MIGRATION_OPTION ] = 0;
snt_os_nav_ids_maybe_migrate();
ok( 2 === count( $GLOBALS['__get_users_calls' ] ), 'a site below the version sweeps again (the map can grow)' );
ok( array( 1 ) === $GLOBALS['__saves'], 'CONTROL: the re-sweep saves nobody — the carry is idempotent' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
