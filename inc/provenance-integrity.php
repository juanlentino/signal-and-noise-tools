<?php
/**
 * Signal & Noise Tools — server-side provenance integrity sweep (13th Content-Health check).
 *
 * The /verify page checks one Note at a time, client-side, only when a reader
 * visits; the 2026-07-21 flattened-content_text repair was exactly the drift
 * class a scheduled fleet self-check would have caught first. This module IS
 * that self-check: for each anchored Note it verifies the trust triangle
 *
 *   (a) the stored chain payload still re-canonicalizes (sn_prov_canonical_json
 *       → sn_prov_content_hash, the EXACT bytes the credential system signed)
 *       to the anchored content_hash;
 *   (b) the Note's published .json twin — fetched over HTTP through the real
 *       public URL, i.e. the live cache stack — still carries the same words
 *       as the signed payload under whitespace-collapse normalization (the
 *       twin's content_text is ONE flattened line vs the payload's paragraphs,
 *       so the collapse folds ALL whitespace; same semantic as the /verify
 *       JS roughNormalize, built on the SHARED sn_prov_normalize_v1 — never a
 *       second divergent normalizer);
 *   (c) the public ledger record notes/<uid>/v<n>.json exists on
 *       raw.githubusercontent.com and attests the same hash, and
 *       keys/provenance-keys.json still serves the published key id.
 *
 * Failure UX names WHICH leg failed, and an outage is never drift: the
 * *_unreachable codes are classified separately from mismatch/missing so a
 * GitHub blip or an edge 500 can't masquerade as tampering.
 *
 * Bounding + cadence: at most SN_PROV_INTEGRITY_NOTES_PER_RUN Notes per run,
 * rotating oldest-checked-first so full fleet coverage accrues across runs;
 * every fetch is timeout-capped; per-Note last-checked state + the sweep
 * summary persist in an autoload=no option (NEVER a transient — transients
 * are flush-volatile under this stack's persistent object cache). The sweep
 * rides the existing Content-Health scan cadence (sn_health_run_scan(), an
 * admin-button / run-health-scan-ability surface — it can never run inline
 * on a front-end request), so no parallel cron is invented. Outbound targets
 * are two fixed known hosts: this site's own home URL and
 * raw.githubusercontent.com.
 *
 * Surfaces: the provenance_integrity Content-Health check (registered in
 * sn_health_run_scan() like every sibling) and the readonly
 * signal-noise/provenance-integrity-status ability (latest sweep summary +
 * per-note failures; reads stored state ONLY and never triggers a sweep).
 * No new bare REST routes.
 *
 * @package SignalNoiseTools
 * @since 9.80.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable sweep state (autoload=no option): per-Note last-checked + last sweep summary. */
const SN_PROV_INTEGRITY_OPT = 'sn_prov_integrity_state';

/** Notes verified per run; the rest wait for the oldest-checked-first rotation. */
const SN_PROV_INTEGRITY_NOTES_PER_RUN = 10;

/** Per-fetch timeout (seconds) for the twin/ledger/key requests. */
const SN_PROV_INTEGRITY_FETCH_TIMEOUT = 5;

/** Consecutive-sweep 404s (a REAL "absent" answer, vs a network error) on the
 *  keys file or a twin before the outage verdict escalates to the distinct
 *  keys_missing / twin_missing finding. One 404 can be an edge blip or a
 *  mid-deploy window; three consecutive sweeps saying "absent" is absence. */
const SN_PROV_INTEGRITY_404_STREAK = 3;

/**
 * The whitespace-collapse comparison form: the SHARED sn-normalize-v1
 * pipeline (inc/provenance-core.php — comments, tags, entities, NFC, line
 * whitespace) followed by ONE final collapse of ALL whitespace including
 * newlines. The canonical payload keeps its paragraph structure while the
 * theme twin's content_text is the document flattened to one line, so
 * line-preserving comparison can never match them; "same words in the same
 * order" is the honest match semantic (paragraph breaks carry no
 * provenance). Mirrors assets/js/prov-verify-core.js roughNormalize().
 *
 * @since 9.80.0
 * @param string $raw Payload content or twin content_text.
 * @return string
 */
