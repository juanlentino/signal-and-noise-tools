<?php
/**
 * S&N Dashboard — Monitoring → Machine Readers, painter helpers.
 *
 * Split out of monitoring-machine-readers.php to keep both files under the
 * house line budget. Every function here is a pure presenter over data the
 * classic readers (inc/machine-readers-*.php) already produced; none of them
 * fetch, none of them mutate their input.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Gather every reading the classic leaf shows, via the SAME reader functions
 * `snt_mr_render_tab()` calls (inc/machine-readers-api.php, -insights.php).
 * Data only — no HTML here.
 *
 * @return array<string,mixed>
 */
function machine_readers_data() {
	$days   = 30;
	$result = function_exists( '\snt_mr_fetch' ) ? \snt_mr_fetch( $days ) : array( 'ok' => false, 'rows' => array() );
	$rows   = ! empty( $result['ok'] ) && is_array( $result['rows'] ?? null ) ? $result['rows'] : array();

	$feed       = function_exists( '\sn_rss_tracker_window_stats_multi' ) ? (array) \sn_rss_tracker_window_stats_multi( array( 7, 30 ) ) : array();
	$feed_total = isset( $feed['windows'][30]['total'] ) ? (int) $feed['windows'][30]['total'] : null;

	$info   = function_exists( '\snt_mr_sensor_info' ) ? \snt_mr_sensor_info() : null;
	$status = function_exists( '\snt_mr_crawler_list_status' ) ? \snt_mr_crawler_list_status() : null;

	$unknown_rows = null;
	$rights_rows  = null;
	$cards        = array();
	if ( ! empty( $result['ok'] ) ) {
		$unknown = \snt_mr_fetch( $days, 'unknown' );
		if ( ! empty( $unknown['ok'] ) ) {
			$unknown_rows = $unknown['rows'] ?? array();
		}
		$rights = \snt_mr_fetch( $days, 'rights' );
		if ( ! empty( $rights['ok'] ) ) {
			$rights_rows = $rights['rows'] ?? array();
		}
		if ( function_exists( '\snt_mr_split_windows' ) && function_exists( '\snt_mr_family_delta_cards' ) ) {
			$win   = \snt_mr_split_windows( $rows, 15, gmdate( 'Y-m-d' ) );
			$cards = \snt_mr_family_delta_cards( $win['current'] ?? array(), $win['prior'] ?? array(), 15 );
		}
	}

	return array(
		'days'         => $days,
		'result'       => $result,
		'rows'         => $rows,
		'feed'         => $feed,
		'feed_total'   => $feed_total,
		'info'         => $info,
		'status'       => $status,
		'unknown_rows' => $unknown_rows,
		'rights_rows'  => $rights_rows,
		'cards'        => $cards,
		'url_locked'   => defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL,
		'token_locked' => defined( 'SN_MR_READ_TOKEN' ) && '' !== (string) SN_MR_READ_TOKEN,
		'stored_url'   => function_exists( '\sn_setting' ) ? (string) \sn_setting( 'machine_readers.worker_url', '' ) : '',
		'has_token'    => function_exists( '\sn_setting' ) && '' !== (string) \sn_setting( 'machine_readers.read_token', '' ),
		'default_url'  => defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? SN_MR_DEFAULT_ENDPOINT : '',
	);
}

/** @param string $summary @param string $inner @return string '' when $inner is empty. */
function machine_readers_disclosure( $summary, $inner, array $attrs = array() ) {
	return \snt_kit_tag( 'os-disclosure', array_merge( array( 'heading' => (string) $summary ), $attrs ), (string) $inner );
}

/** @param string $summary @param string $html @return string */
function machine_readers_fold( $summary, $html ) {
	$html = (string) $html;
	return '' === trim( $html ) ? '' : machine_readers_disclosure( $summary, $html );
}

