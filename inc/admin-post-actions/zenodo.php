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
