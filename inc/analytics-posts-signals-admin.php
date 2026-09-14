<?php
/**
 * Signal & Noise — the Posts tab RENDER layer over sn_analytics_posts_signals().
 * (v14.6.0; replaces analytics-posts-admin.php + analytics-posts-lifecycle-admin.php)
 *
 * Four pieces, top to bottom:
 *   1. The header strip: machine reads (site-wide, said so), the Search
 *      Console window, the coverage run. Read once, never per row.
 *   2. Counts: indexed / with impressions / zero inbound / stale crawl / total.
 *   3. The queue: ONLY notes carrying a flag, most urgent first, each row
 *      naming its flags and the fix each implies. Everything else sits
 *      behind "show all notes".
 *   4. The table: one row per note, every column sortable client-side
 *      (assets/admin.js reads data-sort on the th and data-v on the td),
 *      the full title, never truncated.
 *
 * COPY RULES. No em dashes. A gap is a short muted REASON, never a glyph,
 * never a zero. Flag labels are states, not verbs: a pill that reads
 * "Refresh" looks clickable and was misread as a button.
 *
 * @package signal-and-noise-tools
 * @since 14.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';

/** The flag vocabulary: label, tone, the fix it implies. One place. */
function sn_posts_flag_vocab() {
	return array(
		'not_indexed' => array( 'label' => __( 'Not indexed', 'signal-and-noise-tools' ), 'tone' => 'err' ),
		'stale_crawl' => array( 'label' => __( 'Stale crawl', 'signal-and-noise-tools' ), 'tone' => 'warn' ),
		'orphaned'    => array( 'label' => __( 'Orphaned', 'signal-and-noise-tools' ), 'tone' => 'warn' ),
	);
}

/**
 * The fix a flag implies, in one sentence. "Discovered" and "Crawled"
 * not-indexed states get different sentences because the fixes differ:
 * the first is crawl priority, the second is a quality signal.
 *
 * @param string              $flag
 * @param array<string,mixed> $row
 * @return string
 */
function sn_posts_flag_fix( $flag, array $row ) {
	if ( 'not_indexed' === $flag ) {
		$state = (string) ( $row['coverage_state'] ?? '' );
		if ( 0 === stripos( $state, 'Discovered' ) ) {
			return __( 'Google knows the URL and has not crawled it: link to it from an indexed note and request indexing.', 'signal-and-noise-tools' );
		}
		if ( 0 === stripos( $state, 'Crawled' ) ) {
			return __( 'Google crawled it and chose not to index it: a quality signal. Deepen or merge the note before requesting again.', 'signal-and-noise-tools' );
		}
		return __( 'Not in the index: read the coverage state and request indexing.', 'signal-and-noise-tools' );
	}
	if ( 'stale_crawl' === $flag ) {
		return __( 'The last crawl predates the last body change: Google is serving the old text. Request indexing in Search Console.', 'signal-and-noise-tools' );
	}
	return __( 'At most one inbound internal link: link to it from a related note.', 'signal-and-noise-tools' );
}

/**
 * A gap cell: the reason, muted, with the same text as its title.
 *
 * @param string $why
 * @return string HTML.
 */
function sn_posts_gap( $why ) {
	// The cell shows the short form; the full reason rides the title. An
	// unknown reason shows in full rather than being cut.
	$short = array(
		'not shown by Google in this window'          => __( 'not shown', 'signal-and-noise-tools' ),
		'not among the rows the sync keeps'           => __( 'not in sync', 'signal-and-noise-tools' ),
		'not inspected in the last run'               => __( 'not inspected', 'signal-and-noise-tools' ),
		'not yet inspected: a run is in progress'     => __( 'not yet inspected', 'signal-and-noise-tools' ),
		'coverage inspection has never run'           => __( 'never inspected', 'signal-and-noise-tools' ),
		'Google gave no coverage state'               => __( 'no state', 'signal-and-noise-tools' ),
		'signed, no confirmed anchor yet'             => __( 'not anchored yet', 'signal-and-noise-tools' ),
		'not in the kernel yet: built before this note' => __( 'not in kernel', 'signal-and-noise-tools' ),
	);
	$text = isset( $short[ $why ] ) ? $short[ $why ] : $why;
	return '<span class="sn-an-muted sn-posts-gap" title="' . esc_attr( $why ) . '">' . esc_html( $text ) . '</span>';
}

