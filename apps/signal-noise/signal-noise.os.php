<?php
/**
 * Signal & Noise — the plugin's window, as an OpenStation app.
 *
 * Composition only: the declaration, the state schema, the actions, and one
 * tab per section from snt_os_app_sections() (inc/openstation-app.php, which
 * also documents the section contract). The body is painted by the shared
 * frame in parts/frame.php; what a section CONTAINS lives in parts/notes.php
 * and parts/discography.php, each a plain .php registering itself through
 * the `snt_os_app_sections` filter -- only `*.os.php` files are app entries.
 *
 * Successor to the WP Explorer folder of v12.4.0 (#751), whose seam
 * OpenStation 1.1.6 retired. Server views throughout: no build, no bundle.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App;
use OpenStation\App\Os;
use OpenStation\App\State;
use function OpenStation\App\Html\esc;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/parts/frame.php';
require_once __DIR__ . '/parts/notes.php';
require_once __DIR__ . '/parts/discography.php';

const APP_ID = 'signal-noise';

// Sections are resolved at RENDER time, never here. The framework loads this
// file at `init` (priority 10); a list of tabs frozen then would be gated on
// whoever the current user was at that instant -- nobody, under WP-CLI or a
// cron -- and the window would open on "Nothing to show". The rebuilt WP
// Explorer computes its sections on every render for the same reason; the
// switcher in the body (parts/frame.php) is this app's equivalent of tabs.
return App::define( APP_ID )
	->title( __( 'Signal & Noise', 'signal-and-noise-tools' ) )
	->icon( 'dashicons-megaphone' )
	->size( 1080, 700 )
	->min_size( 640, 440 )
	->placement( 'dock' )
	->capabilities( 'edit_posts' )
	// Repaint when a post changes anywhere on the desktop (a Note edited in
	// its own window, a trash from WP Explorer).
	->watch( 'post' )
	->state(
		array(
			'section' => '', // '' = the first section the user may use.
			'item'    => '', // The selected row's id; '' when the dossier is closed.
			'query'   => '',
			'page'    => 1,
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
	->action(
		'section',
		static function ( State $state, Os $os, array $args ) {
			$state->set( 'section', (string) ( $args['to'] ?? '' ) )->set( 'page', 1 )->reset( 'item' )->reset( 'query' );
		}
	)
	->action(
		'open',
		static function ( State $state, Os $os, array $args ) {
			$state->set( 'item', (string) ( $args['item'] ?? '' ) );
		}
	)
	->action(
		'back',
		static function ( State $state ) {
			$state->reset( 'item' );
		}
	)
	->action(
		'search',
		static function ( State $state ) {
			$state->set( 'page', 1 )->reset( 'item' );
		}
	)
	->action(
		'page',
		static function ( State $state, Os $os, array $args ) {
			$state->set( 'page', max( 1, (int) ( $args['to'] ?? 1 ) ) )->reset( 'item' );
		}
	)
	// Open the item's edit screen as a window. The section computed the URL
	// when it built the dossier; the button carries the section and item
	// back, and the section's own capability check runs again server-side.
	->action(
		'open-edit',
		static function ( State $state, Os $os, array $args ) {
			$section = section_by_id( (string) ( $args['section'] ?? '' ) );
			$dossier = $section ? dossier_of( $section, (string) ( $args['item'] ?? '' ) ) : null;
			if ( is_array( $dossier ) && ! empty( $dossier['edit']['url'] ) ) {
				$os->open_url( (string) $dossier['edit']['url'], (string) ( $dossier['edit']['title'] ?? '' ), (string) ( $section['icon'] ?? '' ) );
			}
		}
	)
	->view( __NAMESPACE__ . '\app_view' );
