<?php
/**
 * S&N Dashboard: Content > Pattern Adoption, the review queue.
 *
 * Split out of content-pattern-adoption.php (2026-10-11, no folds). The queue
 * was a closed disclosure ("Review N candidates") over one card per
 * candidate; it is an open ledger now, the Block Migrations shape: one
 * finding sentence, an `<os-table>` of Post, Pattern and Action whose cells
 * are slot cells (OpenStation 1.1.11) so the Suggest + Dismiss pair stays a
 * light-DOM child the shared assets/health-suggest-actions.js reaches
 * (`.snt-cell` is in its CELL_SEL and ROW_SEL), capped with "+N more".
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One candidate as a table row: the post (title, permalink), the pattern, and
 * the Suggest + Dismiss pair carrying the classic row's data attributes.
 *
 * @param array<string,mixed> $c A candidate from the scan.
 * @return array<string,mixed>
 */
function pattern_adoption_row( array $c ) {
	$type  = (string) ( $c['pattern_type'] ?? '' );
	$raw   = (string) ( $c['permalink'] ?? '' );
	$title = (string) ( $c['post_title'] ?? '' );
	// esc_url() (classic: inc/pattern-adoption-admin.php) blanks a disallowed
	// scheme but still prints the text; snt_kit_link() only escapes the href,
	// so a rejected scheme is painted unlinked here instead of dropping it.
	$safe_link = preg_match( '#^https?://#i', $raw ) ? \snt_kit_link( $raw, $raw ) : \snt_kit_esc( $raw );
	$common    = array(
		'variant'           => 'secondary',
		'data-post-id'      => (string) (int) ( $c['post_id'] ?? 0 ),
		'data-fingerprint'  => (string) ( $c['block_fingerprint'] ?? '' ),
		'data-pattern-type' => $type,
	);
	$post    = \snt_kit_code( $title, false ) . ( '' !== $raw ? '<p class="snt-hint">' . $safe_link . '</p>' : '' );
	$suggest = \snt_kit_tag(
		'os-button',
		$common + array(
			'data-snt-suggest' => '1',
			'data-check'       => 'pull-quote' === $type ? 'pattern_adoption_pull_quote' : 'pattern_adoption_steps_enumerated',
		),
		\snt_kit_esc( __( 'Suggest', 'signal-and-noise-tools' ) )
	);
	$dismiss = \snt_kit_tag( 'os-button', $common + array( 'data-snt-dismiss' => '1' ), \snt_kit_esc( __( 'Dismiss', 'signal-and-noise-tools' ) ) );
	return array(
		'_key'    => 'pa:' . $type . ':' . $common['data-fingerprint'],
		'post'    => array( 'html' => $post, 'text' => $title ),
		'pattern' => array( 'html' => \snt_kit_badge( 'warn', \snt_pattern_adoption_label( $type ) ), 'text' => \snt_pattern_adoption_label( $type ) ),
		// The pair sits in an os-cluster: the action cell the shared script paints its editor into.
		'action'  => array( 'html' => \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $suggest . $dismiss ), 'text' => '' ),
	);
}

/**
 * The review queue, open: the finding, the first SNT_CONTENT_SCAN_ROWS
 * candidates as a table, and the rest counted, in the classic twin's words
 * (inc/content-scans-shared.php).
 *
 * @param array<int,array<string,mixed>> $candidates From the scan.
 * @return string
 */
function pattern_adoption_queue_html( array $candidates ) {
	$candidates = array_values( array_filter( $candidates, 'is_array' ) );
	$columns    = array(
		array( 'key' => 'post', 'label' => __( 'Post', 'signal-and-noise-tools' ), 'stack' => 'title' ),
		array( 'key' => 'pattern', 'label' => __( 'Pattern', 'signal-and-noise-tools' ) ),
		array( 'key' => 'action', 'label' => __( 'Action', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	return \snt_kit_ledger(
		\snt_pattern_adoption_finding( $candidates ),
		$columns,
		array_map( __NAMESPACE__ . '\\pattern_adoption_row', $candidates ),
		\SNT_CONTENT_SCAN_ROWS,
		array(
			'stack_on_phone' => true,
			'striped'        => false,
			'more'           => \snt_content_scan_more_format(),
		)
	);
}
