<?php
/**
 * Signal & Noise Tools — WP Explorer (OpenStation "My WordPress") integration.
 *
 * SUPERSEDED (v13.98.0). OpenStation 1.1.6 rebuilt WP Explorer on its App
 * Framework and retired the two filters this module hangs on: the hooks
 * reference marks `openstation_my_wordpress_entities` INERT (it runs, nothing
 * reads it) and `_window_args` went with the legacy window. The folder this
 * module built stopped rendering on that release with no error anywhere. The
 * same two surfaces now live in the plugin's own app, apps/signal-noise/
 * (see inc/openstation-app.php). This file stays for the `sn_provenance` REST
 * field it registers and for a pre-1.1.6 shell; its filters are harmless
 * where they are inert.
 *
 * The shell's WP Explorer is a Finder-style native window over WordPress
 * content: folder tiles at the root, infinite-scroll lists, a preview pane.
 * This module gives it a "Signal & Noise" folder with two sections:
 *
 *   1. NOTES — the provenance-signed editorial surface. Rides the shell's
 *      built-in `post` kind against `wp/v2/posts` scoped to the Notes
 *      category, so preview, trash, locks and editor links all come free.
 *      What we ADD is the provenance layer: a REST field (`sn_provenance`,
 *      registered below) summarizes each Note's signed commit chain, and the
 *      companion bundle (assets/desktop-mode-explorer.js) renders it as a
 *      tile badge + a preview-pane block via the shell's
 *      `os.my-wordpress.list-tile` / `os.my-wordpress.preview-extras` hooks.
 *   2. DISCOGRAPHY — the Muso.AI/Spotify release cache
 *      (inc/discography-store.php), the one SN collection with real cover
 *      art. The shell has no built-in kind for option-stored data, so this
 *      section declares the custom kind `signal-noise/album`; the companion
 *      bundle registers the renderer via
 *      `wp.os.myWordpress.registerEntityKind()` and feeds it from the
 *      `/desktop/discography` REST route registered here.
 *
 * TIMING: the shell freezes the Explorer's entity list when it builds the
 * window config at `init` priority 99 (upstream window.php docblock), so the
 * entities filter must be attached before then — file scope, like every
 * other consumer in this module set, satisfies that with margin. The script
 * HANDLE registers on `init` priority 5, same slot as its siblings in
 * inc/desktop-mode-assets.php (see the v9.52.1 note in the loader for why
 * `init` and never admin_enqueue_scripts:10).
 *
 * DELIVERY: the bundle is NOT enqueued on admin pages. It rides the Explorer
 * window's `scripts` companion list (appended via the
 * `openstation_my_wordpress_window_args` filter, the same vehicle upstream's
 * own WooCommerce integration uses) — the shell loads it when the window
 * first opens, and a user who never opens WP Explorer never downloads it.
 * Its config travels as an inline blob attached to the registered handle at
 * admin_enqueue_scripts:5, ahead of the shell's payload build at :10, which
 * harvests inline blobs off registered handles for the lazy-load path.
 *
 * CAPABILITIES: sections are gated server-side, mirroring the shell's own
 * preview-actions discipline — a section the user cannot fetch is never
 * serialized into their config. Notes requires `edit_posts` (the Explorer's
 * own entry gate; wp/v2/posts then enforces per-post caps). Discography
 * requires `manage_options`, matching this module set's sibling REST routes
 * (/desktop/site-views, /desktop/machine-readers).
 *
 * The `sn_provenance` REST field is deliberately public for published Notes:
 * version numbers, commit hashes, anchor status and timestamps are the same
 * facts the public ledger, the /verify page and the client-side verifier
 * already publish. Unpublished posts are protected by core's own REST
 * visibility rules before the field callback ever runs.
 *
 * Dual hook names (desktop_mode_* / openstation_*) via
 * inc/openstation-compat.php. Note the pre-rename v0.9.8 shell predates the
 * WP Explorer entirely, so the old-family names likely never fire — they are
 * registered for the same reason every other seam dual-registers: one
 * pattern, no special cases, correct under a hypothetical transition shim.
 * Both filters are idempotent by construction (the entities callback skips
 * ids already present; the window-args callback skips an already-appended
 * handle), so no snt_os_compat_seen_once() guard is needed.
 *
 * @package SignalNoiseTools
 * @since 12.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_EXPLORER_SCRIPT_HANDLE = 'sn-desktop-mode-explorer';
const SNT_EXPLORER_GROUP_ID      = 'plugin:signal-and-noise-tools';
const SNT_EXPLORER_NOTES_ID      = 'sn-notes';
const SNT_EXPLORER_ALBUMS_ID     = 'sn-albums';
const SNT_EXPLORER_ALBUM_KIND    = 'signal-noise/album';

/**
 * The group fields shared by both sections — one "Signal & Noise" folder at
 * the Explorer root. The id follows upstream's owner-resolver convention
 * (`plugin:<folder>`), so if a future SN post type is ever auto-attributed
 * to this plugin it merges into the same folder instead of spawning a twin.
 * Order 15 sorts ahead of the resolver's plugin default (20) — first seen
 * wins on label/icon, and we want ours to be the one seen.
 *
 * @since 12.4.0
 * @return array<string,mixed>
 */
