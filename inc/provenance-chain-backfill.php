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

// Bound per run. 14 known candidates today; the cap only guards against a
// future where the candidate query surprises us.
const SN_PROV_BACKFILL_CAP = 25;

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
	$ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 100,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
	) );
	$out = array();
	foreach ( (array) $ids as $id ) {
		if ( count( $out ) >= SN_PROV_BACKFILL_CAP ) {
			break;
		}
		if ( ! sn_prov_backfill_chain_has_real_commit( sn_prov_get_chain( (int) $id ) ) ) {
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
		'status'        => 'confirmed',
		'bitcoin_block' => (int) $ots['bitcoin_block'],
		'committed_at'  => (string) ( $payload['published_at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) ),
		'backfilled_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	);
	return array( 'ok' => true, 'commit' => $commit );
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
	$skipped  = array();
	$bump     = function ( $reason ) use ( &$skipped ) {
		$skipped[ $reason ] = (int) ( $skipped[ $reason ] ?? 0 ) + 1;
	};

	foreach ( sn_prov_backfill_candidates() as $post_id ) {
		$uid = strtolower( trim( (string) get_post_meta( $post_id, SN_PROV_UID_META, true ) ) );
		if ( '' === $uid ) {
			$bump( 'no_uid' );
			continue;
		}
		$res = sn_prov_integrity_fetch_json( $base . 'notes/' . rawurlencode( $uid ) . '/v1.json', $fetcher );
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
		if ( sn_prov_backfill_chain_has_real_commit( sn_prov_get_chain( $post_id ) ) ) {
			$bump( 'chain_no_longer_empty' );
			continue;
		}
		sn_prov_append_commit( $post_id, $built['commit'] );
		$imported++;
	}

	return array( 'ok' => true, 'imported' => $imported, 'skipped' => $skipped );
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
				/* translators: %s: number of imported commits. */
				__( 'Imported %s confirmed anchors from the ledger.', 'signal-and-noise-tools' ),
				number_format_i18n( (int) ( $result['imported'] ?? 0 ) )
			) )
			. ( $skips ? ' ' . esc_html__( 'Skipped:', 'signal-and-noise-tools' ) . ' ' . implode( ', ', $skips ) : '' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item esc_html'd above.
			. '</p></div>';
	}
	if ( $candidates ) {
		echo '<p class="sn-fieldset-intro">' . esc_html( sprintf(
			/* translators: %s: number of candidate Notes. */
			__( '%s published Notes carry a provenance UID but no local commit chain (the July ledger backfill anchored them worker-side only). Import their confirmed v1 anchors from the public ledger; every record is re-verified against its own hash before anything is written.', 'signal-and-noise-tools' ),
			number_format_i18n( count( $candidates ) )
		) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sn_prov_chain_backfill' );
		echo '<input type="hidden" name="action" value="sn_prov_chain_backfill" />';
		echo '<button type="submit" class="button button-primary">' . esc_html( sprintf(
			/* translators: %s: number of candidate Notes. */
			__( 'Import %s anchors from the ledger', 'signal-and-noise-tools' ),
			number_format_i18n( count( $candidates ) )
		) ) . '</button>';
		echo '</form>';
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
