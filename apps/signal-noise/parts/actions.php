<?php
/**
 * Signal & Noise app — the four server actions the control surface runs.
 *
 * Part of the `signal-noise` app: required by `signal-noise.os.php`, plain
 * `.php` on purpose -- only `*.os.php` files are app entries to the
 * framework loader. This part owns everything a menu pick DOES to a note:
 * trash (the Explorer's, selection-aware), publish, an edge purge, and the
 * anchor re-dispatch. What a note or a signed page IS lives in
 * parts/post-items.php.
 *
 * THE POST TYPE COMES FROM THE OPEN SECTION'S DESCRIPTOR, never from the
 * client and never from a literal: Notes holds `post`, Pages holds `page`,
 * and an id outside the open section's type is refused by every action.
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
 * The post type the OPEN SECTION holds, read from its descriptor.
 *
 * Read from the server's own state and the registry, never from the client:
 * the section decides which post type its actions may touch, so a forged
 * `section` cannot widen an action past what that section lists. A section
 * with no `post_type` (an entry section, or none open) is `post`, the
 * narrowest of the two -- a default that refuses, never one that grants.
 *
 * @param State $state Session state.
 * @return string
 */
function section_post_type( State $state ) {
	$section = \snt_os_app_section( (string) $state->get( 'section' ) );
	$type    = is_array( $section ) ? (string) ( $section['post_type'] ?? '' ) : '';
	return '' !== $type ? $type : 'post';
}

/**
 * May the current user do `$what` to this post?
 *
 * The post type is checked first: an id outside the OPEN SECTION's type is
 * not one of its items, whatever the client sent. `publish` asks the type
 * object's own capability -- `publish_posts` is the POST cap and a page needs
 * `publish_pages`; a type that cannot answer is a refusal, never a guess.
 * `edit_post` and `delete_post` are meta caps and already map per type.
 *
 * @param Os     $os        Host handle.
 * @param int    $id        Post id.
 * @param string $what      edit | delete | publish | manage.
 * @param string $post_type The section's post type; `post` when not given.
 * @return bool
 */
