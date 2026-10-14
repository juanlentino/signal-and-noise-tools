<?php
/**
 * Signal & Noise Tools: provenance integrity sweep, one Note's triangle and the sweep over the fleet.
 *
 * Split from inc/provenance-integrity.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Verify one Note's triangle. Read-only against WP state (never mints a uid,
 * never touches the chain); all networking goes through the injected fetcher.
 *
 * Failure codes (mismatch class): hash_mismatch, twin_drift, ledger_missing,
 * ledger_hash_mismatch, signing_key_unpublished. Outage class: twin_unreachable, ledger_unreachable.
 * The ledger leg only runs for a CONFIRMED commit — a pending anchor has no
 * ledger record yet, and flagging that would fabricate drift.
 *
 * @since 9.80.0
 * @param int      $post_id
 * @param callable $fetcher
 * @return array{post_id:int,uid:string,version:int,anchored_version:int,failures:string[]}|null
 *         null when the Note has no real (v1+) commit to verify yet.
 */
function sn_prov_integrity_check_note( $post_id, $fetcher, $published_key_ids = null ) {
	$post_id = (int) $post_id;
	$chain   = sn_prov_get_chain( $post_id );

	// Latest REAL commit (v1+; a genesis-only chain has nothing verifiable).
	$latest = null;
	for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
		if ( (int) ( $chain[ $i ]['version'] ?? 0 ) >= 1 ) {
			$latest = $chain[ $i ];
			break;
		}
	}
	if ( null === $latest ) {
		return null;
	}

	$uid      = (string) get_post_meta( $post_id, SN_PROV_UID_META, true );
	$failures = array();

	// ── Leg (a): stored payload re-canonicalizes to the anchored hash. ──────
	// Same reproduction sn_prov_credential() performs before it will emit a VC.
	$payload = ( isset( $latest['payload'] ) && is_array( $latest['payload'] ) ) ? $latest['payload'] : null;
	$claimed = (string) ( $latest['content_hash'] ?? '' );
	if ( null === $payload || '' === $claimed
		|| sn_prov_content_hash( sn_prov_canonical_json( $payload ) ) !== $claimed ) {
		$failures[] = 'hash_mismatch';
	}

	// ── Leg (b): the live .json twin, through the real public URL. ──────────
	$twin = sn_prov_integrity_fetch_json( sn_prov_integrity_twin_url( (string) get_permalink( $post_id ) ), $fetcher );
	if ( ! is_array( $twin['json'] ) ) {
		$failures[] = 'twin_unreachable'; // network, 404, 5xx, non-JSON: a gap, never a drift claim.
	} elseif ( is_array( $payload ) ) {
		// v14.7.1: prefer content_signed, the prose as signing sees it (the
		// plugin adds it to the twin through the theme's document filter);
		// rendered content_text made every signed Page read as drift, since
		// a shortcode renders there and stays literal in the payload. Then
		// content_text (NOT `content` — the 2026-07-21 live catch); keep the
		// legacy fallback the /verify JS also keeps.
		$live   = (string) ( $twin['json']['content_signed'] ?? ( $twin['json']['content_text'] ?? ( $twin['json']['content'] ?? '' ) ) );
		$signed = (string) ( $payload['content'] ?? '' );
		if ( sn_prov_integrity_flatten( $live ) !== sn_prov_integrity_flatten( $signed ) ) {
			$failures[] = 'twin_drift';
		}
	}

	// ── Leg (c): the public ledger record for the newest CONFIRMED commit. ──
	$anchored = null;
	for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
		if ( 'confirmed' === (string) ( $chain[ $i ]['status'] ?? '' ) && (int) ( $chain[ $i ]['version'] ?? 0 ) >= 1 ) {
			$anchored = $chain[ $i ];
			break;
		}
	}
	// v12.6.6: the ledger directory follows the SUBJECT KIND. This line said
	// 'notes/' unconditionally, so a signed page was looked up in the wrong
	// directory and would have reported ledger_missing — a confident drift
	// claim produced by asking the wrong question.
	//
	// An unresolved kind is a GAP, never a drift claim, and never a guessed
	// URL: the same rule leg (b) already applies to an unreachable twin.
	$kind       = function_exists( 'sn_prov_subject_kind' ) ? (string) sn_prov_subject_kind( get_post( $post_id ) ) : '';
	$ledger_dir = function_exists( 'sn_prov_ledger_dir' ) ? sn_prov_ledger_dir( $kind ) : '';
	if ( null !== $anchored && '' !== $uid && '' === $ledger_dir ) {
		$failures[] = 'subject_kind_unresolved';
	} elseif ( null !== $anchored && '' !== $uid ) {
		$ledger_url = sn_prov_integrity_ledger_base() . $ledger_dir . '/' . rawurlencode( $uid ) . '/v' . (int) $anchored['version'] . '.json';
		$ledger     = sn_prov_integrity_fetch_json( $ledger_url, $fetcher );
		if ( 404 === $ledger['code'] ) {
			$failures[] = 'ledger_missing'; // a real answer: the record is absent from the ledger.
		} elseif ( ! is_array( $ledger['json'] ) ) {
			$failures[] = 'ledger_unreachable';
		} else {
			// Real record shape: content_hash at the top level, sha256:-prefixed
			// or bare (assets/js/prov-verify-core.js reads it identically).
			$rec_hash    = strtolower( (string) preg_replace( '/^sha256:/', '', (string) ( $ledger['json']['content_hash'] ?? '' ) ) );
			$commit_hash = strtolower( (string) ( $anchored['content_hash'] ?? '' ) );
			if ( '' === $rec_hash ) {
				// The record EXISTS but attests nothing — a malformed ledger
				// write is a real finding, never silence (v9.81.0).
				$failures[] = 'ledger_record_malformed';
			} elseif ( '' !== $commit_hash && $rec_hash !== $commit_hash ) {
				$failures[] = 'ledger_hash_mismatch'; // a PRESENT field that disagrees is a contradiction; an absent one is a gap.
			}
		}
	}

	// ── Leg (d): the key this Note's commit NAMES is still published. ───────
	// The fleet-level keys probe asks only whether the CURRENT key id is
	// served. After a rotation every historical Note depends on a RETIRED key,
	// so dropping that entry strands all of them at once — and nothing looked,
	// because the probe was still happily confirming today's key. The identity
	// is not new: the Worker writes pubkey_id onto every ledger record.
	//
	// $published_key_ids is NULL when the key document could not be read. That
	// is an outage, reported once fleet-level, and it must never become a
	// per-Note drift claim: absence is a finding only when the document was
	// actually read. A commit that names no key (pre-pubkey_id) makes no claim
	// either way.
	$named_key = (string) ( $latest['pubkey_id'] ?? '' );
	if ( is_array( $published_key_ids ) && '' !== $named_key
		&& ! in_array( $named_key, array_map( 'strval', $published_key_ids ), true ) ) {
		$failures[] = 'signing_key_unpublished';
	}

	return array(
		'post_id'          => $post_id,
		'uid'              => $uid,
		'version'          => (int) ( $latest['version'] ?? 0 ),
		'anchored_version' => null !== $anchored ? (int) $anchored['version'] : 0,
		'failures'         => $failures,
		'twin_code'        => (int) $twin['code'], // v9.81.0: the sweep tracks consecutive twin 404s for escalation.
	);
}

