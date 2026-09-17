<?php
/**
 * Signal & Noise Tools -- Content Health check: DOIs.
 *
 * Check 29 (15.11.0): signed documents whose anchor is confirmed and that
 * carry no production DOI. A note publishes, anchors within hours, and gets
 * its DOI on the next pass; one that sits without a DOI is a document the
 * scholarly record cannot resolve. Pending anchors are excluded (they are not
 * ready by design); sandbox DOIs count as none (10.5072 resolves nowhere).
 *
 * SKIPPED, never a pass, when no token is set for the environment: the check
 * cannot say the DOIs are there when nothing could have minted them.
 *
 * @package SignalNoiseTools
 * @since 15.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Judge the ledger rows. PURE.
 *
 * @since 15.11.0
 * @param array $rows sn_zenodo_ledger() rows.
 * @return array Findings.
 */
function sn_health_zenodo_doi_judge( $rows ) {
	$findings = array();
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$state = (string) ( $r['state'] ?? '' );
		if ( 'minted' === $state || 'anchor-pending' === $state || 'no-commit' === $state || 'not-a-subject' === $state || 'not-published' === $state ) {
			continue;
		}
		$id = (int) ( $r['id'] ?? 0 );
		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => $id,
			'subject_url'   => function_exists( 'get_permalink' ) ? (string) get_permalink( $id ) : '',
			'subject_label' => (string) ( $r['title'] ?? '' ),
			'edit_url'      => function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '',
			'note'          => 'sandbox' === $state
				? 'Carries a sandbox DOI only (' . (string) ( $r['doi'] ?? '' ) . '); the production deposit has not run.'
				: ( '' !== (string) ( $r['error'] ?? '' )
					? 'Anchor confirmed, no DOI; the last deposit failed: ' . (string) $r['error']
					: 'Anchor confirmed, no DOI yet; the next Zenodo pass deposits it.' ),
		);
	}
	return $findings;
}

/**
 * CHECK 29: confirmed documents without a production DOI.
 *
 * @since 15.11.0
 * @return array
 */
function sn_health_check_zenodo_doi() {
	$enabled = function_exists( 'sn_zenodo_is_enabled' ) && sn_zenodo_is_enabled();
	$env     = function_exists( 'sn_zenodo_env' ) ? sn_zenodo_env() : 'sandbox';
	$rows    = $enabled && function_exists( 'sn_zenodo_ledger' ) ? sn_zenodo_ledger() : array();
	$skipped = null;
	if ( ! $enabled ) {
		$skipped = 'No Zenodo token for the current environment; nothing could have minted a DOI.';
	} elseif ( 'production' !== $env ) {
		$skipped = 'Zenodo is set to sandbox; production DOIs cannot exist yet.';
	}
	return sn_health_pack_check(
		'Documents without a DOI',
		null === $skipped ? sn_health_zenodo_doi_judge( $rows ) : array(),
		'Connections › Zenodo runs the deposit; a document is deposited once its anchor is confirmed. A failed row names why.',
		$skipped
	);
}
