<?php
/**
 * S&N Dashboard: Site > Performance, the outbound calls from admin pages.
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
 * row {screen, host, ms, status, when}, failed first, then slowest, capped at the module's
 * per-entry ceiling; plus the counts the notices need.
 *
 * @return array{rows:array,total:int,failed:int,failed_host:string,uncaptured:int,hidden:int,entries:int} Rows failed first, then slowest first.
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
			$code   = (int) ( $call['code'] ?? 0 );
			$failed = ! empty( $call['error'] ) || $code >= 400;
			if ( $failed ) {
				++$out['failed'];
				if ( $ms > $failed_ms ) {
					$failed_ms          = $ms;
					$out['failed_host'] = $host;
				}
			}
			$out['rows'][] = array(
				'screen' => performance_screen_name( (string) ( $entry['screen'] ?? '' ) ),
				'host'   => $host,
				'ms'     => $ms,
				// 2026.10.4 audit: the failed calls the notice counts are marked
				// in their own rows, in words and tone, not by the count alone.
				'status' => array(
					'html' => $failed ? \snt_kit_badge( 'err', $code >= 400 ? (string) $code : __( 'failed', 'signal-and-noise-tools' ) ) : \snt_kit_esc( $code > 0 ? (string) $code : __( 'no code', 'signal-and-noise-tools' ) ),
					'text' => $failed ? ( $code >= 400 ? (string) $code : __( 'failed', 'signal-and-noise-tools' ) ) : (string) $code,
				),
				'when'   => \sn_httpdiag_format_age( $entry['t'] ?? null, $now ),
				'_fail'  => $failed,
			);
		}
	}
	// Failed calls first, then slowest first: the cap never hides a failure
	// the warning counts (review on #2020).
	usort(
		$out['rows'],
		static function ( $a, $b ) {
			return array( $b['_fail'], $b['ms'] ) <=> array( $a['_fail'], $a['ms'] );
		}
	);
	$out['total'] = count( $out['rows'] );
	$out['rows']  = array_map(
		static function ( $row ) {
			unset( $row['_fail'] );
			return $row;
		},
		array_slice( $out['rows'], 0, SN_HTTPDIAG_HTTP_MAX )
	);
	return $out;
}

/**
 * The screen a call was made from, by its name in the admin rather than its
 * query string (2026.10.4 audit: the column read "admin.php?page=...&tab=...").
 * Our own leaves are named from the tab registry; core screens by their menu
 * name; anything else stays as logged.
 *
 * @param string $screen The logged screen (file plus page, tab, sub, sn_view).
 * @return string
 */
function performance_screen_name( $screen ) {
	$q    = array();
	$file = (string) strtok( $screen, '?' );
	parse_str( (string) wp_parse_url( $screen, PHP_URL_QUERY ), $q );
	$page = (string) ( $q['page'] ?? '' );
	if ( 'sn-analytics' === $page ) {
		return __( 'S&N Analytics', 'signal-and-noise-tools' );
	}
	if ( 'sn-theme-options' === $page ) {
		$names = array( __( 'Signal & Noise', 'signal-and-noise-tools' ) );
		foreach ( function_exists( 'sn_admin_top_tabs' ) ? (array) \sn_admin_top_tabs() : array() as $top ) {
			if ( (string) ( $q['tab'] ?? 'dashboard' ) === (string) ( $top['tab'] ?? '' ) ) {
				$names[] = (string) ( $top['label'] ?? '' );
				$names[] = (string) ( $top['sub_tabs'][ (string) ( $q['sub'] ?? '' ) ]['label'] ?? '' );
			}
		}
		return implode( ' › ', array_filter( $names, 'strlen' ) );
	}
	$core = array(
		'index.php'       => __( 'Dashboard', 'signal-and-noise-tools' ),
		'edit.php'        => __( 'Posts', 'signal-and-noise-tools' ),
		'post.php'        => __( 'Post editor', 'signal-and-noise-tools' ),
		'post-new.php'    => __( 'New post', 'signal-and-noise-tools' ),
		'upload.php'      => __( 'Media', 'signal-and-noise-tools' ),
		'plugins.php'     => __( 'Plugins', 'signal-and-noise-tools' ),
		'update-core.php' => __( 'Updates', 'signal-and-noise-tools' ),
		'site-health.php' => __( 'Site Health', 'signal-and-noise-tools' ),
	);
	return '' === $page && isset( $core[ $file ] ) ? $core[ $file ] : $screen;
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
	// 2026.10.4 audit: every outbound call is logged, not only slow ones.
	$heading = __( 'Outbound calls from admin pages', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_httpdiag_visible' ) ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'The HTTP diagnosis module is not loaded.', 'signal-and-noise-tools' ) ) );
	}
	$d     = performance_slow_calls();
	$empty = __( 'No outbound call was captured.', 'signal-and-noise-tools' );
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
			array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ),
			array( 'key' => 'when', 'label' => __( 'When', 'signal-and-noise-tools' ) ),
		),
		$d['rows'],
		array( 'empty' => $empty )
	);
	$hidden  = $d['total'] - count( $d['rows'] );
	/* translators: %s: calls not listed. */
	$caption = \snt_kit_more( $hidden, _n( '+%s quicker call is not listed; the list is capped, not complete.', '+%s quicker calls are not listed; the list is capped, not complete.', max( 1, $hidden ), 'signal-and-noise-tools' ) );
	if ( $d['hidden'] > 0 ) {
		$caption .= '<p class="snt-hint">' . \snt_kit_esc( sprintf(
			/* translators: 1: number of entries older than the retention window, 2: the window in days */
			_n( '%1$d older entry hidden (older than %2$d days).', '%1$d older entries hidden (older than %2$d days).', $d['hidden'], 'signal-and-noise-tools' ),
			$d['hidden'],
			(int) ( SN_HTTPDIAG_RETENTION_S / 86400 )
		) ) . '</p>';
	}
	return \snt_kit_section(
		$heading,
		$top . $table . $caption,
		__( 'Every outbound HTTP call made while a wp-admin page loads, failed calls first, then the slowest; the Site Health Info panel reads the same log.', 'signal-and-noise-tools' )
	);
}
