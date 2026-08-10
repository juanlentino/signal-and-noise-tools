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
		$key            = $vendor . '|' . (string) ( $r['purpose'] ?? 'unknown' );
		$pairs[ $key ]  = (int) ( $pairs[ $key ] ?? 0 ) + (int) ( $r['hits'] ?? 0 );
	}
	if ( empty( $pairs ) ) {
		return '';
	}
	arsort( $pairs );
	$shown = array_slice( $pairs, 0, max( 1, (int) $limit ), true );

	$out = snt_mr_table_open( __( 'Reads by vendor and purpose.', 'signal-and-noise-tools' ), array(
		__( 'Vendor', 'signal-and-noise-tools' )  => '',
		__( 'Purpose', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $shown as $key => $hits ) {
		list( $vendor, $purpose ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
		$out                     .= '<tr><td class="column-primary" data-colname="Vendor"><strong>' . esc_html( $vendor ) . '</strong></td>'
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