function snt_explorer_group_fields() {
	return array(
		'group'      => SNT_EXPLORER_GROUP_ID,
		'groupLabel' => __( 'Signal & Noise', 'signal-and-noise-tools' ),
		'groupIcon'  => 'dashicons-megaphone',
		'groupOrder' => 15,
	);
}

/**
 * The Notes section descriptor, or null when it cannot exist yet.
 *
 * Built-in `post` kind against wp/v2/posts, scoped by `listQuery` to the
 * Notes category. The category is resolved through the SAME filter
 * sn_prov_is_note() honours (`sn_prov_note_category`, default 'notes'), so a
 * site that relocated its Notes keeps the section and the provenance field
 * agreeing on what a Note is. Resolved at call time — the filter runs at
 * `init` 99, safely after taxonomies exist.
 *
 * `listFields` carries `sn_provenance` past the shell's `_fields` stripping
 * on BOTH list and detail requests — without it the companion bundle's badge
 * and pane block would never see the chain.
 *
 * THE CATEGORY FILTER RIDES listQuery, AND ONLY listQuery. restPath must
 * stay a bare collection route: the shell builds every PER-ITEM url as
 * `${restPath}/${id}` (detail, trash, revisions — upstream
 * src/my-wordpress/rest.ts), so a query string embedded in restPath lands
 * INSIDE those paths and core 400s the request ("Invalid parameter(s):
 * categories" in the preview pane — shipped briefly during 12.4.0 review
 * and reverted). The cost of the correct spelling is the folder-tile
 * counter (`fetchEntityTotal()`), which probes restPath WITHOUT applying
 * listQuery and would claim the site's entire post count; the companion
 * bundle repaints that one label with a category-scoped count fetched via
 * `notesCountUrl` from the inline config (see the group-extras handler in
 * assets/desktop-mode-explorer.js).
 *
 * @since 12.4.0
 * @return array<string,mixed>|null Null when the Notes category is absent
 *                                  (fresh site, surfaces not yet seeded).
 */
function snt_explorer_notes_entity() {
	$slug = apply_filters( 'sn_prov_note_category', defined( 'SN_NOTES_CATEGORY_SLUG' ) ? SN_NOTES_CATEGORY_SLUG : 'notes' );
	$cat  = get_category_by_slug( (string) $slug );
	if ( ! $cat || empty( $cat->term_id ) ) {
		return null;
	}
	return array_merge(
		array(
			'id'         => SNT_EXPLORER_NOTES_ID,
			'label'      => __( 'Notes', 'signal-and-noise-tools' ),
			'icon'       => 'dashicons-edit-page',
			'restPath'   => 'wp/v2/posts',
			'kind'       => 'post',
			'post_type'  => 'post',
			'listQuery'  => array( 'categories' => (string) (int) $cat->term_id ),
			'listFields' => array( 'sn_provenance' ),
		),
		snt_explorer_group_fields()
	);
}

