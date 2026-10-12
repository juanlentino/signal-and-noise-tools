<?php
/**
 * Signal & Noise Tools: the one fold the kit paints, for the places that earn it.
 *
 * Owner, 2026-10-12: "The collapsible options should be thought. Some places
 * earn it, other don't." A place earns a fold when all three hold:
 *   1. it is reference or setup, read once in a while (setup steps, a full
 *      list behind a counted summary), never status, a warning, or a control
 *      in regular use;
 *   2. the closed fold still states the answer: `$summary` is required and
 *      paints on the closed fold, so closing hides detail, never a fact;
 *   3. it opens itself when something inside needs the reader (`$open`: a
 *      setup not done, an overdue event).
 * Every call site is listed with its reason in tests/sn-dashboard-no-folds.php;
 * a new fold fails that guard until it is listed. Required by
 * inc/openstation-kit-display.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An earned fold: `<os-disclosure>` with its heading and the summary that
 * answers when closed. No summary means no fold: the content paints as an
 * open section instead, so a fold can never hide the only statement of a fact.
 *
 * @param string $heading The heading, the same words an open section would use.
 * @param string $summary What the closed fold says (counts, the verdict, what is inside).
 * @param string $inner   The folded content (painted HTML).
 * @param bool   $open    True when something inside needs the reader now.
 * @return string
 */
function snt_kit_fold( $heading, $summary, $inner, $open = false ) {
	if ( '' === trim( (string) $summary ) ) {
		return snt_kit_section( $heading, $inner );
	}
	return snt_kit_tag(
		'os-disclosure',
		array(
			'heading'       => (string) $heading,
			'hint'          => (string) $summary,
			'open'          => (bool) $open,
			// The key assets/os-host.js remembers the reader's toggle under (the
			// morph strips an `open` the reader set on every repaint otherwise),
			// and the page's own verdict: the reader's choice holds until it changes.
			'data-snt-fold' => snt_kit_fold_key( $heading ),
			'data-snt-open' => $open ? '1' : '0',
		),
		// The same rhythm an open section's `stack` gives its children.
		snt_kit_stack( (string) $inner, 12 )
	);
}

/**
 * The fold's key: its heading as a slug, or a hash of it when the heading
 * has no Latin letters to slug (a translation), so memory never goes silent.
 *
 * @param string $heading The fold's heading.
 * @return string
 */
function snt_kit_fold_key( $heading ) {
	$slug = trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $heading ) ), '-' );
	return '' !== $slug ? $slug : 'f-' . substr( md5( (string) $heading ), 0, 10 );
}