function sn_prov_integrity_flatten( $raw ) {
	$s = sn_prov_normalize_v1( (string) $raw );
	return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * A Note's public .json twin URL: fragment/query stripped, trailing slash
 * normalized, '.json' appended — byte-for-byte the /verify JS derivation
 * (pastedTwinUrl / liveMatchTwinUrl).
 *
 * @since 9.80.0
 * @param string $permalink The Note's public permalink.
 * @return string
 */
function sn_prov_integrity_twin_url( $permalink ) {
	$permalink = (string) preg_replace( '/[#?].*$/', '', (string) $permalink );
	return rtrim( $permalink, '/' ) . '.json';
}

/**
 * Raw ledger base URL — the same sn_prov_ledger_owner / sn_prov_ledger_repo
 * filters inc/provenance-verify.php hands the /verify page, so this sweep
 * can never check a different ledger than the one readers verify against.
 *
 * @since 9.80.0
 * @return string
 */
function sn_prov_integrity_ledger_base() {
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	return "https://raw.githubusercontent.com/{$owner}/{$repo}/main/";
}

/**
 * Default HTTP fetcher: wp_safe_remote_get with the module timeout. Both
 * targets are fixed known hosts (own site + raw.githubusercontent.com); the
 * safe variant additionally refuses any unsafe redirect destination.
 *
 * @since 9.80.0
 * @param string $url
 * @return array{code:int,body:string} code 0 = network error (unreachable).
 */
function sn_prov_integrity_http_fetch( $url ) {
	$resp = wp_safe_remote_get(
		$url,
		array(
			'timeout'     => SN_PROV_INTEGRITY_FETCH_TIMEOUT,
			'redirection' => 2,
			'user-agent'  => 'SN-Provenance-Integrity/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '0' ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return array( 'code' => 0, 'body' => '' );
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $resp ),
		'body' => (string) wp_remote_retrieve_body( $resp ),
	);
}

/**
 * Fetch + decode a JSON document. Anything that is not an HTTP 200 carrying
 * a JSON object decodes to null — the caller decides whether that means
 * "unreachable" (network/5xx/garbage) or "missing" (404 is a real answer).
 *
 * @since 9.80.0
 * @param string   $url
 * @param callable $fetcher
 * @return array{code:int,json:array|null}
 */
function sn_prov_integrity_fetch_json( $url, $fetcher ) {
	$res  = call_user_func( $fetcher, $url );
	$code = (int) ( $res['code'] ?? 0 );
	$json = null;
	if ( 200 === $code ) {
		$decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
		$json    = is_array( $decoded ) ? $decoded : null;
	}
	return array( 'code' => $code, 'json' => $json );
}

/**
 * Is this failure code an outage rather than drift? An unreachable endpoint
 * is a gap in today's evidence, never evidence of tampering.
 *
 * @since 9.80.0
 * @param string $code Failure leg code.
 * @return bool
 */
function sn_prov_integrity_is_outage( $code ) {
	return in_array( (string) $code, array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ), true );
}

/**
 * Pick this run's batch: never-checked (0) first, then oldest-checked
 * ascending, ties broken by post id for a stable rotation, capped.
 * PURE — exhaustively testable.
 *
 * @since 9.80.0
 * @param int[]           $ids          Fleet post ids.
 * @param array<int,int>  $last_checked post id → unix time of last check (absent = never).
 * @param int             $cap
 * @return int[]
 */
function sn_prov_integrity_select_batch( array $ids, array $last_checked, $cap ) {
	$ids = array_values( array_map( 'intval', $ids ) );
	sort( $ids );
	usort(
		$ids,
		static function ( $a, $b ) use ( $last_checked ) {
			$ta = (int) ( $last_checked[ $a ] ?? 0 );
			$tb = (int) ( $last_checked[ $b ] ?? 0 );
			if ( $ta === $tb ) {
				return $a <=> $b;
			}
			return $ta <=> $tb;
		}
	);
	return array_slice( $ids, 0, max( 0, (int) $cap ) );
}

/**
 * Fleet-level key attestation: does the public ledger's
 * keys/provenance-keys.json still serve the published key id with the
 * published key bytes? One verdict per sweep (one key file for the whole
 * fleet), fetched once.
 *
 * @since 9.80.0
 * @param callable $fetcher
 * @return string 'ok' | 'key_mismatch' | 'keys_unreachable' | 'skipped' (no key configured).
 */
function sn_prov_integrity_keys_verdict( $fetcher ) {
	return sn_prov_integrity_keys_probe( $fetcher )['verdict'];
}

/**
 * The keys verdict PLUS the raw HTTP code, so the sweep can distinguish a
 * 404 (a real "the file is absent" answer) from a network error and escalate
 * a PERSISTENT 404 to keys_missing after SN_PROV_INTEGRITY_404_STREAK
 * consecutive sweeps.
 *
 * @since 9.81.0
 * @param callable $fetcher
 * @return array{verdict:string,code:int}
 */
