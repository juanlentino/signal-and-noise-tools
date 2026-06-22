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
 * AE SQL for the top blocked countries over the last N days.
 */
function sn_login_defense_top_country_sql( $days = 30, $limit = 10 ) {
	$d = (int) $days;
	$l = (int) $limit;
	return 'SELECT blob3 AS country, sum(_sample_interval) AS hits '
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE blob2 = 'block' AND timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY blob3 ORDER BY hits DESC LIMIT ' . $l;
}

/**
 * AE SQL for the daily blocked-vs-passed trend. Conditional sum(if(...)) is the
 * proven AE pattern (see inc/analytics-buckets.php); the day bucket mirrors the
 * pageview trend's formatDateTime(toStartOfDay(...)).
 */
function sn_login_defense_trend_sql( $days = 7 ) {
	$d = (int) $days;
	return "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
		. "sum(if(blob2 = 'block', _sample_interval, 0)) AS blocked, "
		. "sum(if(blob2 = 'pass', _sample_interval, 0)) AS passed "
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE timestamp > now() - INTERVAL '" . $d . "' DAY "
		. 'GROUP BY day ORDER BY day';
}

/**
 * AE SQL for the count of DISTINCT attacking networks (ASNs) over N days.
 * Stable across any range (ASN does not rotate), unlike a hashed-IP count which
 * would over-count across days. count(DISTINCT <bare column>) is valid AE dialect.
 */
function sn_login_defense_networks_sql( $days = 30 ) {
	$d = (int) $days;
	return 'SELECT count(DISTINCT blob4) AS networks '
		. 'FROM ' . SN_LG_DATASET . ' '
		. "WHERE blob2 = 'block' AND timestamp > now() - INTERVAL '" . $d . "' DAY";
}

/**
 * Reduce decisions rows ([{decision,hits}]) to checked/blocked/block_rate + the
 * raw per-decision breakdown. Guards divide-by-zero on the block rate.
 */
function sn_login_defense_kpis_from_rows( $rows ) {
	$by = array();
	foreach ( (array) $rows as $r ) {
		$by[ (string) ( $r['decision'] ?? '' ) ] = (int) ( $r['hits'] ?? 0 );
	}
	$blocked = $by['block'] ?? 0;
	$checked = $blocked + ( $by['pass'] ?? 0 );
	$rate    = $checked > 0 ? (int) round( $blocked / $checked * 100 ) : 0;
	return array( 'checked' => $checked, 'blocked' => $blocked, 'block_rate' => $rate, 'breakdown' => $by );
}

/**
 * Map the trend AE rows ([{day,blocked,passed}], already ascending) to the
 * sparkline series shape ([{day,views}]) where views = the blocked count.
 */
function sn_login_defense_trend_series( $rows ) {
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array( 'day' => (string) ( $r['day'] ?? '' ), 'views' => (int) ( $r['blocked'] ?? 0 ) );
	}
	return $out;
}

/**
 * Cached at-a-glance headline (checked/blocked/block_rate + top network) shared
 * by the dashboard widget and the Monitoring view. Short transient so opening
 * both surfaces does not double-hit Analytics Engine.
 */
function sn_login_defense_headline() {
	$cached = get_transient( 'sn_lg_headline' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return array( 'configured' => false, 'checked' => 0, 'blocked' => 0, 'block_rate' => 0, 'top_network' => '' );
	}
	$kpis = sn_login_defense_kpis_from_rows( sn_analytics_query( sn_login_defense_decisions_sql( 7 ) ) ?: array() );
	$asn  = sn_analytics_query( sn_login_defense_top_asn_sql( 7, 1 ) ) ?: array();
	$out  = array(
		'configured'  => true,
		'checked'     => $kpis['checked'],
		'blocked'     => $kpis['blocked'],
		'block_rate'  => $kpis['block_rate'],
		'top_network' => (string) ( $asn[0]['asorg'] ?? '' ),
	);
	set_transient( 'sn_lg_headline', $out, 600 );
	return $out;
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
 * Render the worker status block from a probed status array: deployed worker
 * version + deploy time (parity with the analytics worker-version card), then
 * denylist size + last refresh. Extracted from the panel for testability.
 */
function sn_login_defense_render_status( $status ) {
	if ( ! is_array( $status ) ) {
		echo '<p>' . esc_html__( 'Login guard status unavailable (the Worker is not reachable or not deployed yet).', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	$ver = (string) ( $status['version'] ?? '' );
	if ( '' !== $ver ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: worker version, 2: deploy timestamp */
				__( 'Worker: sn-login-guard v%1$s (deployed %2$s).', 'signal-and-noise-tools' ),
				$ver,
				(string) ( $status['deployed_at'] ?? '?' )
			)
		) . '</p>';
	}
	echo '<p>' . esc_html(
		sprintf(
			/* translators: 1: number of denylist ranges, 2: ISO timestamp */
			__( 'Denylist: %1$s ranges, updated %2$s.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) ( $status['denylistCount'] ?? 0 ) ),
			(string) ( $status['compiledAt'] ?? '?' )
		)
	) . '</p>';
}

/**
 * Render the read-only Security > Login defense STATUS panel: worker version +
 * denylist size + last refresh + attribution + a link to the Monitoring analytics
 * view. The attack analytics live in the dashboard widget + the Monitoring view,
 * so this panel does not duplicate a KPI strip. Native wp-admin markup; escaped.
 */
function sn_login_defense_render() {
	echo '<div class="sn-status-box">';
	sn_login_defense_render_status( sn_login_defense_status() );
	echo '<p class="description">' . esc_html( sn_login_defense_attribution() ) . '</p>';
	echo '<p><a href="' . esc_url( admin_url( 'index.php?page=sn-analytics&sn_view=login-defense' ) ) . '">'
		. esc_html__( 'View login defense analytics', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
	echo '</div>';
}
