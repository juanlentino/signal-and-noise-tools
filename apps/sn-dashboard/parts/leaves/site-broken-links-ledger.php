<?php
/**
 * S&N Dashboard, Site → Broken links: the reading and the broken-path ledger.
 *
 * 2026-10-11 (owner rule: no collapsible sections). Every broken path used to
 * be a fold holding its own create form and dismiss form, 25 of them live.
 * Now: two tiles, one finding, ONE kit table busiest first whose rows carry
 * ONE visible action (Fix, a `go` that sets `sn_edit`). Every write for that
 * path, create or dismiss, sits in ONE editor under its row (2026-10-12,
 * snt_kit_edit_list(); site-broken-links.php). No form sits in a table cell. Required by
 * site-broken-links.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * "7 hits", "1 hit".
 *
 * @param int $n Hits.
 * @return string
 */
function broken_links_hits( $n ) {
	return \sn_404_hits( (int) $n );
}

/**
 * The referring host of a logged 404, or `$none` when the log holds none.
 *
 * @param array<string,mixed> $e    count, last_seen, referer.
 * @param string              $none What to say instead.
 * @return string
 */
function broken_links_referrer( array $e, $none ) {
	$host = empty( $e['referer'] ) ? '' : (string) wp_parse_url( (string) $e['referer'], PHP_URL_HOST );
	return '' !== $host ? $host : (string) $none;
}

/**
 * The reading: broken paths (with the attention swatch when there are any)
 * and automated probes, each with its hits. Both are measured from the log.
 *
 * @param array<string,mixed> $data From redirects_data().
 * @return string
 */
function broken_links_reading_html( array $data ) {
	$n    = count( $data['broken'] );
	$hits = array_sum( array_map( static function ( $row ) { return (int) ( $row['entry']['count'] ?? 0 ); }, (array) $data['broken'] ) );
	$p    = count( $data['probes'] );
	return \snt_kit_grid(
		array(
			\snt_kit_stat( number_format_i18n( $n ), _n( 'Broken path', 'Broken paths', $n, 'signal-and-noise-tools' ), $n > 0 ? broken_links_hits( $hits ) . __( '; needs a look', 'signal-and-noise-tools' ) : __( 'clean', 'signal-and-noise-tools' ), $n > 0 ? 'warn' : 'ok' ),
			\snt_kit_stat( number_format_i18n( $p ), _n( 'Automated probe', 'Automated probes', $p, 'signal-and-noise-tools' ), broken_links_hits( (int) $data['probe_hits'] ) ),
		),
		160,
		10
	);
}

/**
 * One table row: the path's figures and its one row action.
 *
 * @param string              $path      The 404'd path.
 * @param array<string,mixed> $e         count, last_seen, referer.
 * @param string              $suggested Suggested target ('' when none).
 * @param bool                $open      Whether this path's editor is open under it.
 * @param bool                $refs      Whether any listed path has a referrer.
 * @return array<string,mixed>
 */
function broken_links_row( $path, array $e, $suggested, $open = false, $refs = false ) {
	$last   = (int) ( $e['last_seen'] ?? 0 );
	$action = $open
		? \snt_kit_badge( 'info', __( 'Editing', 'signal-and-noise-tools' ) )
		/* translators: %s: the path. */
		: \snt_kit_button( __( 'Fix', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'aria_label' => sprintf( __( 'Fix %s', 'signal-and-noise-tools' ), $path ), 'args' => array( 'sub' => 'broken-links', 'sn_edit' => redirects_edit_value( $path ), 'anchor' => 'snt-edit-region' ) ) );
	// 2026.10.4 live check: the referrer rides under the path, not in its own
	// column (the table overran its half of the row and cut Fix off), and a
	// long path or target breaks inside its cell.
	$host = broken_links_referrer( $e, '' );
	/* translators: %s: the referring host. */
	$from = '' !== $host ? sprintf( __( 'from %s', 'signal-and-noise-tools' ), $host ) : __( 'no referrer recorded', 'signal-and-noise-tools' );
	$sugg = '' !== $suggested ? $suggested : __( 'no close match', 'signal-and-noise-tools' );
	return array(
		'_key'      => $path,
		'path'      => array(
			'html' => '<span class="snt-break">' . \snt_kit_esc( $path ) . '</span>' . ( $refs ? '<span class="snt-cell-sub">' . \snt_kit_esc( $from ) . '</span>' : '' ),
			'text' => $path,
		),
		'hits'      => (int) ( $e['count'] ?? 0 ),
		// The site's date format, on one line (a slotted cell, so the sheet reaches it).
		'last'      => array(
			'html' => '<span class="snt-nowrap">' . \snt_kit_esc( \sn_404_date( $last ) ) . '</span>',
			'text' => \sn_404_date( $last ),
		),
		'suggested' => array(
			'html' => '<span class="snt-break">' . \snt_kit_esc( $sugg ) . '</span>',
			'text' => $sugg,
		),
		'actions'   => array(
			'html' => \snt_kit_row_action( $path, $action ),
			'text' => '',
		),
	);
}