function sn_prov_integrity_keys_probe( $fetcher ) {
	$key_id  = function_exists( 'sn_prov_key_id' ) ? (string) sn_prov_key_id() : '';
	$pub_b64 = function_exists( 'sn_prov_pubkey_b64' ) ? trim( (string) sn_prov_pubkey_b64() ) : '';
	if ( '' === $key_id && '' === $pub_b64 ) {
		return array( 'verdict' => 'skipped', 'code' => 0 ); // no published key to hold the ledger to.
	}
	$res = sn_prov_integrity_fetch_json( sn_prov_integrity_ledger_base() . 'keys/provenance-keys.json', $fetcher );
	if ( ! is_array( $res['json'] ) || ! isset( $res['json']['keys'] ) || ! is_array( $res['json']['keys'] ) ) {
		// network-dead, 404, or un-decodable — an outage/unknown THIS sweep,
		// never a rotation claim; the sweep escalates a persistent 404.
		return array( 'verdict' => 'keys_unreachable', 'code' => (int) $res['code'] );
	}
	foreach ( $res['json']['keys'] as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( '' !== $key_id && (string) ( $entry['id'] ?? '' ) !== $key_id ) {
			continue; // not the published id.
		}
		// The id label alone must not pass a swapped key: when both sides
		// publish key bytes, they must agree.
		if ( '' !== $pub_b64 && isset( $entry['public_key_base64'] ) && trim( (string) $entry['public_key_base64'] ) !== $pub_b64 ) {
			continue;
		}
		return array( 'verdict' => 'ok', 'code' => (int) $res['code'] );
	}
	return array( 'verdict' => 'key_mismatch', 'code' => (int) $res['code'] );
}

/**
 * Verify one Note's triangle. Read-only against WP state (never mints a uid,
 * never touches the chain); all networking goes through the injected fetcher.
 *
 * Failure codes (mismatch class): hash_mismatch, twin_drift, ledger_missing,
 * ledger_hash_mismatch. Outage class: twin_unreachable, ledger_unreachable.
 * The ledger leg only runs for a CONFIRMED commit — a pending anchor has no
 * ledger record yet, and flagging that would fabricate drift.
 *
 * @since 9.80.0
 * @param int      $post_id
 * @param callable $fetcher
 * @return array{post_id:int,uid:string,version:int,anchored_version:int,failures:string[]}|null
 *         null when the Note has no real (v1+) commit to verify yet.
 */
