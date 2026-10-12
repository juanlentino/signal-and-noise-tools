<?php
/**
 * The no-fold helpers for the leaf suites (required by os-leaf-harness.php).
 *
 * 2026-10-11, owner rule: no collapsible sections on the dashboard. A suite
 * pins a leaf fold-free with snt_leaf_folds(), and writes the leaf's painted
 * HTML for the owner's preview with snt_leaf_preview().
 *
 * NOT a suite (tests/run.sh globs tests/*.php non-recursively).
 */

/**
 * Write a leaf's painted HTML to `$SNT_LEAF_PREVIEW_DIR/<name>.html` when that
 * variable is set, and do nothing otherwise: the owner's look at a leaf before
 * a merge, from the real painter on live-shaped fixture data. A suite paints
 * its preview state and passes the HTML here; the sweep stays a sweep.
 *
 *   SNT_LEAF_PREVIEW_DIR=/tmp/leaf-previews bash tests/run.sh
 *
 * @param string $name Preview name, `<tab>-<sub>`.
 * @param string $html The leaf's painted HTML (the frame's inner HTML).
 * @return void
 */
function snt_leaf_preview( $name, $html ) {
	$dir = (string) getenv( 'SNT_LEAF_PREVIEW_DIR' );
	if ( '' === $dir ) {
		return;
	}
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( rtrim( $dir, '/' ) . '/' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $name ) . '.html', (string) $html );
}

/**
 * 2026-10-11 (a11y review): a row action that opens the one edit region must
 * land the keyboard there, and Cancel must land it back on the list. The
 * Edit/Fix `go` carries `anchor=snt-edit-region`, the region carries that id
 * and `data-snt-focus` (assets/os-host.js focuses an anchored target that
 * asks), and Cancel carries `anchor=snt-edit-list` to the list section.
 *
 * @param string $list_html    The default paint (the list, no region).
 * @param string $editing_html The editing paint (the list and the region).
 * @return string[] Problems found, `array()` when the focus path is whole.
 */
function snt_leaf_edit_focus_problems( $list_html, $editing_html ) {
	$problems = array();
	if ( ! preg_match( '/<os-button[^>]*os-action="go"[^>]*os-arg-anchor="snt-edit-region"/', (string) $list_html ) ) {
		$problems[] = 'no row action anchors to the edit region';
	}
	if ( ! preg_match( '/id="snt-edit-list"[^>]*data-snt-focus|data-snt-focus[^>]*id="snt-edit-list"/', (string) $list_html ) ) {
		$problems[] = 'the list section has no focusable snt-edit-list id';
	}
	if ( ! preg_match( '/id="snt-edit-region"[^>]*data-snt-focus|data-snt-focus[^>]*id="snt-edit-region"/', (string) $editing_html ) ) {
		$problems[] = 'the edit region has no focusable snt-edit-region id';
	}
	// Cancel lands on the edited row's own action (an id the list paint
	// carries, with data-snt-focus), or failing that on the list.
	if ( ! preg_match( '/<os-button[^>]*os-action="go"[^>]*os-arg-anchor="([^"]+)"[^>]*>Cancel</', (string) $editing_html, $m )
		|| ! preg_match( '/id="' . preg_quote( $m[1], '/' ) . '"[^>]*data-snt-focus|data-snt-focus[^>]*id="' . preg_quote( $m[1], '/' ) . '"/', (string) $list_html ) ) {
		$problems[] = 'Cancel does not anchor back to a focusable place in the list';
	}
	return $problems;
}

/**
 * 2026-10-12 (owner: "it's weird to click in 'edit' and something else
 * opens"): the editor opens UNDER its row. In the editing paint the table
 * holding the edited row ends with it and marks it selected, the editor
 * (`id="snt-edit-region"`) follows with no table or form between, and the
 * next row starts the table that resumes after the editor.
 *
 * @param string      $html   The editing paint.
 * @param string      $col    The column whose value names a row.
 * @param string      $edited That value on the edited row.
 * @param string|null $next   That value on the row after it; null when it is the last.
 * @return string[] Problems, `array()` when the split is right.
 */
function snt_leaf_split_problems( $html, $col, $edited, $next ) {
	$html   = (string) $html;
	$region = strpos( $html, 'id="snt-edit-region"' );
	if ( false === $region ) {
		return array( 'no edit region' );
	}
	$start  = (int) strrpos( substr( $html, 0, $region ), '<os-section' );
	$tables = snt_leaf_tables( $html );
	$at     = array();
	for ( $o = 0; false !== ( $p = strpos( $html, '<os-table', $o ) ); $o = $p + 1 ) {
		$at[] = $p;
	}
	$head = null;
	$tail = null;
	foreach ( $at as $i => $p ) {
		if ( $p < $start ) {
			$head = $i;
		} elseif ( null === $tail && $p > $region ) {
			$tail = $i;
		}
	}
	if ( null === $head ) {
		return array( 'no table before the editor' );
	}
	$text     = static function ( $v ) { return is_array( $v ) ? (string) ( $v['text'] ?? '' ) : (string) $v; };
	$problems = array();
	$rows     = $tables[ $head ]['data'];
	$last     = $text( end( $rows )[ $col ] ?? '' );
	if ( $last !== $edited ) {
		$problems[] = 'the table before the editor ends with "' . $last . '", not the edited row';
	}
	if ( false === strpos( $tables[ $head ]['attrs'], 'os-prop-selection="[' . ( count( $rows ) - 1 ) . ']"' ) ) {
		$problems[] = 'the edited row is not marked selected';
	}
	$close   = strpos( $html, '</os-table>', $at[ $head ] );
	$between = substr( $html, $close, $start - $close );
	if ( false !== strpos( $between, '<os-table' ) || false !== strpos( $between, '<os-form' ) ) {
		$problems[] = 'something sits between the edited row and its editor';
	}
	if ( null !== $next && ( null === $tail || $text( $tables[ $tail ]['data'][0][ $col ] ?? '' ) !== $next ) ) {
		$problems[] = 'the table does not resume with the next row after the editor';
	}
	return $problems;
}

/**
 * 2026-10-11 (owner rule: no collapsible sections): the fold markers a leaf
 * must not paint. `<os-disclosure`, a raw `<details`, the classic
 * `sn-disclosure` class.
 *
 * @param string $html Markup.
 * @return string[] Markers found.
 */
function snt_leaf_folds( $html ) {
	$found = array();
	foreach ( array( '<os-disclosure', '<details', 'sn-disclosure' ) as $marker ) {
		if ( false !== stripos( (string) $html, $marker ) ) {
			$found[] = $marker;
		}
	}
	return $found;
}
