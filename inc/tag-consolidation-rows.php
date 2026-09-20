<?php
/**
 * Signal & Noise Tools: Content › Tags, the rows of the two ledgers.
 *
 * #1573: "Jev: by tag" and "Recent tag operations" were a run of paragraphs
 * (1,329px and a 1,603-character wall at 1581px). A per-item reading is a
 * ledger: a table with a row cap and a "+N more" line. The rows are shaped
 * ONCE here and painted twice, a plain <table> on the classic leaf and an
 * <os-table> on the native one, the way snt_tags_glance_cards() feeds both
 * glances. Same columns, same cap, same words on both surfaces.
 *
 * @package SignalNoiseTools
 * @since 17.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_TAG_BY_TAG_ROWS   = 30; // by-tag rows painted before "+N more".
const SN_TAG_BY_TAG_TITLES = 3;  // touching titles named in the cell before "+N".
const SN_TAG_RECENT_ROWS   = 10; // recent operations painted before "+N more".
const SN_TAG_RECENT_SLUGS  = 5;  // slugs named in the Tags cell before "+N".

/**
 * A comma-joined list, the first $cap names then "+N".
 *
 * @param string[] $names Names in order.
 * @param int      $cap   How many to name.
 * @return string
 */
function sn_admin_tag_join_capped( array $names, $cap ) {
	$named = array_slice( $names, 0, (int) $cap );
	$rest  = count( $names ) - count( $named );
	/* translators: %d: names not listed */
	return implode( ', ', $named ) . ( $rest > 0 ? ' ' . sprintf( __( '+%d', 'signal-and-noise-tools' ), $rest ) : '' );
}

/**
 * The by-tag ledger's columns, key => label, in paint order.
 *
 * @return array<string,string>
 */
function sn_admin_tag_by_tag_columns() {
	return array(
		'tag'      => __( 'Tag', 'signal-and-noise-tools' ),
		'notes'    => __( 'Notes', 'signal-and-noise-tools' ),
		'mean'     => __( 'Mean (of 2)', 'signal-and-noise-tools' ),
		'touching' => __( 'Only touching', 'signal-and-noise-tools' ),
		'titles'   => __( 'Touching notes', 'signal-and-noise-tools' ),
	);
}

/**
 * The by-tag ledger: one row per wide tag (a tag with notes that only touch
 * it), in sn_jev_tags_by_tag()'s order (touching share descending), capped.
 * The touching notes keep their weakest-first order in the titles cell, each
 * with its score and confidence as the paragraphs carried them; the per-note
 * editor link does not fit a text cell.
 *
 * @param array<int,array<string,mixed>> $wide The wide tags, sorted.
 * @return array{rows:array<int,array<string,mixed>>,more:int}
 */
function sn_admin_tag_by_tag_rows( array $wide ) {
	$rows = array();
	foreach ( array_slice( $wide, 0, SN_TAG_BY_TAG_ROWS ) as $r ) {
		$titles = array();
		foreach ( $r['touching'] as $t ) {
			/* translators: 1: title, 2: score, 3: confidence */
			$titles[] = sprintf( __( '%1$s (%2$s of 2, confidence %3$s)', 'signal-and-noise-tools' ), (string) $t['title'], number_format_i18n( (float) $t['score'], 2 ), number_format_i18n( (float) $t['confidence'], 2 ) );
		}
		$rows[] = array(
			'tag'      => (string) $r['name'],
			'notes'    => (int) $r['notes'],
			'mean'     => number_format_i18n( (float) $r['mean'], 2 ),
			'touching' => count( $r['touching'] ),
			'titles'   => sn_admin_tag_join_capped( $titles, SN_TAG_BY_TAG_TITLES ),
		);
	}
	return array( 'rows' => $rows, 'more' => max( 0, count( $wide ) - count( $rows ) ) );
}

/**
 * The recent operations' columns, key => label, in paint order.
 *
 * @return array<string,string>
 */
function sn_admin_tag_recent_columns() {
	return array(
		'op'   => __( 'Operation', 'signal-and-noise-tools' ),
		'tags' => __( 'Tags', 'signal-and-noise-tools' ),
	);
}

/**
 * The recent operations: one row per merge or prune from the history
 * option (newest first, as stored), capped; the tag list names the first
 * five slugs then "+N". The stored `ts` stays unpainted, as the list left it.
 *
 * @param array<int,mixed> $hist The `sn_tag_merge_history` option.
 * @return array{rows:array<int,array<string,string>>,more:int}
 */
function sn_admin_tag_recent_rows( array $hist ) {
	$hist = array_values( array_filter( $hist, 'is_array' ) );
	$rows = array();
	foreach ( array_slice( $hist, 0, SN_TAG_RECENT_ROWS ) as $h ) {
		if ( 'prune' === ( $h['op'] ?? 'merge' ) ) {
			$op = __( 'deleted unused', 'signal-and-noise-tools' );
		} else {
			/* translators: 1: canonical slug, 2: post count */
			$op = sprintf( __( 'merged into "%1$s" (%2$d posts)', 'signal-and-noise-tools' ), (string) ( $h['into'] ?? '' ), (int) ( $h['posts'] ?? 0 ) );
		}
		$rows[] = array(
			'op'   => $op,
			'tags' => sn_admin_tag_join_capped( array_map( 'strval', (array) ( $h['from'] ?? array() ) ), SN_TAG_RECENT_SLUGS ),
		);
	}
	return array( 'rows' => $rows, 'more' => max( 0, count( $hist ) - count( $rows ) ) );
}

/**
 * The "+N more" line under a capped ledger, or '' when nothing is hidden.
 *
 * @param int    $more  Rows not painted.
 * @param string $class The paragraph's class (snt-hint on the native leaf, description on the classic).
 * @return string
 */
function sn_admin_tag_more_line( $more, $class ) {
	if ( $more < 1 ) {
		return '';
	}
	/* translators: %d: rows not listed */
	return '<p class="' . esc_attr( $class ) . '">' . esc_html( sprintf( __( '+%d more; the list is capped, not complete.', 'signal-and-noise-tools' ), (int) $more ) ) . '</p>';
}

/**
 * The classic twin's table: `<table class="widefat striped">`, the columns
 * as headers, every cell escaped.
 *
 * @param array<string,string>                 $columns key => label.
 * @param array<int,array<string,mixed>>       $rows    Row values keyed by column key.
 * @return string
 */
function sn_admin_tag_table_html( array $columns, array $rows ) {
	$out = '<table class="widefat striped"><thead><tr>';
	foreach ( $columns as $label ) {
		$out .= '<th>' . esc_html( $label ) . '</th>';
	}
	$out .= '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$out .= '<tr>';
		foreach ( array_keys( $columns ) as $key ) {
			$out .= '<td>' . esc_html( (string) ( $row[ $key ] ?? '' ) ) . '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table>';
}
