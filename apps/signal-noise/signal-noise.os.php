<?php
/**
 * Signal & Noise — the plugin's window, as an OpenStation app.
 *
 * Composition only: the declaration, the state schema, the server actions,
 * the data payload and the client view. What a section CONTAINS lives in its
 * own part -- notes, pages, discography, citations, schedules -- each a plain
 * .php registering itself through the `snt_os_app_sections` filter, because
 * only `*.os.php` files are app entries. Notes and Pages are one surface over
 * two post types and share parts/post-items.php. The descriptor contract is
 * documented in inc/openstation-app.php.
 *
 * Built the way WP Explorer is built: the PHP half is the window and the
 * truth, the body is a CLIENT VIEW (signal-noise-client.js) where selection,
 * search, filtering and the view switch are instant. Seven actions reach the
 * server: `go` (a different section, new data), `edit` (an editor window),
 * `verify` (a live re-check), and the control surface's four -- `trash`,
 * `publish`, `purge` and `anchor`, all four in parts/actions.php, each
 * re-checking the selection and the capability the client claimed.
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
require_once __DIR__ . '/parts/post-items.php';
require_once __DIR__ . '/parts/notes.php';
require_once __DIR__ . '/parts/pages.php';
require_once __DIR__ . '/parts/discography.php';
require_once __DIR__ . '/parts/actions.php';
// The two entry sections. Guarded because they are read-only glances over
// optional stores: an install without the citations table or the schedule
// engine still opens the window.
if ( file_exists( __DIR__ . '/parts/citations.php' ) ) {
	require_once __DIR__ . '/parts/citations.php';
}
if ( file_exists( __DIR__ . '/parts/schedules.php' ) ) {
	require_once __DIR__ . '/parts/schedules.php';
}

const APP_ID = 'signal-noise';

/** Where the control surface's four handlers live (parts/actions.php). */

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
			'section'  => '', // '' = the root: one folder tile per section.
			'item'     => '', // The open item's id; '' when the dossier is closed. Local.
			'status'   => '', // The status pill; '' = All. Local.
			'query'    => '', // The search field. Local.
			'view'     => 'icons', // icons | list. Local.
			'verdict'  => array(), // The last re-check verdict { post_id, tone, text, meta, checked_at }; cleared by go. Server-only.
			'selected' => array(), // Selected post ids as strings. Local, except that trash and go reset it.
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
	// Static values that ship once with the window config (`ctx.extra` in the
	// client): the ability's run URL, so the client never spells the abilities
	// path itself. rest_url() carries the pretty or ?rest_route= form the site
	// uses, which is also what the runtime's nonce injection keys on.
	->config(
		array(
			'dossierUrl' => rest_url( 'wp-abilities/v1/abilities/signal-noise/note-dossier/run' ),
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
				->reset( 'verdict' )
				->reset( 'selected' )
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
	)
	// The re-check: the server walks what it can walk (the twin, the ledger
	// record, the published key ids) and the verdict lands in state, which
	// payload() projects into data. Gated on THIS note, not the app.
	->action(
		'verify',
		static function ( State $state, Os $os, array $args ) {
			$id = (int) ( $args['item'] ?? 0 );
			if ( $id <= 0 || ! $os->can( 'edit_post', $id ) || ! function_exists( 'sn_note_dossier_verify' ) ) {
				$state->reset( 'verdict' );
				return;
			}
			$state->set( 'verdict', \sn_note_dossier_verify( $id ) );
		}
	)
	// The control surface. Each handler re-derives its targets from the
	// SERVER's selection and asks the capability again, per note: the client
	// decides what to offer, never what is permitted. parts/actions.php.
	->action( 'trash', __NAMESPACE__ . '\\trash_action' )
	->action( 'publish', __NAMESPACE__ . '\\publish_action' )
	->action( 'purge', __NAMESPACE__ . '\\purge_action' )
	->action( 'anchor', __NAMESPACE__ . '\\anchor_action' );
