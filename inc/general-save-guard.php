<?php
/**
 * Signal & Noise Tools — the General settings save guard (16.7.3).
 *
 * Core registers `admin_email` (with eight other settings) into the
 * "general" group only in register_initial_settings(), on `rest_api_init`,
 * which never fires during an admin form save. The AI plugin (1.2.0+,
 * WordPress/ai#856) calls that function early on `wp_abilities_api_init`,
 * so on any admin POST where the Abilities registry is initialised the
 * nine land in the group; options.php then saves every entry in it,
 * posted or not, and `admin_email`, which the General form does not post
 * (it posts `new_admin_email`), is saved as NULL and rejected with "not a
 * valid email address" on every save. Filed as WordPress/ai#1048.
 *
 * This guard drops `admin_email` from the group at save time. Core's own
 * list never contains it; only the early registration does. Remove the
 * day upstream ships the fix.
 *
 * @since 16.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE. The allowed-options map without `admin_email` in "general".
 *
 * @param array $allowed As options.php hands it to the `allowed_options` filter.
 * @return array
 */
function sn_general_save_guard( $allowed ) {
	if ( ! is_array( $allowed ) || empty( $allowed['general'] ) || ! is_array( $allowed['general'] ) ) {
		return $allowed;
	}
	$allowed['general'] = array_values( array_filter( $allowed['general'], static function ( $name ) {
		return 'admin_email' !== $name;
	} ) );
	return $allowed;
}
// After Core's option_update_filter (10), which is where the registered names arrive.
add_filter( 'allowed_options', 'sn_general_save_guard', 20 );
