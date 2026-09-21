<?php
/**
 * Signal & Noise Tools — Per-post settings meta box.
 *
 * SEO/robots/OG override keys on posts + pages (grown from the original
 * three to eleven: _sn_noindex, _sn_nofollow, _sn_noarchive, _sn_noimageindex,
 * _sn_evergreen, _sn_meta_description, _sn_canonical_url,
 * _sn_og_image_url, _sn_og_card_title, _sn_seo_title,
 * _sn_focus_keyword), plus the pillar
 * curation pair on Pages ONLY (v9.79.0): _sn_pillar +
 * _sn_pillar_designation, consumed by the theme's pillar essay rail.
 *
 * Architecture (#1608): every key is registered with show_in_rest so the
 * editor's native document-settings panel (assets/post-settings-panel.js,
 * a PluginDocumentSettingPanel over useEntityProp) reads and writes them
 * through the post entity. The classic meta box, its nonce and its
 * save_post handler are gone: nothing printed the form fields once the box
 * left, so the handler could never run. Each key sanitizes through its
 * registered sanitize_callback on the REST path, and the panel hands every
 * S&N key at its default ('' or false) back as null, which the REST meta
 * controller turns into delete_post_meta() (a no-op when the row is
 * absent), so what each key stores is '1' or absent for a flag, the
 * sanitized string or absent for text, never an '' row.
 *
 * Added in v1.10.0 (2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_POST_SETTINGS_POST_TYPES = array( 'post', 'page' );

/**
 * Register the meta keys.
 *
 * register_post_meta is per-post-type: the SEO-era keys loop over both
 * supported types with show_in_rest=true. Both they and the pillar pair
 * (v9.79.0, 'page' only) gate their auth_callback on the per-resource
 * edit_post capability against the object id.
 */
