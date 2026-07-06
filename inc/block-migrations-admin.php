<?php
/**
 * Signal & Noise Tools — Block Migrations admin (Tools sub-tab).
 *
 * Renders the "Block Migrations" sub-tab under Tools:
 *   - Heading + count pill + "Scan for block migrations" form button
 *   - Collapsed-by-default <details> with per-candidate review queue
 *   - Per-row buttons: [Suggest] (data-attrs trigger shared
 *     health-suggest-actions.js) + [Dismiss]
 *
 * The dismiss action runs through the unified signal-noise/dismiss-candidate
 * Ability with surface="block-migrations" (see inc/abilities-dismiss.php);
 * the JS client dispatches it via the Abilities run-path. Ladder history:
 * the legacy POST /signal-noise/v1/tools/block-migrations-dismiss REST route
 * was removed in v7.0.0; the per-surface block-migrations-dismiss Ability was
 * deprecated in v7.7.0 and removed in v8.0.0.
 *
 * Mirrors inc/pattern-adoption-admin.php structurally.
 *
 * @package SignalNoiseTools
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Block Migrations sub-tab. Hooked into
 * sn_admin_block_migrations_tab action.
 *
 * @return void
 *
 * @since 4.5.0
 */
add_action( 'sn_admin_block_migrations_tab', 'snt_block_migrations_render_section' );

function snt_block_migrations_render_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$last_scan = function_exists( 'snt_block_migrations_last_scan' ) ? snt_block_migrations_last_scan() : null;

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
	echo esc_html__( 'Block migrations', 'signal-and-noise-tools' );
	if ( $last_scan ) {
		$total     = (int) ( $last_scan['counts']['heading_hierarchy_skip'] ?? 0 );
		$pill_kind = $total > 0 ? 'warn' : 'ok';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' .
			esc_html( sprintf(
				/* translators: %d is the count of block-migration candidates found */
				_n( '%d candidate', '%d candidates', $total, 'signal-and-noise-tools' ),
				$total
			) ) . '</span>';
	}
	echo '</h2>';

	echo '<p class="sn-fieldset-intro">' . esc_html__( 'Scans existing posts for structural issues like heading-hierarchy skips (h3 without preceding h2, WCAG 1.3.1). Pure structural detection — no AI. Each candidate is reviewed and applied per-row.', 'signal-and-noise-tools' ) . '</p>';

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="block_migrations_scan" class="button button-primary">' . esc_html( $last_scan ? __( 'Re-scan', 'signal-and-noise-tools' ) : __( 'Scan for migrations', 'signal-and-noise-tools' ) ) . '</button>';
	echo '</div>';
	echo '</form>';

	if ( ! $last_scan ) {
		echo '</div>';
		return;
	}

	$candidates = (array) ( $last_scan['candidates'] ?? array() );
	if ( empty( $candidates ) ) {
		echo '<p class="sn-fieldset-intro snt-mt-1">' . esc_html__( 'No migrations needed. All headings have valid hierarchy.', 'signal-and-noise-tools' ) . '</p>';
		echo '</div>';
		return;
	}

	echo '<details class="snt-mt-1">';
	echo '<summary>' .
		esc_html( sprintf(
			/* translators: %d is the count of candidates to review */
			_n( 'Review %d candidate', 'Review %d candidates', count( $candidates ), 'signal-and-noise-tools' ),
			count( $candidates )
		) ) . '</summary>';

	echo '<div class="snt-scroll-table snt-mt-075">';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col" class="snt-col-40">' . esc_html__( 'Post', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Issue', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-40">' . esc_html__( 'Action', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $candidates as $c ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $c['post_title'] ) . '</code>';
		if ( ! empty( $c['permalink'] ) ) {
			echo '<br><small><a href="' . esc_url( (string) $c['permalink'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $c['permalink'] ) . '</a></small>';
		}
		echo '</td>';
		echo '<td><span class="sn-pill sn-pill--warn">h' . esc_html( (string) (int) $c['current_level'] ) . ' &#x2192; h' . esc_html( (string) (int) $c['target_level'] ) . '</span></td>';
		echo '<td>';
		echo '<button type="button" class="button button-small" data-snt-suggest="1"';
		echo ' data-check="block_migrations_heading_skip"';
		echo ' data-post-id="' . esc_attr( (string) (int) $c['post_id'] ) . '"';
		echo ' data-fingerprint="' . esc_attr( (string) $c['block_fingerprint'] ) . '"';
		echo ' data-migration-type="' . esc_attr( (string) $c['migration_type'] ) . '"';
		echo '>' . esc_html__( 'Suggest', 'signal-and-noise-tools' ) . '</button>';
		echo ' <button type="button" class="button button-small" data-snt-block-migrations-dismiss="1"';
		echo ' data-post-id="' . esc_attr( (string) (int) $c['post_id'] ) . '"';
		echo ' data-fingerprint="' . esc_attr( (string) $c['block_fingerprint'] ) . '"';
		echo ' data-migration-type="' . esc_attr( (string) $c['migration_type'] ) . '"';
		echo '>' . esc_html__( 'Dismiss', 'signal-and-noise-tools' ) . '</button>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</details>';

	echo '</div>'; // .sn-fieldset
}

/*
 * Admin-post dispatcher branch for the scan trigger form lives in
 * inc/admin-page.php — see the 'block_migrations_scan' case/branch in
 * sn_handle_admin_post(). Project convention is direct branches.
 */
