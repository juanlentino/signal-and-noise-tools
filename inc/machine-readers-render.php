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
 * Open a house table (widefat striped) with a visible caption
 * and a header row. First header cell is the column-primary; the rest carry
 * the class passed per label ('num' for count columns, '' otherwise).
 *
 * @param string $caption Caption text (escaped here).
 * @param array  $heads   Label => extra class map, in column order.
 * @return string Opening HTML through <tbody>.
 */
function snt_mr_table_open( $caption, $heads ) {
	$out   = '<table class="widefat striped sn-an-table">';
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
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No machine reads in this window yet.', 'signal-and-noise-tools' ) . '</p>';
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
		$out .= '<tr><td class="column-primary"><strong>' . esc_html( (string) $family ) . '</strong></td>'
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
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No surface reads in this window yet.', 'signal-and-noise-tools' ) . '</p>';
	}
	$out = snt_mr_table_open( __( 'Reads per machine surface class.', 'signal-and-noise-tools' ), array(
		__( 'Surface', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $totals as $surface => $hits ) {
		$out .= '<tr><td class="column-primary"><strong>' . esc_html( (string) $surface ) . '</strong></td>'
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
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No reads from declared AI-training families in this window.', 'signal-and-noise-tools' ) . '</p>';
	}
	arsort( $totals );
	// v10.2.2: heading-length caption; the observation-not-proof disclaimer
	// moves to the house helper line below the table (a caption is a title).
	$caption = __( 'Observed vs declared: reads from AI-training crawler families.', 'signal-and-noise-tools' );
	$out     = snt_mr_table_open( $caption, array(
		__( 'Family', 'signal-and-noise-tools' )         => '',
		__( 'Observed reads', 'signal-and-noise-tools' ) => 'num',
		__( 'Rights reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $totals as $family => $hits ) {
		$out .= '<tr><td class="column-primary"><strong>' . esc_html( (string) $family ) . '</strong></td>'
			. '<td class="num" data-colname="Observed reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td>'
			. '<td class="num" data-colname="Rights reads">' . esc_html( number_format_i18n( (int) ( $rights[ $family ] ?? 0 ) ) ) . '</td></tr>';
	}
	return $out . '</tbody></table>'
		. '<p class="sn-field-helper">' . esc_html__( 'Read counts are what the edge observed; the AI-training class comes from public declarations. User agents are self-reported, so this is observation, not proof of identity.', 'signal-and-noise-tools' ) . '</p>';
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
 * v10.2.2: an optional fourth chip carries the feed-fetcher half of the
 * machine audience (the R4 "one picture" intent reaching the strip). The two
 * counts are never summed — a feed fetch is not a crawler read; the chip only
 * sits beside the others. Null (tracker absent) omits the chip entirely.
 *
 * @param array    $rows       Normalized rows.
 * @param int      $days       Window the rows cover.
 * @param int|null $feed_total Feed fetches in the same window, or null to omit.
 * @return string HTML.
 */
function snt_mr_render_summary_chips( $rows, $days, $feed_total = null ) {
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
	// v10.2.1: the Analytics KPI vocabulary (.sn-kpi-row / -label / -value),
	// the same one snt_an_kpi_row() paints — not a second stat-card treatment.
	$card = function ( $label, $value ) {
		return '<div class="sn-kpi"><p class="sn-kpi-label">' . esc_html( $label ) . '</p>'
			. '<p class="sn-kpi-value">' . esc_html( (string) $value ) . '</p></div>';
	};
	return '<div class="sn-kpi-row sn-mr-kpi-row">'
		. $card( sprintf( /* translators: %s: window length in days. */ __( 'machine reads, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ), number_format_i18n( $total ) )
		. $card( __( 'top family', 'signal-and-noise-tools' ), '' === $top || '&mdash;' === $top ? '—' : $top )
		. $card( __( 'AI-training reads', 'signal-and-noise-tools' ), number_format_i18n( $ai ) )
		. ( null !== $feed_total
			? $card( sprintf( /* translators: %s: window length in days. */ __( 'feed fetches, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ), number_format_i18n( (int) $feed_total ) )
			: '' )
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

/**
 * R4 (v10.2.1): the feed-fetcher column — the EXISTING rss-feed-tracker stats
 * rendered beside the surface table so the machine audience reads as one
 * picture. inc/rss-feed-tracker.php stays the source of truth: this reads its
 * accessor and re-implements nothing.
 *
 * Deliberate vocabulary: feed pulls are FETCHES, not "reads" — a reader app
 * polling RSS on a schedule is a different act from a crawler reading a page,
 * and the two counts are never summed (the same rule that keeps beacons and
 * edge observations apart).
 *
 * @param array $stats sn_rss_tracker_window_stats_multi() shape:
 *                     { most_recent, windows: { days: { total, uniques } } }.
 * @return string HTML.
 */
function snt_mr_render_feed_table( $stats ) {
	$stats   = (array) $stats;
	$windows = isset( $stats['windows'] ) && is_array( $stats['windows'] ) ? $stats['windows'] : array();
	if ( empty( $windows ) ) {
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No feed fetches recorded yet.', 'signal-and-noise-tools' ) . '</p>';
	}
	$out = snt_mr_table_open( __( 'Feed fetches per window (RSS and JSON Feed).', 'signal-and-noise-tools' ), array(
		__( 'Window', 'signal-and-noise-tools' )   => '',
		__( 'Fetches', 'signal-and-noise-tools' )  => 'num',
		__( 'Fetchers', 'signal-and-noise-tools' ) => 'num',
	) );
	ksort( $windows );
	foreach ( $windows as $days => $row ) {
		$row  = (array) $row;
		$out .= '<tr><td class="column-primary"><strong>'
			/* translators: %s: window length in days. */
			. esc_html( sprintf( __( 'last %s days', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ) ) . '</strong></td>'
			. '<td class="num" data-colname="Fetches">' . esc_html( number_format_i18n( (int) ( $row['total'] ?? 0 ) ) ) . '</td>'
			. '<td class="num" data-colname="Fetchers">' . esc_html( number_format_i18n( (int) ( $row['uniques'] ?? 0 ) ) ) . '</td></tr>';
	}
	$out .= '</tbody></table>';
	if ( '' !== (string) ( $stats['most_recent'] ?? '' ) ) {
		$out .= '<p class="sn-field-helper">' . esc_html__( 'Most recent fetch:', 'signal-and-noise-tools' ) . ' ' . esc_html( (string) $stats['most_recent'] ) . '</p>';
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
	$info = is_array( $info ) ? $info : array();
	$out  = '<div class="notice notice-info notice-alt inline">';
	$out .= '<p><strong>' . esc_html__( 'Worker', 'signal-and-noise-tools' ) . '</strong> <code>sn-rights-signals</code>';
	if ( '' !== (string) ( $info['version'] ?? '' ) ) {
		$out .= ' <code>v' . esc_html( (string) $info['version'] ) . '</code>';
	}
	$out .= '</p>';
	if ( '' !== (string) ( $info['deployed_at'] ?? '' ) ) {
		$out .= '<p><strong>' . esc_html__( 'Deployed:', 'signal-and-noise-tools' ) . '</strong> ' . esc_html( (string) $info['deployed_at'] ) . '</p>';
	}
	if ( isset( $info['fetched_at'] ) ) {
		$out .= '<p><strong>' . esc_html__( 'Read:', 'signal-and-noise-tools' ) . '</strong> '
			/* translators: %s: human-readable duration, e.g. "5 mins". */
			. esc_html( sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $info['fetched_at'], time() ) ) ) . '</p>';
	}
	$out .= '<p><em>' . esc_html__( 'Source:', 'signal-and-noise-tools' ) . '</em> <code>' . esc_html( defined( 'SN_MR_VERSION_ENDPOINT' ) ? SN_MR_VERSION_ENDPOINT : '' ) . '</code></p>';
	$out .= '</div>';
	return $out;
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

/**
 * Say so when the sensor read was capped.
 *
 * WHY. Worker v1.23.0 declared AGGREGATE_LIMIT = 10000 on the view this tab
 * reads, and reports `truncated` on every response. snt_mr_fetch() captured it
 * and the summary payload carried it, but no render path consulted it — so
 * every figure here rendered identically whether the read was complete or
 * capped, on the one surface a human actually reads.
 *
 * The failure mode is why the wording is blunt. A truncated aggregate does not
 * look broken; a sum of capped rows looks like LESS TRAFFIC. A reader who is
 * not told reads the drop as a finding about crawlers rather than as an
 * artifact of the read. That mistake has already been made once here: a 60-day
 * read summing below a 30-day read produced a "15x surge" that never happened.
 *
 * It qualifies the HEADLINE too, not just the tables. v13.34.0 moved the
 * ability's `total` onto the day-only totals view, which cannot truncate, but
 * snt_mr_render_summary_chips() still sums the aggregate — so on this tab every
 * number is downstream of the cap.
 *
 * @since 13.43.0
 * @param bool|null $truncated The edge's own flag, as snt_mr_fetch() captured it.
 * @return string Empty when the read was complete — the common case renders nothing.
 */
function snt_mr_render_truncation_notice( $truncated ) {
	if ( empty( $truncated ) ) {
		return '';
	}
	return '<p class="sn-mr-truncated notice notice-warning inline">'
		. esc_html__( 'The edge capped this read at its row limit, so every figure on this tab — the headline included — is a floor, not a count. A capped read does not look degraded; it looks like fewer machine reads. Narrow the window to get a complete one.', 'signal-and-noise-tools' )
		. '</p>';
}

/**
 * The identity KPI row: markdown adoption and signature verification.
 *
 * Both numbers existed and were rendered nowhere — markdown since v12.16.0,
 * signatures since v12.24.0. This is their door.
 *
 * WHY THE UNMEASURED GUARD. For the first weeks after the sensor ships, nearly
 * every read carries `unmeasured`. Painting "0 verified" there would be a FALSE
 * ZERO: it asserts a measurement that was never taken, and it would make
 * adoption look like a finding when it is an absence of data. The card says "not
 * yet measured" until at least one read carries a real state.
 *
 * Markdown adoption is a DIFFERENT sensor of a different vintage, so it renders
 * regardless — suppressing it would hide a number that is genuinely measured.
 *
 * @since 12.26.0
 * @param array $rows Normalized taxonomy rows.
 * @param int   $days Window length, for the label.
 * @return string
 */
function snt_mr_render_identity_row( $rows, $days ) {
	$t    = snt_mr_identity_totals( $rows );
	$card = function ( $label, $value, $note = '' ) {
		return '<div class="sn-kpi"><p class="sn-kpi-label">' . esc_html( $label ) . '</p>'
			. '<p class="sn-kpi-value">' . esc_html( (string) $value ) . '</p>'
			. ( '' !== $note ? '<p class="sn-kpi-note">' . esc_html( $note ) . '</p>' : '' )
			. '</div>';
	};

	if ( 0 === $t['measured'] ) {
		$signature = $card(
			__( 'proved identity', 'signal-and-noise-tools' ),
			'—',
			__( 'not yet measured — no read in this window carried a signature state', 'signal-and-noise-tools' )
		);
	} else {
		$note = '';
		if ( $t['invalid'] > 0 || $t['unknown_key'] > 0 ) {
			$note = sprintf(
				/* translators: 1: invalid signature count, 2: unknown-key count. */
				__( '%1$s invalid, %2$s unknown key', 'signal-and-noise-tools' ),
				number_format_i18n( $t['invalid'] ),
				number_format_i18n( $t['unknown_key'] )
			);
		}
		$signature = $card(
			__( 'proved identity', 'signal-and-noise-tools' ),
			number_format_i18n( $t['valid'] ) . ' / ' . number_format_i18n( $t['measured'] ),
			$note
		);
	}

	return '<div class="sn-kpi-row sn-mr-kpi-row">'
		. $signature
		. $card(
			sprintf( /* translators: %s: window length in days. */ __( 'asked for markdown, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ),
			number_format_i18n( $t['markdown'] )
		)
		. '</div>';
}
