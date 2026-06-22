<?php
/**
 * Login defense panel (read-only).
 *
 * Surfaces the sn-login-guard edge Worker: reads its decision log from the
 * sn_login_guard Analytics Engine dataset (reusing sn_analytics_query/config)
 * and probes its /_sn/login-guard/status endpoint (SSRF-guarded) for denylist
 * meta. No enforcement here; the Worker owns that at the edge.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LG_DATASET = 'sn_login_guard';

/**
 * AE SQL for login decisions over the last N days, de-sampled.
 * Totals use sum(_sample_interval) (the AE count() dialect rule), not count(*).
 */
function sn_login_defense_decisions_sql( $days = 7 ) {
	$d = (int) $days;
	return 'SELECT blob2 AS decision, sum(_sample_interval) AS hits '
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY blob2 ORDER BY hits DESC';
}

/**
 * AE SQL for the top blocked ASNs over the last N days.
 */
function sn_login_defense_top_asn_sql( $days = 30, $limit = 10 ) {
	$d = (int) $days;
	$l = (int) $limit;
	return 'SELECT blob4 AS asorg, sum(_sample_interval) AS hits '
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE blob2 = 'block' AND timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY blob4 ORDER BY hits DESC LIMIT ' . $l;
}

/**
 * Derive the Worker status URL from the configured collector origin (hairpin-safe),
 * mirroring sn_worker_version_endpoint_url().
 */
function sn_login_defense_status_url() {
	$base = function_exists( 'sn_worker_version_collector_base' )
		? sn_worker_version_collector_base()
		: home_url( '/_sn/px' );
	$p = wp_parse_url( $base );
	if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
		return '';
	}
	$origin = $p['scheme'] . '://' . $p['host'] . ( empty( $p['port'] ) ? '' : ':' . (int) $p['port'] );
	return $origin . '/_sn/login-guard/status';
}

/**
 * Fetch the Worker status (denylist size + last refresh + sources). SSRF-guarded,
 * fail-soft: returns null on any problem so the panel degrades gracefully.
 */
function sn_login_defense_status() {
	$url  = sn_login_defense_status_url();
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( '' === $url
		|| ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) ) ) {
		return null;
	}
	$res = wp_safe_remote_get( $url, array( 'timeout' => 6, 'redirection' => 0 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return is_array( $data ) ? $data : null;
}

/**
 * Attribution required by the FireHOL/Spamhaus license terms (DROP flows through
 * the FireHOL level1 composite).
 */
function sn_login_defense_attribution() {
	return 'Denylist sourced from FireHOL Blocklist-IPSets (level1), which includes '
		. 'The Spamhaus Project DROP list. See the Spamhaus DROP Fair Use Policy.';
}

/**
 * Render the read-only panel. Wired as the Security > Login defense sub-tab render
 * callback in inc/admin-tabs-data.php. Native wp-admin markup; all output escaped.
 */
function sn_login_defense_render() {
	$cfg = function_exists( 'sn_analytics_config' ) ? sn_analytics_config() : null;

	echo '<div class="sn-status-box">';

	if ( ! $cfg ) {
		echo '<p class="notice notice-warning">'
			. esc_html__( 'Connect Cloudflare Analytics (Account ID + token) in the Analytics tab to see login-defense data.', 'signal-and-noise-tools' )
			. '</p>';
	} else {
		$rows = function_exists( 'sn_analytics_query' )
			? sn_analytics_query( sn_login_defense_decisions_sql( 7 ) )
			: null;
		echo '<h3>' . esc_html__( 'Login decisions (7 days)', 'signal-and-noise-tools' ) . '</h3>';
		if ( is_array( $rows ) && $rows ) {
			echo '<ul>';
			foreach ( $rows as $r ) {
				echo '<li><span class="sn-pill">' . esc_html( (string) ( $r['decision'] ?? '' ) ) . '</span> '
					. esc_html( number_format_i18n( (float) ( $r['hits'] ?? 0 ) ) ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No decisions recorded yet.', 'signal-and-noise-tools' ) . '</p>';
		}
	}

	$status = sn_login_defense_status();
	if ( $status ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of denylist ranges, 2: ISO timestamp */
				__( 'Denylist: %1$s ranges, updated %2$s.', 'signal-and-noise-tools' ),
				number_format_i18n( (int) ( $status['denylistCount'] ?? 0 ) ),
				(string) ( $status['compiledAt'] ?? '?' )
			)
		) . '</p>';
	}

	echo '<p class="description">' . esc_html( sn_login_defense_attribution() ) . '</p>';
	echo '</div>';
}
