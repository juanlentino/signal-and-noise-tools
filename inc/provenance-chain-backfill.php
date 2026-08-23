<?php
/**
 * Signal & Noise Tools: one-shot WP-side chain backfill from the public ledger.
 *
 * v10.3.0. The July 2026 worker-side backfill anchored 14 pre-existing Notes
 * in the ledger (all confirmed in block 958897) but never wrote their WP-side
 * chain meta — the chain is written by the plugin's own publish/anchor path,
 * which backfilled Notes never traversed. WP consequences: their confirm
 * callbacks 404ed hourly (version-not-in-chain; worker v1.8.2 now drops those
 * rows), their credential endpoint 404s, and the Provenance panel counts them
 * out. This module imports the missing v1 commits FROM the ledger records,
 * refusing anything that does not self-verify.
 *
 * Verification gates, all required and fixture-pinned
 * (tests/provenance-chain-backfill.php):
 *   - the record's payload.note_uid equals the post's own `_sn_prov_uid`;
 *   - the recomputed canonical hash equals the record's content_hash (the
 *     SAME sn_prov_canonical_json + sn_prov_content_hash the dispatcher uses);
 *   - the OTS status is `confirmed` with a numeric bitcoin_block;
 *   - the payload version is 1 and the post's chain carries NO v1+ commit
 *     (v10.3.1: genesis seeded a v0 entry on every Note that existed at
 *     genesis time, so the backfilled chains are [v0]-only, not empty — the
 *     import appends v1 AFTER the genesis entry; idempotent either way).
 *
 * The fetch layer is the integrity module's guarded fetcher (fixed known
 * host, wp_safe_remote_get, bounded timeout). An unreachable ledger skips
 * with its own reason — an outage is never imported as data.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Two bounds on a RUN, and none on the COUNT (v12.22.1).
 *
 * The cap used to bound both, and the count is what a human reads. It was set
 * to 25 as headroom over "14 known candidates today" — then v12.8.0 widened the
 * corpus from posts to posts AND pages, the population grew past 25, and the
 * panel started reporting the CAP as if it were a census: "25 published Notes
 * cannot currently be verified" was really "at least 25, and this panel cannot
 * tell you the number". A guard that silently becomes the answer is worse than
 * no guard, because it reads exactly like a measurement.
 *
 * So: sn_prov_backfill_candidates() now counts EVERY candidate, and the bounds
 * moved onto the run, where the actual cost is. Each candidate costs one
 * ledger fetch at SN_PROV_INTEGRITY_FETCH_TIMEOUT (5s) worst case, sequentially,
 * inside one admin-post request — 61 subjects could be five minutes. The time
 * budget is the real limiter and the count ceiling is the backstop; whichever
 * trips first, the run stops and REPORTS WHAT IS LEFT, so a partial pass reads
 * as progress rather than as a failure to finish.
 */
const SN_PROV_BACKFILL_CAP = 100;

/** Wall-clock budget for one run, in seconds. Filterable for a slow ledger. */
const SN_PROV_BACKFILL_TIME_BUDGET = 20;

/**
 * True when the chain already carries a REAL commit (version >= 1). A
 * genesis-only [v0] chain does not count: v0 is the site-wide baseline
 * genesis persisted onto every then-existing Note, and a chain without a v1
 * is exactly the state that 404s confirm callbacks and credentials.
 *
 * @param array $chain sn_prov_get_chain() result.
 * @return bool
 */
function sn_prov_backfill_chain_has_real_commit( $chain ) {
	foreach ( (array) $chain as $entry ) {
		if ( (int) ( $entry['version'] ?? 0 ) >= 1 ) {
			return true;
		}
	}
	return false;
}

/**
 * Post IDs eligible for import: published posts carrying the provenance UID
 * meta whose chain has NO v1+ commit (empty, or genesis-v0-only). A post
 * with any real commit is never touched — this module only fills the
 * backfilled-Note gap, it never merges into a live chain.
 *
 * @return int[] Post IDs, capped.
 */
