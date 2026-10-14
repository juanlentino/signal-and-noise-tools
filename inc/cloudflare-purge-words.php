<?php
/**
 * Signal & Noise Tools: the Cloudflare Cache box's phrases, shared by the
 * classic page (inc/cloudflare-purge.php) and the native leaf
 * (connections-cloudflare-parts.php) so the two say the same sentences.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When auto-purge fires, as one phrase on both surfaces (2026-10-13: the
 * Site Editor saves and the mark-stale on an update are new).
 *
 * @return string
 */
function sn_cf_auto_purge_when() {
	return __( 'on post save, on a Site Editor menu, template or template part save, on a theme or plugin update (which marks pages stale so each refreshes on its next request) and through the REST endpoint', 'signal-and-noise-tools' );
}

/**
 * What the last purge covered, in words: the URL count, the full zone, or
 * the full zone marked stale (a code update since 2026-10-13).
 *
 * @param array $last SN_CF_LAST_PURGE_OPT.
 * @return string
 */
function sn_cf_last_purge_kind( array $last ) {
	if ( 'all' !== (string) ( $last['kind'] ?? '' ) ) {
		$n = (int) ( $last['count'] ?? 0 );
		/* translators: %d: URLs purged */
		return sprintf( _n( '%d URL', '%d URLs', $n, 'signal-and-noise-tools' ), $n );
	}
	return 'invalidate' === (string) ( $last['mode'] ?? '' ) ? __( 'full zone, marked stale', 'signal-and-noise-tools' ) : __( 'full zone', 'signal-and-noise-tools' );
}

/**
 * Breeze's page cache as the theme's last purge report read it (theme
 * signal-and-noise#532: legs.breeze_page_cache, true, false or null): 'on',
 * 'off' or 'not read'. On matters: a Breeze hit carries no cache tag, so a
 * tag purge cannot reach the copy the edge stored from it. Breeze is
 * deactivated since 2026-10-13; the breeze_active health check goes red if
 * it comes back.
 *
 * @param mixed $report sn_last_purge_report.
 * @return string
 */
function sn_cf_breeze_page_cache_words( $report ) {
	$v = is_array( $report ) && isset( $report['legs'] ) && is_array( $report['legs'] ) && array_key_exists( 'breeze_page_cache', $report['legs'] ) ? $report['legs']['breeze_page_cache'] : null;
	if ( true === $v ) {
		return __( 'on', 'signal-and-noise-tools' );
	}
	return false === $v ? __( 'off', 'signal-and-noise-tools' ) : __( 'not read', 'signal-and-noise-tools' );
}
