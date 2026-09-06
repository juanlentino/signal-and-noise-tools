<?php
/**
 * Signal & Noise Tools — the Signal & Noise app for OpenStation's App
 * Framework (v13.98.0; rebuilt as a client view in #1049).
 *
 * WHY THIS EXISTS. v12.4.0 (#751, contributed by OpenStation's maintainer)
 * put a "Signal & Noise" folder inside the shell's WP Explorer through two
 * filters, `openstation_my_wordpress_entities` and `_window_args`. OpenStation
 * 1.1.6 rebuilt WP Explorer on its App Framework and retired both: the hooks
 * reference marks the entities filter "inert" (it runs, nothing reads it)
 * and the window-args filter "went with the legacy window". Nothing errored,
 * so nothing warned; the folder simply stopped rendering. The module that
 * built it (inc/desktop-mode-explorer.php) stays for the REST field it
 * registers, but it no longer paints anything.
 *
 * WHAT THIS IS. The same two surfaces -- Notes with their provenance chain,
 * and the Discography -- as a window of their own, declared in one PHP file
 * the framework loads from this plugin's `apps/` directory
 * (`openstation_apps_directories`), and painted the way WP Explorer paints
 * itself: the PHP half returns `data()`, a CLIENT VIEW (signal-noise-client.js,
 * plain JS against the runtime's public API, no build) paints folder tiles at
 * the root, `<os-tile>` canvases with the kit's status ribbons, the shared
 * status pills and search, an `<os-table>` list view, and an item dossier in a
 * side pane (a page on the phone). Selection, search and filtering never make
 * a request; only opening the editor does.
 *
 * EXPANDABLE BY CONSTRUCTION. The app knows nothing about Notes or albums; the
 * client view renders any section from the fields its items carry. Adding a
 * surface is one PHP descriptor through the `snt_os_app_sections` filter:
 *
 *   'id'             => 'ledger',            // slug
 *   'label'          => 'Ledger',
 *   'icon'           => 'dashicons-shield',  // Dashicons class or image URL
 *   'kind'           => 'ledger',            // the tile `type` AND the drag payload's `kind`, a word of yours
 *   'capability'     => 'manage_options',    // hidden without it
 *   'position'       => 30,                  // folder order at the root
 *   'post_type'      => 'page',              // optional; a `post`-kind section's post type. The four server actions refuse an id outside it, and it answers the publish capability. Absent = 'post'.
 *   'hasDossier'     => true,                // optional; the client paints the note dossier beside this section's items. A DESCRIPTOR field, because the client asked `section.id === 'notes'` until a second post section proved an id cannot answer what a section HAS.
 *   'statuses'       => array( array( 'value' => 'publish', 'label' => 'Published' ), … ), // optional pills; '' (All) is added
 *   'default_status' => 'publish',           // optional; '' = All
 *   'columns'        => array( array( 'key' => 'versions', 'label' => 'Versions' ), … ), // optional list-view columns; each `key` reads the item's `columns[key]`
 *   'restPath'       => 'wp/v2/posts',       // optional; the REST path a row dragged to the desktop or the Trash resolves through. No drag without it.
 *   'count'          => function () {},      // optional; count( items() ) otherwise
 *   'items'          => function () {},      // every item, newest first (capped at SN_OS_APP_ITEM_CAP)
 *   'edit_url'       => function ( $id ) {}, // optional; the `edit` action opens it as a window
 *
 * An ITEM: `id`, `title`, `subtitle`, `thumbnail` (URL or ''), `icon`, `status`
 * (a post status; drives the tile ribbon and the pills), `statusLabel`, `date`
 * (ISO), `dateLabel`, `badge` (array( 'text', 'tone' ) with a kit tone --
 * success | warning | danger | info | neutral -- or null), `columns` (extra
 * list-view cells keyed by `key`), and `detail`: `hero` (URL), `facts` (list of
 * [ label, value ]), `blocks` (list of array( 'heading', 'kind' => 'table' |
 * 'code' | 'text', … )), `actions` (list of array( 'label', 'variant',
 * 'dispatch' => 'edit', 'args' ) or array( 'label', 'url' )). Everything is
 * plain text; the client escapes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Items per section shipped to the browser; the client filters and pages locally. */
if ( ! defined( 'SN_OS_APP_ITEM_CAP' ) ) {
	define( 'SN_OS_APP_ITEM_CAP', 400 );
}

