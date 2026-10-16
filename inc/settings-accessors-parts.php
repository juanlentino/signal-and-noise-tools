<?php
/**
 * Signal & Noise Tools: settings storage + accessors, the registration and the accessors (register_setting on init, the whole-tree sanitiser, sn_setting, the cache reset, sn_setting_update).
 *
 * Split from inc/settings.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare sn_settings to core: register_setting() with a sanitize_callback.
 *
 * Core binds the callback to the sanitize_option_{$option} filter, which
 * update_option() and add_option() both run through, so every writer (the
 * form handler, sn_setting_update(), the activation seed, the admin_init
 * prune, the Machine Readers save, WP-CLI, a migration, an ability) lands a
 * sanitised tree. On init, not admin_init: admin_init never fires for WP-CLI,
 * cron or an ability call, and those are the writers this exists for.
 *
 * The group is private and no settings_fields( 'sn_private' ) exists anywhere:
 * a Settings API form posting to options.php saves every option in its group,
 * posted or not (Core's own General form hit this until WordPress/ai#1048
 * was fixed upstream). The forms
 * keep posting to sn_action and the handlers stay the writers.
 *
 * show_in_rest stays false: the tree holds keyring secrets. `default` is
 * omitted because every reader passes array() and sn_setting() merges the
 * defaults itself.
 *
 * @return void
 */
function sn_settings_register() {
	register_setting(
		'sn_private',
		SN_SETTINGS_OPTION,
		array(
			'type'              => 'object',
			'sanitize_callback' => 'sn_settings_sanitize_option',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'init', 'sn_settings_register' );

/**
 * Whole-array sanitiser for sn_settings, keyed on the defaults' types.
 *
 * Not a whitelist: identity.job_title, identity.knows_about, the whole
 * machine_readers subtree and everything under monitoring are absent from
 * sn_settings_defaults() on purpose, so an unknown key passes through as is.
 * Idempotent: sn_settings_save() sanitises first and update_option() runs this
 * on the result.
 *
 * @param mixed $value The tree a writer handed update_option().
 * @return array<string,mixed>
 */
function sn_settings_sanitize_option( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}
	return sn_settings_sanitize_tree( $value, sn_settings_defaults(), '' );
}

/**
 * One level of sn_settings_sanitize_option(): coerce each leaf that has a
 * default to that default's type, recurse into keyed subtrees, and pass
 * through what the defaults do not describe.
 *
 * Credential-shaped leaves (the suffix regex is inc/config-drift.php's, which
 * hashes the same leaves) pass through as plain strings: a GSC service-account
 * JSON or a bearer token must land byte for byte. A leaf whose default is an
 * empty array (social.same_as, analytics.exclude_roles, analytics.funnels,
 * monitoring) carries no shape to coerce to and passes through when it is an
 * array; its form handler owns its shape.
 *
 * Strings go through sanitize_textarea_field(), not sanitize_text_field():
 * the seo_copy descriptions are textarea leaves and the form handler keeps
 * their newlines, and on a single-line value the two are the same function.
 *
 * @param array<string,mixed> $value    The subtree being written.
 * @param array<string,mixed> $defaults The matching subtree of the defaults.
 * @param string              $prefix   Dot path of $value, '' at the root.
 * @return array<string,mixed>
 */
function sn_settings_sanitize_tree( array $value, array $defaults, $prefix ) {
	$out = array();
	foreach ( $value as $key => $leaf ) {
		$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
		if ( ! array_key_exists( $key, $defaults ) ) {
			$out[ $key ] = $leaf;
			continue;
		}
		if ( preg_match( '/(?:token|secret|password|api_key|private_key|push_url|credential)$/i', $path ) ) {
			$out[ $key ] = is_scalar( $leaf ) || null === $leaf ? (string) $leaf : $leaf;
			continue;
		}
		// URL leaves keep their percent-encoded octets: sanitize_text_field()
		// and sanitize_textarea_field() strip every %xx, so the form handler's
		// esc_url_raw() value would be rewritten on write. search_console.property
		// is Google's string for the site ('sc-domain:example.com' or a URL prefix
		// with its own encoding), matched against the sites list before the write,
		// and esc_url_raw() would strip the sc-domain scheme, so it passes through.
		if ( preg_match( '/_url$/', $path ) ) {
			$out[ $key ] = esc_url_raw( trim( is_scalar( $leaf ) || null === $leaf ? (string) $leaf : '' ) );
			continue;
		}
		if ( 'search_console.property' === $path ) {
			$out[ $key ] = trim( is_scalar( $leaf ) || null === $leaf ? (string) $leaf : '' );
			continue;
		}
		$default = $defaults[ $key ];
		if ( is_array( $default ) ) {
			if ( ! is_array( $leaf ) ) {
				$out[ $key ] = array();
			} elseif ( array() === $default ) {
				$out[ $key ] = $leaf;
			} else {
				$out[ $key ] = sn_settings_sanitize_tree( $leaf, $default, $path );
			}
			continue;
		}
		if ( is_bool( $default ) ) {
			$out[ $key ] = is_string( $leaf )
				? in_array( strtolower( trim( $leaf ) ), array( '1', 'true', 'on', 'yes' ), true )
				: (bool) $leaf;
			continue;
		}
		if ( is_int( $default ) || is_float( $default ) ) {
			// Numeric, not (int): theme.ai_monthly_budget and theme.jev_credit
			// default to 0 and 5 but their handler stores 7.50.
			$out[ $key ] = is_numeric( $leaf ) ? $leaf + 0 : 0;
			continue;
		}
		$out[ $key ] = sanitize_textarea_field( is_scalar( $leaf ) || null === $leaf ? (string) $leaf : '' );
	}
	return $out;
}

/**
 * Read a setting by dot-delimited path, deep-merged with defaults.
 *
 * Static-cached per request — one get_option() call regardless of
 * how many sn_setting() invocations.
 *
 * @param string $path    Dot-delimited path (e.g. 'identity.site_name').
 * @param mixed  $default Fallback if path doesn't resolve.
 * @return mixed
 */
function sn_setting( $path, $default = null ) {
	static $merged = null;

	// v4.2.0: sentinel path forces cache reset. Used by
	// sn_setting_reset_cache() — no other call site should pass this.
	if ( '__sn_reset_cache__' === $path ) {
		$merged = null;
		return null;
	}

	if ( null === $merged ) {
		$stored = get_option( SN_SETTINGS_OPTION, array() );
		$merged = array_replace_recursive(
			sn_settings_defaults(),
			is_array( $stored ) ? $stored : array()
		);
	}
	$value = $merged;
	foreach ( explode( '.', $path ) as $key ) {
		if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
			return $default;
		}
		$value = $value[ $key ];
	}
	return $value;
}

