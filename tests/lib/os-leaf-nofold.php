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