/**
 * Tell the App Framework where this plugin keeps its apps.
 *
 * The filter only fires inside OpenStation's own loader, so the app file is
 * never included without the framework that defines the classes it uses.
 *
 * @param string[] $dirs Absolute directories.
 * @return string[]
 */
function snt_os_app_directories( $dirs ) {
	$dirs   = is_array( $dirs ) ? $dirs : array();
	$dirs[] = rtrim( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __DIR__ ) . '/', '/\\' ) . '/apps';
	return $dirs;
}
add_filter( 'openstation_apps_directories', 'snt_os_app_directories' );

/**
 * The ordered, capability-filtered sections of the Signal & Noise app.
 *
 * Built-ins register through the same filter as anyone else (see the app's
 * parts), so the registry has one shape and one order rule: `position`, then
 * label. A descriptor without `id`, `label` and a callable `items` is dropped.
 * Resolved on every call -- never frozen at registration time -- so the
 * current user's capabilities gate what the window offers.
 *
 * @return array<int,array<string,mixed>>
 */
function snt_os_app_sections() {
	$sections = apply_filters( 'snt_os_app_sections', array() );
	$out      = array();
	foreach ( (array) $sections as $section ) {
		if ( ! is_array( $section ) || empty( $section['id'] ) || empty( $section['label'] ) || ! isset( $section['items'] ) || ! is_callable( $section['items'] ) ) {
			continue;
		}
		$id = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $section['id'] ) );
		if ( '' === $id ) {
			continue;
		}
		$capability = (string) ( $section['capability'] ?? '' );
		if ( '' !== $capability && function_exists( 'current_user_can' ) && ! current_user_can( $capability ) ) {
			continue;
		}
		$section['id']       = $id;
		$section['position'] = (int) ( $section['position'] ?? 100 );
		$out[ $id ]          = $section;
	}
	uasort(
		$out,
		static function ( $a, $b ) {
			return ( $a['position'] <=> $b['position'] ) ?: strcmp( (string) $a['label'], (string) $b['label'] );
		}
	);
	return array_values( $out );
}

/**
 * A section descriptor by id, or null.
 *
 * @param string $id Section id.
 * @return array<string,mixed>|null
 */
function snt_os_app_section( $id ) {
	foreach ( snt_os_app_sections() as $section ) {
		if ( (string) $section['id'] === (string) $id ) {
			return $section;
		}
	}
	return null;
}

/**
 * A provenance subject's chain, summarised for a tile badge and a dossier.
 *
 * The chain is inc/provenance-core.php's: ordered commits with `version`,
 * `status` (confirmed | pending | unanchored | genesis), `committed_at`,
 * `content_hash`. Null when the post is not a provenance SUBJECT or has no
 * chain -- absent, never an empty ledger. A subject is signed on publish, so
 * a scheduled or draft one has none yet; that is a fact about the post, not
 * a failure.
 *
 * THE GATE IS THE SUBJECT KIND, not `sn_prov_is_note()`. Asking "is this a
 * Note?" answered `false` for every signed page, so a Pages section would
 * have painted a page that IS in the ledger as "no chain" -- a wrong answer
 * that looks like a measurement. `sn_prov_subject_kind()` is the one place
 * that decides what gets signed (note | page | ''), and a note still
 * resolves to `note`, so the Notes path is unchanged.
 *
 * @param int $post_id Post id.
 * @return array<string,mixed>|null
 */
function snt_os_app_note_provenance( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'sn_prov_get_chain' ) || ! function_exists( 'sn_prov_subject_kind' ) ) {
		return null;
	}
	$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
	if ( ! is_object( $post ) || '' === (string) sn_prov_subject_kind( $post ) ) {
		return null;
	}
	$commits = array();
	foreach ( (array) sn_prov_get_chain( $post_id ) as $commit ) {
		if ( ! is_array( $commit ) ) {
			continue;
		}
		$commits[] = array(
			'version'      => (int) ( $commit['version'] ?? 0 ),
			'status'       => (string) ( $commit['status'] ?? '' ),
			'committed_at' => (string) ( $commit['committed_at'] ?? '' ),
			'content_hash' => (string) ( $commit['content_hash'] ?? '' ),
		);
	}
	if ( array() === $commits ) {
		return null;
	}
	$latest   = $commits[ count( $commits ) - 1 ];
	$anchored = count( array_filter( $commits, static function ( $c ) { return 'confirmed' === $c['status']; } ) );
	$uid      = get_post_meta( $post_id, defined( 'SN_PROV_UID_META' ) ? SN_PROV_UID_META : '_sn_prov_uid', true );
	return array(
		'uid'      => is_string( $uid ) && '' !== $uid ? $uid : null,
		'versions' => max( array_column( $commits, 'version' ) ),
		'status'   => $latest['status'],
		'anchored' => $anchored,
		'commits'  => $commits,
	);
}

