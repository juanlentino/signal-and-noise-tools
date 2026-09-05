<?php
/**
 * Signal & Noise Tools — the note dossier: the trust block, and the re-check.
 *
 * Trust is this app's subject, so it is read in full here: the anchor proof
 * from the PUBLIC ledger's record of the newest confirmed version, the signer
 * against the keys the ledger publishes, the citations the note has received.
 * The re-check walks what the server can walk -- the published twin, the
 * ledger record, the published key ids -- and says exactly that; the
 * signature itself is verified by the public /verify page in the reader's
 * browser, and this never claims otherwise.
 *
 * Reads the head of nothing: the ledger record for a PENDING head does not
 * exist yet, so the newest CONFIRMED version is the one with a record. An
 * unreachable ledger is a gap in evidence, never "not anchored".
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The newest CONFIRMED commit with version >= 1, or null.
 *
 * @param array $chain sn_prov_get_chain() output.
 * @return array|null
 */
function sn_note_dossier_anchored_commit( array $chain ) {
	for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
		$c = $chain[ $i ];
		if ( is_array( $c ) && 'confirmed' === (string) ( $c['status'] ?? '' ) && (int) ( $c['version'] ?? 0 ) >= 1 ) {
			return $c;
		}
	}
	return null;
}

/**
 * Strip an optional 'sha256:' prefix and lowercase, the integrity module's
 * comparison form.
 *
 * @param string $hash
 * @return string
 */
function sn_note_dossier_hash_norm( $hash ) {
	$hash = strtolower( trim( (string) $hash ) );
	return 0 === strpos( $hash, 'sha256:' ) ? substr( $hash, 7 ) : $hash;
}

/**
 * The trust blocks for one note.
 *
 * @param int           $post_id
 * @param callable|null $fetcher callable( string $url ): array{code:int,body:string}; the integrity module's HTTP fetcher by default.
 * @return array<int,array<string,mixed>>
 */
