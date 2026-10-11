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

require_once __DIR__ . '/content-block-migrations-queue.php';

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
	if ( $scanned ) {
		$candidates = (array) ( $last_scan['candidates'] ?? array() );
		$inner     .= empty( $candidates )
			? \snt_kit_empty( __( 'No migrations needed. All headings have valid hierarchy.', 'signal-and-noise-tools' ) )
			: block_migrations_queue_html( $candidates );
	}
	// The scan is the section's action: last, under the queue it refills.
	$inner .= \snt_kit_form(
		'block_migrations_scan',
		'',
		array( 'submit' => $scanned ? __( 'Re-scan', 'signal-and-noise-tools' ) : __( 'Scan for migrations', 'signal-and-noise-tools' ) )
	);
	return \snt_kit_section( __( 'Block migrations', 'signal-and-noise-tools' ), $inner );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/block-migrations'] = __NAMESPACE__ . '\\paint_content_block_migrations';
		return $painters;
	}
);