/** @param array $pills snt_mr_sensor_pills() output. @return string */
function machine_readers_pills_html( array $pills ) {
	$marks = array(
		'ok'      => '✓',
		'warn'    => '!',
		'unknown' => '?',
	);
	$out = '';
	foreach ( $pills as $p ) {
		$state = isset( $marks[ $p[0] ] ) ? $p[0] : 'unknown';
		$out  .= \snt_kit_badge( $state, $marks[ $state ] . ' ' . (string) $p[1] );
	}
	foreach ( $pills as $p ) {
		if ( 'warn' === $p[0] && '' !== (string) ( $p[2] ?? '' ) ) {
			$out .= \snt_kit_notice( 'warn', \snt_kit_esc( $p[1] . ' — ' . $p[2] ) );
		}
	}
	return $out;
}

/** @param array $rows @param int $days @param int|null $feed_total @return string */
function machine_readers_summary_stats_html( array $rows, $days, $feed_total ) {
	$totals = \snt_mr_sum_hits_by( $rows, 'family' );
	$total  = array_sum( $totals );
	$top    = ! empty( $totals ) ? (string) array_key_first( $totals ) : '—';
	$ai     = 0;
	foreach ( \snt_mr_ai_training_families() as $fam ) {
		$ai += (int) ( $totals[ $fam ] ?? 0 );
	}
	$out  = '<div class="snt-stats">';
	$out .= \snt_kit_stat( number_format_i18n( $total ), sprintf( __( 'machine reads, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ) );
	$out .= \snt_kit_stat( $top, __( 'top family', 'signal-and-noise-tools' ) );
	$out .= \snt_kit_stat( number_format_i18n( $ai ), __( 'AI-training reads', 'signal-and-noise-tools' ) );
	if ( null !== $feed_total ) {
		$out .= \snt_kit_stat( number_format_i18n( (int) $feed_total ), sprintf( __( 'feed fetches, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ) );
	}
	return $out . '</div>';
}

/** @param array $rows @param int $days @return string */
function machine_readers_identity_stats_html( array $rows, $days ) {
	$t   = \snt_mr_identity_totals( $rows );
	$out = '<div class="snt-stats">';
	if ( 0 === $t['measured'] ) {
		$out .= \snt_kit_stat( '—', __( 'proved identity', 'signal-and-noise-tools' ), __( 'not yet measured — no read in this window carried a signature state', 'signal-and-noise-tools' ) );
	} else {
		$note = '';
		if ( $t['invalid'] > 0 || $t['unknown_key'] > 0 ) {
			$note = sprintf( __( '%1$s invalid, %2$s unknown key', 'signal-and-noise-tools' ), number_format_i18n( $t['invalid'] ), number_format_i18n( $t['unknown_key'] ) );
		}
		$out .= \snt_kit_stat( number_format_i18n( $t['valid'] ) . ' / ' . number_format_i18n( $t['measured'] ), __( 'proved identity', 'signal-and-noise-tools' ), $note );
	}
	$out .= \snt_kit_stat( number_format_i18n( $t['markdown'] ), sprintf( __( 'asked for markdown, %sd', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ) );
	return $out . '</div>';
}

/** @param array $cards snt_mr_family_delta_cards() output. @return string */
function machine_readers_delta_cards_html( array $cards ) {
	$out = '';
	foreach ( $cards as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$body  = '<b>' . \snt_kit_esc( (string) ( $c['title'] ?? '' ) ) . '</b>';
		$detail = (string) ( $c['detail'] ?? '' );
		if ( '' !== $detail ) {
			$body .= '<p class="snt-prose">' . \snt_kit_esc( $detail ) . '</p>';
		}
		if ( '' !== (string) ( $c['action_url'] ?? '' ) ) {
			$body .= \snt_kit_go( (string) ( $c['action_label'] ?? __( 'Open', 'signal-and-noise-tools' ) ), array( 'tab' => 'monitoring', 'sub' => 'machine-readers', 'current' => 'monitoring' ) );
		}
		$out .= \snt_kit_notice( 'info', $body );
	}
	return $out;
}

/** @param array $visible Rows (observed_at, vendor, purpose, path, user_agent). @return string */
function machine_readers_rights_table( array $visible ) {
	$rows = array();
	foreach ( $visible as $r ) {
		$vendor = \snt_mr_normalize_vendor( $r['vendor'] ?? '' );
		$rows[] = array(
			'when'       => substr( preg_replace( '/[^0-9T:.\-Z]/', '', (string) ( $r['observed_at'] ?? '' ) ), 0, 20 ),
			'vendor'     => '' !== $vendor ? $vendor : '—',
			'purpose'    => (string) ( $r['purpose'] ?? 'unknown' ),
			'document'   => substr( (string) ( $r['path'] ?? '' ), 0, 120 ),
			'user_agent' => substr( (string) ( $r['user_agent'] ?? '' ), 0, 200 ),
		);
	}
	return '<p class="snt-hint">' . \snt_kit_esc( __( 'Rights-surface reads, in full , who asked for the declarations, and for which document.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_table(
			array(
				array( 'key' => 'when', 'label' => __( 'When', 'signal-and-noise-tools' ) ),
				array( 'key' => 'vendor', 'label' => __( 'Vendor', 'signal-and-noise-tools' ) ),
				array( 'key' => 'purpose', 'label' => __( 'Purpose', 'signal-and-noise-tools' ) ),
				array( 'key' => 'document', 'label' => __( 'Document', 'signal-and-noise-tools' ) ),
				array( 'key' => 'user_agent', 'label' => __( 'User agent', 'signal-and-noise-tools' ) ),
			),
			$rows
		);
}

/** @param array $ours Rows whose vendor is signal-and-noise. @return string */
function machine_readers_rights_ours_fold( array $ours ) {
	if ( empty( $ours ) ) {
		return '';
	}
	$cap = defined( 'SN_MR_RIGHTS_DISPLAY_MAX' ) ? (int) SN_MR_RIGHTS_DISPLAY_MAX : 50;
	return machine_readers_disclosure(
		sprintf( _n( '+%s read from our own CI — show it', '+%s reads from our own CI — show them', count( $ours ), 'signal-and-noise-tools' ), number_format_i18n( count( $ours ) ) ),
		machine_readers_rights_table( array_slice( $ours, 0, $cap ) )
	);
}

/**
 * @param array $rows Rows from the 'rights' view.
 * @param int   $limit The sensor's own storage ceiling (not the display cap), mirrors
 *                      snt_mr_render_rights_detail()'s $limit = 500 default.
 * @return string
 */
function machine_readers_rights_html( $rows, $limit = 500 ) {
	$rows = (array) $rows;
	if ( empty( $rows ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No reads of the rights surfaces in this window.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$ours     = array();
	$external = array();
	foreach ( $rows as $r ) {
		if ( 'signal-and-noise' === \snt_mr_normalize_vendor( $r['vendor'] ?? '' ) ) {
			$ours[] = $r;
		} else {
			$external[] = $r;
		}
	}
	if ( empty( $external ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No external reads of the rights surfaces in this window.', 'signal-and-noise-tools' ) ) . '</p>'
			. machine_readers_rights_ours_fold( $ours );
	}

	$total  = count( $external );
	$sorted = $external;
	usort(
		$sorted,
		static function ( $a, $b ) {
			$at = (string) ( $a['observed_at'] ?? '' );
			$bt = (string) ( $b['observed_at'] ?? '' );
			if ( '' === $at || '' === $bt ) {
				return ( '' === $at ? 1 : 0 ) - ( '' === $bt ? 1 : 0 );
			}
			return strcmp( $bt, $at );
		}
	);
	$cap     = defined( 'SN_MR_RIGHTS_DISPLAY_MAX' ) ? (int) SN_MR_RIGHTS_DISPLAY_MAX : 50;
	$visible = array_slice( $sorted, 0, $cap );
	$hidden  = $total - count( $visible );

	$body = machine_readers_rights_table( $visible );
	if ( $hidden > 0 ) {
		$body .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( '+%s more rights-surface events — the list is capped, not complete. Newest first, so the tail is the oldest end.', 'signal-and-noise-tools' ), number_format_i18n( $hidden ) ) ) . '</p>';
	}
	$body .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Showing %1$s of at most %2$s events. These are the only reads the sensor records in full: rights surfaces are a closed set of fixed URLs, so the path identifies the document and nothing about the reader.', 'signal-and-noise-tools' ), number_format_i18n( count( $visible ) ), number_format_i18n( (int) $limit ) ) ) . '</p>';
	$body .= machine_readers_rights_ours_fold( $ours );

	return machine_readers_disclosure( sprintf( _n( '%s external read — show the log', '%s external reads — show the log', $total, 'signal-and-noise-tools' ), number_format_i18n( $total ) ), $body );
}

/** @param array $rows Rows from the 'unknown' view. @return string */
function machine_readers_unknown_html( $rows ) {
	$rows = (array) $rows;
	if ( empty( $rows ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'Every machine read in this window matched the taxonomy. Nothing to review.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$table_rows = array();
	$count      = 0;
	foreach ( $rows as $r ) {
		$ua = \snt_mr_normalize_ua_sample( $r['user_agent'] ?? ( $r['ua_sample'] ?? '' ) );
		if ( '' === $ua ) {
			continue;
		}
		++$count;
		$table_rows[] = array( 'agent' => $ua, 'reads' => number_format_i18n( (int) ( $r['hits'] ?? 0 ) ) );
	}
	$table = '<p class="snt-hint">' . \snt_kit_esc( __( 'Unclassified user agents, by volume — review these to extend the taxonomy.', 'signal-and-noise-tools' ) ) . '</p>';
	$table .= \snt_kit_table(
		array(
			array( 'key' => 'agent', 'label' => __( 'User agent (sampled, sanitized)', 'signal-and-noise-tools' ) ),
			array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$table_rows
	);
	$table .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Showing %1$s of at most %2$s sampled agents. Strings are truncated to 96 characters and stripped to a safe character set at the edge, so they are a review aid, not a verbatim log.', 'signal-and-noise-tools' ), number_format_i18n( $count ), number_format_i18n( 50 ) ) ) . '</p>';
	if ( $count > 0 ) {
		return machine_readers_disclosure( sprintf( _n( '%s unclassified user agent — show the review list', '%s unclassified user agents — show the review list', $count, 'signal-and-noise-tools' ), number_format_i18n( $count ) ), $table );
	}
	return $table;
}

/** @param array $rows @param int $days @return string */
function machine_readers_purpose_table_html( array $rows, $days ) {
	if ( \snt_mr_taxonomy_absent( $rows ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'The deployed sensor predates the vendor/purpose taxonomy, so no purpose data exists for this window. This is not a measured zero.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$totals = \snt_mr_purpose_totals( $rows );
	if ( empty( $totals['purposes'] ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No third-party machine reads in this window.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$table_rows = array();
	foreach ( $totals['purposes'] as $purpose => $hits ) {
		$table_rows[] = array( 'purpose' => (string) $purpose, 'reads' => number_format_i18n( (int) $hits ) );
	}
	$out = '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Reads by purpose, last %d days.', 'signal-and-noise-tools' ), (int) $days ) ) . '</p>';
	$out .= \snt_kit_table(
		array(
			array( 'key' => 'purpose', 'label' => __( 'Purpose', 'signal-and-noise-tools' ) ),
			array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$table_rows
	);
	if ( $totals['first_party'] > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Excludes %s reads from this site\'s own uptime monitoring, which is the site measuring itself rather than readership.', 'signal-and-noise-tools' ), number_format_i18n( (int) $totals['first_party'] ) ) ) . '</p>';
	}
	return $out;
}

/** @param array $rows @param int $limit @return string '' when nothing to show. */
function machine_readers_vendor_purpose_html( array $rows, $limit = 20 ) {
	if ( \snt_mr_taxonomy_absent( $rows ) ) {
		return '';
	}
	$pairs = array();
	foreach ( $rows as $r ) {
		$vendor = (string) ( $r['vendor'] ?? '' );
		if ( '' === $vendor || ! empty( $r['first_party'] ) ) {
			continue;
		}
		$agent         = (string) ( $r['agent'] ?? '' );
		$key           = $vendor . '|' . (string) ( $r['purpose'] ?? 'unknown' ) . '|' . $agent;
		$pairs[ $key ] = (int) ( $pairs[ $key ] ?? 0 ) + (int) ( $r['hits'] ?? 0 );
	}
	if ( empty( $pairs ) ) {
		return '';
	}
	arsort( $pairs );
	$shown      = array_slice( $pairs, 0, max( 1, (int) $limit ), true );
	$table_rows = array();
	foreach ( $shown as $key => $hits ) {
		list( $vendor, $purpose, $agent ) = array_pad( explode( '|', (string) $key, 3 ), 3, '' );
		$table_rows[] = array( 'vendor' => $vendor, 'agent' => '' !== $agent ? $agent : '—', 'purpose' => $purpose, 'reads' => number_format_i18n( (int) $hits ) );
	}
	$out = '<p class="snt-hint">' . \snt_kit_esc( __( 'Reads by agent and purpose, third parties only.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= \snt_kit_table(
		array(
			array( 'key' => 'vendor', 'label' => __( 'Vendor', 'signal-and-noise-tools' ) ),
			array( 'key' => 'agent', 'label' => __( 'Agent', 'signal-and-noise-tools' ) ),
			array( 'key' => 'purpose', 'label' => __( 'Purpose', 'signal-and-noise-tools' ) ),
			array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$table_rows
	);
	$hidden = count( $pairs ) - count( $shown );
	if ( $hidden > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( '+%s more agent/purpose pairs — the list is capped, not complete. Sorted busiest-first, so the tail is the quiet end.', 'signal-and-noise-tools' ), number_format_i18n( (int) $hidden ) ) ) . '</p>';
	}
	return machine_readers_disclosure(
		sprintf( _n( '%s agent/purpose pair — show the breakdown', '%s agent/purpose pairs — show the breakdown', count( $pairs ), 'signal-and-noise-tools' ), number_format_i18n( count( $pairs ) ) ),
		$out
	);
}

/** @param array $rows @param int $days @return string */
function machine_readers_family_table_html( array $rows, $days ) {
	$totals = \snt_mr_sum_hits_by( $rows, 'family' );
	if ( empty( $totals ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No machine reads in this window yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$last = array();
	foreach ( $rows as $r ) {
		$fam = (string) ( $r['family'] ?? '' );
		$day = (string) ( $r['day'] ?? '' );
		if ( '' !== $fam && ( ! isset( $last[ $fam ] ) || strcmp( $day, $last[ $fam ] ) > 0 ) ) {
			$last[ $fam ] = $day;
		}
	}
	$table_rows = array();
	foreach ( $totals as $family => $hits ) {
		$table_rows[] = array( 'family' => (string) $family, 'reads' => number_format_i18n( (int) $hits ), 'last_seen' => (string) ( $last[ $family ] ?? '' ) );
	}
	return '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Reads per crawler family, last %s days.', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ) ) . '</p>'
		. \snt_kit_table(
			array(
				array( 'key' => 'family', 'label' => __( 'Family', 'signal-and-noise-tools' ) ),
				array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
				array( 'key' => 'last_seen', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ),
			),
			$table_rows
		);
}

/** @param array $rows @return string */
function machine_readers_surface_table_html( array $rows ) {
	$totals = \snt_mr_sum_hits_by( $rows, 'surface' );
	if ( empty( $totals ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No surface reads in this window yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$table_rows = array();
	foreach ( $totals as $surface => $hits ) {
		$table_rows[] = array( 'surface' => (string) $surface, 'reads' => number_format_i18n( (int) $hits ) );
	}
	return '<p class="snt-hint">' . \snt_kit_esc( __( 'Reads per machine surface class.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_table(
			array(
				array( 'key' => 'surface', 'label' => __( 'Surface', 'signal-and-noise-tools' ) ),
				array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
			),
			$table_rows
		);
}

/** @param array $rows @return string */
function machine_readers_compliance_html( array $rows ) {
	$ai     = \snt_mr_ai_training_families();
	$totals = array();
	$rights = array();
	foreach ( $rows as $r ) {
		$fam = (string) ( $r['family'] ?? '' );
		if ( ! in_array( $fam, $ai, true ) ) {
			continue;
		}
		$hits           = (int) ( $r['hits'] ?? 0 );
		$totals[ $fam ] = (int) ( $totals[ $fam ] ?? 0 ) + $hits;
		if ( 'rights' === (string) ( $r['surface'] ?? '' ) ) {
			$rights[ $fam ] = (int) ( $rights[ $fam ] ?? 0 ) + $hits;
		}
	}
	if ( empty( $totals ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No reads from declared AI-training families in this window.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	arsort( $totals );
	$table_rows = array();
	foreach ( $totals as $family => $hits ) {
		$table_rows[] = array( 'family' => (string) $family, 'observed' => number_format_i18n( (int) $hits ), 'rights' => number_format_i18n( (int) ( $rights[ $family ] ?? 0 ) ) );
	}
	$out  = '<p class="snt-hint">' . \snt_kit_esc( __( 'Observed vs declared: reads from AI-training crawler families.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= \snt_kit_table(
		array(
			array( 'key' => 'family', 'label' => __( 'Family', 'signal-and-noise-tools' ) ),
			array( 'key' => 'observed', 'label' => __( 'Observed reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'rights', 'label' => __( 'Rights reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$table_rows
	);
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Read counts are what the edge observed; the AI-training class comes from public declarations. User agents are self-reported, so this is observation, not proof of identity.', 'signal-and-noise-tools' ) ) . '</p>';
	return $out;
}

/** @param array $rows @return string '' when nothing to say. */
function machine_readers_reconciliation_html( array $rows ) {
	if ( \snt_mr_taxonomy_absent( $rows ) ) {
		return '';
	}
	$ai_families = \snt_mr_ai_training_families();
	$by_family   = 0;
	$by_purpose  = 0;
	foreach ( $rows as $r ) {
		$hits = (int) ( $r['hits'] ?? 0 );
		if ( in_array( (string) ( $r['family'] ?? '' ), $ai_families, true ) ) {
			$by_family += $hits;
		}
		if ( 'train' === (string) ( $r['purpose'] ?? '' ) ) {
			$by_purpose += $hits;
		}
	}
	if ( 0 === $by_family && 0 === $by_purpose ) {
		return '';
	}
	$table_rows = array(
		array( 'definition' => __( 'By crawler family (frozen)', 'signal-and-noise-tools' ), 'reads' => number_format_i18n( $by_family ) ),
		array( 'definition' => __( 'By declared purpose', 'signal-and-noise-tools' ), 'reads' => number_format_i18n( $by_purpose ) ),
	);
	$out = \snt_kit_table(
		array(
			array( 'key' => 'definition', 'label' => __( 'Definition', 'signal-and-noise-tools' ) ),
			array( 'key' => 'reads', 'label' => __( 'Reads', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$table_rows
	);
	$out = '<p class="snt-hint">' . \snt_kit_esc( __( 'AI-training reads, counted both ways.', 'signal-and-noise-tools' ) ) . '</p>' . $out;
	$delta = $by_family - $by_purpose;
	if ( 0 !== $delta ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'The family count is higher by %s. The frozen families match GoogleOther, MistralAI-Index and MistralAI-User, which their vendors document as generic, index-building and user-directed respectively. The family field is deliberately not corrected: a published figure depends on it. Cite the purpose count.', 'signal-and-noise-tools' ), number_format_i18n( abs( $delta ) ) ) ) . '</p>';
	}
	return $out;
}

/** @param array $stats sn_rss_tracker_window_stats_multi() shape. @return string */
function machine_readers_feed_table_html( array $stats ) {
	$windows = isset( $stats['windows'] ) && is_array( $stats['windows'] ) ? $stats['windows'] : array();
	if ( empty( $windows ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'No feed fetches recorded yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	ksort( $windows );
	$table_rows = array();
	foreach ( $windows as $win_days => $row ) {
		$row          = (array) $row;
		$table_rows[] = array(
			'window'   => sprintf( __( 'last %s days', 'signal-and-noise-tools' ), number_format_i18n( (int) $win_days ) ),
			'fetches'  => number_format_i18n( (int) ( $row['total'] ?? 0 ) ),
			'fetchers' => number_format_i18n( (int) ( $row['uniques'] ?? 0 ) ),
		);
	}
	$out = '<p class="snt-hint">' . \snt_kit_esc( __( 'Feed fetches per window (RSS and JSON Feed).', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= \snt_kit_table(
		array(
			array( 'key' => 'window', 'label' => __( 'Window', 'signal-and-noise-tools' ) ),
			array( 'key' => 'fetches', 'label' => __( 'Fetches', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'fetchers', 'label' => __( 'Fetchers', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$table_rows
	);
	if ( '' !== (string) ( $stats['most_recent'] ?? '' ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Most recent fetch:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( (string) $stats['most_recent'] ) . '</p>';
	}
	return $out;
}

/** @param array|null $info snt_mr_sensor_info() shape, or null. @return string */
function machine_readers_edge_readout_html( $info ) {
	$info  = is_array( $info ) ? $info : array();
	$label = 'sn-rights-signals' . ( '' !== (string) ( $info['version'] ?? '' ) ? ' v' . (string) $info['version'] : '' );
	$lines = '<p><b>' . \snt_kit_esc( __( 'Worker', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_code( $label, false ) . '</p>';
	if ( '' !== (string) ( $info['deployed_at'] ?? '' ) ) {
		$lines .= '<p><b>' . \snt_kit_esc( __( 'Deployed:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $info['deployed_at'] ) . '</p>';
	}
	if ( isset( $info['fetched_at'] ) ) {
		$lines .= '<p><b>' . \snt_kit_esc( __( 'Read:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $info['fetched_at'], time() ) ) ) . '</p>';
	}
	$lines .= '<p><em>' . \snt_kit_esc( __( 'Source:', 'signal-and-noise-tools' ) ) . '</em> ' . \snt_kit_code( defined( 'SN_MR_VERSION_ENDPOINT' ) ? SN_MR_VERSION_ENDPOINT : '', false ) . '</p>';
	return \snt_kit_notice( 'info', $lines );
}

/** @param array<string,mixed> $d machine_readers_data() output. @return string */
function machine_readers_settings_html( array $d ) {
	$snapshot = $d['token_locked']
		? __( 'token locked by constant', 'signal-and-noise-tools' )
		: ( $d['has_token'] ? __( 'token set', 'signal-and-noise-tools' ) : __( 'no token yet', 'signal-and-noise-tools' ) );

	$badge = $d['token_locked']
		? \snt_kit_badge( 'info', __( 'constant', 'signal-and-noise-tools' ) )
		: ( $d['has_token'] ? \snt_kit_badge( 'ok', __( 'set', 'signal-and-noise-tools' ) ) : \snt_kit_badge( 'warn', __( 'not set', 'signal-and-noise-tools' ) ) );

	$fields = \snt_kit_field(
		'url',
		'sn_mr_worker_url',
		__( 'Worker URL', 'signal-and-noise-tools' ),
		$d['stored_url'],
		array(
			'placeholder' => $d['default_url'],
			'disabled'    => $d['url_locked'],
			'hint'        => $d['url_locked']
				? __( 'Locked by the SN_MR_WORKER_URL constant in wp-config.php.', 'signal-and-noise-tools' )
				: __( 'Blank uses the built-in live endpoint. A SN_MR_WORKER_URL constant in wp-config.php overrides both.', 'signal-and-noise-tools' ),
		)
	);
	$fields .= \snt_kit_field(
		'password',
		'sn_mr_read_token',
		__( 'Read token', 'signal-and-noise-tools' ),
		'',
		array(
			'disabled' => $d['token_locked'],
			'hint'     => $d['token_locked']
				? __( 'Locked by the SN_MR_READ_TOKEN constant in wp-config.php.', 'signal-and-noise-tools' )
				: __( 'Write-only: the stored token is never shown here. Leave blank to keep the current value.', 'signal-and-noise-tools' ),
		)
	);

	$form = \snt_kit_form(
		'machine_readers_save',
		$badge . $fields,
		array(
			'submit' => __( 'Save sensor settings', 'signal-and-noise-tools' ),
			'hidden' => array( 'tab' => 'monitoring', 'sub' => 'machine-readers' ),
		)
	);

	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => __( 'Sensor settings', 'signal-and-noise-tools' ),
			'hint'    => $snapshot,
			'open'    => ! $d['has_token'] && ! $d['token_locked'],
		),
		$form
	);
}
