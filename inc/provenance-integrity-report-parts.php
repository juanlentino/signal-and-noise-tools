<?php
/**
 * Signal & Noise Tools: provenance integrity sweep, the reads and the reports (the stored state, the failure sentences, the findings, the Health check, the status ability and its hook).
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
 * The house sentence for one integrity failure code -- ONE table, so the
 * sweep's findings, the health check and the app's re-check verdict say the
 * same thing about the same leg. An unknown code comes back as itself: the
 * caller can see it was not translated, instead of a sentence that reads as
 * a verdict nobody made.
 *
 * @param string $code A failure code from sn_prov_integrity_check_note() or the keys probe.
 * @return string
 */
function sn_prov_integrity_failure_sentence( $code ) {
	static $legs = array(
		'hash_mismatch'           => 'stored payload no longer reproduces the anchored content hash (hash mismatch)',
		'twin_drift'              => 'the published .json twin\'s words no longer match the signed payload (twin drift)',
		'twin_unreachable'        => 'the published .json twin could not be fetched (unreachable: an outage, not drift)',
		'twin_missing'            => 'the published .json twin has 404ed for three consecutive sweeps (twin missing: the public twin is gone, not blipping)',
		'ledger_missing'          => 'the public ledger record <notes|pages>/<uid>/v<n>.json is absent (ledger missing)',
		'subject_kind_unresolved' => 'the subject kind could not be resolved, so the ledger directory is unknown and was NOT guessed (gap, never a drift claim)',
		'ledger_unreachable'      => 'the public ledger could not be reached (unreachable: an outage, not drift)',
		'ledger_hash_mismatch'    => 'the public ledger record attests a different content hash (ledger contradiction)',
		'ledger_record_malformed' => 'the public ledger record exists but carries no content_hash (malformed record: it attests nothing)',
		'no_signed_commit'        => 'the subject carries no signed v1+ commit at all, so /verify tells a reader no proof exists (unverifiable: absence, never drift)',
		'signing_key_unpublished' => 'the key this commit was signed with is no longer published in keys/provenance-keys.json, so readers can no longer verify its signature (retired key dropped)',
		'keys_unreachable'        => 'the ledger\'s key list could not be read (unreachable: an outage, not a mismatch)',
		'key_mismatch'            => 'the ledger publishes the followed key id with different key bytes than this site holds (key mismatch: readers verifying against the ledger will not reproduce this site\'s signatures)',
		'keys_not_configured'     => 'the key ids were not checked: no signing key is configured here (gap, never a mismatch)',
	);
	$code = (string) $code;
	return isset( $legs[ $code ] ) ? $legs[ $code ] : $code;
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
	$out   = array();
	$notes = ( isset( $state['notes'] ) && is_array( $state['notes'] ) ) ? $state['notes'] : array();
	foreach ( $notes as $pid => $row ) {
		$failures = ( isset( $row['failures'] ) && is_array( $row['failures'] ) ) ? $row['failures'] : array();
		if ( array() === $failures ) {
			continue;
		}
		$named = array();
		foreach ( $failures as $code ) {
			$named[] = sn_prov_integrity_failure_sentence( $code );
		}
		$out[] = array(
			'subject_type'  => 'provenance_integrity',
			'subject_id'    => (int) $pid,
			'subject_url'   => (string) ( $row['url'] ?? '' ),
			'subject_label' => (string) ( '' !== (string) ( $row['title'] ?? '' ) ? $row['title'] : ( $row['uid'] ?? '' ) ),
			'edit_url'      => '',
			'note'          => in_array( 'no_signed_commit', $failures, true )
				? sprintf( 'Provenance integrity cannot be checked: %s.', implode( '; ', $named ) )
				: sprintf(
					'Provenance integrity check failed for v%d: %s.',
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
		return sn_health_pack_check( $label, array(), '', 'Provenance subsystem inactive (ext-intl absent): the sweep did not run.' );
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
			), null
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
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
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
