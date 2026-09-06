<?php
/**
 * Signal & Noise app — the four server actions the control surface runs.
 *
 * Part of the `signal-noise` app: required by `signal-noise.os.php`, plain
 * `.php` on purpose -- only `*.os.php` files are app entries to the
 * framework loader. This part owns everything a menu pick DOES to a note:
 * trash (the Explorer's, selection-aware), publish, an edge purge, and the
 * anchor re-dispatch. What a note IS lives in parts/notes.php.
 *
 * THE SELECTION IS RE-CHECKED HERE. The client decides whether a pick scopes
 * to the selection by the Explorer's rule (the clicked row is in a selection
 * of more than one); `targets()` re-applies that rule against the state the
 * server holds, so a forged `selection: true` on a one-member selection still
 * acts on one note. Every capability is asked again, per note, per action.
 *
 * A PURGE NEVER WRITES THE PROBE LOG. It schedules the SAME deferred probe a
 * save schedules, and the probe writes a MEASURED verdict later. v13.87.2:
 * a manual purge that wrote the log moved the stale count with the operator's
 * own button, and the fall read as progress.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App\Os;
use OpenStation\App\State;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Which notes a dispatch acts on: the clicked one, or the whole selection.
 *
 * The selection widens the target set only when the client asked for it AND
 * the clicked note is in the selection the SERVER holds AND that selection
 * has more than one member -- the Explorer's rule, re-derived from state.
 *
 * @param State               $state Session state.
 * @param array<string,mixed> $args  Trigger args (`item`, `selection`).
 * @return int[] Post ids, unique, all greater than zero.
 */
