<?php
/**
 * Signal & Noise Tools: rights evidence, the ledger reads (read-only).
 *
 * The public provenance ledger holds every rights-signal version at
 * `rights-signals/<slug>/vN.json` ({content_hash, ots:{status, bitcoin_block}})
 * and only the CURRENT one in index.json. A record about a past month needs
 * the versions in force during that month, so the history is walked here,
 * N = 1..current. A version's anchor is its Bitcoin block height as the
 * ledger states it; the block's time comes from the same public explorer the
 * provenance worker already reads (blockstream.info, Esplora). A confirmed
 * version and a block time never change, so both are cached for good in one
 * option; a pending version is read again next pass.
 *
 * Also the refresh of stored records (F5): a posted record's status and block
 * are re-read from the ledger file the worker wrote, never from our memory.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explorer for block height to block time (Esplora API, the worker's host). */
const SN_RIGHTS_EVIDENCE_EXPLORER = 'https://blockstream.info/api/';

/** At most this many stored records are re-read from the ledger per pass. */
const SN_RIGHTS_EVIDENCE_REFRESH_CAP = 12;

/**
 * One ledger JSON document, decoded.
 *
 * @param string $path Path under the ledger base.
 * @return array|null null when unreachable, not 200, or not a JSON object.
 */
function sn_rights_evidence_ledger_json( $path ) {
	if ( ! function_exists( 'sn_prov_integrity_ledger_base' ) || ! function_exists( 'sn_prov_integrity_http_fetch' ) ) {
		return null;
	}
	$res = sn_prov_integrity_http_fetch( sn_prov_integrity_ledger_base() . ltrim( (string) $path, '/' ) );
	if ( 200 !== (int) ( $res['code'] ?? 0 ) ) {
		return null;
	}
	$json = json_decode( (string) ( $res['body'] ?? '' ), true );
	return is_array( $json ) ? $json : null;
}

/**
 * The time of a Bitcoin block, ISO 8601 UTC. Cached forever: a mined block's
 * header time does not change.
 *
 * @param int $height Block height.
 * @return string '' when the explorer cannot say.
 */
function sn_rights_evidence_block_time( $height ) {
	$height = (int) $height;
	$cache  = (array) get_option( 'sn_rights_evidence_chain', array() );
	if ( isset( $cache['blocks'][ $height ] ) ) {
		return (string) $cache['blocks'][ $height ];
	}
	if ( $height <= 0 || ! function_exists( 'sn_prov_integrity_http_fetch' ) ) {
		return '';
	}
	$hash = sn_prov_integrity_http_fetch( SN_RIGHTS_EVIDENCE_EXPLORER . 'block-height/' . $height );
	$hash = trim( (string) ( $hash['body'] ?? '' ) );
	if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
		return '';
	}
	$block = sn_prov_integrity_http_fetch( SN_RIGHTS_EVIDENCE_EXPLORER . 'block/' . $hash );
	$json  = json_decode( (string) ( $block['body'] ?? '' ), true );
	$ts    = is_array( $json ) ? (int) ( $json['timestamp'] ?? 0 ) : 0;
	if ( $ts <= 0 || (int) ( $json['height'] ?? -1 ) !== $height ) {
		return '';
	}
	$iso                        = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	$cache['blocks'][ $height ] = $iso;
	update_option( 'sn_rights_evidence_chain', $cache, false );
	return $iso;
}

/** A ceiling on one signal's version walk (tdm-policy is at v9 in 2026-09). */
const SN_RIGHTS_EVIDENCE_MAX_VERSIONS = 200;

/**
 * Every version of every rights signal the index names, with its anchor.
 *
 * @param array $index The decoded index.json.
 * @return array<string,array<int,array{version:int,content_hash:string,block:?int,anchored_at:string}>>|null
 *         Slug => versions ascending. anchored_at '' for a version not yet in a
 *         block. null when a version file or a confirmed block's time cannot be
 *         read: a record with a hole in its reservation is refused, not shipped.
 */
