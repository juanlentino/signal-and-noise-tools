<?php
/**
 * Signal & Noise app, the Attention queue's readers: the integrity and anchors readers.
 *
 * A pure move from parts/attention-readers.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The last integrity sweep's failing notes.
 *
 * PURE over stored state: sn_prov_integrity_state() is one option read and the
 * failure sentences are sn_prov_integrity_failure_sentence()'s single table,
 * so the queue, the health check and the app's re-check verdict say the same
 * thing about the same leg. Findings ACCRUE across the rotation (ten notes per
 * sweep), which is why each row carries the note's own `last_checked` rather
 * than the sweep's: a row is "flagged as of when this note was last looked at".
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_integrity() {
	if ( ! function_exists( 'sn_prov_integrity_state' ) || ! function_exists( 'sn_prov_integrity_failure_sentence' ) ) {
		return attention_read();
	}
	try {
		$state = \sn_prov_integrity_state();
		if ( ! is_array( $state ) ) {
			// Null is "no sweep has ever run": the reader is installed and has
			// nothing to read, which is not the same as a clean fleet.
			return attention_unreadable();
		}
		$door  = attention_door( 'sn-tools', 'trust' );
		$rows  = array();
		$notes = isset( $state['notes'] ) && is_array( $state['notes'] ) ? $state['notes'] : array();
		// A GAP IS NOT A FAILURE. An outage code (the sweep's own
		// sn_prov_integrity_is_outage(); absent, nothing is an outage) and the
		// two named gaps are a hole in today's evidence, not a claim about the
		// note -- which is why the sweep counts a note whose codes are all
		// outages in its own `unreachable` bucket rather than as failed
		// (inc/provenance-integrity.php:506-513), and why
		// sn_note_dossier_verify() tones exactly this partition warning
		// (inc/note-dossier-trust.php:216-286). The estate's rule is that an
		// unreachable check reads unreachable, never failed; one real code and
		// the row is danger again.
		$is_outage = function_exists( 'sn_prov_integrity_is_outage' )
			? 'sn_prov_integrity_is_outage'
			: static function ( $code ) {
				return false;
			};
		$gaps      = array( 'subject_kind_unresolved', 'keys_not_configured' );
		foreach ( $notes as $pid => $note ) {
			$failures = ( isset( $note['failures'] ) && is_array( $note['failures'] ) ) ? array_values( $note['failures'] ) : array();
			if ( array() === $failures ) {
				continue;
			}
			$said = array();
			$real = false;
			foreach ( $failures as $code ) {
				$code   = (string) $code;
				$said[] = (string) \sn_prov_integrity_failure_sentence( $code );
				if ( ! (bool) call_user_func( $is_outage, $code ) && ! in_array( $code, $gaps, true ) ) {
					$real = true;
				}
			}
			$rows[] = attention_row( array(
				'kind'       => 'integrity',
				'key'        => (string) (int) $pid,
				'title'      => attention_post_title( (int) $pid, (string) ( $note['title'] ?? '' ) ),
				'subtitle'   => implode( '; ', $said ),
				'tone'       => $real ? 'danger' : 'warning',
				'stamp'      => attention_stamp( $note['last_checked'] ?? '' ),
				'source'     => __( 'The provenance integrity sweep', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Trust checks in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => (int) $pid,
			) );
		}
		// The FLEET-level key verdict rides the sweep summary, not a note: the
		// ledger's key file disagreeing with this site (or gone, or unreachable)
		// is one row about every signed subject at once, filed under the sweep.
		$keys = (string) ( $state['last_sweep']['keys'] ?? '' );
		if ( in_array( $keys, array( 'key_mismatch', 'keys_missing', 'keys_unreachable' ), true ) ) {
			// Word for word the sentences sn_prov_integrity_findings() files for
			// the same verdicts: the queue says what the findings say.
			$said = array(
				'key_mismatch'     => __( 'The public ledger\'s keys/provenance-keys.json no longer serves the published key id with the published key bytes (key mismatch): readers can no longer independently verify signatures.', 'signal-and-noise-tools' ),
				'keys_missing'     => __( 'The public ledger\'s keys/provenance-keys.json has 404ed for three consecutive sweeps: the key file is absent from the ledger, not blipping. Readers cannot independently cross-check signatures until it is restored.', 'signal-and-noise-tools' ),
				'keys_unreachable' => __( 'The public ledger\'s keys/provenance-keys.json could not be reached (unreachable: an outage, not drift, not a key rotation).', 'signal-and-noise-tools' ),
			);
			$rows[] = attention_row( array(
				'kind'       => 'integrity',
				'key'        => 'keys',
				'title'      => __( 'The ledger\'s key file', 'signal-and-noise-tools' ),
				'subtitle'   => $said[ $keys ],
				'tone'       => 'keys_unreachable' === $keys ? 'warning' : 'danger',
				'stamp'      => attention_stamp( $state['last_sweep']['swept_at'] ?? '' ),
				'source'     => __( 'The provenance integrity sweep', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Trust checks in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => 0,
			) );
		}
		return attention_read( $rows, attention_stamp( $state['last_sweep']['swept_at'] ?? '' ) );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Commits still unanchored or in flight.
 *
 * sn_prov_admin_status(), NOT snt_prov_anchor_overview(): the overview queries
 * `post_type => post` only, so a signed page never appears in it, and it does
 * not report `unanchored` at all. This reader covers both statuses and both
 * subject kinds, which is what the reconcile and integrity sweeps cover.
 *
 * IT IS A WINDOW, NOT THE CHAIN. sn_prov_admin_status() reads the newest 100
 * PUBLISHED subjects carrying a ledger UID, in the default date-desc order,
 * with no pagination (inc/provenance-admin.php:19-27) -- so an old pending
 * commit on the 101st subject is not in this reading at all. The row's
 * `source` says so, the way the edge reader discloses its twenty-save window:
 * a bound nobody states is read as "the whole thing".
 *
 * There is no "long-pending" threshold anywhere in the estate, so none is
 * invented here: the row says how long, and lets the reader judge.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_anchors() {
	if ( ! function_exists( 'sn_prov_admin_status' ) ) {
		return attention_read();
	}
	try {
		$status = \sn_prov_admin_status();
		if ( ! is_array( $status ) ) {
			return attention_unreadable();
		}
		$door    = attention_door( 'sn-tools', 'provenance' );
		$rows    = array();
		$newest  = '';
		foreach ( (array) ( $status['pending'] ?? array() ) as $commit ) {
			if ( ! is_array( $commit ) ) {
				continue;
			}
			$state = (string) ( $commit['status'] ?? '' );
			if ( 'pending' !== $state && 'unanchored' !== $state ) {
				continue;
			}
			$pid     = (int) ( $commit['post_id'] ?? 0 );
			$version = (int) ( $commit['version'] ?? 0 );
			$stamp   = attention_stamp( $commit['committed_at'] ?? '' );
			$since   = '' !== $stamp ? attention_local( $stamp ) : __( 'an unrecorded time', 'signal-and-noise-tools' );
			$rows[]  = attention_row( array(
				'kind'       => 'anchors',
				'key'        => $pid . '-v' . $version,
				'title'      => attention_post_title( $pid, (string) ( $commit['note_uid'] ?? '' ) ),
				'subtitle'   => 'unanchored' === $state
					/* translators: 1: commit version. 2: a timestamp in the site timezone with its zone. */
					? sprintf( __( 'v%1$d unanchored since %2$s', 'signal-and-noise-tools' ), $version, $since )
					/* translators: 1: commit version. 2: a timestamp in the site timezone with its zone. */
					: sprintf( __( 'v%1$d pending since %2$s', 'signal-and-noise-tools' ), $version, $since ),
				// Unanchored is the Worker never answering; pending is a proof
				// in flight, which is the system working.
				'tone'       => 'unanchored' === $state ? 'warning' : 'neutral',
				'stamp'      => $stamp,
				'source'     => __( 'The signed commit chain (the newest hundred published subjects with a ledger UID)', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Provenance in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => $pid,
			) );
			if ( $stamp > $newest ) {
				$newest = $stamp;
			}
		}
		return attention_read( $rows, $newest );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