function sn_prov_backfill_candidates() {
	// v12.8.0: the candidate corpus follows the subject set, like the reconcile
	// and integrity sweeps. A signed PAGE whose chain meta is missing is exactly
	// the gap this module fills; it was simply never eligible to be found.
	// -1, not 100: this is a census now, and a bounded query is the same lie in
	// a different place — it would silently stop counting on a corpus of 101.
	// `fields => ids` keeps it one indexed meta lookup returning integers.
	$ids = get_posts( array(
		'post_type'   => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
	) );
	$out = array();
	foreach ( (array) $ids as $id ) {
		$chain = sn_prov_get_chain( (int) $id );
		// Two shapes qualify: never imported (no real commit), and imported
		// UNSIGNED by the v10.3.x builder (v10.67.0). The second only became
		// visible once something asked the question the panel never did —
		// "can this Note actually produce a credential?"
		if ( ! sn_prov_backfill_chain_has_real_commit( $chain ) || sn_prov_backfill_chain_needs_signature( $chain ) ) {
			$out[] = (int) $id;
		}
	}
	return $out;
}

/**
 * Validate one ledger record and build the v1 chain commit from it. Pure.
 *
 * The commit reproduces the dispatcher's exact shape (version, parent,
 * content_hash, bearing_hash, payload, status, committed_at) plus
 * bitcoin_block (it imports a CONFIRMED anchor) and a `backfilled_at` marker
 * so the row's own provenance is visible in the meta forever.
 *
 * @param string $uid    The post's `_sn_prov_uid` (the ledger key).
 * @param mixed  $record Decoded notes/<uid>/v1.json, or null.
 * @return array {ok: bool, commit?: array, reason?: string}
 */