function sn_post_settings_register_meta() {
	// v10.48.1: per-resource, using the real signature WP passes a registered meta
	// auth_callback ($allowed, $meta_key, $object_id, $user_id, $cap, $caps). This
	// was a blanket `current_user_can( 'edit_posts' )` closure that ignored
	// $object_id — CMA audit 2026-08-05 LOW-1. Not exploitable then (WP applies
	// registered meta only through the parent object's controller, which clears
	// edit_post($id) first), but a meta key should be self-defending rather than trusting
	// its caller. Mirrors $pillar_auth_cb below; pinned by tests/post-settings-meta-auth.php.
	$auth_cb = function ( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
		return current_user_can( 'edit_post', $object_id );
	};

	$bool_args = array(
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'boolean',
		'default'           => false,
		'auth_callback'     => $auth_cb,
		'sanitize_callback' => 'rest_sanitize_boolean',
	);

	$text_args = array(
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
		'default'           => '',
		'auth_callback'     => $auth_cb,
		'sanitize_callback' => 'sanitize_textarea_field',
	);

	$url_args = array(
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
		'default'           => '',
		'auth_callback'     => $auth_cb,
		'sanitize_callback' => 'esc_url_raw',
	);

	// v9.3.0: title override is single-line — sanitize as text, not textarea.
	$title_args                      = $text_args;
	$title_args['sanitize_callback'] = 'sanitize_text_field';

	// #1608: the keyword's 80-character cap used to live only in the POST
	// handler below; the panel saves through REST, so the cap rides the
	// registered sanitizer and both write paths share one limit.
	$keyword_args                      = $title_args;
	$keyword_args['sanitize_callback'] = 'sn_post_settings_sanitize_focus_keyword';

	// v11.15.0: the page-signing opt-in. PAGES ONLY and deliberately outside the
	// shared loop — a post is a subject by CATEGORY (sn_prov_is_note), never by
	// this flag, so registering it for posts would advertise a control that
	// decides nothing.
	// Literal key, not the SN_PROV_SIGN_META constant: inc/provenance-core.php
	// owns it with `const`, and this file is loaded standalone by the fixture
	// suites, which do not boot the provenance module. A defined() guard here
	// would risk redeclaring a top-level const depending on load order. Same
	// precedent as the theme reading `_sn_prov_uid` by its literal name.
	register_post_meta( 'page', '_sn_prov_sign', $bool_args );

	foreach ( SN_POST_SETTINGS_POST_TYPES as $post_type ) {
		register_post_meta( $post_type, '_sn_noindex',          $bool_args );
		register_post_meta( $post_type, '_sn_nofollow',         $bool_args ); // v12.12.0: split out of _sn_noindex, which forced it from v1.6.0.
		register_post_meta( $post_type, '_sn_noarchive',        $bool_args );
		register_post_meta( $post_type, '_sn_noimageindex',     $bool_args );
		register_post_meta( $post_type, '_sn_evergreen',        $bool_args ); // v8.11.0 (B5): freshness flag.
		register_post_meta( $post_type, '_sn_meta_description', $text_args );
		register_post_meta( $post_type, '_sn_canonical_url',    $url_args );
		register_post_meta( $post_type, '_sn_og_image_url',     $url_args );
		register_post_meta( $post_type, '_sn_og_card_title',    $text_args );
		register_post_meta( $post_type, '_sn_seo_title',        $title_args ); // v9.3.0
		register_post_meta( $post_type, '_sn_focus_keyword',    $keyword_args ); // v10.8.0: SEO focus keyword (fed to the AI meta-description generator; also writable via the rw-door update-post-surfaces ability)
	}

	// #1608: the prepop sentinels (owned by inc/ai-prepopulate.php, written
	// by cron after publish) reach the panel's "auto-generated when you
	// published" notice through the same entity. Guarded: the fixture suites
	// load this file without the prepop module.
	if ( function_exists( 'sn_prepop_fields' ) ) {
		foreach ( SN_POST_SETTINGS_POST_TYPES as $post_type ) {
			foreach ( array_keys( sn_prepop_fields() ) as $sentinel ) {
				register_post_meta( $post_type, $sentinel, $bool_args );
			}
		}
	}

	// v9.79.0: pillar essay curation, Pages ONLY (pillars are Pages; the
	// theme's pillar rail derives from this meta). show_in_rest was false
	// while the meta-box bridge saved via POST; #1608's panel is the React
	// sidebar the file said to wait for, so the pair rides REST like every
	// sibling. auth_callback: per-resource edit_post on the object id, using
	// the real signature WP passes registered auth callbacks
	// ($allowed, $meta_key, $object_id, $user_id, $cap, $caps).
	$pillar_auth_cb = function ( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
		return current_user_can( 'edit_post', $object_id );
	};

	register_post_meta(
		'page',
		'_sn_pillar',
		array(
			'show_in_rest'      => true,
			'single'            => true,
			'type'              => 'boolean',
			'default'           => false,
			'auth_callback'     => $pillar_auth_cb,
			'sanitize_callback' => 'rest_sanitize_boolean',
		)
	);
	register_post_meta(
		'page',
		'_sn_pillar_designation',
		array(
			'show_in_rest'      => true,
			'single'            => true,
			'type'              => 'string',
			'default'           => '',
			'auth_callback'     => $pillar_auth_cb,
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
}
add_action( 'init', 'sn_post_settings_register_meta' );

/**
 * Focus keyword sanitizer: single-line text, hard-capped at 80 characters
 * (parity with the rw-door update-post-surfaces schema). Registered as the
 * key's sanitize_callback so the REST path and the POST path share it.
 *
 * @param string $value Raw value.
 * @return string
 */
function sn_post_settings_sanitize_focus_keyword( $value ) {
	return mb_substr( sanitize_text_field( (string) $value ), 0, 80 );
}

/**
 * Enqueue the native document-settings panel on the block editor screens.
 *
 * assets/post-settings-panel.js registers the `snt-post-settings` plugin
 * (wp.plugins.registerPlugin) whose render is a PluginDocumentSettingPanel
 * from @wordpress/editor (Stable; exported since 6.6) over
 * useEntityProp( 'postType', type, 'meta' ) from @wordpress/core-data. The
 * AI suggest scripts contribute their buttons through
 * window.sntPostSettingsActions; they enqueue themselves only when AI is
 * available, so the panel never has to know.
 */
function sn_post_settings_enqueue_panel() {
	// The hook fires in the site editor and the widgets editor too, where the
	// editor package still mounts the PluginDocumentSettingPanel slot for a
	// template; the panel belongs to a post or page document only, the same
	// gate the pre-publish gate and the AI scripts keep on post.php and
	// post-new.php.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, SN_POST_SETTINGS_POST_TYPES, true ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	wp_register_script(
		'snt-post-settings-panel',
		plugins_url( 'assets/post-settings-panel.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-plugins', 'wp-editor', 'wp-core-data', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
		SNT_VERSION,
		true
	);
	// The prepop sentinel labels for the panel's notice: one source
	// (sn_prepop_fields), an object even when empty (a bare [] is a list).
	$prepop = function_exists( 'sn_prepop_fields' ) ? sn_prepop_fields() : array();
	wp_add_inline_script(
		'snt-post-settings-panel',
		'window.sntPostSettingsConfig = ' . wp_json_encode( array( 'prepop' => (object) $prepop ) ) . ';',
		'before'
	);
	wp_enqueue_script( 'snt-post-settings-panel' );
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-post-settings-panel', 'signal-noise-tools' );
	}
}
add_action( 'enqueue_block_editor_assets', 'sn_post_settings_enqueue_panel' );

/**
 * An editor save through REST acknowledges the prepop notice, the way the
 * classic save handler did for a POST save. The sentinels are set by cron
 * after publish, never inside this request, so clearing here cannot race
 * the write.
 *
 * @param WP_Post $post Saved post.
 */
function sn_post_settings_rest_after_insert( $post ) {
	if ( function_exists( 'sn_prepop_clear_sentinels' ) ) {
		sn_prepop_clear_sentinels( $post->ID );
	}
}
foreach ( SN_POST_SETTINGS_POST_TYPES as $sn_post_settings_type ) {
	add_action( 'rest_after_insert_' . $sn_post_settings_type, 'sn_post_settings_rest_after_insert' );
}
unset( $sn_post_settings_type );

/**
 * Typed accessors — read meta with predictable types. Consumers
 * (seo.php / seo-schema.php / og-card-generator.php) call these
 * instead of get_post_meta directly so the type contract lives
 * in one place.
 */
function sn_post_settings_get_noindex( $post_id ) {
	return '1' === (string) get_post_meta( $post_id, '_sn_noindex', true );
}

function sn_post_settings_get_nofollow( $post_id ) {
	return '1' === (string) get_post_meta( $post_id, '_sn_nofollow', true );
}

function sn_post_settings_get_noarchive( $post_id ) {
	return '1' === (string) get_post_meta( $post_id, '_sn_noarchive', true );
}

function sn_post_settings_get_noimageindex( $post_id ) {
	return '1' === (string) get_post_meta( $post_id, '_sn_noimageindex', true );
}

function sn_post_settings_get_description( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_meta_description', true );
}

function sn_post_settings_get_canonical_url( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_canonical_url', true );
}

function sn_post_settings_get_og_image_url( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_og_image_url', true );
}

function sn_post_settings_get_og_card_title( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_og_card_title', true );
}

function sn_post_settings_get_seo_title( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_seo_title', true );
}
