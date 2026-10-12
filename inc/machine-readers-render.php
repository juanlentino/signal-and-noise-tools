<?php
/**
 * Signal & Noise Tools, Machine Readers: pure renderers.
 *
 * Session 3 lane 2. Pure string-returning renderers over normalized rows
 * (canned-rows testable, native wp-admin table markup: the widefat/striped
 * idiom from the analytics tables). esc_html at every cell, including day
 * strings, even though enums are allowlisted upstream (defense in depth).
 * Input rows are never mutated; every aggregate is a fresh map. Paired
 * fixture: tests/machine-readers-render.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Families whose public declarations class them as AI-training crawlers,
 * the static half of the observed-vs-declared compliance read. Observation
 * only: the render never claims proven identity (UAs are self-reported).
 *
 * @return string[]
 */
function snt_mr_ai_training_families() {
	return array( 'openai', 'anthropic', 'google-ai', 'commoncrawl', 'bytedance', 'apple-ai', 'meta-ai', 'mistral', 'cohere', 'allen-ai' );
}

/**
 * Sum hits per value of one row field ('family' or 'surface'), highest first.
 * Pure: builds a fresh map, never mutates $rows.
 *
 * @param array  $rows  Normalized rows.
 * @param string $field Row key to group by.
 * @return array Value => total hits, descending.
 */
function snt_mr_sum_hits_by( $rows, $field ) {
	$totals = array();
	foreach ( (array) $rows as $r ) {
		$key = (string) ( $r[ $field ] ?? '' );
		if ( '' === $key ) {
			continue;
		}
		$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) ( $r['hits'] ?? 0 );
	}
	arsort( $totals );
	return $totals;
}

/**
 * Sensor pipeline pills (v10.1.1) — the Analytics "Pipeline status" idiom,
 * copied rather than reinvented: one pill per stage of the chain that has to
 * work for this tab to mean anything, each [ state, label, warn-note ] exactly
 * like snt_analytics_render_pipeline()'s $pills. Pure.
 *
 * Stages: the deployed sensor, the credential this side holds, the read
 * itself, and the weekly crawler-list check.
 *
 * @param array|null $info   snt_mr_sensor_info() shape, or null.
 * @param array|null $status snt_mr_crawler_list_status() shape, or null.
 * @param array      $result snt_mr_fetch() result.
 * @return array<int,array{0:string,1:string,2:string}>
 */
