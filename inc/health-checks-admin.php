<?php
/**
 * Signal & Noise Tools — Health Checks admin tab.
 *
 * Render-only. The `health_scan` action routes through
 * sn_handle_admin_post in inc/admin-page.php (matches cf_save /
 * pl_save). Shared sn_theme_options_nonce contract.
 *
 * Uses the bespoke .sn-fieldset / .sn-field / .sn-card-grid design
 * system (matches cloudflare-purge.php, plausible-admin.php).
 *
 * @package SignalNoiseTools
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_health_tab', 'sn_health_render_admin_tab' );

function sn_health_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ai_available             = function_exists( 'snt_ai_is_available' ) && snt_ai_is_available();
	$suggest_supported_checks = array( 'missing_alt', 'drift_time_phrases', 'orphaned_media', 'pattern_adoption_pull_quote', 'pattern_adoption_steps_enumerated' );

	$last_scan = sn_health_last_scan();

	// ── INTRO ──
	echo '<p class="sn-prose">Scans your post and attachment graph. AI-assisted fixes are available inline for missing alt text, time-phrase drift, and orphaned media when a provider is configured. Results cache for 24 hours.</p>';

	// ── STATUS BOX + RUN BUTTON ──
	if ( $last_scan ) {
		$total_findings = 0;
		foreach ( $last_scan['checks'] as $check ) {
			$total_findings += (int) $check['count'];
		}
		$pill_kind = $total_findings > 0 ? 'warn' : 'ok';
		echo '<div class="sn-status-box' . ( 'ok' === $pill_kind ? '' : ' sn-status-box--warn' ) . '">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Last scan ' . esc_html( human_time_diff( (int) $last_scan['scanned_at'], time() ) ) . ' ago</p>';
		// v4.1.1 (B-05): dynamic check count. Hardcoded "4 checks" was wrong
		// since v3.7.0 added drift_time_phrases as check #5.
		$check_count = is_array( $last_scan['checks'] ?? null ) ? count( $last_scan['checks'] ) : 0;
		echo '<p class="sn-status-box-body">' . esc_html( $total_findings ) . ' total finding' . ( 1 === $total_findings ? '' : 's' ) . ' across ' . esc_html( $check_count ) . ' checks · scan ran in ' . esc_html( (int) $last_scan['elapsed_ms'] ) . 'ms. Results cached until ' . esc_html( wp_date( 'Y-m-d H:i', (int) $last_scan['scanned_at'] + DAY_IN_SECONDS ) ) . '.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' . esc_html( $total_findings > 0 ? 'Issues found' : 'All clear' ) . '</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">No scan has run yet</p>';
		echo '<p class="sn-status-box-body">Click <strong>Run scan</strong> below to populate findings. The scan reads only — no edits.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Run scan</h2>';
	echo '<p class="sn-fieldset-intro">Sweeps post + attachment tables, follows internal links with HEAD probes (24h cached), and queries last-modified dates. Typical run: 1–10 seconds on a small site.</p>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="health_scan" class="button button-primary">' . esc_html( $last_scan ? 'Re-run scan' : 'Run scan' ) . '</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';

	if ( ! $last_scan ) {
		return;
	}

	// ── ONE FIELDSET PER CHECK ──
	foreach ( $last_scan['checks'] as $key => $check ) {
		echo '<div class="sn-fieldset">';

		echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
		echo esc_html( $check['label'] );
		$pill_kind = $check['count'] > 0 ? 'warn' : 'ok';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' . esc_html( $check['count'] ) . ' finding' . ( 1 === (int) $check['count'] ? '' : 's' ) . '</span>';
		if ( $ai_available && in_array( $key, $suggest_supported_checks, true ) && (int) $check['count'] > 0 ) {
			echo '<button type="button" class="button button-small sn-ml-auto" data-snt-suggest-all="1">' . esc_html( sprintf( __( 'Suggest all %d', 'signal-noise-tools' ), (int) $check['count'] ) ) . '</button>';
		}
		echo '</h2>';

		if ( 0 === (int) $check['count'] ) {
			echo '<p class="sn-fieldset-intro">No findings.</p>';
			echo '</div>';
			continue;
		}

		if ( ! empty( $check['fix_hint'] ) ) {
			echo '<p class="sn-fieldset-intro">' . esc_html( $check['fix_hint'] ) . '</p>';
		}

		// Cap visible rows at 50.
		$visible = array_slice( $check['findings'], 0, 50 );
		$hidden  = count( $check['findings'] ) - count( $visible );

		$show_ai_col = $ai_available && in_array( $key, $suggest_supported_checks, true );
		echo '<div class="snt-scroll-table">';
		echo '<table class="widefat striped" style="margin-top:0.5rem;"><thead><tr>';
		echo '<th scope="col" style="width:' . ( $show_ai_col ? '40%' : '55%' ) . ';">Subject</th>';
		echo '<th scope="col">Note</th>';
		echo '<th scope="col" style="width:90px;">Action</th>';
		if ( $show_ai_col ) {
			echo '<th scope="col" style="width:280px;">' . esc_html__( 'AI fix', 'signal-noise-tools' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $visible as $f ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $f['subject_label'] ) . '</code>';
			if ( ! empty( $f['subject_url'] ) ) {
				echo '<br><small><a href="' . esc_url( $f['subject_url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $f['subject_url'] ) . '</a></small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( (string) ( $f['note'] ?? '' ) ) . '</td>';
			echo '<td>';
			if ( ! empty( $f['edit_url'] ) ) {
				echo '<a href="' . esc_url( $f['edit_url'] ) . '" class="button button-small">Edit</a>';
			}
			echo '</td>';
			if ( $show_ai_col ) {
				echo '<td>';
				echo sn_health_render_suggest_cell( $key, $f );
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		if ( $hidden > 0 ) {
			echo '<p class="sn-field-helper">+' . (int) $hidden . ' more findings — re-run scan after fixing the top batch.</p>';
		}

		echo '</div>'; // .sn-fieldset
	} // end foreach ( $last_scan['checks'] as $key => $check )

	// v4.3.0: Opportunities sub-section (pattern-adoption Suggest+Apply).
	if ( function_exists( 'snt_pattern_adoption_render_opportunities_section' ) ) {
		snt_pattern_adoption_render_opportunities_section();
	}
} // end function sn_health_render_admin_tab

/**
 * Render the AI-fix table cell content for a finding.
 *
 * Returns a Suggest button (with data-attributes that the shared JS module
 * assets/health-suggest-actions.js consumes) for every supported check key.
 *
 * Supported check keys (as of v4.1.0):
 *   - missing_alt              — attachment-alt Suggest+Apply (v4.0.0)
 *   - missing_alt (inline_img) — inline-<img> Suggest+Copy, apply:null (v4.0.2)
 *   - drift_time_phrases       — time-phrase Suggest+Apply (v4.0.0)
 *   - orphaned_media           — orphan-verdict Suggest+Apply, modal-confirmed (v4.1.0)
 *
 * @param string $check_key The Health check key.
 * @param array  $finding   One finding row from the scan result.
 * @return string HTML for the cell content (escaped via esc_attr) — may be empty.
 *
 * @since 4.0.0
 */
