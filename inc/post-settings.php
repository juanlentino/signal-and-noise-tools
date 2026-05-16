<?php
/**
 * Signal & Noise Tools — Per-post SEO settings.
 *
 * Three meta keys, written via the meta box on post + page edit screens:
 *   _sn_noindex            — robots noindex toggle (reader: inc/seo.php
 *                            since v1.6.0; write path added here)
 *   _sn_meta_description   — custom <meta name="description"> override
 *   _sn_og_image_url       — custom OG image URL override (highest priority)
 *
 * Architecture: classic add_meta_box() auto-converts to a block editor
 * sidebar panel via WP's legacy-meta-box bridge. Plus register_post_meta()
 * with show_in_rest=true future-proofs storage for a React sidebar later
 * (no migration). Same pattern Yoast uses at scale.
 *
 * Added in v1.10.0 (2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_POST_SETTINGS_NONCE      = 'sn_post_settings_save';
const SN_POST_SETTINGS_POST_TYPES = array( 'post', 'page' );

/**
 * Register the 3 post meta keys with REST exposure.
 *
 * register_post_meta is per-post-type — loop over our supported types.
 * auth_callback enforces edit_posts for REST writes (without it, non-
 * admin users could bypass the save_post cap check via REST).
 */
function sn_post_settings_register_meta() {
	$auth_cb = function () {
		return current_user_can( 'edit_posts' );
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

	foreach ( SN_POST_SETTINGS_POST_TYPES as $post_type ) {
		register_post_meta( $post_type, '_sn_noindex',          $bool_args );
		register_post_meta( $post_type, '_sn_noarchive',        $bool_args );
		register_post_meta( $post_type, '_sn_noimageindex',     $bool_args );
		register_post_meta( $post_type, '_sn_meta_description', $text_args );
		register_post_meta( $post_type, '_sn_canonical_url',    $url_args );
		register_post_meta( $post_type, '_sn_og_image_url',     $url_args );
	}
}
add_action( 'init', 'sn_post_settings_register_meta' );

/**
 * Register the meta box on post + page edit screens.
 *
 * context='side' = right sidebar position (auto-converts to a block
 * editor sidebar panel). priority='high' = near the top so it's
 * discoverable.
 */
function sn_post_settings_register_meta_box() {
	foreach ( SN_POST_SETTINGS_POST_TYPES as $post_type ) {
		add_meta_box(
			'sn_post_settings',
			'Signal & Noise',
			'sn_post_settings_render',
			$post_type,
			'side',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'sn_post_settings_register_meta_box' );

/**
 * Render the meta box. Uses .sn-fieldset / .sn-field design system
 * classes. The side meta box is narrower (~280px) than admin pages
 * (~820px); width overrides live in assets/admin.css.
 *
 * @param WP_Post $post Current post being edited.
 */
function sn_post_settings_render( $post ) {
	wp_nonce_field( SN_POST_SETTINGS_NONCE, 'sn_post_settings_nonce' );

	$noindex      = (bool) get_post_meta( $post->ID, '_sn_noindex', true );
	$noarchive    = (bool) get_post_meta( $post->ID, '_sn_noarchive', true );
	$noimageindex = (bool) get_post_meta( $post->ID, '_sn_noimageindex', true );
	$desc         = (string) get_post_meta( $post->ID, '_sn_meta_description', true );
	$canonical    = (string) get_post_meta( $post->ID, '_sn_canonical_url', true );
	$og           = (string) get_post_meta( $post->ID, '_sn_og_image_url', true );

	echo '<div class="sn-post-settings">';

	// ─── Robots directives ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label sn-field-label--inline">';
	echo '<input type="checkbox" name="sn_noindex" value="1"' . checked( $noindex, true, false ) . '> ';
	echo 'Hide from search engines (noindex)';
	echo '</label>';
	echo '<p class="sn-field-helper">Adds <code>noindex,nofollow</code> to the robots meta tag.</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label sn-field-label--inline">';
	echo '<input type="checkbox" name="sn_noarchive" value="1"' . checked( $noarchive, true, false ) . '> ';
	echo 'No cached copy (noarchive)';
	echo '</label>';
	echo '<p class="sn-field-helper">Tells Google etc. not to show a cached version of this page.</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label sn-field-label--inline">';
	echo '<input type="checkbox" name="sn_noimageindex" value="1"' . checked( $noimageindex, true, false ) . '> ';
	echo 'Hide images from image search (noimageindex)';
	echo '</label>';
	echo '<p class="sn-field-helper">Images on this page won&rsquo;t appear in Google Images.</p>';
	echo '</div>';

	// ─── Meta description ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_meta_description">Meta description</label>';
	echo '<textarea id="sn_meta_description" name="sn_meta_description" rows="3">' . esc_textarea( $desc ) . '</textarea>';
	echo '<p class="sn-field-helper">Overrides the post excerpt for <code>&lt;meta name=&quot;description&quot;&gt;</code>, OG description, and JSON-LD. Empty falls back to excerpt.</p>';
	echo '</div>';

	// ─── Canonical URL ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_canonical_url">Canonical URL</label>';
	echo '<input type="url" id="sn_canonical_url" name="sn_canonical_url" value="' . esc_attr( $canonical ) . '" placeholder="' . esc_attr( get_permalink( $post ) ?: 'https://...' ) . '">';
	echo '<p class="sn-field-helper">Overrides the default <code>&lt;link rel=&quot;canonical&quot;&gt;</code>. Use when this post is a republish / syndication of content that lives at another URL. Empty falls back to the permalink.</p>';
	echo '</div>';

	// ─── OG image URL ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_og_image_url">OG image URL</label>';
	echo '<input type="url" id="sn_og_image_url" name="sn_og_image_url" value="' . esc_attr( $og ) . '" placeholder="https://...">';
	echo '<p class="sn-field-helper">Overrides the featured image / auto-generated card for OG and Twitter shares. Empty falls back to default resolution.</p>';
	echo '</div>';

	echo '</div>';
}

/**
 * Save handler. Hooked to save_post — runs on every save (including
 * autosaves + revisions, both guarded out).
 *
 * Empty values trigger delete_post_meta() rather than persisting
 * empty strings — keeps DB clean and means get_post_meta returns
 * the documented '' default for missing keys.
 *
 * @param int $post_id Post being saved.
 */
function sn_post_settings_save( $post_id ) {
	// Nonce absent — happens on REST writes and autosaves where our
	// meta box wasn't part of the form. Silent return.
	if ( ! isset( $_POST['sn_post_settings_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( wp_unslash( $_POST['sn_post_settings_nonce'] ), SN_POST_SETTINGS_NONCE ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Boolean flags — checkbox unchecked = absent from $_POST.
	$bool_fields = array(
		'_sn_noindex'      => 'sn_noindex',
		'_sn_noarchive'    => 'sn_noarchive',
		'_sn_noimageindex' => 'sn_noimageindex',
	);
	foreach ( $bool_fields as $meta_key => $post_key ) {
		if ( ! empty( $_POST[ $post_key ] ) ) {
			update_post_meta( $post_id, $meta_key, '1' );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	// Meta description — wp_unslash before sanitize (WP stripslashes
	// hostile input on POST; we want the actual value to sanitize).
	$desc = isset( $_POST['sn_meta_description'] )
		? sanitize_textarea_field( wp_unslash( $_POST['sn_meta_description'] ) )
		: '';
	if ( '' !== $desc ) {
		update_post_meta( $post_id, '_sn_meta_description', $desc );
	} else {
		delete_post_meta( $post_id, '_sn_meta_description' );
	}

	// URL fields — esc_url_raw strips invalid URLs to ''.
	$url_fields = array(
		'_sn_canonical_url' => 'sn_canonical_url',
		'_sn_og_image_url'  => 'sn_og_image_url',
	);
	foreach ( $url_fields as $meta_key => $post_key ) {
		$url = isset( $_POST[ $post_key ] )
			? esc_url_raw( wp_unslash( $_POST[ $post_key ] ) )
			: '';
		if ( '' !== $url ) {
			update_post_meta( $post_id, $meta_key, $url );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}
}
add_action( 'save_post', 'sn_post_settings_save' );

/**
 * Typed accessors — read meta with predictable types. Consumers
 * (seo.php / seo-schema.php / og-card-generator.php) call these
 * instead of get_post_meta directly so the type contract lives
 * in one place.
 */
function sn_post_settings_get_noindex( $post_id ) {
	return '1' === (string) get_post_meta( $post_id, '_sn_noindex', true );
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
