<?php
/**
 * S&N Dashboard: Content > Block Migrations, the review queue.
 *
 * Split out of content-block-migrations.php (2026-10-11, no folds). The queue
 * was an `<os-table>` inside a closed disclosure ("Review N candidates"); it
 * is an open ledger now: one finding sentence, the table capped at
 * SNT_CONTENT_SCAN_ROWS, and the rest counted. The rows are unchanged
 * (17.9.0, #1624): the post, the issue pill and the Suggest + Dismiss pair are
 * slot cells (OpenStation 1.1.11), keyed by the candidate's type:fingerprint
 * so a morph follows a row a dismissal moved, and the buttons stay light-DOM
 * children the shared assets/health-suggest-actions.js reaches.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One candidate as a table row: the post (title, permalink), the issue, and
 * the two buttons with the data contract the shared suggest script reads.
 *
 * @param array<string,mixed> $c A candidate from the envelope.
 * @return array<string,mixed>
 */
function block_migrations_row( array $c ) {
	$post_id   = (string) (int) ( $c['post_id'] ?? 0 );
	$fp        = (string) ( $c['block_fingerprint'] ?? '' );
	$type      = (string) ( $c['migration_type'] ?? '' );
	$permalink = (string) ( $c['permalink'] ?? '' );
	// esc_url() (classic: inc/block-migrations-admin.php) blanks a disallowed
	// scheme; snt_kit_link() only escapes the href, so the scheme is filtered
	// here before it reaches the helper.
	$permalink = preg_match( '#^https?://#i', $permalink ) ? $permalink : '';
	$title     = (string) ( $c['post_title'] ?? '' );
	$post      = \snt_kit_code( $title, false )
		. ( '' !== $permalink ? '<p class="snt-hint">' . \snt_kit_link( $permalink, $permalink ) . '</p>' : '' );
	$level     = \snt_block_migrations_level( $c );
	$data      = array( 'data-post-id' => $post_id, 'data-fingerprint' => $fp, 'data-migration-type' => $type );
	$actions   = \snt_kit_tag(
		'os-button',
		array(
			'variant'          => 'secondary',
			'data-snt-suggest' => '1',
			'data-check'       => 'block_migrations_heading_skip',
		) + $data,
		\snt_kit_esc( __( 'Suggest', 'signal-and-noise-tools' ) )
	) . \snt_kit_tag(
		'os-button',
		array( 'variant' => 'ghost', 'data-snt-block-migrations-dismiss' => '1' ) + $data,
		\snt_kit_esc( __( 'Dismiss', 'signal-and-noise-tools' ) )
	);
	return array(
		'_key'   => $type . ':' . $fp,
		'post'   => array( 'html' => $post, 'text' => $title ),
		'issue'  => array( 'html' => \snt_kit_badge( 'warn', $level ), 'text' => $level ),
		'action' => array( 'html' => \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $actions ), 'text' => '' ),
	);
}

/**
 * The review queue, open: the finding, the first SNT_CONTENT_SCAN_ROWS rows
 * of Post, Issue and Action (a card per candidate on a phone), the rest
 * counted, in the classic twin's words (inc/content-scans-shared.php).
 *
 * @param array<int,array<string,mixed>> $candidates From the envelope.
 * @return string
 */
function block_migrations_queue_html( array $candidates ) {
	$candidates = array_values( array_filter( $candidates, 'is_array' ) );
	$columns    = array(
		array( 'key' => 'post', 'label' => __( 'Post', 'signal-and-noise-tools' ), 'stack' => 'title' ),
		array( 'key' => 'issue', 'label' => __( 'Issue', 'signal-and-noise-tools' ) ),
		array( 'key' => 'action', 'label' => __( 'Action', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	return \snt_kit_ledger(
		\snt_block_migrations_finding( $candidates ),
		$columns,
		array_map( __NAMESPACE__ . '\\block_migrations_row', $candidates ),
		\SNT_CONTENT_SCAN_ROWS,
		array(
			'stack_on_phone' => true,
			'striped'        => false,
			'more'           => \snt_content_scan_more_format(),
		)
	);
}
