<?php
/**
 * Signal & Noise Tools -- admin post actions: Zenodo (15.11.0).
 *
 * Actions served: zenodo_env_save (the environment switch), zenodo_deposit_batch
 * (one backfill pass, now), zenodo_deposit_one (one document, by id).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The environment switch. */
function sn_handle_zenodo_env_save( $post ) {
	$env = ( isset( $post['zenodo_env'] ) && 'production' === (string) $post['zenodo_env'] ) ? 'production' : 'sandbox';
	update_option( SN_ZENODO_ENV_OPT, $env );
	// 16.1.1: the ledger's concept DOI rides the same form. A non-DOI paste
	// clears the option rather than storing a string the API would reject.
	update_option( SN_ZENODO_LEDGER_DOI_OPT, sn_zenodo_normalize_doi( wp_unslash( (string) ( $post['zenodo_ledger_doi'] ?? '' ) ) ) );
	return 'zenodo_env_saved';
}

/** One backfill pass, on demand (the same pass the hourly cron runs). */
function sn_handle_zenodo_deposit_batch( $post ) {
	unset( $post );
	if ( ! sn_zenodo_is_enabled() ) {
		return 'zenodo_no_token';
	}
	$r = sn_zenodo_backfill_pass();
	set_transient( 'sn_zenodo_last_batch', $r, HOUR_IN_SECONDS );
	return $r['published'] > 0 ? 'zenodo_batch_done' : ( $r['attempted'] > 0 ? 'zenodo_batch_failed' : 'zenodo_batch_empty' );
}

/** One document, by id. */
function sn_handle_zenodo_deposit_one( $post ) {
	$id = (int) ( $post['post_id'] ?? 0 );
	if ( $id <= 0 ) {
		return 'zenodo_batch_empty';
	}
	if ( ! sn_zenodo_is_enabled() ) {
		return 'zenodo_no_token';
	}
	$r = sn_zenodo_deposit( $id );
	return $r['ok'] ? 'zenodo_batch_done' : 'zenodo_batch_failed';
}