function snt_mr_sensor_pills( $info, $status, $result ) {
	$min     = defined( 'SN_MR_SENSOR_MIN' ) ? (string) SN_MR_SENSOR_MIN : '1.4.0';
	$result  = (array) $result;
	$version = ( is_array( $info ) && isset( $info['version'] ) ) ? (string) $info['version'] : '';
	$pills   = array();

	// 1. The deployed edge sensor.
	if ( null === $info ) {
		$pills[] = array( 'unknown', __( 'Sensor unreachable', 'signal-and-noise-tools' ), '' );
	} elseif ( is_array( $info ) && true === ( $info['reachable'] ?? false ) && '' === $version ) {
		$pills[] = array(
			'warn',
			__( 'Sensor reachable, version unreported', 'signal-and-noise-tools' ),
			__( 'The deploy does not set SN_VERSION, so these panels cannot check the minimum version.', 'signal-and-noise-tools' ),
		);
	} elseif ( version_compare( $version, $min, '<' ) ) {
		$pills[] = array(
			'warn',
			/* translators: %s: deployed worker version. */
			sprintf( __( 'Sensor v%s outdated', 'signal-and-noise-tools' ), $version ),
			/* translators: %s: required minimum version. */
			sprintf( __( 'These panels need v%s or newer. Deploy the sensor release.', 'signal-and-noise-tools' ), $min ),
		);
	} else {
		/* translators: %s: deployed worker version. */
		$pills[] = array( 'ok', sprintf( __( 'Sensor v%s', 'signal-and-noise-tools' ), $version ), '' );
	}

	// 2. The read credential this side holds (presence only, never the value).
	$has_token = ( defined( 'SN_MR_READ_TOKEN' ) && '' !== (string) SN_MR_READ_TOKEN )
		|| ( function_exists( 'sn_setting' ) && '' !== (string) sn_setting( 'machine_readers.read_token', '' ) );
	$pills[] = $has_token
		? array( 'ok', __( 'Read token set', 'signal-and-noise-tools' ), '' )
		: array( 'warn', __( 'Read token missing', 'signal-and-noise-tools' ), __( 'Set the read token under Connections › Credentials, or define SN_MR_READ_TOKEN in wp-config.php.', 'signal-and-noise-tools' ) );

	// 3. The read itself.
	if ( ! empty( $result['ok'] ) ) {
		$pills[] = array( 'ok', __( 'Aggregates current', 'signal-and-noise-tools' ), '' );
	} elseif ( 'not_configured' === (string) ( $result['error'] ?? '' ) ) {
		$pills[] = array( 'warn', __( 'Not configured', 'signal-and-noise-tools' ), __( 'The sensor cannot be read until the read token is set under Connections › Credentials.', 'signal-and-noise-tools' ) );
	} else {
		// 15.1.1: the code's meaning, from the one map every tile reads.
		$hint    = function_exists( 'snt_mr_error_hint' ) ? snt_mr_error_hint( (string) ( $result['error'] ?? 'unknown' ) ) : array( 'title' => __( 'Read failed', 'signal-and-noise-tools' ), 'detail' => '' );
		$pills[] = array(
			'warn',
			$hint['title'],
			/* translators: 1: what the code means, 2: machine-readable error code. */
			sprintf( __( '%1$s The last read returned %2$s.', 'signal-and-noise-tools' ), $hint['detail'], (string) ( $result['error'] ?? 'unknown' ) ),
		);
	}

	// 4. The weekly crawler-list drift check.
	if ( ! is_array( $status ) || ! isset( $status['last_check_ok'] ) ) {
		$pills[] = array( 'unknown', __( 'Crawler list unchecked', 'signal-and-noise-tools' ), '' );
	} else {
		$c_ok    = '' !== (string) $status['last_check_ok'];
		$c_drift = '' !== (string) ( $status['last_check_drift'] ?? '' );
		if ( $c_ok && ! $c_drift ) {
			$pills[] = array( 'ok', __( 'Crawler list in sync', 'signal-and-noise-tools' ), '' );
		} elseif ( $c_ok ) {
			$pills[] = array( 'warn', __( 'Crawler list drift', 'signal-and-noise-tools' ), __( 'Cloudflare\'s published crawler list changed; the edge Worker\'s blocked-crawler list needs a review.', 'signal-and-noise-tools' ) );
		} else {
			$pills[] = array( 'warn', __( 'Crawler check failed', 'signal-and-noise-tools' ), __( 'The last weekly diff could not complete; it retries on its cron.', 'signal-and-noise-tools' ) );
		}
	}

	return $pills;
}

/**
 * Render the pills exactly as snt_analytics_render_pipeline() does: the pill
 * row, then a warn line per warning pill beneath it. Pure.
 *
 * @param array $pills snt_mr_sensor_pills() output.
 * @return string HTML.
 */
function snt_mr_render_sensor_status( $pills ) {
	$marks = array(
		'ok'      => '✓',
		'warn'    => '!',
		'unknown' => '?',
	);
	$out = '<div class="sn-an-pipeline-pills">';
	foreach ( (array) $pills as $p ) {
		$state = isset( $marks[ $p[0] ] ) ? $p[0] : 'unknown';
		$out  .= '<span class="sn-an-pill sn-an-pill--' . esc_attr( $state ) . '">'
			. '<span class="sn-an-pill-mark">' . esc_html( $marks[ $state ] ) . '</span> '
			. esc_html( (string) $p[1] ) . '</span>';
	}
	$out .= '</div>';
	foreach ( (array) $pills as $p ) {
		if ( 'warn' === $p[0] && '' !== (string) ( $p[2] ?? '' ) ) {
			$out .= '<p class="sn-an-pipeline-warn">' . esc_html( (string) $p[1] ) . ': ' . esc_html( (string) $p[2] ) . '</p>';
		}
	}
	return $out;
}

/**
 * The deployed-worker readout, as a string.
 *
 * Extracted verbatim from snt_mr_render_tab()'s inline echo in v12.22.0 so the
 * leaf composition could become a pure function. Same markup, same native
 * notice-info treatment, same omissions: an entry cached before v10.70.2 has no
 * fetched_at and prints no age line at all, because an unknown read time and a
 * read time of "just now" are different answers.
 *
 * @param array $info Sensor info: version, deployed_at, fetched_at.
 * @return string
 */
