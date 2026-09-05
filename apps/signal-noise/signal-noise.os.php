<?php
/**
 * Signal & Noise — the plugin's window, as an OpenStation app.
 *
 * Composition only: the declaration, the state schema, the server actions,
 * the data payload and the client view. What a section CONTAINS lives in
 * parts/notes.php and parts/discography.php, each a plain .php registering
 * itself through the `snt_os_app_sections` filter -- only `*.os.php` files
 * are app entries. The contract is documented in inc/openstation-app.php.
 *
 * Built the way WP Explorer is built: the PHP half is the window and the
 * truth, the body is a CLIENT VIEW (signal-noise-client.js) where selection,
 * search, filtering and the view switch are instant. Only `go` (a different
 * section, new data) and `edit` (an editor window) reach the server.
 *
 * Successor to the WP Explorer folder of v12.4.0 (#751), whose seam
 * OpenStation 1.1.6 retired.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App;
use OpenStation\App\Os;
use OpenStation\App\State;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/parts/payload.php';
require_once __DIR__ . '/parts/notes.php';
require_once __DIR__ . '/parts/discography.php';

const APP_ID = 'signal-noise';

// Sections are resolved at RENDER time (payload.php), never here: the
// framework loads this file at `init`, and a list frozen then is gated on
// whoever the user was at that instant -- nobody, under WP-CLI or a cron.
return App::define( APP_ID )
	->title( __( 'Signal & Noise', 'signal-and-noise-tools' ) )
	->icon( 'dashicons-megaphone' )
	->size( 1120, 720 )
	->min_size( 640, 440 )
	->placement( 'dock' )
	->capabilities( 'edit_posts' )
	// Repaint when a post changes anywhere on the desktop (a Note edited in
	// its own window, a trash from WP Explorer).
	->watch( 'post' )
	->state(
		array(
			'section' => '', // '' = the root: one folder tile per section.
			'item'    => '', // The open item's id; '' when the dossier is closed. Local.
			'status'  => '', // The status pill; '' = All. Local.
			'query'   => '', // The search field. Local.
			'view'    => 'icons', // icons | list. Local.
		)
	)
	->title_bar_button(
		'refresh',
		array(
			'label'  => __( 'Refresh', 'signal-and-noise-tools' ),
			'icon'   => 'reload',
			'action' => 'refresh',
		)
	)
	->client( __DIR__ . '/signal-noise-client.js' )
	->data( __NAMESPACE__ . '\payload' )
	// Into a section (or back to the root with no section). New data, so a
	// server action; the browser-side facets reset with it.
	->action(
		'go',
		static function ( State $state, Os $os, array $args ) {
			$id      = (string) ( $args['section'] ?? '' );
			$section = '' !== $id ? \snt_os_app_section( $id ) : null;
			$state->set( 'section', $section ? $id : '' )
				->reset( 'item' )
				->reset( 'query' )
				->set( 'status', $section ? (string) ( $section['default_status'] ?? '' ) : '' );
		}
	)
	// The item's editor as a window. The section owns the URL and the
	// capability check runs server-side, whatever the client asked for.
	->action(
		'edit',
		static function ( State $state, Os $os, array $args ) {
			$section = \snt_os_app_section( (string) $state->get( 'section' ) );
			if ( ! $section || empty( $section['edit_url'] ) || ! is_callable( $section['edit_url'] ) ) {
				return;
			}
			$url = (string) call_user_func( $section['edit_url'], (string) ( $args['item'] ?? '' ) );
			if ( '' !== $url ) {
				$os->open_url( $url, (string) ( $args['title'] ?? '' ), (string) ( $section['icon'] ?? '' ) );
			}
		}
	);
