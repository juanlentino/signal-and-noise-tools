<?php
/**
 * Signal & Noise Tools — Pattern-adoption admin (Health-tab integration).
 *
 * Renders the "Opportunities" sub-section in the Health admin tab:
 *   - Collapsed-by-default summary row with count badge
 *   - Expanded: per-candidate review queue with Suggest button
 *   - "Scan for pattern opportunities" trigger button (POSTs to
 *     /signal-noise/v1/health/pattern-adoption-scan)
 *
 * Also registers the dismiss REST endpoint at POST
 * /signal-noise/v1/health/pattern-adoption-dismiss which appends a
 * fingerprint to the post's _snt_pattern_adoption_dismissed meta and
 * invalidates the current user's scan transient.
 *
 * @package SignalNoiseTools
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Opportunities sub-section. Called from
 * sn_health_render_admin_tab() at the end of the Problems checks loop.
 *
 * @return void
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_render_opportunities_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$last_scan = snt_pattern_adoption_last_scan();

	echo '<div class="sn-fieldset" style="margin-top:2rem;">';
	echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
	echo esc_html__( 'Opportunities', 'signal-noise-tools' );
	if ( $last_scan ) {
		$total = (int) ( $last_scan['counts']['pull_quote'] ?? 0 ) + (int) ( $last_scan['counts']['steps_enumerated'] ?? 0 );
		$pill_kind = $total > 0 ? 'warn' : 'ok';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' .
			esc_html( sprintf(
				/* translators: %d is the count of pattern-adoption opportunities found */
				_n( '%d opportunity', '%d opportunities', $total, 'signal-noise-tools' ),
				$total
			) ) . '</span>';
	}
	echo '</h2>';

	echo '<p class="sn-fieldset-intro">' . esc_html__( 'Scans existing /notes posts for blockquote and ordered-list blocks that could be upgraded to the v9.2.0 pull-quote and steps-enumerated patterns. Pure structural detection — no AI calls. Editorial: every upgrade is reviewed before apply.', 'signal-noise-tools' ) . '</p>';

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="pattern_adoption_scan" class="button button-primary">' . esc_html( $last_scan ? __( 'Re-scan opportunities', 'signal-noise-tools' ) : __( 'Scan for opportunities', 'signal-noise-tools' ) ) . '</button>';
	echo '</div>';
	echo '</form>';

	if ( ! $last_scan ) {
		echo '</div>';
		return;
	}

	$candidates = (array) ( $last_scan['candidates'] ?? array() );
	if ( empty( $candidates ) ) {
		echo '<p class="sn-fieldset-intro" style="margin-top:1rem;">' . esc_html__( 'No opportunities found. All eligible blocks are either already pattern-upgraded or have been dismissed.', 'signal-noise-tools' ) . '</p>';
		echo '</div>';
		return;
	}

	// Collapsed-by-default: wrap candidate rows in <details>.
	echo '<details style="margin-top:1rem;">';
	echo '<summary>' .
		esc_html( sprintf(
			/* translators: %d is the count of pattern-adoption candidates to review */
			_n( 'Review %d candidate', 'Review %d candidates', count( $candidates ), 'signal-noise-tools' ),
			count( $candidates )
		) ) . '</summary>';

	echo '<div class="snt-scroll-table" style="margin-top:0.75rem;">';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col" style="width:40%;">' . esc_html__( 'Post', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col" style="width:20%;">' . esc_html__( 'Pattern', 'signal-noise-tools' ) . '</th>';
	echo '<th scope="col" style="width:40%;">' . esc_html__( 'Action', 'signal-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $candidates as $c ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $c['post_title'] ) . '</code>';
		if ( ! empty( $c['permalink'] ) ) {
			echo '<br><small><a href="' . esc_url( (string) $c['permalink'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $c['permalink'] ) . '</a></small>';
		}
		echo '</td>';
		echo '<td><span class="sn-pill sn-pill--warn">' . esc_html( (string) $c['pattern_type'] ) . '</span></td>';
		echo '<td>';
		$check_key = 'pull-quote' === $c['pattern_type'] ? 'pattern_adoption_pull_quote' : 'pattern_adoption_steps_enumerated';
		echo '<button type="button" class="button button-small" data-snt-suggest="1"';
		echo ' data-check="' . esc_attr( $check_key ) . '"';
		echo ' data-post-id="' . esc_attr( (string) (int) $c['post_id'] ) . '"';
		echo ' data-fingerprint="' . esc_attr( (string) $c['block_fingerprint'] ) . '"';
		echo ' data-pattern-type="' . esc_attr( (string) $c['pattern_type'] ) . '"';
		echo '>' . esc_html__( 'Suggest', 'signal-noise-tools' ) . '</button>';
		echo ' <button type="button" class="button button-small" data-snt-dismiss="1"';
		echo ' data-post-id="' . esc_attr( (string) (int) $c['post_id'] ) . '"';
		echo ' data-fingerprint="' . esc_attr( (string) $c['block_fingerprint'] ) . '"';
		echo ' data-pattern-type="' . esc_attr( (string) $c['pattern_type'] ) . '"';
		echo '>' . esc_html__( 'Dismiss', 'signal-noise-tools' ) . '</button>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</details>';

	echo '</div>'; // .sn-fieldset
}

// v4.3.0: scan-trigger form posts route through sn_handle_admin_post()
// in inc/admin-page.php (the established SN dispatcher pattern). See the
// 'pattern_adoption_scan' branch around admin-page.php:805. The form in
// snt_pattern_adoption_render_opportunities_section() above posts to the
// current admin URL with name="sn_action" value="pattern_adoption_scan";
// the dispatcher reads $_POST['sn_action'], runs the scan, and redirects
// with sn_flash=pattern_adoption_scanned.

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — dismiss.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/health/pattern-adoption-dismiss', array(
		'methods'             => 'POST',
		'callback'            => function( WP_REST_Request $request ) {
			$post_id      = (int) $request->get_param( 'post_id' );
			$fingerprint  = (string) $request->get_param( 'block_fingerprint' );
			$pattern_type = (string) $request->get_param( 'pattern_type' );

			$existing = (array) get_post_meta( $post_id, '_snt_pattern_adoption_dismissed', true );
			if ( ! is_array( $existing ) ) { $existing = array(); }
			$key = $pattern_type . ':' . $fingerprint;
			if ( ! in_array( $key, $existing, true ) ) {
				$existing[] = $key;
				update_post_meta( $post_id, '_snt_pattern_adoption_dismissed', $existing );
			}

			// Invalidate the user's scan transient so the next render reflects the dismissal.
			$tkey = 'snt_pattern_adoption_candidates_' . (int) get_current_user_id();
			delete_transient( $tkey );

			return rest_ensure_response( array( 'ok' => true ) );
		},
		'permission_callback' => function( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args' => array(
			'post_id'           => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'block_fingerprint' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'pattern_type'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		),
	) );
} );
