<?php
/**
 * Signal & Noise Tools — carry a user's shell placement from the old menu ids
 * to the app ids.
 *
 * v13.104.0 and v13.105.0 replaced the two auto-imported admin menus on the
 * dock with App Framework apps. The shell keys a user's placement preference
 * (`navPlacement`, `navOrder`, `mobileTabs`, `dockPromotedPositions` in the
 * per-user `desktop_mode_os_settings` meta) by NAV ID, and an app's id is not
 * its menu's: the menu was `toplevel_page_sn-analytics`, the app is
 * `sn-analytics`. The owner had moved Analytics to the desktop; after the
 * update it sat in the dock, because the preference named an item nothing
 * paints any more and the app entry had no preference of its own. This module
 * copies each preference onto the new id, once per site, and leaves the old
 * key in place: the shell keeps unknown ids so a deactivated plugin's setting
 * survives reactivation, and the menu comes back if the apps ever go.
 *
 * @package SignalNoiseTools
 * @since 13.105.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option recording the migration version this site has completed. */
const SNT_OS_NAV_ID_MIGRATION_OPTION = 'snt_os_nav_id_migration';

/** Bump when the map gains an entry; the sweep runs once more. */
const SNT_OS_NAV_ID_MIGRATION_VERSION = 1;

/**
 * Old nav id → new nav id.
 *
 * The old ids are WordPress's hook names for our two top-level menus
 * (`toplevel_page_<slug>`; inc/admin-menu.php registers `sn-theme-options`,
 * inc/analytics-dashboard-page.php registers `SNT_ANALYTICS_PAGE_SLUG`), which
 * is how the shell keyed the auto-imported tiles. The new ids are the apps'
 * `APP_ID` constants.
 *
 * @return array<string,string>
 */
function snt_os_nav_id_map() {
	return array(
		'toplevel_page_sn-theme-options' => 'sn-dashboard',
		'toplevel_page_sn-analytics'     => 'sn-analytics',
	);
}

/**
 * Carry every preference keyed by an old id onto its new id.
 *
 * Pure. A preference the new id already has is never overwritten: the user
 * may have re-set it since the update, and that later choice wins. Map-shaped
 * fields (`navPlacement`, `dockPromotedPositions`) gain the new key beside the
 * old one; list-shaped fields (`navOrder`, `mobileTabs`) have the old id
 * replaced in place, so the item keeps its slot in the order or on the phone
 * tab bar. A list that already names the new id is left alone.
 *
 * @param array $settings Shaped OS settings, as `openstation_get_os_settings()` returns them.
 * @param array $map      Old id → new id.
 * @return array|null The settings with the carried keys, or null when nothing moved.
 */
function snt_os_nav_ids_carry( $settings, $map ) {
	if ( ! is_array( $settings ) || ! is_array( $map ) ) {
		return null;
	}
	$changed = false;

	foreach ( array( 'navPlacement', 'dockPromotedPositions' ) as $field ) {
		$values = isset( $settings[ $field ] ) && is_array( $settings[ $field ] ) ? $settings[ $field ] : array();
		foreach ( $map as $old => $new ) {
			if ( ! isset( $values[ $old ] ) || isset( $values[ $new ] ) ) {
				continue;
			}
			$values[ $new ]     = $values[ $old ];
			$settings[ $field ] = $values;
			$changed            = true;
		}
	}

	foreach ( array( 'navOrder', 'mobileTabs' ) as $field ) {
		$list = isset( $settings[ $field ] ) && is_array( $settings[ $field ] ) ? array_values( $settings[ $field ] ) : array();
		foreach ( $map as $old => $new ) {
			$at = array_search( $old, $list, true );
			if ( false === $at || in_array( $new, $list, true ) ) {
				continue;
			}
			$list[ $at ]        = $new;
			$settings[ $field ] = $list;
			$changed            = true;
		}
	}

	return $changed ? $settings : null;
}

/**
 * Carry one user's preferences and write them back when something moved.
 *
 * Reads the shaped settings and saves through the shell's own saver, whose
 * contract is sanitize-and-replace, the same path a save from OS Settings
 * takes.
 *
 * @param int $user_id User id.
 * @return bool True when the meta was rewritten.
 */
function snt_os_nav_ids_migrate_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'openstation_get_os_settings' ) || ! function_exists( 'openstation_save_os_settings' ) ) {
		return false;
	}
	$carried = snt_os_nav_ids_carry( openstation_get_os_settings( $user_id ), snt_os_nav_id_map() );
	if ( null === $carried ) {
		return false;
	}
	return (bool) openstation_save_os_settings( $user_id, $carried );
}

/**
 * Sweep every user who has the shell's settings meta, once per migration version.
 *
 * Mirrors the shell's own migration runner (includes/migrations.php): on
 * `admin_init`, because the desktop and the phone shell are both admin pages;
 * an option recording the completed version; only users who have the meta.
 * Priority 20 puts it after the shell's runner at 10. While the shell's
 * settings functions are absent nothing runs and nothing is recorded, so the
 * sweep waits for the shell rather than marking itself done on a site that
 * has not activated it yet.
 *
 * @return void
 */
function snt_os_nav_ids_maybe_migrate() {
	if ( ! function_exists( 'openstation_get_os_settings' ) || ! function_exists( 'openstation_save_os_settings' ) || ! defined( 'OPENSTATION_OS_SETTINGS_META_KEY' ) ) {
		return;
	}
	if ( (int) get_option( SNT_OS_NAV_ID_MIGRATION_OPTION, 0 ) >= SNT_OS_NAV_ID_MIGRATION_VERSION ) {
		return;
	}
	$user_ids = get_users(
		array(
			'fields'       => 'ID',
			'meta_key'     => OPENSTATION_OS_SETTINGS_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time sweep behind the option; mirrors the shell's includes/migrations.php.
			'meta_compare' => 'EXISTS',
		)
	);
	foreach ( (array) $user_ids as $user_id ) {
		snt_os_nav_ids_migrate_user( $user_id );
	}
	update_option( SNT_OS_NAV_ID_MIGRATION_OPTION, SNT_OS_NAV_ID_MIGRATION_VERSION, true );
}
add_action( 'admin_init', 'snt_os_nav_ids_maybe_migrate', 20 );