/**
 * The Discography section descriptor, or null when there is nothing to show.
 *
 * Registered ONLY when the store holds entries: an empty section would
 * render an honest-but-useless empty folder on every site that never
 * configured Muso.AI sync, and "nothing synced" is already reported where it
 * belongs (Connections → Music). `restPath` names the real collection route
 * for the descriptor's contract, though the custom-kind renderer fetches it
 * itself via the inline config.
 *
 * `thumbnails` is false because the tiles have no featured images to
 * replace the icon with — cover art is the RENDERER's job, painted from
 * each entry's `image` URL.
 *
 * @since 12.4.0
 * @return array<string,mixed>|null
 */
function snt_explorer_albums_entity() {
	if ( ! function_exists( 'sn_discography_get' ) ) {
		return null;
	}
	$store = sn_discography_get();
	if ( empty( $store['entries'] ) || ! is_array( $store['entries'] ) ) {
		return null;
	}
	return array_merge(
		array(
			'id'         => SNT_EXPLORER_ALBUMS_ID,
			'label'      => __( 'Discography', 'signal-and-noise-tools' ),
			'icon'       => 'dashicons-album',
			'restPath'   => 'signal-noise/v1/desktop/discography',
			'kind'       => SNT_EXPLORER_ALBUM_KIND,
			'thumbnails' => false,
			'tileSize'   => 'large',
		),
		snt_explorer_group_fields()
	);
}

/**
 * Filter callback: add the SN sections to the Explorer's entity list.
 *
 * Idempotent — an id already present is never appended again, which is the
 * filter-shaped answer to the cross-family double-fire concern the compat
 * layer's seen-once guard solves for side-effect handlers (a transition shim
 * chaining both hook names would otherwise duplicate both folders).
 *
 * @since 12.4.0
 * @param array<int,array<string,mixed>> $entities Upstream entity list.
 * @return array<int,array<string,mixed>>
 */
function snt_explorer_add_entities( $entities ) {
	if ( ! is_array( $entities ) ) {
		return $entities;
	}
	$have = array();
	foreach ( $entities as $entity ) {
		if ( is_array( $entity ) && isset( $entity['id'] ) ) {
			$have[ (string) $entity['id'] ] = true;
		}
	}

	$sections = array();
	if ( current_user_can( 'edit_posts' ) ) {
		$sections[] = snt_explorer_notes_entity();
	}
	if ( current_user_can( 'manage_options' ) ) {
		$sections[] = snt_explorer_albums_entity();
	}

	foreach ( $sections as $section ) {
		if ( is_array( $section ) && empty( $have[ $section['id'] ] ) ) {
			$entities[] = $section;
		}
	}
	return $entities;
}
snt_os_compat_add_filter( 'desktop_mode_my_wordpress_entities', 'openstation_my_wordpress_entities', 'snt_explorer_add_entities' );

/**
 * Filter callback: ride the Explorer window as a `scripts` companion.
 *
 * The shell loads every handle in `scripts` when the window first opens —
 * the same lazy vehicle upstream's WooCommerce integration uses — so the
 * bundle costs nothing on admin pages and nothing for users who never open
 * WP Explorer. Idempotent: an already-present handle is not appended twice.
 *
 * @since 12.4.0
 * @param array<string,mixed> $args Window registration args.
 * @return array<string,mixed>
 */
function snt_explorer_window_args( $args ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}
	$scripts = isset( $args['scripts'] ) ? (array) $args['scripts'] : array();
	if ( ! in_array( SNT_EXPLORER_SCRIPT_HANDLE, $scripts, true ) ) {
		$scripts[] = SNT_EXPLORER_SCRIPT_HANDLE;
	}
	$args['scripts'] = $scripts;
	return $args;
}
snt_os_compat_add_filter( 'desktop_mode_my_wordpress_window_args', 'openstation_my_wordpress_window_args', 'snt_explorer_window_args' );

