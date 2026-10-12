<?php
/**
 * Signal & Noise Tools: the words both surfaces of the two content scanners
 * share (Content > Pattern Adoption, Content > Block Migrations).
 *
 * 2026-10-12 audit: the native leaves opened the review queue into a finding
 * sentence, a 25-row cap and a "+N more" line while the classic twins still
 * folded every row in a "Review N candidates" disclosure; the leaves printed
 * the scan's counts as current with no age; the Pattern column showed raw
 * slugs beside a version stamp ("the v9.2.0 ... patterns"). One builder per
 * sentence here, so the twins cannot drift again. Pure: no reads, no writes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Candidates either queue paints before its "+N more" line, on both surfaces. */
const SNT_CONTENT_SCAN_ROWS = 25;

/**
 * The "+N more" line under a capped queue (a sprintf format: %s is the count).
 *
 * @return string
 */
function snt_content_scan_more_format() {
	/* translators: %s: candidates not shown. */
	return __( '+%s more candidates; the list is capped, not complete. Work these, then re-scan to list the rest.', 'signal-and-noise-tools' );
}

/**
 * When the scan ran, and how long its list is kept (a sprintf format: %s is
 * the time, "12 minutes ago"). The cache expires an hour after the scan; a
 * dismiss or an apply drops its row and keeps that expiry.
 *
 * @return string
 */
function snt_content_scan_age_format() {
	/* translators: %s: when the scan ran ("12 minutes ago"). */
	return __( 'Scanned %s. A scan\'s list is kept for an hour; Re-scan lists what is left.', 'signal-and-noise-tools' );
}

/**
 * What Pattern Adoption looks for, on both surfaces.
 *
 * @return string
 */
function snt_pattern_adoption_intro() {
	return __( 'Scans existing /notes posts for blockquote and ordered-list blocks that could be upgraded to the pull-quote and numbered-steps patterns. Pure structural detection: no AI calls. Editorial: every upgrade is reviewed before apply.', 'signal-and-noise-tools' );
}

/**
 * What Block Migrations looks for, on both surfaces.
 *
 * @return string
 */
function snt_block_migrations_intro() {
	return __( 'Scans published and scheduled posts for structural issues like heading-hierarchy skips (an h3 or h4 subhead with no preceding h2, WCAG 1.3.1). Pure structural detection: no AI. Each candidate is reviewed and applied per-row.', 'signal-and-noise-tools' );
}

/**
 * How a queue row is worked, on both scanners and both surfaces
 * (assets/health-suggest-actions.js: Suggest fills an editor, Apply writes
 * after its preview, a dismissed fingerprint is skipped by later scans).
 *
 * @return string
 */
function snt_content_scan_rows_line() {
	return __( 'On each row, Suggest drafts the upgraded block for you to review, Apply writes it after a preview, and Dismiss keeps that block out of later scans.', 'signal-and-noise-tools' );
}

/**
 * The clean-scan line: what the detector checks, and no more (2026-10-12
 * audit: "All headings have valid hierarchy" claimed more than it checks).
 *
 * @return string
 */
function snt_block_migrations_clean() {
	return __( 'No heading skips found: no post has an h3 or h4 before its first h2.', 'signal-and-noise-tools' );
}

/**
 * The count pill's words, on both surfaces: "N candidates" (2026-10-12 audit:
 * Pattern Adoption's said "opportunities" over a finding about candidates).
 *
 * @param int $total Candidates the scan counted.
 * @return string
 */
function snt_content_scan_count( $total ) {
	/* translators: %s: candidates the scan found. */
	return sprintf( _n( '%s candidate', '%s candidates', (int) $total, 'signal-and-noise-tools' ), number_format_i18n( (int) $total ) );
}

/**
 * A pattern slug in words: the Pattern column and the finding.
 *
 * @param string $type 'pull-quote' | 'steps-enumerated'.
 * @return string
 */
function snt_pattern_adoption_label( $type ) {
	$labels = array(
		'pull-quote'       => __( 'Pull quote', 'signal-and-noise-tools' ),
		'steps-enumerated' => __( 'Numbered steps', 'signal-and-noise-tools' ),
	);
	return $labels[ (string) $type ] ?? (string) $type;
}

/**
 * "N candidates in M posts: ..." over a list of candidates, from the rows
 * themselves, with the per-kind counts the caller words.
 *
 * @param array<int,array<string,mixed>> $candidates The scan's rows.
 * @param string[]                       $parts      Per-kind counts, already worded.
 * @return string
 */
