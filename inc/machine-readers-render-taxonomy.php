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
 * MR1 display cap for the rights-surface log — a PRESENTATION ceiling only,
 * deliberately distinct from the sensor's own envelope (reported as "at most
 * 500" in the table footer, and unchanged by this constant). The table is
 * sorted newest-first, so the cap truncates the OLD end and the remainder line
 * says so. Same shape as SN_HEALTH_MOTION_MAX_ROWS.
 */
if ( ! defined( 'SN_MR_RIGHTS_DISPLAY_MAX' ) ) {
	define( 'SN_MR_RIGHTS_DISPLAY_MAX', 50 );
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
		$out .= '<tr><td class="column-primary"><strong>' . esc_html( (string) $purpose ) . '</strong></td>'
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

	// MR3: the fold. Unlike the two logs, this table's cap was already REAL —
	// what it lacked was a summary saying how much sits inside, so the summary
	// carries the TRUE pair count while the table keeps showing the worst 20.
	// No empty fold is possible here: both the pre-taxonomy and the
	// nothing-attributed paths returned '' above, before this point.
	$out  = '<details class="sn-mr-vendor-purpose sn-disclosure"><summary>';
	$out .= esc_html(
		sprintf(
			/* translators: %s: the true number of agent/purpose pairs. */
			_n( '%s agent/purpose pair — show the breakdown', '%s agent/purpose pairs — show the breakdown', count( $pairs ), 'signal-and-noise-tools' ),
			number_format_i18n( count( $pairs ) )
		)
	);
	$out .= '</summary>';

	$out .= snt_mr_table_open( __( 'Reads by agent and purpose, third parties only.', 'signal-and-noise-tools' ), array(
		__( 'Vendor', 'signal-and-noise-tools' )  => '',
		__( 'Agent', 'signal-and-noise-tools' )   => '',
		__( 'Purpose', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' )   => 'num',
	) );
	foreach ( $shown as $key => $hits ) {
		list( $vendor, $purpose, $agent ) = array_pad( explode( '|', (string) $key, 3 ), 3, '' );
		$out                             .= '<tr><td class="column-primary"><strong>' . esc_html( $vendor ) . '</strong></td>'
			. '<td data-colname="Agent"><code>' . esc_html( '' !== $agent ? $agent : '—' ) . '</code></td>'
			. '<td data-colname="Purpose">' . esc_html( $purpose ) . '</td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) $hits ) ) . '</td></tr>';
	}
	$out .= '</tbody></table>';

	$hidden = count( $pairs ) - count( $shown );
	if ( $hidden > 0 ) {
		// House wording, and the vocabulary the TABLE uses: v10.80.0 keyed
		// these rows on the agent so GPTBot and ChatGPT-User stop collapsing
		// into one another, but this line still called them vendor/purpose
		// pairs. "Not shown" also read softer than the rest of the surface —
		// a cap is a cap, and the reader is told the list is incomplete.
		$out .= '<p class="description">' . esc_html( sprintf(
			/* translators: %s: number of additional agent/purpose pairs. */
			__( '+%s more agent/purpose pairs — the list is capped, not complete. Sorted busiest-first, so the tail is the quiet end.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $hidden )
		) ) . '</p>';
	}
	$out .= '</details>';
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
	$out .= '<tr><td class="column-primary"><strong>' . esc_html__( 'By crawler family (frozen)', 'signal-and-noise-tools' ) . '</strong></td>'
		. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( $by_family ) ) . '</td></tr>';
	$out .= '<tr><td class="column-primary"><strong>' . esc_html__( 'By declared purpose', 'signal-and-noise-tools' ) . '</strong></td>'
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
/**
 * One rights-log table. Extracted so the same shape renders twice: once for
 * external readers, once for our own CI traffic inside its fold.
 *
 * @param array $visible Rows to paint, already sorted and capped.
 * @return string
 */
/**
 * Our own CI traffic, folded away but declared.
 *
 * Hidden by default and never subtracted from any count: the leaf's KPI row
 * still counts the population it has always counted. This fold is what keeps
 * the default view quiet without the display lying about what it left out.
 *
 * @param array $ours Rows whose vendor is signal-and-noise.
 * @return string
 */
function snt_mr_rights_ours_fold( $ours ) {
	$ours = (array) $ours;
	if ( empty( $ours ) ) {
		return '';
	}
	return '<details class="sn-disclosure sn-mr-ours"><summary>'
		. esc_html( sprintf(
			/* translators: %s: number of reads from this site's own CI. */
			_n( '+%s read from our own CI — show it', '+%s reads from our own CI — show them', count( $ours ), 'signal-and-noise-tools' ),
			number_format_i18n( count( $ours ) )
		) )
		. '</summary>'
		. snt_mr_rights_table( array_slice( $ours, 0, SN_MR_RIGHTS_DISPLAY_MAX ) )
		. '</details>';
}

function snt_mr_rights_table( $visible ) {
	$out = '';
	$out .= snt_mr_table_open( __( 'Rights-surface reads, in full , who asked for the declarations, and for which document.', 'signal-and-noise-tools' ), array(
		__( 'When', 'signal-and-noise-tools' )       => '',
		__( 'Vendor', 'signal-and-noise-tools' )     => '',
		__( 'Purpose', 'signal-and-noise-tools' )    => '',
		__( 'Document', 'signal-and-noise-tools' )   => '',
		__( 'User agent', 'signal-and-noise-tools' ) => '',
	) );
	foreach ( $visible as $r ) {
		$when   = substr( preg_replace( '/[^0-9T:.\-Z]/', '', (string) ( $r['observed_at'] ?? '' ) ), 0, 20 );
		$vendor = snt_mr_normalize_vendor( $r['vendor'] ?? '' );
		$out   .= '<tr><td class="column-primary"><code>' . esc_html( $when ) . '</code></td>'
			. '<td data-colname="Vendor">' . esc_html( '' !== $vendor ? $vendor : '—' ) . '</td>'
			. '<td data-colname="Purpose">' . esc_html( (string) ( $r['purpose'] ?? 'unknown' ) ) . '</td>'
			. '<td data-colname="Document"><code>' . esc_html( substr( (string) ( $r['path'] ?? '' ), 0, 120 ) ) . '</code></td>'
			. '<td data-colname="User agent"><code>' . esc_html( substr( (string) ( $r['user_agent'] ?? '' ), 0, 200 ) ) . '</code></td></tr>';
	}
	$out .= '</tbody></table>';
	return $out;
}

function snt_mr_render_rights_detail( $rows, $limit = 500 ) {
	$rows = (array) $rows;
	if ( empty( $rows ) ) {
		// NO fold here, deliberately: a closed disclosure whose summary read
		// "0 events" would rhyme with a measured zero. An empty window is a
		// sentence, the same one it has always been.
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No reads of the rights surfaces in this window.', 'signal-and-noise-tools' ) . '</p>';
	}

	// Our own CI is not a machine reader. The hourly SignalNoise-SmokeTest run
	// fetches all three rights documents, so it writes three rows an hour and
	// buries the reads that mean something — on 2026-08-23 a single
	// OAI-SearchBot pass sat under a wall of our own traffic. The taxonomy
	// already tells them apart (vendor = signal-and-noise, purpose = ops); only
	// this renderer ignored it.
	//
	// They are HIDDEN, never dropped: the fold below declares how many there
	// were and opens on all of them, and no count anywhere else changes. A
	// number that quietly stops counting part of its population makes every
	// comparison across the change invalid.
	$ours     = array();
	$external = array();
	foreach ( $rows as $sn_mr_r ) {
		if ( 'signal-and-noise' === snt_mr_normalize_vendor( $sn_mr_r['vendor'] ?? '' ) ) {
			$ours[] = $sn_mr_r;
		} else {
			$external[] = $sn_mr_r;
		}
	}
	$rows = $external;

	if ( empty( $rows ) ) {
		// Every read in the window was ours. Say so plainly rather than
		// rendering an empty log that reads like nobody came at all.
		return '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'No external reads of the rights surfaces in this window.', 'signal-and-noise-tools' ) . '</p>'
			. snt_mr_rights_ours_fold( $ours );
	}

	// MR1: the fold. Until now $limit was PRINTED in the footer and never
	// applied — every row the sensor handed over rendered, fully open, in
	// arrival order, which is how a "capped at 500" log became a wall. The cap
	// is now real at the DISPLAY layer only: the sensor envelope, the view
	// allowlist, and snt_mr_normalize_rights_rows() are all untouched, and the
	// "at most %d" sentence below still names the sensor's own ceiling so a
	// tighter display cap cannot claim the sensor stores less than it does.
	$total = count( $rows );

	// Newest-first, on a COPY — the input array is never mutated (this file's
	// standing promise). A row with no parseable timestamp sorts LAST rather
	// than being handed an invented date to sort by: an unknown observation
	// time and an old one are different answers.
	$sorted = $rows;
	usort(
		$sorted,
		static function ( $a, $b ) {
			$at = (string) ( $a['observed_at'] ?? '' );
			$bt = (string) ( $b['observed_at'] ?? '' );
			if ( '' === $at || '' === $bt ) {
				// Undated always after dated; two undated keep their order.
				return ( '' === $at ? 1 : 0 ) - ( '' === $bt ? 1 : 0 );
			}
			return strcmp( $bt, $at ); // ISO-8601 sorts lexicographically.
		}
	);

	$visible = array_slice( $sorted, 0, SN_MR_RIGHTS_DISPLAY_MAX );
	$hidden  = $total - count( $visible );

	$out  = '<details class="sn-mr-rights-log sn-disclosure"><summary>';
	$out .= esc_html(
		sprintf(
			/* translators: %s: the true number of rights-surface events in the window. */
			_n( '%s external read — show the log', '%s external reads — show the log', $total, 'signal-and-noise-tools' ),
			number_format_i18n( $total )
		)
	);
	$out .= '</summary>';

	$out .= snt_mr_rights_table( $visible );

	if ( $hidden > 0 ) {
		$out .= '<p class="description">' . esc_html( sprintf(
			/* translators: %s: the number of events not shown. */
			__( '+%s more rights-surface events — the list is capped, not complete. Newest first, so the tail is the oldest end.', 'signal-and-noise-tools' ),
			number_format_i18n( $hidden )
		) ) . '</p>';
	}

	// The SENSOR's ceiling, kept verbatim beside the display cap: this number
	// describes what the edge stores, not what this table chose to paint.
	$out .= '<p class="description">' . esc_html( sprintf(
		/* translators: 1: rows shown, 2: the sensor cap. */
		__( 'Showing %1$s of at most %2$s events. These are the only reads the sensor records in full: rights surfaces are a closed set of fixed URLs, so the path identifies the document and nothing about the reader.', 'signal-and-noise-tools' ),
		number_format_i18n( count( $visible ) ),
		number_format_i18n( (int) $limit )
	) ) . '</p>';
	$out .= snt_mr_rights_ours_fold( $ours );
	$out .= '</details>';
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

	// MR2: the rows are built FIRST so the fold's summary can carry the number
	// that actually survives sanitisation. Counting $rows would promise more
	// than the fold contains whenever a UA normalizes to '' — the summary is a
	// claim about what is inside, not about what the sensor sent.
	$body  = '';
	$count = 0;
	foreach ( $rows as $r ) {
		$ua = snt_mr_normalize_ua_sample( $r['user_agent'] ?? ( $r['ua_sample'] ?? '' ) );
		if ( '' === $ua ) {
			continue;
		}
		++$count;
		$body .= '<tr><td class="column-primary"><code>' . esc_html( $ua ) . '</code></td>'
			. '<td class="num" data-colname="Reads">' . esc_html( number_format_i18n( (int) ( $r['hits'] ?? 0 ) ) ) . '</td></tr>';
	}

	// Fold only when there is something to fold. If every agent normalized
	// away, the bucket was NOT empty — it held something the sanitizer could
	// not render — so the block stays open and keeps saying "Showing 0 of at
	// most 50" rather than claiming a clean window it cannot claim, and rather
	// than offering a "0 agents" disclosure that would rhyme with a measured
	// zero. RULE 2's requirement is that the bucket stay inspectable; folding
	// the ROWS honours it, hiding the FACT of the bucket would not.
	$fold = $count > 0;
	$out  = '';
	if ( $fold ) {
		$out .= '<details class="sn-mr-unknown-log sn-disclosure"><summary>';
		$out .= esc_html(
			sprintf(
				/* translators: %s: number of unclassified user agents shown. */
				_n( '%s unclassified user agent — show the review list', '%s unclassified user agents — show the review list', $count, 'signal-and-noise-tools' ),
				number_format_i18n( $count )
			)
		);
		$out .= '</summary>';
	}

	$out .= snt_mr_table_open( __( 'Unclassified user agents, by volume — review these to extend the taxonomy.', 'signal-and-noise-tools' ), array(
		__( 'User agent (sampled, sanitized)', 'signal-and-noise-tools' ) => '',
		__( 'Reads', 'signal-and-noise-tools' ) => 'num',
	) );
	$out .= $body;
	$out .= '</tbody></table>';
	$out .= '<p class="description">' . esc_html( sprintf(
		/* translators: 1: rows shown, 2: the sensor's cap. */
		__( 'Showing %1$s of at most %2$s sampled agents. Strings are truncated to 96 characters and stripped to a safe character set at the edge, so they are a review aid, not a verbatim log.', 'signal-and-noise-tools' ),
		number_format_i18n( $count ),
		number_format_i18n( (int) $limit )
	) ) . '</p>';
	if ( $fold ) {
		$out .= '</details>';
	}
	return $out;
}