function targets( State $state, array $args ) {
	$clicked = (int) ( $args['item'] ?? 0 );
	$ids     = $clicked > 0 ? array( $clicked ) : array();
	$chosen  = array();
	foreach ( (array) $state->get( 'selected', array() ) as $one ) {
		$one = (int) $one;
		if ( $one > 0 ) {
			$chosen[] = $one;
		}
	}
	$chosen = array_values( array_unique( $chosen ) );
	if ( ! empty( $args['selection'] ) && count( $chosen ) > 1 && in_array( $clicked, $chosen, true ) ) {
		$ids = array_merge( $ids, $chosen );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * May the current user do `$what` to this note?
 *
 * The post type is checked first: an id that is not a `post` is not a Note,
 * whatever the client sent.
 *
 * @param Os     $os   Host handle.
 * @param int    $id   Post id.
 * @param string $what edit | delete | publish | manage.
 * @return bool
 */
function note_allowed( Os $os, $id, $what ) {
	$id   = (int) $id;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || 'post' !== $post->post_type ) {
		return false;
	}
	if ( 'edit' === $what ) {
		return (bool) $os->can( 'edit_post', $id );
	}
	if ( 'delete' === $what ) {
		return (bool) $os->can( 'delete_post', $id );
	}
	if ( 'publish' === $what ) {
		return $os->can( 'publish_posts' ) && $os->can( 'edit_post', $id );
	}
	if ( 'manage' === $what ) {
		return (bool) $os->can( 'manage_options' );
	}
	return false;
}

/**
 * `trash`: move the target notes to the Trash, note by note, gated per note.
 *
 * @param State               $state Session state.
 * @param Os                  $os    Host handle.
 * @param array<string,mixed> $args  Trigger args.
 * @return void
 */
function trash_action( State $state, Os $os, array $args ) {
	$done = array();
	foreach ( targets( $state, $args ) as $id ) {
		if ( note_allowed( $os, $id, 'delete' ) && wp_trash_post( $id ) ) {
			$done[] = $id;
		}
	}
	$state->reset( 'selected' );
	if ( in_array( (int) $state->get( 'item' ), $done, true ) ) {
		// The dossier is showing a note that no longer exists.
		$state->reset( 'item' )->reset( 'verdict' );
	}
	$count = count( $done );
	if ( 0 === $count ) {
		$os->toast( __( 'Nothing could be trashed.', 'signal-and-noise-tools' ) );
		return;
	}
	$os->toast(
		1 === $count
			? __( 'Moved to the Trash.', 'signal-and-noise-tools' )
			: sprintf(
				/* translators: %s: trashed count. */
				_n( 'Moved %s item to the Trash.', 'Moved %s items to the Trash.', $count, 'signal-and-noise-tools' ),
				number_format_i18n( $count )
			)
	);
	$os->announce( 'post', 'trashed', $done );
}

/**
 * `publish`: publish ONE note. Never the selection: a signature is permanent,
 * so this is one deliberate act at a time.
 *
 * `wp_update_post()` fires the plugin's own publish path -- the provenance
 * signature, the edge purge, the schedule sync -- exactly as the editor does.
 *
 * @param State               $state Session state.
 * @param Os                  $os    Host handle.
 * @param array<string,mixed> $args  Trigger args.
 * @return void
 */
function publish_action( State $state, Os $os, array $args ) {
	$id     = (int) ( $args['item'] ?? 0 );
	$post   = $id > 0 ? get_post( $id ) : null;
	$done   = false;
	$staged = $post && in_array( (string) $post->post_status, array( 'draft', 'pending', 'future' ), true );
	if ( $staged && note_allowed( $os, $id, 'publish' ) ) {
		$done = (bool) wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
	}
	if ( ! $done ) {
		$os->toast( __( 'Nothing could be published.', 'signal-and-noise-tools' ) );
		return;
	}
	// The chain gained a version; the shown verdict is about the old one.
	$state->reset( 'verdict' );
	$os->toast( __( 'Published.', 'signal-and-noise-tools' ) );
	$os->announce( 'post', 'updated', array( $id ) );
}

/**
 * `purge`: purge the target notes' URLs at the edge, then schedule the same
 * deferred probe a save schedules -- which is the only thing that may ever
 * write the probe log.
 *
 * @param State               $state Session state.
 * @param Os                  $os    Host handle.
 * @param array<string,mixed> $args  Trigger args.
 * @return void
 */
function purge_action( State $state, Os $os, array $args ) {
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! \sn_cf_is_configured() ) {
		$os->toast( __( 'Cloudflare is not configured.', 'signal-and-noise-tools' ) );
		return;
	}
	$ids  = array();
	$urls = array();
	foreach ( targets( $state, $args ) as $id ) {
		if ( ! note_allowed( $os, $id, 'manage' ) ) {
			continue;
		}
		$ids[] = $id;
		if ( function_exists( 'sn_cf_post_purge_urls' ) ) {
			$urls = array_merge( $urls, (array) \sn_cf_post_purge_urls( $id, get_post( $id ) ) );
		}
	}
	$urls = array_values(
		array_unique(
			array_filter(
				$urls,
				static function ( $url ) {
					return is_string( $url ) && '' !== $url;
				}
			)
		)
	);
	if ( array() === $ids || array() === $urls ) {
		$os->toast( __( 'Nothing to purge.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( function_exists( 'sn_cf_purge_urls' ) ) {
		\sn_cf_purge_urls( $urls );
	}
	// The purge above is fire-and-forget and cannot report whether it worked.
	// The probe reads what a reader would actually get, later, and writes the
	// verdict. Rescheduling mirrors the save hook: several purges in a minute
	// probe once, after the last.
	if ( function_exists( 'wp_schedule_single_event' ) && defined( 'SN_CF_PROBE_HOOK' ) ) {
		foreach ( $ids as $id ) {
			$probe = array( $id );
			$next  = wp_next_scheduled( SN_CF_PROBE_HOOK, $probe );
			if ( $next ) {
				wp_unschedule_event( $next, SN_CF_PROBE_HOOK, $probe );
			}
			wp_schedule_single_event( time() + SN_CF_PROBE_DELAY, SN_CF_PROBE_HOOK, $probe );
		}
	}
	$count = count( $urls );
	$os->toast(
		sprintf(
			/* translators: %s: purged URL count. */
			_n(
				'Purged %s URL at the edge; the probe checks it in two minutes.',
				'Purged %s URLs at the edge; the probe checks them in two minutes.',
				$count,
				'signal-and-noise-tools'
			),
			number_format_i18n( $count )
		)
	);
}

/**
 * `anchor`: re-dispatch ONE note's unanchored commits (dropped-webhook
 * recovery). Offered only where there is something to dispatch; the chain is
 * append-only, so this is the only honest per-note advance.
 *
 * @param State               $state Session state.
 * @param Os                  $os    Host handle.
 * @param array<string,mixed> $args  Trigger args.
 * @return void
 */
function anchor_action( State $state, Os $os, array $args ) {
	$id = (int) ( $args['item'] ?? 0 );
	if ( ! note_allowed( $os, $id, 'manage' ) ) {
		$os->toast( __( 'The dispatch could not be retried.', 'signal-and-noise-tools' ) );
		return;
	}
	$unanchored = false;
	if ( function_exists( 'sn_prov_get_chain' ) ) {
		foreach ( (array) \sn_prov_get_chain( $id ) as $commit ) {
			if ( is_array( $commit ) && 'unanchored' === (string) ( $commit['status'] ?? '' ) ) {
				$unanchored = true;
				break;
			}
		}
	}
	if ( ! $unanchored ) {
		$os->toast( __( 'Nothing to dispatch: every version is anchored or pending.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( ! function_exists( 'sn_prov_reconcile_post' ) || false === \sn_prov_reconcile_post( $id ) ) {
		$os->toast( __( 'The dispatch could not be retried.', 'signal-and-noise-tools' ) );
		return;
	}
	// The chain's statuses are in flight; the shown verdict is about before.
	$state->reset( 'verdict' );
	$os->toast( __( 'Anchor dispatch retried; the ledger answers when it lands.', 'signal-and-noise-tools' ) );
}