function snt_content_scan_finding( array $candidates, array $parts ) {
	$posts = array();
	foreach ( $candidates as $c ) {
		$posts[ (int) ( $c['post_id'] ?? 0 ) ] = true;
	}
	$total = count( $candidates );
	return sprintf(
		/* translators: 1: candidates, 2: "N posts", 3: per-kind counts. */
		_n( '%1$s candidate in %2$s: %3$s.', '%1$s candidates in %2$s: %3$s.', $total, 'signal-and-noise-tools' ),
		number_format_i18n( $total ),
		/* translators: %s: posts holding a candidate. */
		sprintf( _n( '%s post', '%s posts', count( $posts ), 'signal-and-noise-tools' ), number_format_i18n( count( $posts ) ) ),
		implode( ', ', $parts )
	);
}

/**
 * The Pattern Adoption finding: "3 candidates in 2 posts: 2 pull quotes,
 * 1 numbered-steps list."
 *
 * @param array<int,array<string,mixed>> $candidates The scan's rows.
 * @return string
 */
function snt_pattern_adoption_finding( array $candidates ) {
	$by_type = array( 'pull-quote' => 0, 'steps-enumerated' => 0 ); // the ladder's order, whatever the scan's.
	foreach ( $candidates as $c ) {
		$type             = (string) ( $c['pattern_type'] ?? '' );
		$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
	}
	$parts = array();
	foreach ( array_filter( $by_type ) as $type => $n ) {
		$n_text  = number_format_i18n( $n );
		$parts[] = 'pull-quote' === $type
			/* translators: %s: count. */
			? sprintf( _n( '%s pull quote', '%s pull quotes', $n, 'signal-and-noise-tools' ), $n_text )
			: ( 'steps-enumerated' === $type
				/* translators: %s: count. */
				? sprintf( _n( '%s numbered-steps list', '%s numbered-steps lists', $n, 'signal-and-noise-tools' ), $n_text )
				: $n_text . ' ' . $type );
	}
	return snt_content_scan_finding( $candidates, $parts );
}

/**
 * The heading jump a block-migration candidate fixes, "h3 → h2".
 *
 * @param array<string,mixed> $c A candidate.
 * @return string
 */
function snt_block_migrations_level( array $c ) {
	return 'h' . (int) ( $c['current_level'] ?? 0 ) . ' → h' . (int) ( $c['target_level'] ?? 0 );
}

/**
 * The Block Migrations finding: "2 candidates in 2 posts: 1 h3 → h2, 1 h4 → h2."
 *
 * @param array<int,array<string,mixed>> $candidates The scan's rows.
 * @return string
 */
function snt_block_migrations_finding( array $candidates ) {
	$by_level = array();
	foreach ( $candidates as $c ) {
		$level              = snt_block_migrations_level( (array) $c );
		$by_level[ $level ] = ( $by_level[ $level ] ?? 0 ) + 1;
	}
	ksort( $by_level );
	$parts = array();
	foreach ( $by_level as $level => $n ) {
		$parts[] = number_format_i18n( $n ) . ' ' . $level;
	}
	return snt_content_scan_finding( $candidates, $parts );
}

/**
 * The classic twins' "Scanned ..." line (the window paints the same sentence
 * around an os-relative-time).
 *
 * @param mixed $scan The cached scan, or a falsy value before the first.
 * @return void
 */
function snt_content_scan_classic_age( $scan ) {
	if ( ! $scan ) {
		echo '<p class="description">' . esc_html__( 'Not scanned yet.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	$at = is_array( $scan ) ? (int) ( $scan['scanned_at'] ?? 0 ) : 0;
	$when = $at > 0
		/* translators: %s: human time difference ("12 minutes"). */
		? sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( min( $at, time() ), time() ) )
		: '';
	echo '<p class="description">' . esc_html( '' !== $when ? sprintf( snt_content_scan_age_format(), $when ) : __( 'Scanned (the time was not recorded).', 'signal-and-noise-tools' ) ) . '</p>';
}

/**
 * The classic twins' "+N more" line under a capped queue.
 *
 * @param int $total Candidates in the scan.
 * @return void
 */
function snt_content_scan_classic_more( $total ) {
	$more = (int) $total - SNT_CONTENT_SCAN_ROWS;
	if ( $more > 0 ) {
		echo '<p class="description">' . esc_html( sprintf( snt_content_scan_more_format(), number_format_i18n( $more ) ) ) . '</p>';
	}
}