function snt_mr_render_edge_readout( $info ) {
	$read = snt_mr_edge_readout( $info );
	if ( null === $read ) {
		return wp_get_admin_notice( esc_html( snt_mr_edge_not_read() ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
	}
	$out = '';
	foreach ( $read as $row ) {
		$out .= '<p><strong>' . esc_html( $row['label'] ) . ':</strong> ' . esc_html( $row['value'] ) . '</p>';
	}
	return wp_get_admin_notice( $out, array( 'type' => 'info', 'additional_classes' => array( 'notice-alt', 'inline' ), 'paragraph_wrap' => false ) );
}

/**
 * The Edge sensor box's words when the version endpoint did not answer
 * (2026-10-12 audit: the box showed the Worker's name and its source as if
 * read, and only the hero pill said otherwise).
 *
 * @return string
 */
function snt_mr_edge_not_read() {
	return __( 'Not read: the version endpoint did not answer.', 'signal-and-noise-tools' );
}

/**
 * The Edge sensor readout as label/value rows, the same on both surfaces, or
 * null when the version endpoint did not answer. An entry cached before
 * v10.70.2 has no fetched_at and gets no Read row: an unknown read time and
 * "just now" are different answers.
 *
 * @param array|null $info snt_mr_sensor_info(): version, deployed_at, fetched_at.
 * @return array<int,array{label:string,value:string,ts?:int}>|null
 */
function snt_mr_edge_readout( $info ) {
	if ( ! is_array( $info ) ) {
		return null;
	}
	$rows = array( array( 'label' => __( 'Worker', 'signal-and-noise-tools' ), 'value' => 'sn-rights-signals' . ( '' !== (string) ( $info['version'] ?? '' ) ? ' v' . (string) $info['version'] : '' ) ) );
	if ( '' !== (string) ( $info['deployed_at'] ?? '' ) ) {
		$ts     = strtotime( (string) $info['deployed_at'] );
		$rows[] = array( 'label' => __( 'Deployed', 'signal-and-noise-tools' ), 'value' => false !== $ts ? wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $ts ) : (string) $info['deployed_at'] );
	}
	if ( isset( $info['fetched_at'] ) ) {
		/* translators: %s: human-readable duration, e.g. "5 mins". */
		$rows[] = array( 'label' => __( 'Read', 'signal-and-noise-tools' ), 'value' => sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $info['fetched_at'], time() ) ), 'ts' => (int) $info['fetched_at'] );
	}
	$rows[] = array( 'label' => __( 'Source', 'signal-and-noise-tools' ), 'value' => defined( 'SN_MR_VERSION_ENDPOINT' ) ? (string) SN_MR_VERSION_ENDPOINT : '' );
	return $rows;
}

/**
 * Sum the two identity signals across a window: did the reader ask for markdown,
 * and did it PROVE who it is.
 *
 * `measured` counts only reads carrying a real signature state. Rows written
 * before Worker v1.19.0 read `unmeasured` and are deliberately excluded from it,
 * because a share computed against them would answer a question nobody asked:
 * "what fraction of all history signed?" is not the same as "what fraction of
 * reads we can actually judge signed?".
 *
 * @since 12.26.0
 * @param array $rows Normalized taxonomy rows.
 * @return array{total:int,measured:int,valid:int,invalid:int,unknown_key:int,unsigned:int,markdown:int}
 */
function snt_mr_identity_totals( $rows ) {
	$out = array(
		'total'       => 0,
		'measured'    => 0,
		'valid'       => 0,
		'invalid'     => 0,
		'unknown_key' => 0,
		'unsigned'    => 0,
		'markdown'    => 0,
	);
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$hits           = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$out['total']  += $hits;
		if ( ! empty( $row['markdown_requested'] ) ) {
			$out['markdown'] += $hits;
		}
		$bucket = array(
			'valid'       => 'valid',
			'invalid'     => 'invalid',
			'unknown-key' => 'unknown_key',
			'unsigned'    => 'unsigned',
		);
		$state = (string) ( $row['signed_agent'] ?? '' );
		// 'unmeasured', 'other' and '' fall through: counted in total, never in
		// measured. Silence is not a measurement.
		if ( isset( $bucket[ $state ] ) ) {
			$out[ $bucket[ $state ] ] += $hits;
			$out['measured']          += $hits;
		}
	}
	return $out;
}

