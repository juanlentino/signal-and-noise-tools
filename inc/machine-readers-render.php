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
 * Open a house table (wp-list-table widefat striped) with a visible caption
 * and a header row. First header cell is the column-primary; the rest carry
 * the class passed per label ('num' for count columns, '' otherwise).
 *
 * @param string $caption Caption text (escaped here).
 * @param array  $heads   Label => extra class map, in column order.
 * @return string Opening HTML through <tbody>.
 */
function snt_mr_table_open( $caption, $heads ) {
	$out   = '<table class="wp-list-table widefat striped sn-mr-table">';
	$out  .= '<caption>' . esc_html( $caption ) . '</caption><thead><tr>';
	$first = true;
	foreach ( $heads as $label => $class ) {
		$cls   = 'manage-column' . ( $first ? ' column-primary' : '' ) . ( '' !== $class ? ' ' . $class : '' );
		$out  .= '<th scope="col" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</th>';
		$first = false;
	}
	return $out . '</tr></thead><tbody>';
}

/**
 * Family summary table: hits per family aggregated across surfaces, rows
 * descending, the window stated in the caption, last-seen day alongside.
 *
 * @param array $rows Normalized rows (snt_mr_normalize_rows shape).
 * @param int   $days Window the rows cover.
 * @return string HTML.
 */
