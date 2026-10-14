<?php
/**
 * Signal & Noise Tools: what is left of the Cloudways app purge.
 *
 * RETIRED 2026-10-14. The purge (Cloudways API /app/cache/purge, v8.6.0) ran
 * only on `breeze_clear_varnish`. Varnish is off for this app (Cloudways API:
 * app 5349211, varnish_enabled false), the theme stopped firing that action
 * (signal-and-noise#532), and Breeze is deactivated, so nothing could reach it.
 * The hook, the call, its token cache and the SN_CLOUDWAYS_APP_PURGE switch
 * are gone. The Cloudflare edge is the only page cache; the manual purge
 * empties Redis itself.
 *
 * Kept: the four wp-config credential readers (the Connections leaf shows
 * whether they are set) and SNT_CW_LAST_PURGE_OPT, the record of the last call
 * Cloudways answered, which the leaf still reads as history.
 *
 * @package SignalNoiseTools
 * @since 8.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_CW_LAST_PURGE_OPT = 'sn_cloudways_last_purge';

/**
 * Read a Cloudways config constant.
 *
 * @param string $name Constant name.
 * @return string Value, or '' when undefined/empty.
 */
function sn_cloudways_cfg( $name ) {
	return ( defined( $name ) && constant( $name ) ) ? (string) constant( $name ) : '';
}

/**
 * True only when all four credentials are present.
 *
 * @return bool
 */
function sn_cloudways_is_configured() {
	return '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_EMAIL' )
		&& '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_API_KEY' )
		&& '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_SERVER_ID' )
		&& '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_APP_ID' );
}

/**
 * Whether the app purge runs: never (retired 2026-10-14). The Connections
 * leaves ask this to read the last record as history, not as a live result.
 *
 * @return bool
 */
function sn_cloudways_app_purge_on() {
	return false;
}

/**
 * The purge ledger's Cloudways column for a new row. Older rows keep the
 * words they were written with ('ok http 200', 'skipped: page-only purge',
 * 'not run').
 *
 * @return string
 */
function sn_cloudways_not_run_label() {
	return 'retired: Varnish is off for this app';
}