/**
 * A pill for a state. Never a verb.
 *
 * @param string $label
 * @param string $tone  ok|warn|err|muted.
 * @param string $title
 * @return string HTML.
 */
function sn_posts_pill( $label, $tone, $title = '' ) {
	return '<span class="sn-pill sn-pill--' . esc_attr( $tone ) . '"' . ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>' . esc_html( $label ) . '</span>';
}

/**
 * The whole tab.
 *
 * @param array|null $signals From sn_analytics_posts_signals().
 */
function snt_analytics_render_posts_signals_view( $signals ) {
	if ( ! is_array( $signals ) || empty( $signals['rows'] ) ) {
		snt_an_gate(
			__( 'Posts', 'signal-and-noise-tools' ),
			__( 'No published notes yet: this view reads each note against Search Console, the coverage inspection, the link graph and the provenance chain once you publish.', 'signal-and-noise-tools' )
		);
		return;
	}
	sn_posts_render_strip( (array) $signals['strip'], (array) $signals['counts'] );
	sn_posts_render_queue( (array) $signals['rows'] );
	sn_posts_render_table( (array) $signals['rows'], (array) $signals['strip'] );
	snt_an_flush_empty_fold();
}

/**
 * Piece 1 + 2: the header strip and the counts, one panel.
 *
 * @param array<string,mixed> $strip
 * @param array<string,int>   $counts
 */