/**
 * How an anchor status reads: a label and an <os-badge> tone.
 *
 * The vocabulary #751 painted on its tiles, on the kit's badge tones
 * (success | warning | danger | info | neutral).
 *
 * @param string $status Chain status.
 * @return array{label:string,tone:string}
 */
function snt_os_app_anchor_badge( $status ) {
	switch ( (string) $status ) {
		case 'confirmed':
			return array( 'label' => __( 'Anchored', 'signal-and-noise-tools' ), 'tone' => 'success' );
		case 'pending':
			return array( 'label' => __( 'Awaiting anchor', 'signal-and-noise-tools' ), 'tone' => 'warning' );
		case 'genesis':
			return array( 'label' => __( 'Genesis', 'signal-and-noise-tools' ), 'tone' => 'info' );
		default:
			return array( 'label' => __( 'Not yet anchored', 'signal-and-noise-tools' ), 'tone' => 'neutral' );
	}
}

/**
 * Carry the app's stylesheet and client script when the framework could not.
 *
 * The framework registers `<app dir>/signal-noise.css` and the `client()`
 * script itself, but only when it can map each file's REAL path to a URL:
 * openstation_apps_path_to_url() answers '' for anything outside wp-content
 * and ABSPATH, and a symlinked plugin directory (a dev checkout, some hosts)
 * resolves through realpath() to exactly that. The window then opens
 * unstyled, or -- for a client view -- never paints at all. This appends our
 * own handles, served from the plugin URL, ONLY when the framework's are
 * absent; a plain install sees no change and loads each file once.
 *
 * @param array<string,mixed> $window_args openstation_register_window() args.
 * @param string              $id          App id.
 * @return array<string,mixed>
 */
function snt_os_app_window_args( $window_args, $id ) {
	if ( 'signal-noise' !== (string) $id || ! is_array( $window_args ) ) {
		return $window_args;
	}
	$base_path = ( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __DIR__ ) . '/' ) . 'apps/signal-noise/';
	$base_url  = ( defined( 'SNT_URL' ) ? SNT_URL : plugins_url( '/', __DIR__ ) ) . 'apps/signal-noise/';

	$styles = isset( $window_args['styles'] ) ? (array) $window_args['styles'] : array();
	$theirs = function_exists( 'openstation_apps_style_handle' ) ? openstation_apps_style_handle( $id ) : 'openstation-app-' . $id;
	if ( ! in_array( $theirs, $styles, true ) ) {
		$handle = 'snt-os-app-signal-noise';
		if ( function_exists( 'wp_register_style' ) && ! wp_style_is( $handle, 'registered' ) ) {
			$file = $base_path . 'signal-noise.css';
			wp_register_style( $handle, $base_url . 'signal-noise.css', array( 'os-variables' ), is_file( $file ) ? (string) filemtime( $file ) : null );
		}
		$styles[]              = $handle;
		$window_args['styles'] = $styles;
	}

	$scripts = isset( $window_args['scripts'] ) ? (array) $window_args['scripts'] : array();
	if ( ! in_array( 'openstation-app-' . $id . '-client', $scripts, true ) ) {
		$handle = 'snt-os-app-signal-noise-client';
		if ( function_exists( 'wp_register_script' ) && ! wp_script_is( $handle, 'registered' ) ) {
			$file = $base_path . 'signal-noise-client.js';
			wp_register_script( $handle, $base_url . 'signal-noise-client.js', array( 'wp-i18n' ), is_file( $file ) ? (string) filemtime( $file ) : null, true );
		}
		$scripts[]              = $handle;
		$window_args['scripts'] = $scripts;
	}
	return $window_args;
}
add_filter( 'openstation_app_window_args', 'snt_os_app_window_args', 10, 2 );
