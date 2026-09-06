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
 * The queue is NOT an `<os-table>`: that component takes its cells as JSON
 * and paints them in its shadow root, so a per-row button could neither be
 * painted into it nor be reached by the shared script's document-level
 * delegation. The rows are light-DOM `<os-row>`s on the classic 40/20/40
 * proportions, rounded to the 12-column grid as 5/2/5 (help: os-row,
 * os-stack, os-cluster, os-button, os-disclosure).
 *
 * The header row is painted as `<span class="snt-col__h">`, not classic's
 * `<th scope="col">` — dropping the table drops column-header semantics for
 * assistive tech; `role="row"` / `role="columnheader"` restore the ARIA
 * relationship without inventing a kit prop.
 *
 * Refresh is an ADDITION, not a port: the classic leaf has no live-refresh
 * behaviour, but a kit row's Dismiss can't remove its own row (see below), so
 * Refresh is the only way to clear a dismissed candidate from view.
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
 * One candidate as a row: the post (title, permalink), the issue, the two
 * buttons with the data contract the shared suggest script reads.
 *
 * @param array<string,mixed> $c A candidate from the envelope.
 * @return string
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
	$post      = \snt_kit_code( (string) ( $c['post_title'] ?? '' ), false )
		. ( '' !== $permalink ? '<p class="snt-hint">' . \snt_kit_link( $permalink, $permalink ) . '</p>' : '' );
	$issue     = \snt_kit_badge( 'warn', 'h' . (int) ( $c['current_level'] ?? 0 ) . ' → h' . (int) ( $c['target_level'] ?? 0 ) );
	$data      = array( 'data-post-id' => $post_id, 'data-fingerprint' => $fp, 'data-migration-type' => $type );
	// Suggest replaces the classic table CELL with its editor (`closest( 'td,th' )`
	// in health-suggest-actions.js); a window paints no cell, so the button is
	// present, disabled, and the queue's hint (block_migrations_queue_html())
	// says where it runs (mirrors the structural twin, content-pattern-adoption.php).
	$actions   = \snt_kit_tag(
		'os-button',
		array(
			'variant'          => 'secondary',
			'data-snt-suggest' => '1',
			'data-check'       => 'block_migrations_heading_skip',
			'disabled'         => true,
			'title'            => __( 'Suggest runs on the classic Content → Block Migrations page.', 'signal-and-noise-tools' ),
		) + $data,
		\snt_kit_esc( __( 'Suggest', 'signal-and-noise-tools' ) )
	) . \snt_kit_tag(
		'os-button',
		array( 'variant' => 'ghost', 'data-snt-block-migrations-dismiss' => '1' ) + $data,
		\snt_kit_esc( __( 'Dismiss', 'signal-and-noise-tools' ) )
	);
	return \snt_kit_tag(
		'os-row',
		array( 'gap' => '12', 'os-key' => $type . ':' . $fp ),
		\snt_kit_tag( 'div', array( 'col' => '5' ), $post )
		. \snt_kit_tag( 'div', array( 'col' => '2' ), $issue )
		. \snt_kit_tag( 'os-cluster', array( 'col' => '5', 'gap' => '6' ), $actions )
	);
}

/**
 * The review queue: the classic table's three headed columns, one row per candidate.
 *
 * @param array<int,array<string,mixed>> $candidates From the envelope.
 * @return string
 */
function block_migrations_queue_html( array $candidates ) {
	$head = '';
	foreach ( array( array( '5', __( 'Post', 'signal-and-noise-tools' ) ), array( '2', __( 'Issue', 'signal-and-noise-tools' ) ), array( '5', __( 'Action', 'signal-and-noise-tools' ) ) ) as $column ) {
		$head .= \snt_kit_tag( 'span', array( 'col' => $column[0], 'class' => 'snt-col__h', 'role' => 'columnheader' ), \snt_kit_esc( $column[1] ) );
	}
	$rows = \snt_kit_tag( 'os-row', array( 'gap' => '12', 'role' => 'row' ), $head );
	foreach ( $candidates as $c ) {
		if ( is_array( $c ) ) {
			$rows .= block_migrations_row( $c );
		}
	}
	// Dismiss removes the row's server-side record, but health-suggest-actions.js's
	// `btn.closest( 'tr' )` finds nothing in a kit row, so the row survives until a
	// repaint: Refresh (an ADDITION — the classic leaf has no live-refresh of its
	// own) re-runs the leaf's data() and clears it.
	$toolbar = \snt_kit_button( __( 'Refresh', 'signal-and-noise-tools' ), 'refresh', array( 'variant' => 'ghost' ) );
	$hint    = '<p class="snt-hint">' . \snt_kit_esc( __( 'Suggest opens its editor inside the classic table cell, which this window does not paint: run Suggest and Apply from the classic Content → Block Migrations page. A dismissed candidate stays on screen until Refresh — the dismissal itself is already written.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => sprintf(
				/* translators: %d is the count of candidates to review */
				_n( 'Review %d candidate', 'Review %d candidates', count( $candidates ), 'signal-and-noise-tools' ),
				count( $candidates )
			),
		),
		$hint . \snt_kit_tag( 'os-stack', array( 'gap' => '8' ), $toolbar . \snt_kit_tag( 'os-stack', array( 'gap' => '8' ), $rows ) )
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