/*
 * Register the companion bundle's handle on `init` priority 5 — the same
 * slot as every sn-desktop-mode* sibling, for the same load-bearing reason
 * (the loader's v9.52.1 note). Registration only, never enqueue: the shell
 * resolves the handle when the Explorer window opens.
 *
 * Deps: wp-hooks only. The bundle self-aliases the wp.os / wp.desktop
 * globals (the REJECT #11 lesson — the shell's lazy loader injects script
 * tags by URL and never walks the dependency graph, so nothing here may
 * DEPEND on another handle having run first), and it fetches REST with
 * window.fetch + the nonce from its inline config rather than assuming
 * wp-api-fetch is present in the tab.
 */
add_action( 'init', function () {
	if ( ! snt_os_active() ) {
		return;
	}
	wp_register_script(
		SNT_EXPLORER_SCRIPT_HANDLE,
		plugins_url( 'assets/desktop-mode-explorer.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-hooks' ),
		SNT_VERSION,
		true
	);
}, 5 );

/*
 * Attach the bundle's config to its handle at admin_enqueue_scripts:5 —
 * ahead of the shell's payload build at :10, which harvests inline blobs
 * off registered handles so the lazy loader can replay them around the
 * injected script tag (upstream's own WooCommerce integration documents
 * this exact contract). The bundle reads the global lazily, at call time.
 *
 * The discography URL is empty (not absent) for non-admins: the section is
 * never registered for them, but an empty string keeps the JS read
 * unconditional and the intent explicit.
 */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! snt_os_active() || ! snt_os_is_enabled() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$config = array(
		'notesEntityId'  => SNT_EXPLORER_NOTES_ID,
		'albumsEntityId' => SNT_EXPLORER_ALBUMS_ID,
		'albumKind'      => SNT_EXPLORER_ALBUM_KIND,
		'groupId'        => SNT_EXPLORER_GROUP_ID,
		'discographyUrl' => current_user_can( 'manage_options' )
			? esc_url_raw( rest_url( 'signal-noise/v1/desktop/discography' ) )
			: '',
		'restNonce'      => wp_create_nonce( 'wp_rest' ),
	);

	// The category-scoped count probe behind the folder-tile repaint. Same
	// shape as the shell's own probe (per_page=1, id only, all statuses the
	// list shows) PLUS the listQuery the shell's probe ignores — see the
	// restPath/listQuery note on snt_explorer_notes_entity().
	$notes = snt_explorer_notes_entity();
	if ( is_array( $notes ) ) {
		$config['notesLabel']    = (string) $notes['label'];
		$config['notesCountUrl'] = esc_url_raw(
			add_query_arg(
				array_merge(
					$notes['listQuery'],
					array(
						'per_page' => 1,
						'_fields'  => 'id',
						'status'   => 'publish,future,draft,pending,private',
					)
				),
				rest_url( $notes['restPath'] )
			)
		);
	}

	wp_add_inline_script(
		SNT_EXPLORER_SCRIPT_HANDLE,
		sprintf( 'window.snExplorerConfig=%s;', wp_json_encode( $config ) )
	);
}, 5 );

/**
 * REST callback: the discography store, verbatim.
 *
 * Entries were normalized + boundary-sanitized at write time
 * (sn_discography_normalize_entry(); cron is the sole writer), so this is a
 * pass-through of already-clean data — re-sanitizing here would imply the
 * store can hold dirty rows, which the store's own contract forbids.
 *
 * The X-WP-Total / X-WP-TotalPages headers are LOAD-BEARING, not core
 * cargo-culting: the shell's folder-tile counter probes this route and reads
 * the count exclusively from `X-WP-Total` (upstream fetchEntityTotal(),
 * src/my-wordpress/rest.ts). Without them the Discography folder claimed
 * "0" while holding entries.
 *
 * @since 12.4.0
 * @return WP_REST_Response
 */