/**
 * The broken-paths section: the finding and the capped ledger, busiest first.
 * With a path open, the ledger splits at its row and `$editor` opens under it
 * (snt_kit_edit_list()).
 *
 * @param array<string,array{entry:array,suggested:string}> $broken  Busiest first.
 * @param string                                            $editing The open path, '' for none.
 * @param string                                            $editor  Its editor, '' for none.
 * @return string
 */
function broken_links_paths_html( array $broken, $editing = '', $editor = '' ) {
	$heading = __( 'Broken paths', 'signal-and-noise-tools' );
	$n       = count( $broken );
	if ( 0 === $n ) {
		// The classic's sentence (inc/redirects-copy.php), "or something here links to it" kept.
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( \sn_404_empty_sentence() ) . '</p>' );
	}
	$finding = \sn_404_broken_finding( array_map( static function ( $row ) { return (int) ( $row['entry']['count'] ?? 0 ); }, $broken ) );
	$listed = array_slice( $broken, 0, SN_404_LIST_CAP, true );
	$refs   = array() !== array_filter( $listed, static fn( $row ) => '' !== broken_links_referrer( (array) $row['entry'], '' ) );
	if ( ! $refs ) {
		// Said once here, not in a column of "none recorded".
		$finding .= ' ' . _n( 'No referrer was recorded for it.', 'No referrer was recorded for any of them.', count( $listed ), 'signal-and-noise-tools' );
	}
	$rows = array();
	$at   = -1;
	foreach ( $listed as $path => $row ) {
		$open   = (string) $path === (string) $editing;
		$at     = $open ? count( $rows ) : $at;
		$rows[] = broken_links_row( (string) $path, (array) $row['entry'], (string) $row['suggested'], $open, $refs );
	}
	$columns = array(
		array( 'key' => 'path', 'label' => __( 'Path', 'signal-and-noise-tools' ) ),
		array( 'key' => 'hits', 'label' => __( 'Hits', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'last', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ),
		array( 'key' => 'suggested', 'label' => __( 'Suggested target', 'signal-and-noise-tools' ) ),
		array( 'key' => 'actions', 'label' => __( 'Actions', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	/* translators: 1: paths listed, 2: paths in the log in total. */
	$showing = sprintf( __( 'Showing the %1$s busiest of %2$s broken paths. Clear the log or act on these to see the rest.', 'signal-and-noise-tools' ), number_format_i18n( SN_404_LIST_CAP ), number_format_i18n( $n ) );
	return \snt_kit_edit_list(
		array(
			'heading'     => $heading,
			'lead'        => '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>',
			'columns'     => $columns,
			'rows'        => $rows,
			'at'          => $at,
			/* translators: %s: paths not listed. */
			'more'        => \snt_kit_more( $n - count( $rows ), __( '+%s quieter paths are not listed.', 'signal-and-noise-tools' ) . ' ' . str_replace( '%', '%%', $showing ) ),
			'description' => __( 'Busiest first. Fix opens the path under its row, to redirect it or dismiss it; either clears it from this list.', 'signal-and-noise-tools' ),
			'attrs'       => array( 'id' => 'snt-edit-list', 'tabindex' => '-1', 'data-snt-focus' => true ),
		),
		$editor
	);
}
