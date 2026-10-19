<?php
/**
 * Signal & Noise Tools — the Cloudflare monitor's worker reading: the last
 * day of every Worker on the account from GraphQL workersInvocationsAdaptive
 * (requests, errors, subrequests, CPU p50/p99, by status), read with the
 * zone and firewall readings and stored in the same record.
 *
 * Needs Account › Account Analytics › Read on the token. A refused read says
 * needs_permission and names that scope; it is never zero.
 *
 * SHOWN, NOT JUDGED. A Worker's CPU limit (wrangler's limits.cpu_ms) lives in
 * its own repo and nowhere here, so there is no rule to hold a p99 against,
 * and no error share has been settled as unusual. Add a flag when one is.
 *
 * Source: developers.cloudflare.com/analytics/graphql-api/tutorials/querying-workers-metrics/
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_WORKERS_SCOPE = 'Account › Account Analytics › Read';

/**
 * The worker reading: pure over the GraphQL body. Rows arrive one per script
 * and status; counts add across them, quantiles do not, so a script's CPU
 * p50/p99 are the ones of its status with the most requests. cpuTime is in
 * microseconds; stored as milliseconds.
 *
 * @param array{http:int,body:array<string,mixed>,error:string} $res
 * @return array<string,mixed>
 */
function sn_cf_monitor_workers_from( array $res ) {
	$out = array( 'available' => false, 'needs_permission' => false, 'scope' => SN_CF_WORKERS_SCOPE, 'error' => '', 'scripts' => array() );
	if ( 0 === (int) $res['http'] ) {
		return array( 'error' => (string) $res['error'] ) + $out;
	}
	if ( sn_cf_graphql_needs_permission( $res['body'] ) ) {
		return array( 'needs_permission' => true, 'error' => (string) ( $res['body']['errors'][0]['message'] ?? '' ) ) + $out;
	}
	$rows = $res['body']['data']['viewer']['accounts'][0]['workersInvocationsAdaptive'] ?? null;
	if ( ! is_array( $rows ) ) {
		return array( 'error' => (string) ( $res['body']['errors'][0]['message'] ?? ( 'HTTP ' . (int) $res['http'] ) ) ) + $out;
	}
	$by = array();
	foreach ( $rows as $r ) {
		$name = (string) ( $r['dimensions']['scriptName'] ?? '' );
		$req  = (int) ( $r['sum']['requests'] ?? 0 );
		$s    = $by[ $name ] ?? array( 'script' => $name, 'requests' => 0, 'errors' => 0, 'subrequests' => 0, 'status' => array(), 'top' => -1 );
		$s['requests']    += $req;
		$s['errors']      += (int) ( $r['sum']['errors'] ?? 0 );
		$s['subrequests'] += (int) ( $r['sum']['subrequests'] ?? 0 );
		$status            = (string) ( $r['dimensions']['status'] ?? '' );
		$s['status'][ $status ] = ( $s['status'][ $status ] ?? 0 ) + $req;
		if ( $req > $s['top'] ) {
			$s['top']        = $req;
			$s['cpu_p50_ms'] = round( (float) ( $r['quantiles']['cpuTimeP50'] ?? 0 ) / 1000, 2 );
			$s['cpu_p99_ms'] = round( (float) ( $r['quantiles']['cpuTimeP99'] ?? 0 ) / 1000, 2 );
		}
		$by[ $name ] = $s;
	}
	$scripts = array();
	foreach ( $by as $s ) {
		unset( $s['top'] );
		arsort( $s['status'] );
		$scripts[] = array( 'error_share' => $s['requests'] > 0 ? round( 100 * $s['errors'] / $s['requests'], 1 ) : null ) + $s;
	}
	usort( $scripts, static fn( $a, $b ) => $b['requests'] <=> $a['requests'] );
	return array( 'available' => true, 'scripts' => $scripts ) + $out;
}

/**
 * Read the last day, both bounds explicit (sn_cf_firewall_window()'s lesson:
 * an adaptive dataset closes an open window at Cloudflare's clock).
 *
 * @param int|null $now Unix seconds; null = time().
 * @return array<string,mixed>
 */
function sn_cf_monitor_workers_read( $now = null ) {
	$account = function_exists( 'sn_cf_get_account_id' ) ? (string) sn_cf_get_account_id() : '';
	if ( '' === $account ) {
		return array( 'available' => false, 'needs_permission' => false, 'scope' => SN_CF_WORKERS_SCOPE, 'error' => 'No Cloudflare account ID is set, so the account\'s Workers cannot be read.', 'scripts' => array() );
	}
	$w = sn_cf_firewall_window( $now );
	$q = 'query ($account: String!, $since: Time!, $until: Time!) { viewer { accounts(filter: {accountTag: $account}) { workersInvocationsAdaptive(limit: 1000, filter: {datetime_geq: $since, datetime_leq: $until}) { sum { requests errors subrequests } quantiles { cpuTimeP50 cpuTimeP99 } dimensions { scriptName status } } } } }';
	return sn_cf_monitor_workers_from( sn_cf_graphql( $q, array( 'account' => $account, 'since' => $w['since'], 'until' => $w['until'] ) ) );
}

/**
 * The Edge card's "Workers, last day" table. Prints nothing for a record
 * stored before this reading existed.
 *
 * @param array<string,mixed> $record The monitor record.
 * @return void
 */
function sn_cf_workers_card_render( array $record ) {
	$w = $record['workers'] ?? null;
	if ( ! is_array( $w ) ) {
		return;
	}
	echo '<h3>' . esc_html__( 'Workers, last day', 'signal-and-noise-tools' ) . '</h3>';
	if ( empty( $w['available'] ) ) {
		$why = ! empty( $w['needs_permission'] )
			/* translators: %s: the Cloudflare permission. */
			? sprintf( __( 'Worker analytics needs %s on the token; not read, which is not zero.', 'signal-and-noise-tools' ), (string) $w['scope'] )
			: __( 'Worker analytics could not be read: ', 'signal-and-noise-tools' ) . (string) ( $w['error'] ?? '' );
		wp_admin_notice( esc_html( $why ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
		return;
	}
	echo '<table class="widefat" style="width:auto"><thead><tr>';
	foreach ( array( __( 'Worker', 'signal-and-noise-tools' ), __( 'Requests', 'signal-and-noise-tools' ), __( 'Errors', 'signal-and-noise-tools' ), __( 'Subrequests', 'signal-and-noise-tools' ), __( 'CPU p50', 'signal-and-noise-tools' ), __( 'CPU p99', 'signal-and-noise-tools' ) ) as $h ) {
		echo '<th scope="col">' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( (array) $w['scripts'] as $s ) {
		echo '<tr><th scope="row">' . esc_html( $s['script'] ) . '</th><td>' . esc_html( number_format_i18n( (int) $s['requests'] ) ) . '</td><td>' . esc_html( null === $s['error_share'] ? '-' : number_format_i18n( (float) $s['error_share'], 1 ) . '%' ) . '</td><td>' . esc_html( number_format_i18n( (int) $s['subrequests'] ) ) . '</td><td>' . esc_html( $s['cpu_p50_ms'] . ' ms' ) . '</td><td>' . esc_html( $s['cpu_p99_ms'] . ' ms' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}
