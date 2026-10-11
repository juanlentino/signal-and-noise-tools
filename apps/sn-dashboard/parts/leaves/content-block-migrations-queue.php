<?php
/**
 * S&N Dashboard: Content > Block Migrations, the review queue.
 *
 * Split out of content-block-migrations.php (2026-10-11, no folds). The queue
 * was an `<os-table>` inside a closed disclosure ("Review N candidates"); it
 * is an open ledger now: one finding sentence, the table capped at
 * BLOCK_MIGRATIONS_ROWS, and the rest counted. The rows are unchanged
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

/** Candidates painted before the "+N more" line. */
const BLOCK_MIGRATIONS_ROWS = 25;

/**
 * The heading jump a candidate fixes, "h3 → h2".
 *
 * @param array<string,mixed> $c A candidate from the envelope.
 * @return string
 */
function block_migrations_level( array $c ) {
	return 'h' . (int) ( $c['current_level'] ?? 0 ) . ' → h' . (int) ( $c['target_level'] ?? 0 );
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
	$level     = block_migrations_level( $c );
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
 * The finding over the queue: candidates, posts, and the count per heading
 * jump, from the rows themselves.
 *
 * @param array<int,array<string,mixed>> $candidates From the envelope.
 * @return string
 */
function block_migrations_finding( array $candidates ) {
	$posts    = array();
	$by_level = array();
	foreach ( $candidates as $c ) {
		$posts[ (int) ( $c['post_id'] ?? 0 ) ] = true;
		$level                                = block_migrations_level( $c );
		$by_level[ $level ]                   = ( $by_level[ $level ] ?? 0 ) + 1;
	}
	ksort( $by_level );
	$parts = array();
	foreach ( $by_level as $level => $n ) {
		$parts[] = number_format_i18n( $n ) . ' ' . $level;
	}
	$total = count( $candidates );
	return sprintf(
		/* translators: 1: candidates, 2: "N posts", 3: per-jump counts, e.g. "14 h3 → h2, 6 h4 → h2". */
		_n( '%1$s candidate in %2$s: %3$s.', '%1$s candidates in %2$s: %3$s.', $total, 'signal-and-noise-tools' ),
		number_format_i18n( $total ),
		/* translators: %s: posts holding a candidate. */
		sprintf( _n( '%s post', '%s posts', count( $posts ), 'signal-and-noise-tools' ), number_format_i18n( count( $posts ) ) ),
		implode( ', ', $parts )
	);
}

/**
 * The review queue, open: the finding, the first BLOCK_MIGRATIONS_ROWS rows
 * of Post, Issue and Action (a card per candidate on a phone), the rest counted.
 *
 * @param array<int,array<string,mixed>> $candidates From the envelope.
 * @return string
 */
function block_migrations_queue_html( array $candidates ) {
	$candidates = array_values( array_filter( $candidates, 'is_array' ) );
	$columns    = array(
		array( 'key' => 'post', 'label' => __( 'Post', 'signal-and-noise-tools' ), 'sortable' => true, 'stack' => 'title' ),
		array( 'key' => 'issue', 'label' => __( 'Issue', 'signal-and-noise-tools' ), 'sortable' => true ),
		array( 'key' => 'action', 'label' => __( 'Action', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	return \snt_kit_ledger(
		block_migrations_finding( $candidates ),
		$columns,
		array_map( __NAMESPACE__ . '\\block_migrations_row', $candidates ),
		BLOCK_MIGRATIONS_ROWS,
		array(
			'stack_on_phone' => true,
			'striped'        => false,
			/* translators: %s: candidates not shown. */
			'more'           => __( '+%s more candidates; the list is capped, not complete. Work these, then re-scan to list the rest.', 'signal-and-noise-tools' ),
		)
	);
}