function snt_mr_render_family_table( $rows, $days ) {
	$totals = snt_mr_sum_hits_by( $rows, 'family' );
	if ( empty( $totals ) ) {
		return '<p class="sn-mr-empty">' . esc_html__( 'No machine reads in this window yet.', 'signal-and-noise-tools' ) . '</p>';
	}
	// Last-seen day per family (max Y-m-d compares fine as a string). Escaped
	// on output like every other cell: normalized shape or not.
	$last = array();
	foreach ( (array) $rows as $r ) {
		$fam = (string) ( $r['family'] ?? '' );
		$day = (string) ( $r['day'] ?? '' );
		if ( '' !== $fam && ( ! isset( $last[ $fam ] ) || strcmp( $day, $last[ $fam ] ) > 0 ) ) {
			$last[ $fam ] = $day;
		}
	}
	$caption = sprintf(
		/* translators: %s: window length in days. */
		__( 'Reads per crawler family, last %s days.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) $days )
	);
	$out = snt_mr_table_open( $caption, array(
		__( 'Family', 'signal-and-noise-tools' )    => '',
		__( 'Reads', 'signal-and-noise-tools' )     => 'num',
		__( 'Last seen', 'signal-and-noise-tools' ) => '',
	) );
	foreach ( $totals as $family => $hits ) {
		$out .= '<tr><td class="column-primary" data-colname="Family"><strong>' . esc_html( (string) $family ) . '</strong></td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td>'
			. '<td data-colname="Last seen">' . esc_html( (string) ( $last[ $family ] ?? '' ) ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/**
 * Surface-class breakdown table: which machine surfaces get read, descending.
 *
 * @param array $rows Normalized rows.
 * @return string HTML.
 */
function snt_mr_render_surface_table( $rows ) {
	$totals = snt_mr_sum_hits_by( $rows, 'surface' );
	if ( empty( $totals ) ) {
		return '<p class="sn-mr-empty">' . esc_html__( 'No surface reads in this window yet.', 'signal-and-noise-tools' ) . '</p>';
	}
	$out = snt_mr_table_open( __( 'Reads per machine surface class.', 'signal-and-noise-tools' ), array(
		__( 'Surface', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $totals as $surface => $hits ) {
		$out .= '<tr><td class="column-primary" data-colname="Surface"><strong>' . esc_html( (string) $surface ) . '</strong></td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/**
 * Observed-vs-declared compliance table: only families the static map classes
 * as AI-training, each with total observed reads and their reads of the
 * `rights` surface. The caption names the framing (observed reads crossed
 * with declared class) and never claims proven identity.
 *
 * @param array $rows Normalized rows.
 * @return string HTML.
 */
function snt_mr_render_compliance( $rows ) {
	$ai     = snt_mr_ai_training_families();
	$totals = array();
	$rights = array();
	foreach ( (array) $rows as $r ) {
		$fam = (string) ( $r['family'] ?? '' );
		if ( ! in_array( $fam, $ai, true ) ) {
			continue; // Only declared AI-training families belong in this read.
		}
		$hits           = (int) ( $r['hits'] ?? 0 );
		$totals[ $fam ] = (int) ( $totals[ $fam ] ?? 0 ) + $hits;
		if ( 'rights' === (string) ( $r['surface'] ?? '' ) ) {
			$rights[ $fam ] = (int) ( $rights[ $fam ] ?? 0 ) + $hits;
		}
	}
	if ( empty( $totals ) ) {
		return '<p class="sn-mr-empty">' . esc_html__( 'No reads from declared AI-training families in this window.', 'signal-and-noise-tools' ) . '</p>';
	}
	arsort( $totals );
	$caption = __( 'Observed vs declared: read counts are what the edge observed; the AI-training class comes from public declarations. User agents are self-reported, so this is observation, not proof of identity.', 'signal-and-noise-tools' );
	$out     = snt_mr_table_open( $caption, array(
		__( 'Family', 'signal-and-noise-tools' )         => '',
		__( 'Observed reads', 'signal-and-noise-tools' ) => 'num',
		__( 'Rights reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $totals as $family => $hits ) {
		$out .= '<tr><td class="column-primary" data-colname="Family"><strong>' . esc_html( (string) $family ) . '</strong></td>'
			. '<td class="num" data-colname="Observed reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td>'
			. '<td class="num" data-colname="Rights reads">' . esc_html( number_format_i18n( (int) ( $rights[ $family ] ?? 0 ) ) ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/**
 * Sensor card: deployed worker version + deploy date vs the contract minimum
 * (SN_MR_SENSOR_MIN). Null info renders the quiet dash, never a warning or a
 * fatal; a below-minimum deploy warns and names the required version. Pure.
 *
 * @param array|null $info snt_mr_sensor_info() shape, or null on failure.
 * @return string HTML.
 */
function snt_mr_render_sensor_card( $info ) {
	$min = defined( 'SN_MR_SENSOR_MIN' ) ? (string) SN_MR_SENSOR_MIN : '1.4.0';
	if ( ! is_array( $info ) || '' === (string) ( $info['version'] ?? '' ) ) {
		return '<p class="sn-mr-sensor"><strong>' . esc_html__( 'Sensor', 'signal-and-noise-tools' ) . ':</strong> &mdash;</p>';
	}
	$version  = (string) $info['version'];
	$deployed = (string) ( $info['deployed_at'] ?? '' );
	$out      = '<p class="sn-mr-sensor"><strong>' . esc_html__( 'Sensor', 'signal-and-noise-tools' ) . ':</strong> sn-rights-signals v'
		. esc_html( $version )
		. ( '' !== $deployed ? ' <span class="description">(' . esc_html__( 'deployed', 'signal-and-noise-tools' ) . ' ' . esc_html( $deployed ) . ')</span>' : '' )
		. '</p>';
	if ( version_compare( $version, $min, '<' ) ) {
		$out .= '<div class="notice notice-warning notice-alt inline"><p><strong>' . esc_html__( 'Sensor outdated:', 'signal-and-noise-tools' ) . '</strong> '
			. esc_html( sprintf( /* translators: 1: deployed version, 2: required minimum. */ __( 'the deployed worker is v%1$s; these panels need v%2$s or newer. Deploy the sensor release.', 'signal-and-noise-tools' ), $version, $min ) )
			. '</p></div>';
	}
	return $out;
}

/**
 * Summary stat strip: total machine reads, top family, AI-training reads —
 * the at-a-glance row above the tables. Pure; every value escaped; empty rows
 * still render the strip (zeros), never a fatal.
 *
 * @param array $rows Normalized rows.
 * @param int   $days Window the rows cover.
 * @return string HTML.
 */
function snt_mr_render_summary_chips( $rows, $days ) {
	$totals = snt_mr_sum_hits_by( $rows, 'family' );
	$total  = 0;
	foreach ( $totals as $hits ) {
		$total += (int) $hits;
	}
	$top = '' !== (string) key( $totals ) && ! empty( $totals ) ? (string) key( $totals ) : '&mdash;';
	$ai  = 0;
	foreach ( snt_mr_ai_training_families() as $fam ) {
		$ai += (int) ( $totals[ $fam ] ?? 0 );
	}
	$stat = function ( $label, $value, $raw = false ) {
		return '<div class="sn-mr-stat"><span class="sn-mr-stat-v">' . ( $raw ? $value : esc_html( (string) $value ) ) . '</span>'
			. '<span class="sn-mr-stat-l">' . esc_html( $label ) . '</span></div>';
	};
	return '<div class="sn-mr-stats">'
		. $stat( sprintf( /* translators: %s: window length in days. */ __( 'machine reads, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ), number_format_i18n( $total ) )
		. $stat( __( 'top family', 'signal-and-noise-tools' ), '&mdash;' === $top ? $top : esc_html( $top ), '&mdash;' === $top )
		. $stat( __( 'AI-training reads', 'signal-and-noise-tools' ), number_format_i18n( $ai ) )
		. '</div>';
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
	if ( '' === $version ) {
		$pills[] = array( 'unknown', __( 'Sensor unreachable', 'signal-and-noise-tools' ), '' );
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
		: array( 'warn', __( 'Read token missing', 'signal-and-noise-tools' ), __( 'Save a read token below, or define SN_MR_READ_TOKEN in wp-config.php.', 'signal-and-noise-tools' ) );

	// 3. The read itself.
	if ( ! empty( $result['ok'] ) ) {
		$pills[] = array( 'ok', __( 'Aggregates current', 'signal-and-noise-tools' ), '' );
	} elseif ( 'not_configured' === (string) ( $result['error'] ?? '' ) ) {
		$pills[] = array( 'warn', __( 'Not configured', 'signal-and-noise-tools' ), __( 'The sensor cannot be read until the token above is set.', 'signal-and-noise-tools' ) );
	} else {
		$pills[] = array(
			'warn',
			__( 'Read failed', 'signal-and-noise-tools' ),
			/* translators: %s: machine-readable error code. */
			sprintf( __( 'The last read returned %s. The panel retries on the next load.', 'signal-and-noise-tools' ), (string) ( $result['error'] ?? 'unknown' ) ),
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
			$pills[] = array( 'warn', __( 'Crawler list drift', 'signal-and-noise-tools' ), __( 'Cloudflare\'s published list changed; robots-block.mjs needs a review.', 'signal-and-noise-tools' ) );
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
			$out .= '<p class="sn-an-pipeline-warn">' . esc_html( (string) $p[1] ) . ' — ' . esc_html( (string) $p[2] ) . '</p>';
		}
	}
	return $out;
}
