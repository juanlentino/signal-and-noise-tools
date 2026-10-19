<?php
/**
 * Signal & Noise Tools: the Search Console sitemaps report, read weekly.
 *
 * URL Inspection says, per URL, whether Google indexed it. It cannot say
 * whether Google is reading the sitemap that lists those URLs, and a sitemap
 * Google stopped downloading (or reads with errors) explains a "Discovered -
 * currently not indexed" run better than any single URL can. One GET to
 * webmasters/v3 sites.sitemaps.list, made at the end of the weekly coverage
 * run, under the webmasters.readonly scope the credential already holds.
 *
 * Kept: lastSubmitted, lastDownloaded, isPending, isSitemapsIndex, errors,
 * warnings and contents[].submitted. contents[].indexed is deprecated by
 * Google and never stored.
 *
 * @package SignalNoiseTools
 */

defined( 'ABSPATH' ) || exit;

const SNT_GSC_SITEMAPS_OPTION = 'snt_gsc_sitemaps';

/**
 * Normalize the sites.sitemaps.list body. PURE. The API sends int64 counts as
 * strings; they become integers here.
 *
 * @param array $body Decoded response.
 * @return array<int,array<string,mixed>>
 */
function snt_gsc_sitemaps_normalize( $body ) {
	$out = array();
	foreach ( (array) ( is_array( $body ) ? ( $body['sitemap'] ?? array() ) : array() ) as $s ) {
		if ( ! is_array( $s ) || '' === (string) ( $s['path'] ?? '' ) ) {
			continue;
		}
		$submitted = 0;
		foreach ( (array) ( $s['contents'] ?? array() ) as $c ) {
			$submitted += is_array( $c ) ? (int) ( $c['submitted'] ?? 0 ) : 0;
		}
		$out[] = array(
			'path'            => (string) $s['path'],
			'last_submitted'  => (string) ( $s['lastSubmitted'] ?? '' ),
			'last_downloaded' => (string) ( $s['lastDownloaded'] ?? '' ),
			'is_pending'      => ! empty( $s['isPending'] ),
			'is_index'        => ! empty( $s['isSitemapsIndex'] ),
			'errors'          => (int) ( $s['errors'] ?? 0 ),
			'warnings'        => (int) ( $s['warnings'] ?? 0 ),
			'submitted'       => $submitted,
		);
	}
	return $out;
}

/**
 * Read the report and store it. A failed read keeps the last good record with
 * the error beside it: a refused call must not read as "no sitemaps".
 *
 * @param string $property The Search Console property.
 * @return array{ok:bool,error:string}
 */
function snt_gsc_sitemaps_sync( $property ) {
	if ( ! function_exists( 'snt_gsc_api_get' ) || '' === (string) $property ) {
		return array( 'ok' => false, 'error' => 'not-ready' );
	}
	$body = snt_gsc_api_get( '/sites/' . rawurlencode( (string) $property ) . '/sitemaps' );
	$prev = snt_gsc_sitemaps_data();
	if ( is_wp_error( $body ) ) {
		$error = gmdate( 'Y-m-d' ) . ' ' . $body->get_error_message();
		update_option( SNT_GSC_SITEMAPS_OPTION, array_merge( is_array( $prev ) ? $prev : array( 'synced_at' => 0, 'sitemaps' => array() ), array( 'last_error' => $error ) ), false );
		return array( 'ok' => false, 'error' => $error );
	}
	update_option( SNT_GSC_SITEMAPS_OPTION, array( 'synced_at' => time(), 'sitemaps' => snt_gsc_sitemaps_normalize( $body ), 'last_error' => '' ), false );
	return array( 'ok' => true, 'error' => '' );
}

/** The stored record, or null before the first read. */
function snt_gsc_sitemaps_data() {
	$d = function_exists( 'get_option' ) ? get_option( SNT_GSC_SITEMAPS_OPTION, null ) : null;
	return is_array( $d ) && isset( $d['sitemaps'] ) ? $d : null;
}

/** "1 error", "2 warnings". */
function snt_gsc_sitemaps_count( $n, $one, $many ) {
	return number_format_i18n( (int) $n ) . ' ' . ( 1 === (int) $n ? $one : $many );
}

/**
 * What a reader sees, in plain words, for both twins. PURE.
 *
 * @param array|null $d snt_gsc_sitemaps_data().
 * @return array{note:string,lines:array<int,string>}
 */
function snt_gsc_sitemaps_view( $d ) {
	if ( ! is_array( $d ) ) {
		return array( 'note' => __( 'The sitemaps report is read with the next weekly coverage run.', 'signal-and-noise-tools' ), 'lines' => array() );
	}
	$lines = array();
	foreach ( (array) $d['sitemaps'] as $s ) {
		$path   = (string) ( parse_url( (string) $s['path'], PHP_URL_PATH ) ?: $s['path'] );
		$counts = sprintf(
			/* translators: 1: URL count, 2: errors, 3: warnings. */
			__( '%1$s URLs listed; %2$s, %3$s.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $s['submitted'] ),
			snt_gsc_sitemaps_count( $s['errors'], __( 'error', 'signal-and-noise-tools' ), __( 'errors', 'signal-and-noise-tools' ) ),
			snt_gsc_sitemaps_count( $s['warnings'], __( 'warning', 'signal-and-noise-tools' ), __( 'warnings', 'signal-and-noise-tools' ) )
		);
		if ( '' === (string) $s['last_downloaded'] ) {
			/* translators: 1: sitemap path, 2: counts. */
			$lines[] = sprintf( __( '%1$s: submitted, but Google has not read it yet. %2$s', 'signal-and-noise-tools' ), $path, $counts );
			continue;
		}
		/* translators: 1: sitemap path, 2: date, 3: counts. */
		$line = sprintf( __( '%1$s: Google last read it on %2$s. %3$s', 'signal-and-noise-tools' ), $path, substr( (string) $s['last_downloaded'], 0, 10 ), $counts );
		if ( ! empty( $s['is_pending'] ) ) {
			$line .= ' ' . __( 'A newer submission is waiting to be read.', 'signal-and-noise-tools' );
		}
		$lines[] = $line;
	}
	$note = array() === $lines
		? __( 'Google lists no sitemap for this property. Submit one in Search Console\'s Sitemaps report.', 'signal-and-noise-tools' )
		: __( 'Sitemaps, as Google last reported them:', 'signal-and-noise-tools' );
	if ( '' !== (string) ( $d['last_error'] ?? '' ) ) {
		/* translators: %s: the dated error. */
		$note = sprintf( __( 'The last read failed (%s); this is the record before it.', 'signal-and-noise-tools' ), (string) $d['last_error'] ) . ' ' . ( array() === $lines ? '' : $note );
	}
	return array( 'note' => trim( $note ), 'lines' => $lines );
}

/** The classic twin: echoed inside the Index coverage panel. */
function snt_gsc_sitemaps_render_classic() {
	$v = snt_gsc_sitemaps_view( snt_gsc_sitemaps_data() );
	echo '<h3>' . esc_html__( 'Sitemaps', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p>' . esc_html( $v['note'] ) . '</p>';
	if ( array() !== $v['lines'] ) {
		echo '<ul>';
		foreach ( $v['lines'] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}
}
