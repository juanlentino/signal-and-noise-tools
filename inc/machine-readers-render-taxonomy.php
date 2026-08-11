<?php
/**
 * Renderers for the vendor/purpose axes (v10.79.0). Pure: every function takes
 * normalized rows and returns HTML, escaping every cell. No fetching, no state.
 *
 * @package Signal_and_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sum hits per purpose, excluding the site's own first-party monitoring.
 *
 * The exclusion is the point: at v1.11.0 the owner's Better Stack monitor was
 * 6,403 of 17,463 reads (37%), and a "machine readership" figure that silently
 * counts the site watching itself is measuring the wrong thing. Excluded, not
 * hidden — snt_mr_render_purpose_table prints the excluded total underneath.
 *
 * @param array $rows Normalized rows.
 * @return array{purposes:array<string,int>,first_party:int}
 */
function snt_mr_purpose_totals( $rows ) {
	$out   = array();
	$first = 0;
	foreach ( (array) $rows as $r ) {
		$hits = (int) ( $r['hits'] ?? 0 );
		if ( ! empty( $r['first_party'] ) ) {
			$first += $hits;
			continue;
		}
		$purpose         = (string) ( $r['purpose'] ?? 'unknown' );
		$out[ $purpose ] = (int) ( $out[ $purpose ] ?? 0 ) + $hits;
	}
	arsort( $out );
	return array( 'purposes' => $out, 'first_party' => $first );
}

/**
 * Reads per purpose — the axis the published claims run along.
 *
 * @param array $rows Normalized rows.
 * @param int   $days Window, for the caption.
 * @return string HTML.
 */
