<?php
/**
 * Signal & Noise Tools: the settings, plugin and theme hooks that feed the
 * audit log's changes (account hooks: inc/audit-log-changes-hooks.php).
 *
 * Each observes a core action after the change and records one row through
 * snt_audit_record_change_impl(). Core fires update_option_{name} only when
 * the value actually changed.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings: open registration, the default role (administrator is high) and
 * the site admin email (no addresses kept). Core fires these only on a change.
 */
add_action(
	'update_option_users_can_register',
	static function ( $old, $new ) {
		snt_audit_record_change_impl( (int) $new ? 'registration_on' : 'registration_off', 'users_can_register' );
	},
	10,
	2
);
add_action(
	'update_option_default_role',
	static function ( $old, $new ) {
		snt_audit_record_change_impl( 'default_role', 'default_role', (string) $old . ' → ' . (string) $new, 'administrator' === $new );
	},
	10,
	2
);
add_action(
	'update_option_admin_email',
	static function () {
		snt_audit_record_change_impl( 'admin_email', 'admin_email' );
	},
	10,
	0
);

/**
 * Plugins and themes.
 */
add_action(
	'activated_plugin',
	static function ( $plugin ) {
		snt_audit_record_change_impl( 'plugin_activated', (string) $plugin );
	},
	10,
	1
);
add_action(
	'deactivated_plugin',
	static function ( $plugin ) {
		snt_audit_record_change_impl( 'plugin_deactivated', (string) $plugin );
	},
	10,
	1
);
add_action(
	'deleted_plugin',
	static function ( $plugin, $deleted ) {
		if ( $deleted ) {
			snt_audit_record_change_impl( 'plugin_deleted', (string) $plugin );
		}
	},
	10,
	2
);
add_action(
	'switch_theme',
	static function ( $new_name, $new_theme = null, $old_theme = null ) {
		$old = '';
		if ( is_object( $old_theme ) ) {
			$old = method_exists( $old_theme, 'get' ) ? (string) $old_theme->get( 'Name' ) : (string) ( $old_theme->name ?? '' );
		}
		/* translators: %s: previous theme name. */
		snt_audit_record_change_impl( 'theme_switched', (string) $new_name, '' !== $old ? sprintf( __( 'from %s', 'signal-and-noise-tools' ), $old ) : '' );
	},
	10,
	3
);
