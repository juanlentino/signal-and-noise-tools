<?php
/**
 * S&N Dashboard — Content → Block Migrations, painted from the kit.
 *
 * The classic leaf (inc/block-migrations-admin.php,
 * `snt_block_migrations_render_section()` behind the
 * `sn_admin_render_block_migrations_section()` wrapper) paints a heading with
 * a count pill, an intro, one form (`sn_action=block_migrations_scan`), and
 * either the empty note or a collapsed review queue: one row per candidate
 * with the post, the issue pill, and the Suggest + Dismiss buttons the shared
 * assets/health-suggest-actions.js drives through the Abilities run-path.
 * Same reader, same form, same handler, same data contract on the buttons —
 * the kit's parts instead of wp-admin's.
 *
 * 17.9.0 (#1624): the queue is an `<os-table>`. It could not be one while the
 * component took cells as JSON only, because a per-row button could neither
 * be painted into it nor be reached by the shared script's document-level
 * delegation. OpenStation 1.1.11's slot cells fixed that: the post, the issue
 * pill and the button pair ride as light-DOM children, keyed by the
 * candidate's type:fingerprint so a morph follows a row a dismissal moved.
 * The table's own header carries the column semantics that classic's
 * `<th scope="col">` did, so the hand-rolled `role="columnheader"` row is gone.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The cached scan envelope, read the way the classic leaf reads it.
 *
 * @return array<string,mixed>|null
 */
function block_migrations_last_scan() {
	$scan = function_exists( 'snt_block_migrations_last_scan' ) ? \snt_block_migrations_last_scan() : null;
	return is_array( $scan ) ? $scan : null;
}

/**
 * The count pill: warn when there is anything to review, ok otherwise.
 *
 * @param array<string,mixed> $last_scan The envelope.
 * @return string
 */
function block_migrations_count_badge( array $last_scan ) {
	$total = (int) ( $last_scan['counts']['heading_hierarchy_skip'] ?? 0 );
	return \snt_kit_badge(
		$total > 0 ? 'warn' : 'ok',
		sprintf(
			/* translators: %d is the count of block-migration candidates found */
			_n( '%d candidate', '%d candidates', $total, 'signal-and-noise-tools' ),
			$total
		)
	);
}

/**
 * One candidate as a table row: the post (title, permalink), the issue, and
 * the two buttons with the data contract the shared suggest script reads.
 * 17.9.0 (#1624): the three are slot cells of an `<os-table>` (OpenStation
 * 1.1.11), so the buttons stay light-DOM children the script reaches.
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
	// scheme; snt_kit_link() only htmlspecialchars-escapes the href, so the
	// scheme is filtered here before it reaches the helper.
	$permalink = preg_match( '#^https?://#i', $permalink ) ? $permalink : '';
	$title     = (string) ( $c['post_title'] ?? '' );
	$post      = \snt_kit_code( $title, false )
		. ( '' !== $permalink ? '<p class="snt-hint">' . \snt_kit_link( $permalink, $permalink ) . '</p>' : '' );
	$level     = 'h' . (int) ( $c['current_level'] ?? 0 ) . ' → h' . (int) ( $c['target_level'] ?? 0 );
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
 * The review queue: the classic table's three headed columns, one row per
 * candidate, as an `<os-table>` that sorts by post and is a card per
 * candidate on a phone (#1624).
 *
 * @param array<int,array<string,mixed>> $candidates From the envelope.
 * @return string
 */
function block_migrations_queue_html( array $candidates ) {
	$rows = array();
	foreach ( $candidates as $c ) {
		if ( is_array( $c ) ) {
			$rows[] = block_migrations_row( $c );
		}
	}
	$columns = array(
		array( 'key' => 'post', 'label' => __( 'Post', 'signal-and-noise-tools' ), 'sortable' => true, 'stack' => 'title' ),
		array( 'key' => 'issue', 'label' => __( 'Issue', 'signal-and-noise-tools' ), 'sortable' => true ),
		array( 'key' => 'action', 'label' => __( 'Action', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: %d is the count of candidates to review */
				_n( 'Review %d candidate', 'Review %d candidates', count( $candidates ), 'signal-and-noise-tools' ),
				count( $candidates )
			),
		),
		\snt_kit_table( $columns, $rows, array( 'stack_on_phone' => true, 'striped' => false ) )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_block_migrations( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$last_scan = block_migrations_last_scan();
	// Classic: inc/block-migrations-admin.php tests truthiness (`if ( $last_scan )`),
	// not is_array() — a transient holding array() must read as "never scanned".
	$scanned   = ! empty( $last_scan );
	$inner     = $scanned ? block_migrations_count_badge( $last_scan ) : '';
	$inner    .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Scans published and scheduled posts for structural issues like heading-hierarchy skips (an h3 or h4 subhead with no preceding h2, WCAG 1.3.1). Pure structural detection: no AI. Each candidate is reviewed and applied per-row.', 'signal-and-noise-tools' ) ) . '</p>';
	$inner    .= \snt_kit_form(
		'block_migrations_scan',
		'',
		array( 'submit' => $scanned ? __( 'Re-scan', 'signal-and-noise-tools' ) : __( 'Scan for migrations', 'signal-and-noise-tools' ) )
	);
	if ( $scanned ) {
		$candidates = (array) ( $last_scan['candidates'] ?? array() );
		$inner     .= empty( $candidates )
			? \snt_kit_empty( __( 'No migrations needed. All headings have valid hierarchy.', 'signal-and-noise-tools' ) )
			: block_migrations_queue_html( $candidates );
	}
	return \snt_kit_section( __( 'Block migrations', 'signal-and-noise-tools' ), $inner );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/block-migrations'] = __NAMESPACE__ . '\\paint_content_block_migrations';
		return $painters;
	}
);