function sn_note_dossier_trust( $post_id, $fetcher = null ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$fetcher = is_callable( $fetcher ) ? $fetcher : ( function_exists( 'sn_prov_integrity_http_fetch' ) ? 'sn_prov_integrity_http_fetch' : null );
	$blocks  = array();
	$chain   = function_exists( 'sn_prov_get_chain' ) ? (array) sn_prov_get_chain( $post->ID ) : array();
	$uid     = (string) get_post_meta( $post->ID, '_sn_prov_uid', true );
	$commit  = sn_note_dossier_anchored_commit( $chain );
	$record  = null;

	// ── Anchor proof ─────────────────────────────────────────────────────
	$anchor_door = function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( __( 'Open Provenance in S&N Dashboard', 'signal-and-noise-tools' ), snt_desktop_admin_url( 'sn-tools', 'provenance' ) ) : null;
	if ( ! $commit ) {
		$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'neutral', __( 'No confirmed anchor yet.', 'signal-and-noise-tools' ), __( 'A version is anchored once the ledger confirms it in Bitcoin; a pending anchor has no record to read yet.', 'signal-and-noise-tools' ), __( 'local chain', 'signal-and-noise-tools' ), $anchor_door );
	} elseif ( '' === $uid ) {
		// A confirmed commit whose note carries no `_sn_prov_uid`: the record
		// cannot be LOCATED. A gap in the lookup, never "no anchor".
		$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', sprintf( /* translators: %d: version. */ __( 'v%d is confirmed locally, but this note carries no ledger UID.', 'signal-and-noise-tools' ), (int) $commit['version'] ), __( 'Without the UID the ledger record cannot be located; a gap in the lookup, not a missing anchor.', 'signal-and-noise-tools' ), __( 'local chain', 'signal-and-noise-tools' ), $anchor_door );
	} elseif ( ! is_callable( $fetcher ) || ! function_exists( 'sn_prov_integrity_fetch_json' ) || ! function_exists( 'sn_prov_ledger_dir' ) || ! function_exists( 'sn_prov_subject_kind' ) || ! function_exists( 'sn_prov_integrity_ledger_base' ) ) {
		$blocks[] = sn_note_dossier_unreadable( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), __( 'the provenance module', 'signal-and-noise-tools' ) );
	} else {
		$dir = (string) sn_prov_ledger_dir( (string) sn_prov_subject_kind( $post ) );
		$v   = (int) $commit['version'];
		if ( '' === $dir ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', __( 'The subject kind could not be resolved.', 'signal-and-noise-tools' ), __( 'The ledger directory was not guessed; a gap, not a verdict.', 'signal-and-noise-tools' ), __( 'public ledger', 'signal-and-noise-tools' ), $anchor_door );
		} else {
			$url = sn_prov_integrity_ledger_base() . $dir . '/' . rawurlencode( $uid ) . '/v' . $v . '.json';
			$res = sn_prov_integrity_fetch_json( $url, $fetcher );
			if ( 404 === (int) $res['code'] ) {
				$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', sprintf( /* translators: %d: version number. */ __( 'The ledger holds no record for v%d.', 'signal-and-noise-tools' ), $v ), __( 'A real absence: the record is not there, the ledger answered.', 'signal-and-noise-tools' ), __( 'public ledger', 'signal-and-noise-tools' ), $anchor_door );
			} elseif ( ! is_array( $res['json'] ) ) {
				$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', __( 'The public ledger could not be reached.', 'signal-and-noise-tools' ), __( 'A gap in evidence, never "not anchored".', 'signal-and-noise-tools' ), __( 'public ledger', 'signal-and-noise-tools' ), $anchor_door );
			} else {
				$record = $res['json'];
				$ots    = is_array( $record['ots'] ?? null ) ? $record['ots'] : array();
				$txid   = (string) ( $ots['bitcoin_txid'] ?? ( $record['bitcoin_txid'] ?? '' ) );
				$block  = (int) ( $ots['bitcoin_block'] ?? 0 );
				$conf   = isset( $ots['confirmations'] ) ? (int) $ots['confirmations'] : null;
				$same   = '' !== (string) ( $record['content_hash'] ?? '' ) && sn_note_dossier_hash_norm( $record['content_hash'] ) === sn_note_dossier_hash_norm( $commit['content_hash'] ?? '' );
				if ( $block > 0 ) {
					$text = sprintf( /* translators: 1: version, 2: block number. */ __( 'v%1$d anchored in Bitcoin block %2$s', 'signal-and-noise-tools' ), $v, number_format_i18n( $block ) );
					if ( null !== $conf ) {
						$text .= sprintf( /* translators: %d: confirmations. */ _n( ', %d confirmation', ', %d confirmations', $conf, 'signal-and-noise-tools' ), $conf );
					}
					$text .= '.';
				} else {
					$text = sprintf( /* translators: %d: version. */ __( 'v%d is in the ledger; the record names no block yet.', 'signal-and-noise-tools' ), $v );
				}
				$meta = $same ? __( 'The ledger record attests the same content hash.', 'signal-and-noise-tools' ) : __( 'The ledger record attests a DIFFERENT content hash.', 'signal-and-noise-tools' );
				$time = (string) ( $commit['block_time'] ?? '' );
				if ( '' !== $time ) {
					$meta .= ' ' . sprintf( /* translators: %s: block time string. */ __( 'Block time as the worker reported it: %s.', 'signal-and-noise-tools' ), $time );
				}
				$door = '' !== $txid ? sn_note_dossier_door( __( 'View the transaction', 'signal-and-noise-tools' ), 'https://mempool.space/tx/' . rawurlencode( $txid ) ) : $anchor_door;
				$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), $same ? 'success' : 'danger', $text, $meta, __( 'public ledger', 'signal-and-noise-tools' ), $door );
			}
		}
	}

	// ── Signer ───────────────────────────────────────────────────────────
	$named = (string) ( is_array( $record ) ? ( $record['pubkey_id'] ?? '' ) : '' );
	if ( '' === $named ) {
		$head  = $commit ?: ( $chain ? end( $chain ) : null );
		$named = is_array( $head ) ? (string) ( $head['pubkey_id'] ?? '' ) : '';
	}
	if ( '' === $named ) {
		$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'neutral', __( 'Signer not recorded.', 'signal-and-noise-tools' ), __( 'Commits made before the worker returned a key id carry none; the ledger record names the key when there is one.', 'signal-and-noise-tools' ), __( 'local chain', 'signal-and-noise-tools' ) );
	} elseif ( ! is_callable( $fetcher ) || ! function_exists( 'sn_prov_integrity_keys_probe' ) || ! function_exists( 'sn_prov_key_id' ) ) {
		$blocks[] = sn_note_dossier_unreadable( 'trust', __( 'Signer', 'signal-and-noise-tools' ), __( 'the provenance module', 'signal-and-noise-tools' ) );
	} else {
		$probe    = sn_prov_integrity_keys_probe( $fetcher );
		$ids      = isset( $probe['published_ids'] ) && is_array( $probe['published_ids'] ) ? $probe['published_ids'] : null;
		$followed = (string) sn_prov_key_id();
		if ( null === $ids ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'warning', sprintf( /* translators: %s: key id. */ __( 'Signed by %s; the ledger\'s key list could not be checked.', 'signal-and-noise-tools' ), $named ), __( 'Could not be checked, not a mismatch.', 'signal-and-noise-tools' ), __( 'ledger keys', 'signal-and-noise-tools' ) );
		} elseif ( ! in_array( $named, $ids, true ) ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'danger', sprintf( /* translators: %s: key id. */ __( 'Signed by %s, a key the ledger no longer publishes.', 'signal-and-noise-tools' ), $named ), __( 'Readers can no longer verify this signature from the published keys.', 'signal-and-noise-tools' ), __( 'ledger keys', 'signal-and-noise-tools' ) );
		} elseif ( $named === $followed ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'success', sprintf( /* translators: %s: key id. */ __( 'Signed by %s, the followed key.', 'signal-and-noise-tools' ), $named ), '', __( 'ledger keys', 'signal-and-noise-tools' ) );
		} else {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'info', sprintf( /* translators: 1: key id, 2: the followed key id. */ __( 'Signed by %1$s; the followed key is now %2$s.', 'signal-and-noise-tools' ), $named, $followed ), __( 'A retired key the ledger still publishes verifies.', 'signal-and-noise-tools' ), __( 'ledger keys', 'signal-and-noise-tools' ) );
		}
	}

	// ── Citations received ───────────────────────────────────────────────
	$rows = function_exists( 'sn_cit_for_post' ) ? (array) sn_cit_for_post( $post->ID, false ) : array();
	if ( array() === $rows ) {
		$blocks[] = sn_note_dossier_text( 'trust', __( 'Citations received', 'signal-and-noise-tools' ), __( 'No citations recorded for this note.', 'signal-and-noise-tools' ), __( 'citation graph', 'signal-and-noise-tools' ) );
	} else {
		$out = array();
		foreach ( $rows as $r ) {
			$tier  = (string) ( $r->tier ?? 'unverified' );
			$host  = (string) wp_parse_url( (string) ( $r->source_url ?? '' ), PHP_URL_HOST );
			$title = trim( (string) ( $r->source_title ?? '' ) );
			$out[] = array(
				'tier'    => array( 'text' => $tier, 'tone' => sn_note_dossier_tone( function_exists( 'sn_cit_tier_pill_kind' ) ? sn_cit_tier_pill_kind( $tier ) : '' ) ),
				'source'  => '' !== $title ? $title : ( '' !== $host ? $host : (string) ( $r->source_url ?? '' ) ),
				'checked' => function_exists( 'sn_cit_ago_label' ) ? sn_cit_ago_label( $r->last_checked_gmt ?? null ) : '',
			);
		}
		$blocks[] = sn_note_dossier_table(
			'trust',
			__( 'Citations received', 'signal-and-noise-tools' ),
			array( array( 'key' => 'tier', 'label' => __( 'Tier', 'signal-and-noise-tools' ) ), array( 'key' => 'source', 'label' => __( 'Source', 'signal-and-noise-tools' ) ), array( 'key' => 'checked', 'label' => __( 'Last checked', 'signal-and-noise-tools' ) ) ),
			$out,
			__( 'citation graph', 'signal-and-noise-tools' ),
			function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( __( 'Open Citations in S&N Dashboard', 'signal-and-noise-tools' ), snt_desktop_admin_url( 'sn-tools', 'citations' ) ) : null
		);
	}
	return $blocks;
}