function sn_posts_render_strip( array $strip, array $counts ) {
	snt_an_panel_open( __( 'Site-wide context', 'signal-and-noise-tools' ), array( 'panel_class' => 'sn-overview', 'inside_class' => 'inside inside-flush sn-an-panel' ) );

	$mr   = (array) $strip['machine_reads'];
	$days = (int) $strip['machine_reads_days'];
	$gw   = is_array( $strip['gsc_window'] ) ? $strip['gsc_window'] : null;
	$cr   = is_array( $strip['coverage_run'] ) ? $strip['coverage_run'] : null;

	$cards = array(
		array(
			'l'        => sprintf( /* translators: %d: days. */ __( 'Machine reads, site-wide, %d days', 'signal-and-noise-tools' ), $days ),
			'n'        => null === $mr['value'] ? $mr['why'] : number_format_i18n( (int) $mr['value'] ),
			'sub'      => ! empty( $strip['machine_reads_stale'] ) ? __( 'snapshot stale', 'signal-and-noise-tools' ) : __( 'not counted per note, by the sensor\'s privacy contract', 'signal-and-noise-tools' ),
			'promoted' => true,
		),
		array(
			'l'   => __( 'Search Console window', 'signal-and-noise-tools' ),
			'n'   => $gw ? sprintf( /* translators: 1: start date, 2: end date. */ __( '%1$s to %2$s', 'signal-and-noise-tools' ), $gw['start'], $gw['end'] ) : __( 'never synced', 'signal-and-noise-tools' ),
			'sub' => __( 'impressions, clicks, position below', 'signal-and-noise-tools' ),
		),
		array(
			'l'   => __( 'Coverage inspection', 'signal-and-noise-tools' ),
			'n'   => $cr && $cr['finished_at'] > 0 ? gmdate( 'Y-m-d', (int) $cr['finished_at'] ) : __( 'never run', 'signal-and-noise-tools' ),
			'sub' => $cr ? sprintf( /* translators: 1: urls inspected, 2: errors. */ __( '%1$d URLs inspected, %2$d errors', 'signal-and-noise-tools' ), (int) $cr['inspected'], (int) $cr['errors'] ) : '',
		),
	);
	snt_an_kpi_row( $cards, array( 'empty_slot' => 'omit' ) );

	$c = $counts;
	snt_an_kpi_row( array(
		array( 'l' => __( 'Notes', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $c['total'] ) ),
		array( 'l' => __( 'Indexed', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $c['indexed'] ), 'sub' => $c['not_inspected'] > 0 ? sprintf( /* translators: %d: count. */ _n( '%d not inspected', '%d not inspected', (int) $c['not_inspected'], 'signal-and-noise-tools' ), (int) $c['not_inspected'] ) : '' ),
		array( 'l' => __( 'With impressions', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $c['with_impressions'] ), 'sub' => __( 'in the Search Console window', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Zero inbound links', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $c['zero_inbound'] ) ),
		array( 'l' => __( 'Stale crawls', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $c['stale_crawl'] ) ),
	), array( 'empty_slot' => 'omit' ) );
	snt_an_panel_close();
}

/**
 * Piece 3: the queue. Flagged notes only, most urgent first.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function sn_posts_render_queue( array $rows ) {
	$vocab   = sn_posts_flag_vocab();
	$flagged = array_values( array_filter( $rows, static function ( $r ) {
		return in_array( true, (array) ( $r['flags'] ?? array() ), true );
	} ) );
	usort( $flagged, static function ( $a, $b ) {
		$d = sn_analytics_posts_severity( (array) $b['flags'] ) - sn_analytics_posts_severity( (array) $a['flags'] );
		return 0 !== $d ? $d : strcmp( (string) $a['title'], (string) $b['title'] );
	} );

	snt_an_panel_open(
		__( 'Queue', 'signal-and-noise-tools' ),
		array( 'inside_class' => 'inside sn-an-table-inside', 'header_meta' => sprintf( /* translators: 1: flagged, 2: total. */ __( '%1$d of %2$d notes carry a flag', 'signal-and-noise-tools' ), count( $flagged ), count( $rows ) ) )
	);
	if ( array() === $flagged ) {
		echo '<p class="sn-an-foot">' . esc_html__( 'No note carries a flag: every inspected note is indexed, crawled since its last edit, and linked from at least two others.', 'signal-and-noise-tools' ) . '</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr>'
			. '<th scope="col" class="manage-column column-primary">' . esc_html__( 'Note', 'signal-and-noise-tools' ) . '</th>'
			. '<th scope="col" class="manage-column">' . esc_html__( 'Flags', 'signal-and-noise-tools' ) . '</th>'
			. '<th scope="col" class="manage-column">' . esc_html__( 'What it implies', 'signal-and-noise-tools' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $flagged as $r ) {
			$pills = '';
			$fixes = array();
			foreach ( $vocab as $flag => $v ) {
				if ( ! empty( $r['flags'][ $flag ] ) ) {
					$pills  .= sn_posts_pill( $v['label'], $v['tone'], 'not_indexed' === $flag ? (string) $r['coverage_state'] : '' ) . ' ';
					$fixes[] = sn_posts_flag_fix( $flag, $r );
				}
			}
			echo '<tr><td class="column-primary"><a href="' . esc_url( (string) $r['permalink'] ) . '"><strong>' . esc_html( (string) $r['title'] ) . '</strong></a></td>'
				. '<td>' . trim( $pills ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from sn_posts_pill(), escaped there.
				. '<td>' . esc_html( implode( ' ', $fixes ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	snt_an_panel_close();
}

/**
 * Piece 4: the table. Every th carries data-sort (num|text|date); every td a
 * data-v the sort reads, so a gap sorts LAST whichever way the column goes.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,mixed>            $strip For the window label, once.
 */
function sn_posts_render_table( array $rows, array $strip ) {
	$vocab = sn_posts_flag_vocab();
	$gw    = is_array( $strip['gsc_window'] ) ? $strip['gsc_window'] : null;
	$win   = $gw ? sprintf( /* translators: 1: start, 2: end. */ __( 'Search Console, %1$s to %2$s', 'signal-and-noise-tools' ), $gw['start'], $gw['end'] ) : __( 'Search Console, never synced', 'signal-and-noise-tools' );

	$cols = array(
		array( 'l' => __( 'Note', 'signal-and-noise-tools' ), 's' => 'text', 'c' => 'column-primary' ),
		array( 'l' => __( 'Age', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num' ),
		array( 'l' => __( 'Words', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num' ),
		array( 'l' => __( 'Index', 'signal-and-noise-tools' ), 's' => 'text', 'c' => '' ),
		array( 'l' => __( 'Last crawl', 'signal-and-noise-tools' ), 's' => 'date', 'c' => '' ),
		array( 'l' => __( 'Impr', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num', 't' => $win ),
		array( 'l' => __( 'Clicks', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num', 't' => $win ),
		array( 'l' => __( 'Pos', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num', 't' => $win ),
		array( 'l' => __( 'Inbound', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num' ),
		array( 'l' => __( 'Anchor', 'signal-and-noise-tools' ), 's' => 'num', 'c' => '' ),
		array( 'l' => __( 'Related', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num', 't' => __( 'ML kernel: related notes in the index, and the top similarity score', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Views', 'signal-and-noise-tools' ), 's' => 'num', 'c' => 'num', 't' => __( 'Lifetime human pageviews. A raw count, not a verdict.', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Flags', 'signal-and-noise-tools' ), 's' => 'num', 'c' => '' ),
	);

	// Collapsed by default: the queue above is the work; this is the reference.
	// The panel remembers the reader's choice (admin.js, per panel, localStorage).
	snt_an_panel_open( __( 'Show all notes', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside sn-an-table-inside', 'header_meta' => $win, 'collapsible' => true, 'collapsed' => true ) );
	echo '<div class="snt-scroll-table"><table class="widefat striped sn-posts-table" data-sortable><thead><tr>';
	foreach ( $cols as $col ) {
		echo '<th scope="col" class="manage-column ' . esc_attr( $col['c'] ) . '" data-sort="' . esc_attr( $col['s'] ) . '"' . ( isset( $col['t'] ) ? ' title="' . esc_attr( $col['t'] ) . '"' : '' ) . '><button type="button" class="sn-posts-sort">' . esc_html( $col['l'] ) . '</button></th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$cells  = sn_posts_td( '<a href="' . esc_url( (string) $r['permalink'] ) . '"><strong>' . esc_html( (string) $r['title'] ) . '</strong></a>', (string) $r['title'], 'column-primary' );
		$cells .= sn_posts_td_num( $r['age'], 'd' );
		$cells .= sn_posts_td_num( $r['words'] );
		$cells .= sn_posts_td_index( $r );
		$cells .= sn_posts_td_crawl( $r );
		$cells .= sn_posts_td_num( $r['impressions'] );
		$cells .= sn_posts_td_num( $r['clicks'] );
		$cells .= sn_posts_td_num( $r['position'], '', 1 );
		$cells .= sn_posts_td_num( $r['inbound'] );
		$cells .= sn_posts_td_anchor( $r );
		$cells .= sn_posts_td_related( $r );
		$cells .= sn_posts_td_num( $r['views'] );
		$pills  = '';
		$n      = 0;
		foreach ( $vocab as $flag => $v ) {
			if ( ! empty( $r['flags'][ $flag ] ) ) {
				$pills .= sn_posts_pill( $v['label'], $v['tone'] ) . ' ';
				++$n;
			}
		}
		$cells .= sn_posts_td( trim( $pills ), (string) $n );
		echo '<tr>' . $cells . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell is built by the sn_posts_td_* helpers above, which escape each value they place (esc_html / esc_attr / esc_url) and compose only their own markup.
	}
	echo '</tbody></table></div>';
	snt_an_panel_close();
}

/** @internal */
function sn_posts_td( $html, $sort_value, $class = '' ) {
	return '<td' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' data-v="' . esc_attr( (string) $sort_value ) . '">' . $html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped/built markup.
}

/** @internal A numeric field, or its gap. */
function sn_posts_td_num( array $f, $suffix = '', $decimals = 0 ) {
	if ( null === $f['value'] ) {
		return sn_posts_td( sn_posts_gap( $f['why'] ), '', 'num' );
	}
	return sn_posts_td( esc_html( number_format_i18n( (float) $f['value'], $decimals ) . $suffix ), (string) $f['value'], 'num' );
}

/** @internal Index: indexed / not indexed / no coverage state, coverage_state verbatim as the title. */
function sn_posts_td_index( array $r ) {
	$f = $r['index'];
	if ( '' !== (string) $f['why'] && '' === (string) $r['coverage_state'] ) {
		return sn_posts_td( sn_posts_gap( $f['why'] ), '' );
	}
	$state = (string) $r['coverage_state'];
	if ( true === $f['value'] ) {
		return sn_posts_td( sn_posts_pill( __( 'Indexed', 'signal-and-noise-tools' ), 'ok', $state ), '2 ' . __( 'Indexed', 'signal-and-noise-tools' ) );
	}
	if ( false === $f['value'] ) {
		$label = 0 === stripos( $state, 'Discovered' ) ? __( 'Discovered, not indexed', 'signal-and-noise-tools' ) : ( 0 === stripos( $state, 'Crawled' ) ? __( 'Crawled, not indexed', 'signal-and-noise-tools' ) : __( 'Not indexed', 'signal-and-noise-tools' ) );
		return sn_posts_td( sn_posts_pill( $label, 'err', $state ), '0 ' . $label );
	}
	return sn_posts_td( sn_posts_pill( __( 'No coverage state', 'signal-and-noise-tools' ), 'muted', $state ), '1 ' . __( 'No coverage state', 'signal-and-noise-tools' ) );
}

/** @internal Last crawl: the date, marked when it predates the last edit. */
function sn_posts_td_crawl( array $r ) {
	$f = $r['last_crawl'];
	if ( null === $f['value'] ) {
		return sn_posts_td( sn_posts_gap( $f['why'] ), '' );
	}
	$date  = gmdate( 'Y-m-d', (int) $f['value'] );
	$stale = ! empty( $r['flags']['stale_crawl'] );
	$html  = $stale
		? '<span class="sn-posts-stale" title="' . esc_attr( sprintf( /* translators: %s: date. */ __( 'Body changed %s, after this crawl', 'signal-and-noise-tools' ), gmdate( 'Y-m-d', (int) ( $r['body_changed_ts'] ?? $r['modified_ts'] ) ) ) ) . '">' . esc_html( $date ) . '</span>'
		: esc_html( $date );
	return sn_posts_td( $html, $date );
}

/** @internal Anchor: version and block, green when signed and anchored by the followed key. */
function sn_posts_td_anchor( array $r ) {
	$f = $r['anchor'];
	if ( null === $f['value'] ) {
		return sn_posts_td( sn_posts_gap( $f['why'] ), '' );
	}
	$a     = (array) $f['value'];
	$label = sprintf( /* translators: 1: version, 2: block. */ __( 'v%1$d at %2$s', 'signal-and-noise-tools' ), (int) $a['version'], number_format_i18n( (int) $a['block'] ) );
	if ( true === $a['followed_key'] ) {
		return sn_posts_td( sn_posts_pill( $label, 'ok', __( 'Anchored by the followed key', 'signal-and-noise-tools' ) ), (string) $a['block'] );
	}
	if ( false === $a['followed_key'] ) {
		return sn_posts_td( sn_posts_pill( $label, 'warn', __( 'Anchored by a key that is not the followed one', 'signal-and-noise-tools' ) ), (string) $a['block'] );
	}
	return sn_posts_td( sn_posts_pill( $label, 'muted', __( 'Signer key not recorded on the local chain', 'signal-and-noise-tools' ) ), (string) $a['block'] );
}

/** @internal Related: count of kernel neighbours, top score as the title. */
function sn_posts_td_related( array $r ) {
	$f = $r['related'];
	if ( null === $f['value'] ) {
		return sn_posts_td( sn_posts_gap( $f['why'] ), '', 'num' );
	}
	$v = (array) $f['value'];
	return sn_posts_td( '<span title="' . esc_attr( sprintf( /* translators: %s: score. */ __( 'top similarity %s', 'signal-and-noise-tools' ), number_format_i18n( (float) $v['top'], 2 ) ) ) . '">' . esc_html( number_format_i18n( (int) $v['count'] ) ) . '</span>', (string) $v['count'], 'num' );
}