/**
 * Run one bounded sweep: discover the fleet the way the provenance system
 * does (published posts carrying the uid meta — the snt_prov_anchor_overview
 * / sn_prov_post_by_uid discovery, narrowed to publish because the twin and
 * credential are public-only surfaces), rotate through it oldest-checked-first,
 * verify each batch Note's triangle, and persist state + summary durably.
 *
 * @since 9.80.0
 * @param callable|null $fetcher Injectable for tests; defaults to the wp_safe_remote_get fetcher.
 * @return array The sweep summary (also stored as state['last_sweep']).
 */
function sn_prov_integrity_run_sweep( $fetcher = null ) {
	$fetcher = is_callable( $fetcher ) ? $fetcher : 'sn_prov_integrity_http_fetch';

	// v12.6.6: the sweep walked ONLY post_type 'post', so a signed PAGE was
	// never in its corpus — the instrument built to catch ledger drift could
	// not see half the subjects it was meant to watch. get_posts() defaults to
	// 'post' (documented), which is exactly how the omission read as normal.
	// Same widening the reconcile sweep took in v10.84.0.
	$ids = get_posts(
		array(
			'post_type'      => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => SN_PROV_UID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded corpus (~dozens of Notes), mirrors inc/abilities-provenance.php.
			'no_found_rows'  => true,
		)
	);
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	// v13.70.0: A UID IS NOT A SUBJECT (the v13.69.1 lesson, second site).
	// sn_prov_note_uid() mints on read, so pages that merely rendered a schema
	// identifier carry the meta without ever being opted in. Selecting the fleet
	// by that meta walked ~25 of them, each returning null below and — until the
	// count fix in this release — landing in `clean`. Measured live 2026-09-02:
	// fleet 64 against 38 published Notes.
	if ( function_exists( 'sn_prov_subject_kind' ) ) {
		$ids = array_values( array_filter( $ids, static function ( $id ) {
			return '' !== (string) sn_prov_subject_kind( get_post( (int) $id ) );
		} ) );
	}

	$state = get_option( SN_PROV_INTEGRITY_OPT );
	$state = is_array( $state ) ? $state : array();
	$notes = ( isset( $state['notes'] ) && is_array( $state['notes'] ) ) ? $state['notes'] : array();
	$notes = array_intersect_key( $notes, array_flip( $ids ) ); // prune Notes gone from the fleet.

	$last_checked = array();
	$failing_ids  = array();
	foreach ( $notes as $pid => $row ) {
		$last_checked[ (int) $pid ] = (int) ( $row['last_checked'] ?? 0 );
		// Only a verdict a re-read could CHANGE earns the early slot: a
		// mismatch (drift, hash, ledger, key). An outage is a gap in
		// yesterday's evidence, and absence (no signed commit, unresolved
		// kind) is an owner-side state that no re-read clears; both wait
		// their turn in the rotation.
		$codes = is_array( $row['failures'] ?? null ) ? $row['failures'] : array();
		if ( array() !== array_filter( $codes, 'sn_prov_integrity_is_reconfirmable' ) ) {
			$failing_ids[] = (int) $pid;
		}
	}

	$batch = sn_prov_integrity_select_batch( $ids, $last_checked, SN_PROV_INTEGRITY_NOTES_PER_RUN, $failing_ids );
	$keys_probe = array() !== $ids ? sn_prov_integrity_keys_probe( $fetcher ) : array( 'verdict' => 'skipped', 'code' => 0 );
	$kv_result       = (string) $keys_probe['verdict'];
	// v9.81.0 escalation: a 404 is a real "absent" answer. Persisted across
	// SN_PROV_INTEGRITY_404_STREAK CONSECUTIVE sweeps it stops being an outage
	// and becomes the distinct keys_missing finding. Any non-404 sweep
	// (success, network error, 5xx) resets the streak.
	$file404_streak = ( 404 === (int) $keys_probe['code'] ) ? (int) ( $state['keys_404_streak'] ?? 0 ) + 1 : 0;
	$escalate_404 = ( $file404_streak >= SN_PROV_INTEGRITY_404_STREAK );
	if ( $escalate_404 && 'keys_unreachable' === $kv_result ) {
		$kv_result = 'keys_missing';
	}
	$now   = time();

	$clean       = 0;
	$failed      = 0;
	$unreachable = 0;
	foreach ( $batch as $pid ) {
		// The key document is fleet-level and fetched ONCE above; leg (d)
		// cannot ask its question without it. null (unreadable) keeps leg (d)
		// silent rather than turning an outage into per-Note drift.
		$result   = sn_prov_integrity_check_note( $pid, $fetcher, $keys_probe['published_ids'] ?? null );
		// v13.70.0: NULL means "no signed v1+ commit", i.e. /verify tells a reader
		// no proof exists. It used to become array() here, which took the
		// `array() === $failures` branch below and was counted CLEAN — the
		// instrument reporting its healthiest verdict over the one population it
		// had verified nothing about.
		//
		// It is a FAILURE CODE and not a fourth counter on purpose. This summary
		// is the payload of signal-noise/provenance-integrity-status, which has a
		// byte-identical remote twin, and the remote CONTRACT HASH covers the
		// twins' output_schemas — a new integer key here is a contract 4 -> 5 bump
		// plus a worker release. The count is already derivable from failing[],
		// which the schema declares and whose `failures` array is free-form, so
		// the honest reading survives with the shape frozen. Revisit only if a
		// dedicated counter earns a contract bump on its own merits.
		$failures = null !== $result ? $result['failures'] : array( 'no_signed_commit' );

		// Twin 404 escalation (same consecutive-sweep rule as the keys file).
		$twin_streak = ( null !== $result && 404 === (int) ( $result['twin_code'] ?? 0 ) )
			? (int) ( $notes[ $pid ]['twin_404_streak'] ?? 0 ) + 1
			: 0;
		if ( $twin_streak >= SN_PROV_INTEGRITY_404_STREAK && in_array( 'twin_unreachable', $failures, true ) ) {
			$failures = array_values( array_diff( $failures, array( 'twin_unreachable' ) ) );
			$failures[] = 'twin_missing';
		}

		$mismatches = array_values( array_filter( $failures, static function ( $c ) { return ! sn_prov_integrity_is_outage( $c ); } ) );
		if ( array() === $failures ) {
			$clean++;
		} elseif ( array() === $mismatches ) {
			$unreachable++; // outage-only: a gap in today's evidence, counted apart from drift.
		} else {
			$failed++;
		}

		$notes[ $pid ] = array(
			'uid'             => null !== $result ? $result['uid'] : (string) get_post_meta( (int) $pid, SN_PROV_UID_META, true ),
			'title'           => (string) get_the_title( (int) $pid ),
			'url'             => (string) get_permalink( (int) $pid ),
			'version'         => null !== $result ? $result['version'] : 0,
			'last_checked'    => $now,
			'failures'        => $failures,
			'twin_404_streak' => $twin_streak,
		);
	}

	// v12.6.6: stamp the CORPUS this sweep actually walked. 'fleet' changed
	// meaning the moment pages entered it, and a stored summary that does not
	// say which corpus produced it invites comparing a number against an older
	// one computed over a different population. Repairing an instrument does
	// not repair its log: the record has to say which instrument wrote it.
	$summary = array(
		'swept_at'    => $now,
		'corpus'      => array_values( (array) ( function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : array( 'post' ) ) ),
		'fleet'       => count( $ids ),
		'checked'     => count( $batch ),
		'clean'       => $clean,
		'failed'      => $failed,
		'unreachable' => $unreachable,
		'keys'        => $kv_result,
	);

	$state['notes']           = $notes;
	$state['keys_404_streak'] = $file404_streak;
	$state['last_sweep']      = $summary;
	update_option( SN_PROV_INTEGRITY_OPT, $state, false ); // autoload=no: only read on Health surfaces + the status ability.

	return $summary;
}