/**
 * The re-check: what the server can verify about one note, right now.
 *
 * Walks the integrity module's legs -- the published twin, the ledger record
 * of the newest confirmed version, the key ids the ledger publishes -- and
 * returns a verdict the app stores in its state. The Ed25519 signature is
 * verified by the public /verify page in the browser; this verdict names
 * what it checked and never claims more.
 *
 * @param int           $post_id
 * @param callable|null $fetcher As in sn_note_dossier_trust().
 * @return array{post_id:int,tone:string,text:string,meta:string,checked_at:string}
 */
function sn_note_dossier_verify( $post_id, $fetcher = null ) {
	$checked_at = gmdate( 'c' );
	$post       = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return array( 'post_id' => (int) $post_id, 'tone' => 'warning', 'text' => __( 'Not a note.', 'signal-and-noise-tools' ), 'meta' => '', 'checked_at' => $checked_at );
	}
	$fetcher = is_callable( $fetcher ) ? $fetcher : ( function_exists( 'sn_prov_integrity_http_fetch' ) ? 'sn_prov_integrity_http_fetch' : null );
	if ( ! is_callable( $fetcher ) || ! function_exists( 'sn_prov_integrity_keys_probe' ) || ! function_exists( 'sn_prov_integrity_check_note' ) ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'warning', 'text' => __( 'The verifier is not loaded.', 'signal-and-noise-tools' ), 'meta' => '', 'checked_at' => $checked_at );
	}
	$probe = sn_prov_integrity_keys_probe( $fetcher );
	$ids   = isset( $probe['published_ids'] ) && is_array( $probe['published_ids'] ) ? $probe['published_ids'] : null;
	$r     = sn_prov_integrity_check_note( (int) $post->ID, $fetcher, $ids );
	if ( ! is_array( $r ) ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'neutral', 'text' => __( 'Nothing to verify yet: no signed version.', 'signal-and-noise-tools' ), 'meta' => __( 'A note is signed when it is published.', 'signal-and-noise-tools' ), 'checked_at' => $checked_at );
	}
	$failures = array_values( array_map( 'strval', (array) ( $r['failures'] ?? array() ) ) );
	if ( 'keys_unreachable' === (string) ( $probe['verdict'] ?? '' ) ) {
		$failures[] = 'keys_unreachable';
	}
	// Outages AND named gaps are warnings: neither is a claim about the note.
	// `subject_kind_unresolved` is the house's own "a gap, never a drift claim".
	$gaps      = array( 'subject_kind_unresolved' );
	$is_outage = function_exists( 'sn_prov_integrity_is_outage' ) ? 'sn_prov_integrity_is_outage' : static function ( $c ) { return false; };
	$is_gap    = static function ( $c ) use ( $is_outage, $gaps ) { return (bool) call_user_func( $is_outage, $c ) || in_array( $c, $gaps, true ); };
	$real      = array_values( array_filter( $failures, static function ( $c ) use ( $is_gap ) { return ! $is_gap( $c ); } ) );
	$outages   = array_values( array_filter( $failures, $is_gap ) );
	$sentence  = function_exists( 'sn_prov_integrity_failure_sentence' ) ? 'sn_prov_integrity_failure_sentence' : 'strval';
	$anchored  = (int) ( $r['anchored_version'] ?? 0 );
	$version   = (int) ( $r['version'] ?? 0 );
	$uid       = (string) ( $r['uid'] ?? '' );
	// The ledger leg runs only for a confirmed version WITH a uid (provenance-
	// integrity.php:353). The sentence keys on the same precondition, so it never
	// claims a check that did not run.
	if ( $anchored > 0 && '' !== $uid ) {
		$checked = sprintf( /* translators: %d: anchored version. */ __( 'Checked: the published twin, the ledger record for v%d, and the key ids the ledger publishes. The signature itself is verified by the public /verify page.', 'signal-and-noise-tools' ), $anchored );
	} elseif ( '' === $uid ) {
		$checked = __( 'Checked: the published twin and the key ids the ledger publishes; this note carries no ledger UID, so no ledger record was located. The signature itself is verified by the public /verify page.', 'signal-and-noise-tools' );
	} else {
		$checked = __( 'Checked: the published twin and the key ids the ledger publishes; no confirmed anchor yet, so there is no ledger record to read. The signature itself is verified by the public /verify page.', 'signal-and-noise-tools' );
	}
	if ( $real ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'danger', 'text' => sprintf( /* translators: %d: version. */ __( 'v%d does not hold.', 'signal-and-noise-tools' ), $version ), 'meta' => implode( '; ', array_map( $sentence, $failures ) ) . '. ' . $checked, 'checked_at' => $checked_at );
	}
	if ( $outages ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'warning', 'text' => sprintf( /* translators: %d: version. */ __( 'v%d could not be fully checked.', 'signal-and-noise-tools' ), $version ), 'meta' => implode( '; ', array_map( $sentence, $outages ) ) . '. ' . $checked, 'checked_at' => $checked_at );
	}
	return array( 'post_id' => (int) $post->ID, 'tone' => 'success', 'text' => sprintf( /* translators: %d: version. */ __( 'v%d holds.', 'signal-and-noise-tools' ), $version ), 'meta' => $checked, 'checked_at' => $checked_at );
}