function sn_prov_backfill_commit_from_record( $uid, $record ) {
	if ( ! is_array( $record ) || ! isset( $record['payload'] ) || ! is_array( $record['payload'] ) ) {
		return array( 'ok' => false, 'reason' => 'record_malformed' );
	}
	$payload = $record['payload'];
	if ( (string) ( $payload['note_uid'] ?? '' ) !== (string) $uid ) {
		return array( 'ok' => false, 'reason' => 'uid_mismatch' );
	}
	if ( 1 !== (int) ( $payload['version'] ?? 0 ) ) {
		return array( 'ok' => false, 'reason' => 'not_v1' );
	}
	$ots = isset( $record['ots'] ) && is_array( $record['ots'] ) ? $record['ots'] : array();
	if ( 'confirmed' !== (string) ( $ots['status'] ?? '' ) || ! is_numeric( $ots['bitcoin_block'] ?? null ) ) {
		return array( 'ok' => false, 'reason' => 'not_confirmed' );
	}
	// The trust gate: the record must hash to its own claim under the SAME
	// canonicalization the dispatcher uses. A record that fails this is
	// tampered or drifted — never imported, loudly counted.
	$hash = sn_prov_content_hash( sn_prov_canonical_json( $payload ) );
	if ( ! hash_equals( (string) ( $record['content_hash'] ?? '' ), $hash ) ) {
		return array( 'ok' => false, 'reason' => 'hash_mismatch' );
	}
	$bearing_fields = $payload;
	unset( $bearing_fields['parent'], $bearing_fields['version'] );
	$commit = array(
		'version'       => 1,
		'parent'        => isset( $payload['parent'] ) ? $payload['parent'] : null,
		'content_hash'  => $hash,
		'bearing_hash'  => sn_prov_content_hash( sn_prov_canonical_json( $bearing_fields ) ),
		'payload'       => $payload,
		// v10.67.0: THE SIGNATURE, and the key that made it. Their absence is
		// why this import produced chains that could never yield a credential —
		// sn_prov_credential() refuses an unsigned commit ("the proof does not
		// exist yet"), so /verify answered "No public credential exists for this
		// Note" on 18 of 30 live Notes while every dashboard read CONFIRMED and
		// the integrity sweep read clean. The ledger record carried both fields
		// the whole time; this builder simply never copied them.
		'signature'     => (string) ( $record['signature'] ?? '' ),
		'pubkey_id'     => (string) ( $record['pubkey_id'] ?? '' ),
		'status'        => 'confirmed',
		'bitcoin_block' => (int) $ots['bitcoin_block'],
		'committed_at'  => (string) ( $payload['published_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) ),
		'backfilled_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	);
	if ( '' === $commit['signature'] ) {
		// An unsigned record cannot yield a verifiable credential, so importing
		// one would recreate the exact defect this release repairs.
		return array( 'ok' => false, 'reason' => 'record_unsigned' );
	}
	return array( 'ok' => true, 'commit' => $commit );
}

/**
 * True when the chain carries a real commit (version >= 1) that has NO
 * signature — the v10.3.x import's output, which looks anchored everywhere
 * (the panel counts it CONFIRMED, the byline renders its block, the integrity
 * sweep passes it) and yet can never produce a credential.
 *
 * Deliberately separate from sn_prov_backfill_chain_has_real_commit(): that
 * one answers "may I append?", this one answers "must I repair?". Fixing the
 * builder alone repairs nothing already written, because those posts DO have a
 * real commit and the old gate skips them forever.
 *
 * @param array $chain
 * @return bool
 */
function sn_prov_backfill_chain_needs_signature( $chain ) {
	foreach ( (array) $chain as $entry ) {
		if ( (int) ( $entry['version'] ?? 0 ) >= 1 && '' === (string) ( $entry['signature'] ?? '' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Replace the unsigned v1+ commit in $chain with $commit, in place.
 *
 * IN PLACE, never appended: a second v1 row would be a second claim about the
 * same version. Only the entry that needs the signature is touched; every
 * other row (a genesis v0, a later signed version) is left exactly as it was.
 *
 * @param array $chain
 * @param array $commit
 * @return array The repaired chain.
 */
function sn_prov_backfill_repair_chain( array $chain, array $commit ) {
	foreach ( $chain as $i => $entry ) {
		if ( (int) ( $entry['version'] ?? 0 ) >= 1 && '' === (string) ( $entry['signature'] ?? '' ) ) {
			$chain[ $i ] = $commit;
			break;
		}
	}
	return $chain;
}

/**
 * Run the import over every candidate. Returns a summary the panel flash
 * renders; every skip carries its reason — a run that imports nothing still
 * says exactly why.
 *
 * @param callable|null $fetcher HTTP fetcher (tests inject; defaults to the
 *                               integrity module's guarded fetcher).
 * @return array {ok: bool, imported: int, skipped: array<string,int>}
 */
function sn_prov_backfill_run( $fetcher = null ) {
	$fetcher  = $fetcher ? $fetcher : 'sn_prov_integrity_http_fetch';
	$base     = sn_prov_integrity_ledger_base();
	$imported = 0;
	$repaired = 0;
	$skipped  = array();
	$bump     = function ( $reason ) use ( &$skipped ) {
		$skipped[ $reason ] = (int) ( $skipped[ $reason ] ?? 0 ) + 1;
	};

	$all       = sn_prov_backfill_candidates();
	$total     = count( $all );
	$attempted = 0;
	$started   = time();
	$budget    = (int) apply_filters( 'sn_prov_backfill_time_budget', SN_PROV_BACKFILL_TIME_BUDGET );
	$stopped   = '';

	foreach ( $all as $post_id ) {
		// Whichever bound trips first stops the run. Checked BEFORE the fetch,
		// because the fetch is the thing that costs — stopping after it would
		// overrun the budget by exactly the amount the budget exists to avoid.
		if ( $attempted >= SN_PROV_BACKFILL_CAP ) {
			$stopped = 'cap';
			break;
		}
		// No special case for zero. `$budget > 0` would have made 0 mean
		// "disabled", which is the opposite of what it reads like and made the
		// bound untestable without waiting out a real clock. The rule is plain:
		// stop once elapsed >= budget, so 0 stops before the first fetch and a
		// large value is how you effectively turn it off.
		if ( ( time() - $started ) >= $budget ) {
			$stopped = 'time';
			break;
		}
		++$attempted;
		$uid = strtolower( trim( (string) get_post_meta( $post_id, SN_PROV_UID_META, true ) ) );
		if ( '' === $uid ) {
			$bump( 'no_uid' );
			continue;
		}
		// v12.8.0: the ledger directory follows the SUBJECT KIND. This said
		// 'notes/' unconditionally, so a page's record was looked up where it
		// can never be — and the 404 would have been counted as ledger_missing,
		// a real-sounding answer to a question asked of the wrong directory.
		$bf_kind = function_exists( 'sn_prov_subject_kind' ) ? (string) sn_prov_subject_kind( get_post( $post_id ) ) : '';
		$bf_dir  = function_exists( 'sn_prov_ledger_dir' ) ? sn_prov_ledger_dir( $bf_kind ) : '';
		if ( '' === $bf_dir ) {
			// Nothing is guessed. An unresolved kind is a skip with its own
			// reason, never a ledger verdict.
			$bump( 'kind_unresolved' );
			continue;
		}
		$res = sn_prov_integrity_fetch_json( $base . $bf_dir . '/' . rawurlencode( $uid ) . '/v1.json', $fetcher );
		if ( 404 === (int) $res['code'] ) {
			$bump( 'ledger_missing' ); // a real answer: this Note has no ledger record
			continue;
		}
		if ( 200 !== (int) $res['code'] || null === $res['json'] ) {
			$bump( 'ledger_unreachable' ); // an outage is a gap in evidence, not data
			continue;
		}
		$built = sn_prov_backfill_commit_from_record( $uid, $res['json'] );
		if ( empty( $built['ok'] ) ) {
			$bump( (string) $built['reason'] );
			continue;
		}
		// Re-check at write time: candidates were computed before the fetches,
		// and this module never writes beside an existing real commit.
		$chain = sn_prov_get_chain( $post_id );
		if ( sn_prov_backfill_chain_has_real_commit( $chain ) ) {
			// v10.67.0 REPAIR PATH. A real commit that carries no signature is
			// the v10.3.x import's own output — anchored everywhere, verifiable
			// nowhere. Repair may only ever FILL IN the missing signature, so it
			// is gated on the ledger record agreeing with the stored commit's
			// content_hash exactly: same content, same version, same anchor.
			// Anything else is a disagreement between two records and is refused,
			// never reconciled by overwriting one with the other.
			if ( ! sn_prov_backfill_chain_needs_signature( $chain ) ) {
				$bump( 'chain_no_longer_empty' );
				continue;
			}
			$stored_hash = '';
			foreach ( $chain as $entry ) {
				if ( (int) ( $entry['version'] ?? 0 ) >= 1 && '' === (string) ( $entry['signature'] ?? '' ) ) {
					$stored_hash = (string) ( $entry['content_hash'] ?? '' );
					break;
				}
			}
			if ( '' === $stored_hash || ! hash_equals( $stored_hash, (string) $built['commit']['content_hash'] ) ) {
				$bump( 'repair_hash_mismatch' );
				continue;
			}
			update_post_meta( $post_id, SN_PROV_CHAIN_META, sn_prov_backfill_repair_chain( $chain, $built['commit'] ) );
			$repaired++;
			continue;
		}
		sn_prov_append_commit( $post_id, $built['commit'] );
		$imported++;
	}

	// 'remaining' is recomputed, never derived by subtraction: a candidate that
	// was attempted and SKIPPED (ledger_missing, unreachable) is still a
	// candidate, so total - attempted would under-report the backlog and the
	// panel would claim progress it did not make.
	$left = count( sn_prov_backfill_candidates() );

	return array(
		'ok'        => true,
		'imported'  => $imported,
		'repaired'  => $repaired,
		'skipped'   => $skipped,
		'total'     => $total,
		'attempted' => $attempted,
		'remaining' => $left,
		'stopped'   => $stopped, // '' ran to the end · 'cap' · 'time'
	);
}

/**
 * Panel fieldset: the one-shot trigger, shown ONLY while candidates exist
 * (after a clean import the section disappears — the surface is the task).
 */
function sn_prov_backfill_render_fieldset() {
	$candidates = sn_prov_backfill_candidates();
	$result     = get_transient( 'sn_prov_backfill_result_' . get_current_user_id() );
	if ( ! $candidates && ! is_array( $result ) ) {
		return;
	}
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Ledger backfill', 'signal-and-noise-tools' ) . '</h2>';
	if ( is_array( $result ) ) {
		delete_transient( 'sn_prov_backfill_result_' . get_current_user_id() );
		$skips = array();
		foreach ( (array) ( $result['skipped'] ?? array() ) as $reason => $n ) {
			$skips[] = esc_html( $reason . ' ×' . (int) $n );
		}
		echo '<div class="notice notice-' . ( empty( $result['skipped'] ) ? 'success' : 'warning' ) . ' notice-alt inline"><p>'
			. esc_html( sprintf(
				/* translators: 1: number of imported commits, 2: number of repaired commits. */
				__( 'Imported %1$s confirmed anchors from the ledger, and repaired %2$s missing signatures.', 'signal-and-noise-tools' ),
				number_format_i18n( (int) ( $result['imported'] ?? 0 ) ),
				number_format_i18n( (int) ( $result['repaired'] ?? 0 ) )
			) )
			. ( $skips ? ' ' . esc_html__( 'Skipped:', 'signal-and-noise-tools' ) . ' ' . implode( ', ', $skips ) : '' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item esc_html'd above.
			// What is LEFT, always — a run that stopped on a bound and said
			// nothing reads exactly like a run that finished the job.
			. ( (int) ( $result['remaining'] ?? 0 ) > 0
				? ' ' . esc_html( sprintf(
					/* translators: 1: number still to repair, 2: why the run stopped. */
					__( '%1$s still cannot be verified%2$s — run this again.', 'signal-and-noise-tools' ),
					number_format_i18n( (int) $result['remaining'] ),
					'time' === ( $result['stopped'] ?? '' )
						? __( ' (this run hit its time budget)', 'signal-and-noise-tools' )
						: ( 'cap' === ( $result['stopped'] ?? '' ) ? __( ' (this run hit its per-run ceiling)', 'signal-and-noise-tools' ) : '' )
				) )
				: ' ' . esc_html__( 'Nothing is left unverifiable.', 'signal-and-noise-tools' ) )
			. '</p></div>';
	}
	if ( $candidates ) {
		echo '<p class="sn-fieldset-intro">' . esc_html( sprintf(
			/* translators: %s: number of candidate Notes. */
			__( '%s published Notes cannot currently be verified: either they carry a provenance UID with no local commit chain (the July ledger backfill anchored them worker-side only), or their imported commit is missing its signature, which makes /verify tell a reader no proof exists. Import or repair them from the public ledger; every record is re-verified against its own hash first, and a repair only ever fills in the missing signature.', 'signal-and-noise-tools' ),
			number_format_i18n( count( $candidates ) )
		) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sn_prov_chain_backfill' );
		echo '<input type="hidden" name="action" value="sn_prov_chain_backfill" />';
		echo '<button type="submit" class="button button-primary">' . esc_html( sprintf(
			/* translators: %s: number of candidate Notes. */
			__( 'Repair %s Notes from the ledger', 'signal-and-noise-tools' ),
			number_format_i18n( count( $candidates ) )
		) ) . '</button>';
		echo '</form>';
		// Stated up front rather than discovered afterwards: each candidate costs
		// one ledger fetch, so a run is bounded by wall clock. A residual is the
		// design working, not the button failing.
		echo '<p class="sn-fieldset-actions-hint">' . esc_html( sprintf(
			/* translators: %s: the per-run time budget in seconds. */
			__( 'Each Note costs one ledger fetch, so a run is bounded to about %s seconds. If any are left afterwards the panel says how many, and you can run it again.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) apply_filters( 'sn_prov_backfill_time_budget', SN_PROV_BACKFILL_TIME_BUDGET ) )
		) ) . '</p>';
	}
	echo '</div>';
}

/**
 * admin_post handler — the sn_prov_runsweep pattern: nonce + manage_options,
 * result in a short per-user transient, redirect back to the Provenance sub-tab.
 */
function sn_prov_backfill_handler() {
	check_admin_referer( 'sn_prov_chain_backfill' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	set_transient( 'sn_prov_backfill_result_' . get_current_user_id(), sn_prov_backfill_run(), MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg(
		array(
			'page' => 'sn-theme-options',
			'tab'  => 'tools',
			'sub'  => 'provenance',
		),
		admin_url( 'admin.php' )
	) );
	exit;
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_post_sn_prov_chain_backfill', 'sn_prov_backfill_handler' );
}
