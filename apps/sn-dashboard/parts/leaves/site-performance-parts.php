<?php
/**
 * S&N Dashboard: Site > Performance, the slow admin requests ledger.
 *
 * inc/http-diagnostics.php captures every outbound HTTP call made during a
 * wp-admin page view into the `snt_httpdiag_log` option and paints it on
 * the Site Health Info panel (`sn_httpdiag_debug_information()`), a classic
 * screen the window does not reach. This is that reading's native twin:
 * the same option, the same render-time retention (`sn_httpdiag_visible()`,
 * never written back), the calls flattened across pages and sorted slowest
 * first. The log stores scheme+host+path; the ledger paints the host alone,
 * so no path segment (an id, a slug) reaches the window. The Site Health
 * panel stays: an export reads it. Same capability (manage_options), so no
 * new exposure. Read only; the module has no write to twin.
 *
 * @package SignalNoiseTools
 * @since 17.4.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The log, read and reduced: every call across the visible entries as a
 * row {screen, host, ms, when}, slowest first, capped at the module's
 * per-entry ceiling; plus the counts the notices need.
 *
 * @return array{rows:array,failed:int,failed_host:string,uncaptured:int,hidden:int,entries:int}
 */
function performance_slow_calls() {
	$log     = get_option( 'snt_httpdiag_log' );
	$log     = is_array( $log ) ? $log : array();
	$now     = time();
	$visible = \sn_httpdiag_visible( $log, $now );
	$out     = array( 'rows' => array(), 'failed' => 0, 'failed_host' => '', 'uncaptured' => 0, 'hidden' => count( $log ) - count( $visible ), 'entries' => count( $visible ) );
	$failed_ms = -1;

	foreach ( $visible as $entry ) {
		$calls = is_array( $entry['http'] ?? null ) ? $entry['http'] : array();
		if ( ! $calls && (float) ( $entry['wall_s'] ?? 0 ) > SN_HTTPDIAG_WALL_THRESHOLD_S ) {
			++$out['uncaptured'];
		}
		foreach ( $calls as $call ) {
			$ms   = (int) ( $call['ms'] ?? 0 );
			$host = (string) wp_parse_url( (string) ( $call['url'] ?? '' ), PHP_URL_HOST );
			if ( '' === $host ) {
				$host = __( '(no host)', 'signal-and-noise-tools' );
			}
			if ( ! empty( $call['error'] ) || (int) ( $call['code'] ?? 0 ) >= 400 ) {
				++$out['failed'];
				if ( $ms > $failed_ms ) {
					$failed_ms          = $ms;
					$out['failed_host'] = $host;
				}
			}
			$out['rows'][] = array(
				'screen' => (string) ( $entry['screen'] ?? '' ),
				'host'   => $host,
				'ms'     => $ms,
				'when'   => \sn_httpdiag_format_age( $entry['t'] ?? null, $now ),
			);
		}
	}
	usort(
		$out['rows'],
		static function ( $a, $b ) {
			return $b['ms'] <=> $a['ms'];
		}
	);
	$out['rows'] = array_slice( $out['rows'], 0, SN_HTTPDIAG_HTTP_MAX );
	return $out;
}

/**
 * The section: problems as notices on top (failed calls naming the slowest
 * failed host; page loads over the module's slow line with nothing
 * captured), then the ledger, then the retention caption. Empty when the
 * module is not loaded (says so) or nothing is in the window.
 *
 * @return string
 */
function performance_slow_calls_html() {
	$heading = __( 'Slow admin requests', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_httpdiag_visible' ) ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'The HTTP diagnosis module is not loaded.', 'signal-and-noise-tools' ) ) );
	}
	$d     = performance_slow_calls();
	$empty = __( 'No slow call was captured.', 'signal-and-noise-tools' );
	if ( 0 === $d['entries'] ) {
		return \snt_kit_section( $heading, \snt_kit_empty( $empty ) );
	}
	$top = '';
	if ( $d['failed'] > 0 ) {
		$top .= \snt_kit_notice( 'warn', \snt_kit_esc( sprintf(
			/* translators: 1: number of failed calls, 2: the host of the slowest failed call */
			_n( '%1$d failed call; the slowest was %2$s.', '%1$d failed calls; the slowest was %2$s.', $d['failed'], 'signal-and-noise-tools' ),
			$d['failed'],
			$d['failed_host']
		) ) );
	}
	if ( $d['uncaptured'] > 0 ) {
		$top .= \snt_kit_notice( 'info', \snt_kit_esc( sprintf(
			/* translators: 1: number of page loads, 2: the slow line in seconds */
			_n( '%1$d page load over %2$ss captured no outbound call; the slow part was not an HTTP request this module can see.', '%1$d page loads over %2$ss captured no outbound call; the slow part was not an HTTP request this module can see.', $d['uncaptured'], 'signal-and-noise-tools' ),
			$d['uncaptured'],
			number_format( (float) SN_HTTPDIAG_WALL_THRESHOLD_S, 1 )
		) ) );
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'screen', 'label' => __( 'Screen', 'signal-and-noise-tools' ) ),
			array( 'key' => 'host', 'label' => __( 'Host', 'signal-and-noise-tools' ) ),
			array( 'key' => 'ms', 'label' => __( 'Duration (ms)', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'when', 'label' => __( 'When', 'signal-and-noise-tools' ) ),
		),
		$d['rows'],
		array( 'empty' => $empty )
	);
	$caption = '';
	if ( $d['hidden'] > 0 ) {
		$caption = '<p class="snt-hint">' . \snt_kit_esc( sprintf(
			/* translators: 1: number of entries older than the retention window, 2: the window in days */
			_n( '%1$d older entry hidden (older than %2$d days).', '%1$d older entries hidden (older than %2$d days).', $d['hidden'], 'signal-and-noise-tools' ),
			$d['hidden'],
			(int) ( SN_HTTPDIAG_RETENTION_S / 86400 )
		) ) . '</p>';
	}
	return \snt_kit_section(
		$heading,
		$top . $table . $caption,
		__( 'Outbound HTTP calls captured during wp-admin page loads, slowest first; the Site Health Info panel reads the same log.', 'signal-and-noise-tools' )
	);
}