function snt_mr_render_purpose_table( $rows, $days ) {
	if ( snt_mr_taxonomy_absent( $rows ) ) {
		// Never-measured is not measured-zero. An older Worker means this
		// surface has no answer, which must not render as a table of zeroes.
		return '<p class="sn-an-empty sn-an-empty--note">'
			. esc_html__( 'The deployed sensor predates the vendor/purpose taxonomy, so no purpose data exists for this window. This is not a measured zero.', 'signal-and-noise-tools' )
			. '</p>';
	}

	$totals = snt_mr_purpose_totals( $rows );
	if ( empty( $totals['purposes'] ) ) {
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No third-party machine reads in this window.', 'signal-and-noise-tools' ) . '</p>';
	}

	/* translators: %d: number of days in the window. */
	$caption = sprintf( __( 'Reads by purpose, last %d days.', 'signal-and-noise-tools' ), (int) $days );
	$out     = snt_mr_table_open( $caption, array(
		__( 'Purpose', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $totals['purposes'] as $purpose => $hits ) {
		$out .= '<tr><td class="column-primary" data-colname="Purpose"><strong>' . esc_html( (string) $purpose ) . '</strong></td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td></tr>';
	}
	$out .= '</tbody></table>';

	if ( $totals['first_party'] > 0 ) {
		$out .= '<p class="description">' . esc_html( sprintf(
			/* translators: %s: formatted read count. */
			__( 'Excludes %s reads from this site\'s own uptime monitoring, which is the site measuring itself rather than readership.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $totals['first_party'] )
		) ) . '</p>';
	}
	return $out;
}

/**
 * Vendor x purpose, the two axes crossed. One vendor legitimately occupies
 * several rows: openai/train, openai/search and openai/user are three distinct
 * readerships, and collapsing them is what the old single axis did.
 *
 * @param array $rows  Normalized rows.
 * @param int   $limit Rows to show.
 * @return string HTML.
 */
function snt_mr_render_vendor_purpose_table( $rows, $limit = 20 ) {
	if ( snt_mr_taxonomy_absent( $rows ) ) {
		return '';
	}
	$pairs = array();
	foreach ( (array) $rows as $r ) {
		$vendor = (string) ( $r['vendor'] ?? '' );
		if ( '' === $vendor ) {
			continue; // Unattributed reads belong in the unknown review, not here.
		}
		if ( ! empty( $r['first_party'] ) ) {
			continue; // Self-traffic is not readership; the purpose table reports it separately.
		}
		// v10.80.0: keyed on the AGENT where the sensor supplies one. A vendor row
		// alone cannot distinguish GPTBot from ChatGPT-User, which is the entire
		// question the purpose axis exists to answer.
		$agent          = (string) ( $r['agent'] ?? '' );
		$key            = $vendor . '|' . (string) ( $r['purpose'] ?? 'unknown' ) . '|' . $agent;
		$pairs[ $key ]  = (int) ( $pairs[ $key ] ?? 0 ) + (int) ( $r['hits'] ?? 0 );
	}
	if ( empty( $pairs ) ) {
		return '';
	}
	arsort( $pairs );
	$shown = array_slice( $pairs, 0, max( 1, (int) $limit ), true );

	$out = snt_mr_table_open( __( 'Reads by agent and purpose, third parties only.', 'signal-and-noise-tools' ), array(
		__( 'Vendor', 'signal-and-noise-tools' )  => '',
		__( 'Agent', 'signal-and-noise-tools' )   => '',
		__( 'Purpose', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $shown as $key => $hits ) {
		list( $vendor, $purpose, $agent ) = array_pad( explode( '|', (string) $key, 3 ), 3, '' );
		$out                             .= '<tr><td class="column-primary" data-colname="Vendor"><strong>' . esc_html( $vendor ) . '</strong></td>'
			. '<td data-colname="Agent"><code>' . esc_html( '' !== $agent ? $agent : '—' ) . '</code></td>'
			. '<td data-colname="Purpose">' . esc_html( $purpose ) . '</td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td></tr>';
	}
	$out .= '</tbody></table>';

	$hidden = count( $pairs ) - count( $shown );
	if ( $hidden > 0 ) {
		$out .= '<p class="description">' . esc_html( sprintf(
			/* translators: %s: number of additional vendor/purpose pairs. */
			__( '%s further vendor/purpose pairs are not shown.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $hidden )
		) ) . '</p>';
	}
	return $out;
}

/**
 * The same question asked two ways, side by side.
 *
 * The frozen `family` classifier calls googleother, MistralAI-Index and
 * MistralAI-User AI-training crawlers; the vendors' own docs do not. The
 * purpose axis is the correction, but a correction nobody sees is not one, so
 * the two readings sit next to each other and the gap between them is printed
 * rather than reconciled. That gap IS the finding.
 *
 * @param array $rows Normalized rows.
 * @return string HTML.
 */
function snt_mr_render_ai_reconciliation( $rows ) {
	if ( snt_mr_taxonomy_absent( $rows ) ) {
		return '';
	}
	$ai_families = function_exists( 'snt_mr_ai_training_families' ) ? snt_mr_ai_training_families() : array();
	$by_family   = 0;
	$by_purpose  = 0;
	foreach ( (array) $rows as $r ) {
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

	$out = snt_mr_table_open( __( 'AI-training reads, counted both ways.', 'signal-and-noise-tools' ), array(
		__( 'Definition', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )      => 'num',
	) );
	$out .= '<tr><td class="column-primary" data-colname="Definition"><strong>' . esc_html__( 'By crawler family (frozen)', 'signal-and-noise-tools' ) . '</strong></td>'
		. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( $by_family ) ) . '</td></tr>';
	$out .= '<tr><td class="column-primary" data-colname="Definition"><strong>' . esc_html__( 'By declared purpose', 'signal-and-noise-tools' ) . '</strong></td>'
		. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( $by_purpose ) ) . '</td></tr>';
	$out .= '</tbody></table>';

	$delta = $by_family - $by_purpose;
	if ( 0 !== $delta ) {
		$out .= '<p class="description">' . esc_html( sprintf(
			/* translators: %s: the difference between the two counts. */
			__( 'The family count is higher by %s. The frozen families match GoogleOther, MistralAI-Index and MistralAI-User, which their vendors document as generic, index-building and user-directed respectively. The family field is deliberately not corrected: a published figure depends on it. Cite the purpose count.', 'signal-and-noise-tools' ),
			number_format_i18n( abs( $delta ) )
		) ) . '</p>';
	}
	return $out;
}

/**
 * RULE 3, made readable: the rights-surface events in full.
 *
 * Logging these and giving nobody a way to read them would repeat the exact
 * failure RULE 2 exists to fix. Rare enough to list individually (~80 per 30
 * days), and these are the events the published claim rests on.
 *
 * Every field here is FULL FIDELITY at the edge, including a complete
 * User-Agent with no character allowlist, so this renderer is the only line of
 * defence and escapes all of it.
 *
 * @param array $rows  Rows from the 'rights' view.
 * @param int   $limit Sensor cap, reported.
 * @return string HTML.
 */
function snt_mr_render_rights_detail( $rows, $limit = 500 ) {
	$rows = (array) $rows;
	if ( empty( $rows ) ) {
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No reads of the rights surfaces in this window.', 'signal-and-noise-tools' ) . '</p>';
	}
	$out = snt_mr_table_open( __( 'Rights-surface reads, in full , who asked for the declarations, and for which document.', 'signal-and-noise-tools' ), array(
		__( 'When', 'signal-and-noise-tools' )       => '',
		__( 'Vendor', 'signal-and-noise-tools' )     => '',
		__( 'Purpose', 'signal-and-noise-tools' )    => '',
		__( 'Document', 'signal-and-noise-tools' )   => '',
		__( 'User agent', 'signal-and-noise-tools' ) => '',
	) );
	$shown = 0;
	foreach ( $rows as $r ) {
		++$shown;
		$when   = substr( preg_replace( '/[^0-9T:.\-Z]/', '', (string) ( $r['observed_at'] ?? '' ) ), 0, 20 );
		$vendor = snt_mr_normalize_vendor( $r['vendor'] ?? '' );
		$out   .= '<tr><td class="column-primary" data-colname="When"><code>' . esc_html( $when ) . '</code></td>'
			. '<td data-colname="Vendor">' . esc_html( '' !== $vendor ? $vendor : '—' ) . '</td>'
			. '<td data-colname="Purpose">' . esc_html( (string) ( $r['purpose'] ?? 'unknown' ) ) . '</td>'
			. '<td data-colname="Document"><code>' . esc_html( substr( (string) ( $r['path'] ?? '' ), 0, 120 ) ) . '</code></td>'
			. '<td data-colname="User agent"><code>' . esc_html( substr( (string) ( $r['user_agent'] ?? '' ), 0, 200 ) ) . '</code></td></tr>';
	}
	$out .= '</tbody></table>';
	$out .= '<p class="description">' . esc_html( sprintf(
		/* translators: 1: rows shown, 2: the sensor cap. */
		__( 'Showing %1$s of at most %2$s events. These are the only reads the sensor records in full: rights surfaces are a closed set of fixed URLs, so the path identifies the document and nothing about the reader.', 'signal-and-noise-tools' ),
		number_format_i18n( $shown ),
		number_format_i18n( (int) $limit )
	) ) . '</p>';
	return $out;
}

/**
 * RULE 2: the unclassified bucket, made inspectable.
 *
 * `other-bot` has been the second-largest bucket on this surface and entirely
 * opaque. A bucket nobody can look into is not a measurement, so the top
 * unmatched user-agent strings are listed here by volume, to be read and turned
 * into taxonomy entries. Every string is sanitized twice (Worker allowlist,
 * then snt_mr_normalize_ua_sample) and escaped here a third time.
 *
 * @param array $rows  Rows from the 'unknown' view.
 * @param int   $limit The Worker's cap, reported so truncation is never silent.
 * @return string HTML.
 */
function snt_mr_render_unknown_agents( $rows, $limit = 50 ) {
	$rows = (array) $rows;
	if ( empty( $rows ) ) {
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'Every machine read in this window matched the taxonomy. Nothing to review.', 'signal-and-noise-tools' ) . '</p>';
	}

	$out = snt_mr_table_open( __( 'Unclassified user agents, by volume — review these to extend the taxonomy.', 'signal-and-noise-tools' ), array(
		__( 'User agent (sampled, sanitized)', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' ) => 'num',
	) );
	$count = 0;
	foreach ( $rows as $r ) {
		$ua = snt_mr_normalize_ua_sample( $r['user_agent'] ?? ( $r['ua_sample'] ?? '' ) );
		if ( '' === $ua ) {
			continue;
		}
		++$count;
		$out .= '<tr><td class="column-primary" data-colname="User agent"><code>' . esc_html( $ua ) . '</code></td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) ( $r['hits'] ?? 0 ) ) ) . '</td></tr>';
	}
	$out .= '</tbody></table>';
	$out .= '<p class="description">' . esc_html( sprintf(
		/* translators: 1: rows shown, 2: the sensor's cap. */
		__( 'Showing %1$s of at most %2$s sampled agents. Strings are truncated to 96 characters and stripped to a safe character set at the edge, so they are a review aid, not a verbatim log.', 'signal-and-noise-tools' ),
		number_format_i18n( $count ),
		number_format_i18n( (int) $limit )
	) ) . '</p>';
	return $out;
}