function sn_health_render_suggest_cell( $check_key, $finding ) {
	// v4.0.2: inline-img findings now emit a Suggest button (was empty in v4.0.0).
	// The button uses a distinct check key so the JS dispatch table can route to
	// the sibling ability signal-noise/ai-alt-inline-suggest with apply: null.
	$attrs = array(
		'type'             => 'button',
		'class'            => 'button button-small',
		'data-snt-suggest' => '1',
	);

	if ( 'missing_alt' === $check_key ) {
		$is_inline = isset( $finding['subject_type'] ) && 'inline_img' === $finding['subject_type'];
		$attrs['data-check'] = $is_inline ? 'missing_alt_inline' : 'missing_alt';
		if ( $is_inline ) {
			$attrs['data-post-id']   = (int) ( $finding['subject_id'] ?? 0 );
			$attrs['data-image-src'] = (string) ( $finding['subject_url'] ?? '' );
		} else {
			// Attachment case — subject_id IS the attachment ID.
			$attrs['data-attachment-id'] = (int) ( $finding['subject_id'] ?? 0 );
		}
	} elseif ( 'drift_time_phrases' === $check_key ) {
		$attrs['data-check']    = $check_key;
		$attrs['data-post-id']  = (int) ( $finding['subject_id'] ?? 0 );
		$attrs['data-phrase']   = isset( $finding['phrase'] ) ? (string) $finding['phrase'] : '';
		$attrs['data-position'] = (int) ( $finding['position'] ?? 0 );
		$attrs['data-context']  = isset( $finding['context_snippet'] ) ? (string) $finding['context_snippet'] : '';
	} elseif ( 'orphaned_media' === $check_key ) {
		$attrs['data-check']         = 'orphaned_media';
		$attrs['data-attachment-id'] = (int) ( $finding['subject_id'] ?? 0 );
	} elseif ( 'pattern_adoption_pull_quote' === $check_key || 'pattern_adoption_steps_enumerated' === $check_key ) {
		// v4.3.0: pattern-adoption opportunities — rendered by snt_pattern_adoption_render_opportunities_section,
		// not by this generic suggest_cell helper. This branch exists so the existing $suggest_supported_checks
		// gate in sn_health_render_admin_tab doesn't trip if someone wires these check keys into the general
		// findings table by accident. NOTE: the candidate row shape uses 'block_fingerprint' (not 'fingerprint')
		// — verified against snt_pattern_adoption_detect_candidates() return shape.
		$attrs['data-check']         = $check_key;
		$attrs['data-post-id']       = (int) ( $finding['post_id'] ?? 0 );
		$attrs['data-fingerprint']   = (string) ( $finding['block_fingerprint'] ?? '' );
		$attrs['data-pattern-type']  = (string) ( $finding['pattern_type'] ?? '' );
	}

	$html = '<button';
	foreach ( $attrs as $k => $v ) {
		$html .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
	}
	$html .= '>' . esc_html__( 'Suggest', 'signal-noise-tools' ) . '</button>';

	return $html;
}