function sn_rights_evidence_signal_history( array $index ) {
	$cache = (array) get_option( 'sn_rights_evidence_chain', array() );
	$out   = array();
	foreach ( (array) ( $index['rights_signals'] ?? array() ) as $r ) {
		$slug    = is_array( $r ) ? (string) ( $r['slug'] ?? '' ) : '';
		$current = is_array( $r ) ? (int) ( $r['version'] ?? 0 ) : 0;
		if ( 1 !== preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) || $current < 1 ) {
			continue;
		}
		if ( $current > SN_RIGHTS_EVIDENCE_MAX_VERSIONS ) {
			return null; // An index claiming more versions than a signal can carry is not walked.
		}
		for ( $n = 1; $n <= $current; $n++ ) {
			$v = $cache['versions'][ $slug ][ $n ] ?? null;
			if ( ! is_array( $v ) ) {
				$doc = sn_rights_evidence_ledger_json( 'rights-signals/' . $slug . '/v' . $n . '.json' );
				if ( null === $doc ) {
					return null;
				}
				if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) ( $doc['content_hash'] ?? '' ) ) ) {
					return null; // A version with no sha256 cannot be attested in force.
				}
				$block = 'confirmed' === (string) ( $doc['ots']['status'] ?? '' ) && is_numeric( $doc['ots']['bitcoin_block'] ?? null ) ? (int) $doc['ots']['bitcoin_block'] : null;
				$at    = null === $block ? '' : sn_rights_evidence_block_time( $block );
				if ( null !== $block && '' === $at ) {
					return null;
				}
				$v = array( 'version' => $n, 'content_hash' => (string) ( $doc['content_hash'] ?? '' ), 'block' => $block, 'anchored_at' => $at );
				if ( null !== $block ) {
					$cache                               = (array) get_option( 'sn_rights_evidence_chain', array() );
					$cache['versions'][ $slug ][ $n ]    = $v; // Confirmed: immutable, never read again.
					update_option( 'sn_rights_evidence_chain', $cache, false );
				}
			}
			$out[ $slug ][] = $v;
		}
	}
	ksort( $out );
	return $out;
}

/**
 * F5: re-read every posted record that is not yet final from the ledger file
 * the worker wrote, and take its status and block. Read-only against the
 * ledger; runs before the hold, so a held pass still refreshes.
 * ponytail: capped at SN_RIGHTS_EVIDENCE_REFRESH_CAP reads per pass, oldest
 * month first; the rest wait a day. Lift the cap if records ever pile up.
 *
 * @return int Records whose stored status or block changed.
 */
function sn_rights_evidence_refresh() {
	$final   = array( 'confirmed', 'conflict', 'retracted' );
	$data    = sn_rights_evidence_data();
	$reads   = 0;
	$updates = array();
	ksort( $data );
	foreach ( $data as $month => $families ) {
		foreach ( (array) $families as $family => $e ) {
			$path = is_array( $e ) ? (string) ( $e['ledger_path'] ?? '' ) : '';
			// Final: never re-read. A retracted record's v1 file still says
			// confirmed; re-reading it would flip the retraction back.
			if ( '' === $path || in_array( (string) ( $e['status'] ?? '' ), $final, true ) ) {
				continue;
			}
			if ( $reads++ >= SN_RIGHTS_EVIDENCE_REFRESH_CAP ) {
				break 2;
			}
			$doc    = sn_rights_evidence_ledger_json( $path );
			$status = (string) ( $doc['ots']['status'] ?? '' );
			if ( null === $doc || '' === $status ) {
				continue;
			}
			$block = is_numeric( $doc['ots']['bitcoin_block'] ?? null ) ? (int) $doc['ots']['bitcoin_block'] : null;
			if ( $status !== (string) ( $e['status'] ?? '' ) || $block !== ( $e['block'] ?? null ) ) {
				$updates[ $month ][ $family ] = array( $status, $block );
			}
		}
	}
	if ( ! $updates ) {
		return 0;
	}
	// The reads above can take seconds; a retraction or a pass may have
	// written meanwhile. Re-read, and touch only status and block of entries
	// that are still non-final in the fresh copy: never a concurrent change.
	$fresh   = sn_rights_evidence_data();
	$changed = 0;
	foreach ( $updates as $month => $families ) {
		foreach ( $families as $family => $u ) {
			$e = $fresh[ $month ][ $family ] ?? null;
			if ( ! is_array( $e ) || in_array( (string) ( $e['status'] ?? '' ), $final, true ) ) {
				continue;
			}
			$fresh[ $month ][ $family ]['status'] = $u[0];
			$fresh[ $month ][ $family ]['block']  = $u[1];
			$changed++;
		}
	}
	if ( $changed ) {
		update_option( SN_RIGHTS_EVIDENCE_OPTION, $fresh, false );
	}
	return $changed;
}
