<?php
/**
 * Signal & Noise Tools — Per-post settings meta box.
 *
 * SEO/robots/OG override keys on posts + pages (grown from the original
 * three to nine: _sn_noindex, _sn_noarchive, _sn_noimageindex,
 * _sn_evergreen, _sn_meta_description, _sn_canonical_url,
 * _sn_og_image_url, _sn_og_card_title, _sn_seo_title), plus the pillar
 * curation pair on Pages ONLY (v9.79.0): _sn_pillar +
 * _sn_pillar_designation, consumed by the theme's pillar essay rail.
 *
 * Architecture: classic add_meta_box() auto-converts to a block editor
 * sidebar panel via WP's legacy-meta-box bridge. The SEO-era keys carry
 * register_post_meta() show_in_rest=true (future React sidebar seam); the
 * pillar keys deliberately do NOT (the meta-box bridge saves via POST, so
 * REST exposure would be surface without a consumer).
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
 * Register the meta keys.
 *
 * register_post_meta is per-post-type — the SEO-era keys loop over both
 * supported types with show_in_rest=true and a blanket edit_posts
 * auth_callback (their original contract, kept stable). The pillar pair
 * (v9.79.0) registers on 'page' only, show_in_rest=false, with a
 * per-resource edit_post auth_callback on the object id.
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

	// v9.3.0: title override is single-line — sanitize as text, not textarea.
	$title_args                      = $text_args;
	$title_args['sanitize_callback'] = 'sanitize_text_field';

	foreach ( SN_POST_SETTINGS_POST_TYPES as $post_type ) {
		register_post_meta( $post_type, '_sn_noindex',          $bool_args );
		register_post_meta( $post_type, '_sn_noarchive',        $bool_args );
		register_post_meta( $post_type, '_sn_noimageindex',     $bool_args );
		register_post_meta( $post_type, '_sn_evergreen',        $bool_args ); // v8.11.0 (B5): freshness flag.
		register_post_meta( $post_type, '_sn_meta_description', $text_args );
		register_post_meta( $post_type, '_sn_canonical_url',    $url_args );
		register_post_meta( $post_type, '_sn_og_image_url',     $url_args );
		register_post_meta( $post_type, '_sn_og_card_title',    $text_args );
		register_post_meta( $post_type, '_sn_seo_title',        $title_args ); // v9.3.0
	}

	// v9.79.0: pillar essay curation, Pages ONLY (pillars are Pages; the
	// theme's pillar rail derives from this meta). Two deliberate divergences
	// from the older keys above:
	//   show_in_rest => false: the classic meta-box bridge saves via POST,
	//   so REST exposure buys nothing today; flip to true only when a React
	//   sidebar actually needs REST.
	//   auth_callback: per-resource edit_post on the object id, using the
	//   real signature WP passes registered auth callbacks
	//   ($allowed, $meta_key, $object_id, $user_id, $cap, $caps), not the
	//   blanket edit_posts closure above.
	$pillar_auth_cb = function ( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
		return current_user_can( 'edit_post', $object_id );
	};

	register_post_meta(
		'page',
		'_sn_pillar',
		array(
			'show_in_rest'      => false,
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
			'show_in_rest'      => false,
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

	$evergreen     = (bool) get_post_meta( $post->ID, '_sn_evergreen', true );
	$noindex       = (bool) get_post_meta( $post->ID, '_sn_noindex', true );
	$noarchive     = (bool) get_post_meta( $post->ID, '_sn_noarchive', true );
	$noimageindex  = (bool) get_post_meta( $post->ID, '_sn_noimageindex', true );
	$desc          = (string) get_post_meta( $post->ID, '_sn_meta_description', true );
	$canonical     = (string) get_post_meta( $post->ID, '_sn_canonical_url', true );
	$og            = (string) get_post_meta( $post->ID, '_sn_og_image_url', true );
	$og_card_title = (string) get_post_meta( $post->ID, '_sn_og_card_title', true );
	$seo_title     = (string) get_post_meta( $post->ID, '_sn_seo_title', true );

	echo '<div class="sn-post-settings">';

	// v4.8.0: consolidated "auto-generated at publish" notice (empty unless
	// a prepop sentinel is set for this post).
	if ( function_exists( 'sn_prepop_render_notice' ) ) {
		sn_prepop_render_notice( $post );
	}

	// ─── Freshness (v8.11.0, B5) ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label sn-field-label--inline">';
	echo '<input type="checkbox" name="sn_evergreen" value="1"' . checked( $evergreen, true, false ) . '> ';
	echo 'Evergreen (timeless)';
	echo '</label>';
	echo '<p class="sn-field-helper">Marks this Note as intentionally timeless: it&rsquo;s exempt from the &ldquo;stale content&rdquo; health check and won&rsquo;t show as a refresh candidate in Analytics &rarr; Posts even if its traffic is cooling.</p>';
	echo '</div>';

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

	// ─── SEO / social title override (v9.3.0) ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_seo_title">SEO title</label>';
	echo '<input type="text" id="sn_seo_title" name="sn_seo_title" value="' . esc_attr( $seo_title ) . '" placeholder="' . esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ) . '">';
	echo '<p class="sn-field-helper">Overrides the page title used for the browser tab, <code>og:title</code>, and <code>twitter:title</code>. The site name is still appended. Empty falls back to the real page title.</p>';
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

	// ─── OG card title override ───
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_og_card_title">OG card title</label>';
	echo '<textarea id="sn_og_card_title" name="sn_og_card_title" rows="2">' . esc_textarea( $og_card_title ) . '</textarea>';
	echo '<p class="sn-field-helper">Replaces the post title in the social-share <strong>card image</strong> only — the <code>og:title</code> HTML meta still uses the real title. Empty falls back to the post title. Aim for 60-90 chars for the punchiest card.</p>';
	echo '</div>';

	// ─── Pillar essay (v9.79.0, Pages only) ───
	// Flat .sn-field sections like every sibling above: this box never
	// grew section headings, and admin.css does not load on the edit
	// screens, so heading markup would render as a raw wp-admin h2.
	if ( 'page' === $post->post_type ) {
		$pillar      = (bool) get_post_meta( $post->ID, '_sn_pillar', true );
		$designation = (string) get_post_meta( $post->ID, '_sn_pillar_designation', true );

		echo '<div class="sn-field">';
		echo '<label class="sn-field-label sn-field-label--inline">';
		echo '<input type="checkbox" name="sn_pillar" value="1"' . checked( $pillar, true, false ) . '> ';
		echo 'Feature as a pillar essay';
		echo '</label>';
		echo '<p class="sn-field-helper">Surfaces this Page in the theme&rsquo;s pillar essay rail.</p>';
		echo '</div>';

		echo '<div class="sn-field">';
		echo '<label class="sn-field-label" for="sn_pillar_designation">Pillar designation</label>';
		echo '<input type="text" id="sn_pillar_designation" name="sn_pillar_designation" value="' . esc_attr( $designation ) . '" placeholder="1.01">';
		echo '<p class="sn-field-helper">Editorial number, for example <code>1.01</code>. The pillar rail sorts numerically by major.minor.</p>';
		echo '</div>';
	}

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
		'_sn_evergreen'    => 'sn_evergreen',
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

	// OG card title override — same sanitize shape as meta description.
	$og_card_title = isset( $_POST['sn_og_card_title'] )
		? sanitize_textarea_field( wp_unslash( $_POST['sn_og_card_title'] ) )
		: '';
	if ( '' !== $og_card_title ) {
		update_post_meta( $post_id, '_sn_og_card_title', $og_card_title );
	} else {
		delete_post_meta( $post_id, '_sn_og_card_title' );
	}

	// SEO title override (v9.3.0) — single-line, sanitize as text.
	$seo_title = isset( $_POST['sn_seo_title'] )
		? sanitize_text_field( wp_unslash( $_POST['sn_seo_title'] ) )
		: '';
	if ( '' !== $seo_title ) {
		update_post_meta( $post_id, '_sn_seo_title', $seo_title );
	} else {
		delete_post_meta( $post_id, '_sn_seo_title' );
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

	// v9.79.0: pillar essay curation, Pages ONLY. The post-type guard means
	// a crafted POST against a post can never set page-only meta.
	if ( 'page' === get_post_type( $post_id ) ) {
		if ( ! empty( $_POST['sn_pillar'] ) ) {
			update_post_meta( $post_id, '_sn_pillar', '1' );
		} else {
			delete_post_meta( $post_id, '_sn_pillar' );
		}

		$designation = isset( $_POST['sn_pillar_designation'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['sn_pillar_designation'] ) ) )
			: '';
		if ( '' !== $designation ) {
			update_post_meta( $post_id, '_sn_pillar_designation', $designation );
		} else {
			delete_post_meta( $post_id, '_sn_pillar_designation' );
		}
	}

	// v4.8.0: an editor save acknowledges the prepop notice — clear sentinels.
	if ( function_exists( 'sn_prepop_clear_sentinels' ) ) {
		sn_prepop_clear_sentinels( $post_id );
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

function sn_post_settings_get_og_card_title( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_og_card_title', true );
}

function sn_post_settings_get_seo_title( $post_id ) {
	return (string) get_post_meta( $post_id, '_sn_seo_title', true );
}
