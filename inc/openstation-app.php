<?php
/**
 * Signal & Noise Tools — the Signal & Noise app for OpenStation's App
 * Framework (v13.98.0).
 *
 * WHY THIS EXISTS. v12.4.0 (#751, contributed by OpenStation's maintainer)
 * put a "Signal & Noise" folder inside the shell's WP Explorer through two
 * filters, `openstation_my_wordpress_entities` and `_window_args`. OpenStation
 * 1.1.6 rebuilt WP Explorer on its App Framework and retired both: the hooks
 * reference now marks the entities filter "inert" (it runs, nothing reads it)
 * and the window-args filter "went with the legacy window". Nothing errored,
 * so nothing warned; the folder simply stopped rendering. The module that
 * built it (inc/desktop-mode-explorer.php) is left in place for the REST field
 * it registers and for any pre-1.1.6 shell, but it no longer paints anything.
 *
 * WHAT THIS IS. The same two surfaces -- Notes with their provenance chain,
 * and the Discography -- as a window of their own, declared in one PHP file
 * the framework loads from this plugin's `apps/` directory
 * (`openstation_apps_directories`). Server-rendered: no JavaScript build, no
 * bundle to ride another window, and the phone layer paints it for free.
 *
 * EXPANDABLE BY CONSTRUCTION. The app knows nothing about Notes or albums.
 * It reads sn_os_app_sections(): an ordered list of SECTION descriptors, each
 * a tab. A section supplies data through two callables and the shared frame
 * (apps/signal-noise/parts/frame.php) paints it -- toolbar, list, dossier,
 * pager, empty states. Adding a surface is one descriptor:
 *
 *   add_filter( 'snt_os_app_sections', function ( $sections ) {
 *       $sections[] = array(
 *           'id'         => 'ledger',                    // tab slug (not "main")
 *           'label'      => 'Ledger',
 *           'icon'       => 'dashicons-shield',
 *           'capability' => 'manage_options',           // hidden without it
 *           'position'   => 30,                         // tab order
 *           'rows'       => function ( $state ) { … },  // list page, see below
 *           'dossier'    => function ( $id ) { … },     // one item, or null
 *           'empty'      => array( 'heading' => …, 'description' => … ),
 *       );
 *       return $sections;
 *   } );
 *
 * `rows( State $state, Os $os )` returns array( 'items' => array<array>, 'total' =>
 * int, 'page' => int, 'per_page' => int ); each item carries `id`, `title`,
 * and optionally `subtitle`, `meta` (short text), `thumbnail` (URL), `status`
 * (a post status, drives the tile ribbon), `chip` (array( 'label', 'tone' )).
 * `dossier( string $id )` returns null or array( 'title', 'subtitle',
 * 'thumbnail', 'chips' => array<array{label,tone}>, 'blocks' => array<array{
 * heading, html }> (html ALREADY ESCAPED), 'links' => array<array{label,url}>,
 * 'edit' => array( 'url', 'title' ) ). The state a section reads:
 * `query` (search), `page` (1-based), `item` (the selected id, '' for none).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * label. A descriptor without `id`, `label` and a callable `rows` is dropped.
 *
 * @return array<int,array<string,mixed>>
 */
function snt_os_app_sections() {
	$sections = apply_filters( 'snt_os_app_sections', array() );
	$out      = array();
	foreach ( (array) $sections as $section ) {
		if ( ! is_array( $section ) || empty( $section['id'] ) || empty( $section['label'] ) || ! isset( $section['rows'] ) || ! is_callable( $section['rows'] ) ) {
			continue;
		}
		$id = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $section['id'] ) );
		if ( '' === $id || 'main' === $id ) {
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
 * A Note's provenance, summarised for a tile and a dossier.
 *
 * The chain is inc/provenance-core.php's: ordered commits with `version`,
 * `status` (confirmed | pending | unanchored | genesis), `committed_at`,
 * `content_hash`. Null when the post is not a Note or has no chain -- absent,
 * never an empty ledger.
 *
 * @param int $post_id Post id.
 * @return array<string,mixed>|null
 */
function snt_os_app_note_provenance( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'sn_prov_get_chain' ) || ! function_exists( 'sn_prov_is_note' ) || ! sn_prov_is_note( $post_id ) ) {
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
 * How an anchor status reads: a label and an <os-chip> tone.
 *
 * Same vocabulary the v12.4.0 tile badge used, on the kit's tone scale
 * (neutral | accent | positive | warning | danger).
 *
 * @param string $status Chain status.
 * @return array{label:string,tone:string}
 */
function snt_os_app_anchor_chip( $status ) {
	switch ( (string) $status ) {
		case 'confirmed':
			return array( 'label' => __( 'Anchored', 'signal-and-noise-tools' ), 'tone' => 'positive' );
		case 'pending':
			return array( 'label' => __( 'Awaiting anchor', 'signal-and-noise-tools' ), 'tone' => 'warning' );
		case 'genesis':
			return array( 'label' => __( 'Genesis', 'signal-and-noise-tools' ), 'tone' => 'neutral' );
		default:
			return array( 'label' => __( 'Not yet anchored', 'signal-and-noise-tools' ), 'tone' => 'neutral' );
	}
}

/**
 * Carry the app's stylesheet when the framework could not.
 *
 * The framework registers `<app dir>/signal-noise.css` itself, but only when
 * it can map the file's REAL path to a URL: openstation_apps_path_to_url()
 * answers '' for anything outside wp-content and ABSPATH, and a symlinked
 * plugin directory (a dev checkout, some hosts) resolves through realpath()
 * to exactly that. The window then opens unstyled -- measured in the sandbox
 * on 2026-09-05: everything painted, no grid. This appends our own handle,
 * served from the plugin URL, ONLY when the framework's handle is absent; a
 * plain install sees no change and loads the sheet once.
 *
 * @param array<string,mixed> $window_args openstation_register_window() args.
 * @param string              $id          App id.
 * @return array<string,mixed>
 */
function snt_os_app_window_args( $window_args, $id ) {
	if ( 'signal-noise' !== (string) $id || ! is_array( $window_args ) ) {
		return $window_args;
	}
	$styles = isset( $window_args['styles'] ) ? (array) $window_args['styles'] : array();
	$theirs = function_exists( 'openstation_apps_style_handle' ) ? openstation_apps_style_handle( $id ) : 'openstation-app-' . $id;
	if ( in_array( $theirs, $styles, true ) ) {
		return $window_args;
	}
	$handle = 'snt-os-app-signal-noise';
	if ( function_exists( 'wp_register_style' ) && ! wp_style_is( $handle, 'registered' ) ) {
		$file = ( defined( 'SNT_PATH' ) ? SNT_PATH : dirname( __DIR__ ) . '/' ) . 'apps/signal-noise/signal-noise.css';
		$url  = ( defined( 'SNT_URL' ) ? SNT_URL : plugins_url( '/', __DIR__ ) ) . 'apps/signal-noise/signal-noise.css';
		wp_register_style( $handle, $url, array( 'os-variables' ), is_file( $file ) ? (string) filemtime( $file ) : null );
	}
	$styles[]              = $handle;
	$window_args['styles'] = $styles;
	return $window_args;
}
add_filter( 'openstation_app_window_args', 'snt_os_app_window_args', 10, 2 );