function sn_prov_integrity_check_note( $post_id, $fetcher ) {
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
		// The twin schema carries content_text (NOT `content` — the 2026-07-21
		// live catch); keep the legacy fallback the /verify JS also keeps.
		$live   = (string) ( $twin['json']['content_text'] ?? ( $twin['json']['content'] ?? '' ) );
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
	if ( null !== $anchored && '' !== $uid ) {
		$ledger_url = sn_prov_integrity_ledger_base() . 'notes/' . rawurlencode( $uid ) . '/v' . (int) $anchored['version'] . '.json';
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

	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => SN_PROV_UID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded corpus (~dozens of Notes), mirrors inc/abilities-provenance.php.
			'no_found_rows'  => true,
		)
	);
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

	$state = get_option( SN_PROV_INTEGRITY_OPT );
	$state = is_array( $state ) ? $state : array();
	$notes = ( isset( $state['notes'] ) && is_array( $state['notes'] ) ) ? $state['notes'] : array();
	$notes = array_intersect_key( $notes, array_flip( $ids ) ); // prune Notes gone from the fleet.

	$last_checked = array();
	foreach ( $notes as $pid => $row ) {
		$last_checked[ (int) $pid ] = (int) ( $row['last_checked'] ?? 0 );
	}

	$batch = sn_prov_integrity_select_batch( $ids, $last_checked, SN_PROV_INTEGRITY_NOTES_PER_RUN );
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
		$result   = sn_prov_integrity_check_note( $pid, $fetcher );
		$failures = null !== $result ? $result['failures'] : array();

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

	$summary = array(
		'swept_at'    => $now,
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

/**
 * The stored sweep state, or null when no sweep has run yet.
 *
 * @since 9.80.0
 * @return array|null
 */
function sn_prov_integrity_state() {
	$stored = get_option( SN_PROV_INTEGRITY_OPT );
	return is_array( $stored ) ? $stored : null;
}

/**
 * Build Content-Health findings from stored sweep state. PURE (no I/O) so
 * the per-leg wording is exhaustively testable. Findings accrue across the
 * rotation: a Note that failed in an earlier batch stays flagged until its
 * next check clears it. Every failed leg is named, and outage legs say so —
 * an outage is not drift.
 *
 * @since 9.80.0
 * @param array $state The sn_prov_integrity_state() array.
 * @return array[] Finding rows (sn_health_pack_check shape).
 */
function sn_prov_integrity_findings( $state ) {
	$legs = array(
		'hash_mismatch'        => 'stored payload no longer reproduces the anchored content hash (hash mismatch)',
		'twin_drift'           => 'the published .json twin\'s words no longer match the signed payload (twin drift)',
		'twin_unreachable'     => 'the published .json twin could not be fetched (unreachable: an outage, not drift)',
		'twin_missing'         => 'the published .json twin has 404ed for three consecutive sweeps (twin missing: the public twin is gone, not blipping)',
		'ledger_missing'       => 'the public ledger record notes/<uid>/v<n>.json is absent (ledger missing)',
		'ledger_unreachable'   => 'the public ledger could not be reached (unreachable: an outage, not drift)',
		'ledger_hash_mismatch' => 'the public ledger record attests a different content hash (ledger contradiction)',
		'ledger_record_malformed' => 'the public ledger record exists but carries no content_hash (malformed record: it attests nothing)',
	);

	$out   = array();
	$notes = ( isset( $state['notes'] ) && is_array( $state['notes'] ) ) ? $state['notes'] : array();
	foreach ( $notes as $pid => $row ) {
		$failures = ( isset( $row['failures'] ) && is_array( $row['failures'] ) ) ? $row['failures'] : array();
		if ( array() === $failures ) {
			continue;
		}
		$named = array();
		foreach ( $failures as $code ) {
			$named[] = $legs[ $code ] ?? (string) $code;
		}
		$out[] = array(
			'subject_type'  => 'provenance_integrity',
			'subject_id'    => (int) $pid,
			'subject_url'   => (string) ( $row['url'] ?? '' ),
			'subject_label' => (string) ( '' !== (string) ( $row['title'] ?? '' ) ? $row['title'] : ( $row['uid'] ?? '' ) ),
			'edit_url'      => '',
			'note'          => sprintf(
				'Provenance triangle check failed for v%d: %s.',
				(int) ( $row['version'] ?? 0 ),
				implode( '; ', $named )
			),
		);
	}

	// Fleet-level key attestation from the last sweep.
	$kv_result = (string) ( $state['last_sweep']['keys'] ?? '' );
	if ( 'key_mismatch' === $kv_result ) {
		$out[] = array(
			'subject_type'  => 'provenance_integrity',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'ledger key file',
			'edit_url'      => '',
			'note'          => 'The public ledger\'s keys/provenance-keys.json no longer serves the published key id with the published key bytes (key mismatch): readers can no longer independently verify signatures.',
		);
	} elseif ( 'keys_missing' === $kv_result ) {
		$out[] = array(
			'subject_type'  => 'provenance_integrity',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'ledger key file',
			'edit_url'      => '',
			'note'          => 'The public ledger\'s keys/provenance-keys.json has 404ed for three consecutive sweeps: the key file is absent from the ledger, not blipping. Readers cannot independently cross-check signatures until it is restored.',
		);
	} elseif ( 'keys_unreachable' === $kv_result ) {
		$out[] = array(
			'subject_type'  => 'provenance_integrity',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'ledger key file',
			'edit_url'      => '',
			'note'          => 'The public ledger\'s keys/provenance-keys.json could not be reached (unreachable: an outage, not drift, not a key rotation).',
		);
	}

	return $out;
}

/**
 * CHECK 13: provenance integrity sweep.
 *
 * Runs one bounded sweep as part of the scan (the scan is dispatched from
 * the admin "Run scan" button and the run-health-scan ability only — never
 * a front-end request), then reports the ACCRUED state: earlier batches'
 * failures stay flagged until their next rotation check clears them.
 *
 * @since 9.80.0
 * @param callable|null $fetcher Injectable for tests; production calls bare.
 * @return array pack_check envelope.
 */
function sn_health_check_provenance_integrity( $fetcher = null ) {
	$label = 'Provenance integrity';
	if ( ! function_exists( 'sn_prov_get_chain' ) || ! function_exists( 'sn_prov_active' ) || ! sn_prov_active() ) {
		return sn_health_pack_check( $label, array(), 'Provenance subsystem inactive (ext-intl absent): sweep skipped, nothing flagged.' );
	}

	$summary  = sn_prov_integrity_run_sweep( $fetcher );
	$state    = sn_prov_integrity_state();
	$findings = sn_prov_integrity_findings( is_array( $state ) ? $state : array() );

	if ( array() === $findings ) {
		return sn_health_pack_check(
			$label,
			array(),
			sprintf(
				'Provenance integrity: all triangle legs held (payload hash, live .json twin, public ledger + key file). %1$d of %2$d Notes verified this run; coverage rotates oldest-checked-first, so the whole fleet accrues across scans.',
				(int) $summary['checked'],
				(int) $summary['fleet']
			)
		);
	}

	return sn_health_pack_check(
		$label,
		$findings,
		'Each finding names WHICH leg failed. Mismatch legs (hash mismatch, twin drift, ledger missing/contradiction, key mismatch) are the drift class the /verify page would show a reader: investigate against the ledger + the anchor worker before touching content. Unreachable legs are outages, not drift: re-run the scan once the endpoint is back. State persists across scans and clears when a re-checked Note comes back clean.'
	);
}

/**
 * Register the readonly status ability on the canonical registrar hook.
 *
 * @since 9.80.0
 */
function snt_abilities_provenance_integrity_register() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/provenance-integrity-status', array(
		'label'               => 'Provenance integrity sweep status',
		'description'         => 'Returns the latest server-side provenance integrity sweep: summary counts (fleet, checked, clean, failed, unreachable, ledger-key verdict) plus every Note currently failing a triangle leg, each naming WHICH leg (hash mismatch, twin drift, twin unreachable, ledger missing, ledger contradiction). Returns null before the first sweep. Read-only — never triggers a sweep (the Content-Health scan owns that).',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_provenance_integrity_status',
		'input_schema'        => array(
			// The [object,null] union, per the Group E structural law for READ
			// abilities: readonly ⇒ GET run-path ⇒ a caller that omits ?input=
			// delivers NULL, and a plain 'object' rejects every such call.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'swept_at'    => array( 'type' => 'integer' ),
				'fleet'       => array( 'type' => 'integer' ),
				'checked'     => array( 'type' => 'integer' ),
				'clean'       => array( 'type' => 'integer' ),
				'failed'      => array( 'type' => 'integer' ),
				'unreachable' => array( 'type' => 'integer' ),
				'keys'        => array( 'type' => 'string' ),
				'failing'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'      => array( 'type' => 'integer' ),
							'uid'          => array( 'type' => 'string' ),
							'title'        => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string' ),
							'version'      => array( 'type' => 'integer' ),
							'failures'     => array( 'type' => 'array' ),
							'last_checked' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'destructive'     => false,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
}
add_action( 'wp_abilities_api_init', 'snt_abilities_provenance_integrity_register' );

/**
 * Ability execute callback: signal-noise/provenance-integrity-status.
 *
 * Reads the stored sweep state ONLY (a sweep does bounded remote fetches;
 * the Content-Health scan owns running it). Null before the first sweep —
 * never a fabricated green fleet.
 *
 * @since 9.80.0
 * @param array|null $input Unused.
 * @return array|null
 */
function snt_ability_provenance_integrity_status( $input = null ) {
	$state = sn_prov_integrity_state();
	if ( ! is_array( $state ) || ! isset( $state['last_sweep'] ) || ! is_array( $state['last_sweep'] ) ) {
		return null;
	}
	$failing = array();
	$notes   = ( isset( $state['notes'] ) && is_array( $state['notes'] ) ) ? $state['notes'] : array();
	foreach ( $notes as $pid => $row ) {
		$failures = ( isset( $row['failures'] ) && is_array( $row['failures'] ) ) ? $row['failures'] : array();
		if ( array() === $failures ) {
			continue;
		}
		$failing[] = array(
			'post_id'      => (int) $pid,
			'uid'          => (string) ( $row['uid'] ?? '' ),
			'title'        => (string) ( $row['title'] ?? '' ),
			'url'          => (string) ( $row['url'] ?? '' ),
			'version'      => (int) ( $row['version'] ?? 0 ),
			'failures'     => array_values( array_map( 'strval', $failures ) ),
			'last_checked' => (int) ( $row['last_checked'] ?? 0 ),
		);
	}
	return array_merge( $state['last_sweep'], array( 'failing' => $failing ) );
}