function note_allowed( Os $os, $id, $what, $post_type = 'post' ) {
	$id   = (int) $id;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || (string) $post_type !== (string) $post->post_type ) {
		return false;
	}
	if ( 'edit' === $what ) {
		return (bool) $os->can( 'edit_post', $id );
	}
	if ( 'delete' === $what ) {
		return (bool) $os->can( 'delete_post', $id );
	}
	if ( 'publish' === $what ) {
		$cap = post_publish_cap( (string) $post->post_type );
		return '' !== $cap && $os->can( $cap ) && $os->can( 'edit_post', $id );
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
	$type = section_post_type( $state );
	$ids  = targets( $state, $args );
	foreach ( $ids as $id ) {
		if ( note_allowed( $os, $id, 'delete', $type ) && wp_trash_post( $id ) ) {
			$done[] = $id;
		}
	}
	// The Explorer's pair: a trashed note leaves the selection; a note the
	// action did not touch stays selected. Never a reset of what it did not act on.
	$selected = array_values( array_map( 'strval', (array) $state->get( 'selected', array() ) ) );
	$state->set( 'selected', array_values( array_diff( $selected, array_map( 'strval', $done ) ) ) );
	if ( in_array( (int) $state->get( 'item' ), $done, true ) ) {
		// The dossier is showing a note that no longer exists.
		$state->reset( 'item' )->reset( 'verdict' );
	}
	$count = count( $done );
	if ( 0 === $count ) {
		// One note: the Explorer separates "you may not" from "WordPress refused".
		if ( 1 === count( $ids ) ) {
			$os->toast( note_allowed( $os, $ids[0], 'delete', $type ) ? __( 'Trashing failed.', 'signal-and-noise-tools' ) : __( 'You cannot trash this item.', 'signal-and-noise-tools' ) );
		} else {
			$os->toast( __( 'Nothing could be trashed.', 'signal-and-noise-tools' ) );
		}
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
	// Draft or pending only: a SCHEDULED note keeps its date (core re-asserts
	// `future` for a dated post on write), so publishing it here would write
	// nothing and a "Published." would be false.
	$staged = $post && in_array( (string) $post->post_status, array( 'draft', 'pending' ), true );
	if ( $staged && note_allowed( $os, $id, 'publish', section_post_type( $state ) ) ) {
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
	// Verified, not inferred: the return code says the row was written, not
	// that the note is published. Read it back before saying so.
	$now = (string) get_post_status( $id );
	if ( 'publish' !== $now ) {
		$os->toast( sprintf( /* translators: %s: post status. */ __( 'The note did not publish; its status is still %s.', 'signal-and-noise-tools' ), $now ) );
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
	$type = section_post_type( $state );
	foreach ( targets( $state, $args ) as $id ) {
		if ( ! note_allowed( $os, $id, 'manage', $type ) ) {
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
	// sn_cf_purge_urls() is fire-and-forget: true means the request was SENT,
	// never that the edge is clean. The toast says "dispatched" for that reason.
	$sent = function_exists( 'sn_cf_purge_urls' ) && (bool) \sn_cf_purge_urls( $urls );
	if ( ! $sent ) {
		$os->toast( __( 'The purge was not dispatched.', 'signal-and-noise-tools' ) );
		return;
	}
	// The probe reads what a reader would actually get, later, and writes the
	// verdict. Rescheduling mirrors the save hook: several purges in a minute
	// probe once, after the last. Counted, so the toast promises a probe only
	// when one was booked.
	$probed = 0;
	if ( function_exists( 'wp_schedule_single_event' ) && defined( 'SN_CF_PROBE_HOOK' ) ) {
		foreach ( $ids as $id ) {
			$probe = array( $id );
			$next  = wp_next_scheduled( SN_CF_PROBE_HOOK, $probe );
			if ( $next ) {
				wp_unschedule_event( $next, SN_CF_PROBE_HOOK, $probe );
			}
			if ( false !== wp_schedule_single_event( time() + SN_CF_PROBE_DELAY, SN_CF_PROBE_HOOK, $probe ) ) {
				++$probed;
			}
		}
	}
	$count = count( $urls );
	$os->toast(
		$probed > 0
			? sprintf(
				/* translators: %s: URL count. */
				_n(
					'Purge dispatched for %s URL; the probe checks it in two minutes.',
					'Purge dispatched for %s URLs; the probe checks them in two minutes.',
					$count,
					'signal-and-noise-tools'
				),
				number_format_i18n( $count )
			)
			: sprintf(
				/* translators: %s: URL count. */
				_n( 'Purge dispatched for %s URL.', 'Purge dispatched for %s URLs.', $count, 'signal-and-noise-tools' ),
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
	if ( ! note_allowed( $os, $id, 'manage', section_post_type( $state ) ) ) {
		$os->toast( __( 'The dispatch could not be retried.', 'signal-and-noise-tools' ) );
		return;
	}
	// sn_prov_dispatch() bails SILENTLY on an unconfigured worker, so the gate
	// is asked here, before any sentence about a retry can be written.
	if ( ! anchor_worker_configured() ) {
		$os->toast( __( 'The anchor worker is not configured here.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( ! function_exists( 'sn_prov_get_chain' ) ) {
		$os->toast( __( 'The chain could not be read.', 'signal-and-noise-tools' ) );
		return;
	}
	$versions = array();
	foreach ( (array) \sn_prov_get_chain( $id ) as $commit ) {
		if ( is_array( $commit ) && 'unanchored' === (string) ( $commit['status'] ?? '' ) ) {
			$versions[] = 'v' . (int) ( $commit['version'] ?? 0 );
		}
	}
	if ( array() === $versions ) {
		$os->toast( __( 'Nothing to dispatch: every version is anchored or pending.', 'signal-and-noise-tools' ) );
		return;
	}
	if ( ! function_exists( 'sn_prov_reconcile_post' ) || false === \sn_prov_reconcile_post( $id ) ) {
		$os->toast( __( 'The dispatch could not be retried.', 'signal-and-noise-tools' ) );
		return;
	}
	// The chain's statuses are in flight; the shown verdict is about before.
	// A REQUEST was made; the ledger, not this toast, says whether it landed.
	$state->reset( 'verdict' );
	$os->toast( sprintf( /* translators: %s: versions, e.g. "v3, v4". */ __( 'Re-dispatch requested for %s; the ledger answers when it lands.', 'signal-and-noise-tools' ), implode( ', ', $versions ) ) );
}

/**
 * Is the anchor worker reachable in principle: a URL and a secret configured?
 * The dispatcher itself bails silently without them.
 *
 * @return bool
 */
function anchor_worker_configured() {
	return function_exists( 'sn_prov_worker_url' ) && '' !== (string) \sn_prov_worker_url()
		&& function_exists( 'sn_prov_hmac_secret' ) && '' !== (string) \sn_prov_hmac_secret();
}