/**
 * Bust the per-request static cache held by sn_setting().
 *
 * Call after any write to the sn_settings option that happens outside
 * sn_setting_update() — for example, manual update_option() in a save
 * handler. Without this, subsequent sn_setting() reads in the same
 * request return the stale cached value (the D-06 audit failure mode).
 *
 * @since 4.2.0
 */
function sn_setting_reset_cache() {
	sn_setting( '__sn_reset_cache__' );
}

/**
 * Write a single dot-path setting + bust the cache + return success.
 *
 * Replaces the direct get_option/update_option pattern in admin save
 * handlers. Busting the cache makes the new value visible to any
 * sn_setting() call later in the same request.
 *
 * Note: this writes a SPARSE option — only the dot-path key is included in the
 * stored array. Read-side defaults from sn_settings_defaults() fill the gaps,
 * but if sn_settings_save() runs afterward and you've written a setting whose
 * top-level key isn't in the Identity-tab form payload, this sparse write may
 * be overwritten by sn_settings_save()'s whole-option replace.
 *
 * @since 4.2.0
 * @param string $path  Dot-delimited path (e.g. 'login.slug').
 * @param mixed  $value New value.
 * @return bool True if value is correctly present after the write
 *              (re-read disambiguates "no change" from "real failure" —
 *              same gotcha as the v3.x save_login handler comment).
 */
function sn_setting_update( $path, $value ) {
	$settings = (array) get_option( SN_SETTINGS_OPTION, array() );
	$segments = explode( '.', $path );
	$cursor   =& $settings;
	$last     = count( $segments ) - 1;
	foreach ( $segments as $i => $segment ) {
		if ( $i === $last ) {
			$cursor[ $segment ] = $value;
		} else {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}
			$cursor =& $cursor[ $segment ];
		}
	}
	unset( $cursor );

	update_option( SN_SETTINGS_OPTION, $settings );
	sn_setting_reset_cache();

	// gotcha #10 disambiguation: update_option returns false on both
	// "no change" and "real failure" — re-read to confirm.
	$re_read = (array) get_option( SN_SETTINGS_OPTION, array() );
	$cursor  = $re_read;
	foreach ( $segments as $segment ) {
		if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
			return false;
		}
		$cursor = $cursor[ $segment ];
	}
	return $cursor === $value;
}