function snt_explorer_discography_payload() {
	$store   = function_exists( 'sn_discography_get' ) ? sn_discography_get() : array();
	$entries = isset( $store['entries'] ) && is_array( $store['entries'] ) ? array_values( $store['entries'] ) : array();

	$response = new WP_REST_Response(
		array(
			'entries'     => $entries,
			'count'       => count( $entries ),
			'last_synced' => (int) ( $store['last_synced'] ?? 0 ),
		),
		200
	);
	$response->header( 'X-WP-Total', (string) count( $entries ) );
	$response->header( 'X-WP-TotalPages', '1' );
	return $response;
}

/**
 * REST field callback: summarize a Note's provenance chain for the Explorer.
 *
 * null — never a fabricated empty struct — when the post is not a Note, the
 * provenance subsystem is absent, or the chain has no commits: "unsigned" and
 * "signed zero times" are different facts and the bundle renders nothing for
 * null rather than an empty ledger.
 *
 * Reads the UID meta RAW instead of via sn_prov_note_uid(), which MINTS AND
 * PERSISTS a UUID on first read — a REST GET must not write post meta.
 *
 * The commit list is capped at the newest 20 (order preserved, newest last)
 * to bound the payload on long-lived Notes; `versions` still reports the
 * full chain's head version, so the cap is visible, not silent.
 *
 * @since 12.4.0
 * @param array<string,mixed> $post_arr Prepared post row (needs only `id`).
 * @return array<string,mixed>|null
 */
function snt_explorer_provenance_field( $post_arr ) {
	$post_id = (int) ( $post_arr['id'] ?? 0 );
	if ( $post_id <= 0
		|| ! function_exists( 'sn_prov_get_chain' )
		|| ! function_exists( 'sn_prov_is_note' )
		|| ! sn_prov_is_note( $post_id ) ) {
		return null;
	}
	$chain = sn_prov_get_chain( $post_id );
	if ( ! $chain ) {
		return null;
	}

	$commits  = array();
	$anchored = 0;
	$version  = 0;
	foreach ( $chain as $commit ) {
		if ( ! is_array( $commit ) ) {
			continue;
		}
		$status = (string) ( $commit['status'] ?? '' );
		if ( 'confirmed' === $status ) {
			++$anchored;
		}
		$version   = max( $version, (int) ( $commit['version'] ?? 0 ) );
		$commits[] = array(
			'version'      => (int) ( $commit['version'] ?? 0 ),
			'status'       => $status,
			'committed_at' => (string) ( $commit['committed_at'] ?? '' ),
			'content_hash' => (string) ( $commit['content_hash'] ?? '' ),
		);
	}
	if ( ! $commits ) {
		return null;
	}
	$latest = $commits[ count( $commits ) - 1 ];

	$uid = get_post_meta( $post_id, defined( 'SN_PROV_UID_META' ) ? SN_PROV_UID_META : '_sn_prov_uid', true );

	return array(
		'uid'      => is_string( $uid ) && '' !== $uid ? $uid : null,
		'versions' => $version,
		'status'   => $latest['status'],
		'anchored' => $anchored,
		'commits'  => array_slice( $commits, -20 ),
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'signal-noise/v1', '/desktop/discography', array(
		'methods'             => 'GET',
		'callback'            => 'snt_explorer_discography_payload',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );

	// The provenance field registers regardless of shell presence: it is a
	// statement about Notes, not about the Explorer, and other REST readers
	// (the theme, the verifier) may use it. Guarded inside the callback.
	register_rest_field( 'post', 'sn_provenance', array(
		'get_callback' => 'snt_explorer_provenance_field',
		'schema'       => array(
			'description' => __( 'Provenance chain summary for a Note: head version, anchor status, and the newest commits.', 'signal-and-noise-tools' ),
			'type'        => array( 'object', 'null' ),
			'readonly'    => true,
			'context'     => array( 'view', 'edit', 'embed' ),
		),
	) );
} );
