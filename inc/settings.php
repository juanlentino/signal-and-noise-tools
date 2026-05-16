<?php
/**
 * Signal & Noise Tools — settings storage + accessors.
 *
 * Single wp_option ('sn_settings') stores all site-identity
 * configuration as a structured array across 5 categories:
 * identity, social, og, login, seo_copy. Code throughout the
 * plugin reads via `sn_setting('cat.field')` with dot-paths.
 *
 * Defaults are generic — pulled from WP built-ins (get_bloginfo)
 * where possible — keeping the plugin portable for new installs.
 * The current production site (juanlentino.com) gets its specific
 * legacy values seeded on first v1.8.0 activation via a hostname-
 * gated migration. A lazy admin_init fallback covers SSH-based
 * upgrades where register_activation_hook doesn't fire.
 *
 * Added in v1.8.0 (2026-05-16, Phase 11.5).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SETTINGS_OPTION       = 'sn_settings';
const SN_SETTINGS_MIGRATED_FLAG = 'sn_settings_migrated_v1';
const SN_LEGACY_HOST            = 'juanlentino.com';

/**
 * Full settings schema with generic defaults.
 *
 * Defaults are intentionally NOT site-specific. The juanlentino.com
 * legacy values are seeded via sn_settings_seed_legacy_values()
 * exactly once per environment, hostname-gated.
 *
 * @return array<string,array<string,mixed>>
 */
function sn_settings_defaults() {
	return array(
		'identity' => array(
			'site_name'        => get_bloginfo( 'name' ),
			'site_description' => get_bloginfo( 'description' ),
			'person_name'      => get_bloginfo( 'name' ),
			'locale'           => 'en_US',
		),
		'social' => array(
			'twitter_handle' => '',
			'same_as'        => array(),
		),
		'og' => array(
			'default_image_url' => '',
			'card_width'        => 1200,
			'card_height'       => 630,
		),
		'login' => array(
			'slug' => 'sn-login',
		),
		'seo_copy' => array(
			'home_title'             => '',
			'home_description'       => '',
			'notes_title'            => '',
			'notes_description'      => '',
			'provenance_title'       => '',
			'provenance_description' => '',
		),
	);
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
 * Sanitize + persist settings from a $_POST submission.
 *
 * @param array $raw Raw $_POST data from the Identity tab form.
 * @return bool True on update_option success.
 */
function sn_settings_save( $raw ) {
	$sanitized = array();

	$sanitized['identity'] = array(
		'site_name'        => sanitize_text_field( (string) ( $raw['identity_site_name'] ?? '' ) ),
		'site_description' => sanitize_text_field( (string) ( $raw['identity_site_description'] ?? '' ) ),
		'person_name'      => sanitize_text_field( (string) ( $raw['identity_person_name'] ?? '' ) ),
		'locale'           => sanitize_text_field( (string) ( $raw['identity_locale'] ?? 'en_US' ) ),
	);

	$same_as_raw   = (array) ( $raw['social_same_as'] ?? array() );
	$same_as_clean = array();
	foreach ( $same_as_raw as $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( $url ) {
			$same_as_clean[] = $url;
		}
	}
	$sanitized['social'] = array(
		'twitter_handle' => sanitize_text_field( (string) ( $raw['social_twitter_handle'] ?? '' ) ),
		'same_as'        => array_values( $same_as_clean ),
	);

	$sanitized['og'] = array(
		'default_image_url' => esc_url_raw( (string) ( $raw['og_default_image_url'] ?? '' ) ),
		'card_width'        => max( 1, (int) ( $raw['og_card_width'] ?? 1200 ) ),
		'card_height'       => max( 1, (int) ( $raw['og_card_height'] ?? 630 ) ),
	);

	$sanitized['login'] = array(
		'slug' => sanitize_title( (string) ( $raw['login_slug'] ?? 'sn-login' ) ),
	);

	$sanitized['seo_copy'] = array(
		'home_title'             => sanitize_text_field( (string) ( $raw['seo_home_title'] ?? '' ) ),
		'home_description'       => sanitize_textarea_field( (string) ( $raw['seo_home_description'] ?? '' ) ),
		'notes_title'            => sanitize_text_field( (string) ( $raw['seo_notes_title'] ?? '' ) ),
		'notes_description'      => sanitize_textarea_field( (string) ( $raw['seo_notes_description'] ?? '' ) ),
		'provenance_title'       => sanitize_text_field( (string) ( $raw['seo_provenance_title'] ?? '' ) ),
		'provenance_description' => sanitize_textarea_field( (string) ( $raw['seo_provenance_description'] ?? '' ) ),
	);

	return (bool) update_option( SN_SETTINGS_OPTION, $sanitized );
}

/**
 * One-time seed of the JL-specific legacy values into wp_options.
 *
 * Hostname-gated: only seeds when home_url() host is juanlentino.com.
 * On any other host, sets only the migrated flag so the migration
 * doesn't re-attempt and generic defaults from sn_settings_defaults()
 * take over.
 *
 * Idempotent — guarded by SN_SETTINGS_MIGRATED_FLAG.
 */
function sn_settings_seed_legacy_values() {
	if ( get_option( SN_SETTINGS_MIGRATED_FLAG ) ) {
		return;
	}

	$host = parse_url( home_url(), PHP_URL_HOST );
	if ( $host === SN_LEGACY_HOST ) {
		update_option( SN_SETTINGS_OPTION, array(
			'identity' => array(
				'site_name'        => 'Juan Lentino',
				'site_description' => 'Music Production & Creative Strategy',
				'person_name'      => 'Juan Lentino',
				'locale'           => 'en_US',
			),
			'social' => array(
				'twitter_handle' => '@juan_lentino',
				'same_as'        => array(
					'https://x.com/juan_lentino',
					'https://instagram.com/juan_lentino',
					'https://linkedin.com/in/juanlentino',
				),
			),
			'og' => array(
				'default_image_url' => home_url( '/wp-content/uploads/2026/02/cropped-jl_logo-min-300x300.png' ),
				'card_width'        => 1200,
				'card_height'       => 630,
			),
			'login' => array(
				'slug' => 'sn-login',
			),
			'seo_copy' => array(
				'home_title'             => 'Juan Lentino — Music producer & creative strategist',
				'home_description'       => 'Music producer, mix engineer, and creative strategist based in Buenos Aires. Founder of Panacea recording studio.',
				'notes_title'            => 'Notes — Juan Lentino',
				'notes_description'      => 'Working notes on music, AI, and the infrastructure underneath. Written when there\'s something worth writing.',
				'provenance_title'       => 'Music has a verification problem. Detection isn\'t the answer.',
				'provenance_description' => "A short read on why the industry needs to prove what's human, not chase what isn't.",
			),
		) );
	}

	update_option( SN_SETTINGS_MIGRATED_FLAG, '1' );
}

/**
 * Lazy fallback handler for SSH-based upgrades.
 *
 * register_activation_hook() doesn't fire when the plugin is upgraded
 * via git checkout (Phase 2c auto-deploy uses SSH + git checkout).
 * Run the migration check on admin_init so it self-heals on the next
 * admin pageview.
 */
function sn_settings_lazy_migration_check() {
	if ( ! get_option( SN_SETTINGS_MIGRATED_FLAG ) ) {
		sn_settings_seed_legacy_values();
	}
}
