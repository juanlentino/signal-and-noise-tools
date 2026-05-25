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

	$last_scan = sn_health_last_scan();

	// ── INTRO ──
	echo '<p class="sn-prose">Detection-only scans of your post + attachment graph. v1 finds problems; the editor is the fix surface. Results cache for 24 hours.</p>';

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
		echo '<p class="sn-status-box-body">' . esc_html( $total_findings ) . ' total finding' . ( 1 === $total_findings ? '' : 's' ) . ' across 4 checks · scan ran in ' . esc_html( (int) $last_scan['elapsed_ms'] ) . 'ms. Results cached until ' . esc_html( wp_date( 'Y-m-d H:i', (int) $last_scan['scanned_at'] + DAY_IN_SECONDS ) ) . '.</p>';
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

		echo '<h2 class="sn-fieldset-h" style="display:flex;align-items:baseline;gap:0.75rem;">';
		echo esc_html( $check['label'] );
		$pill_kind = $check['count'] > 0 ? 'warn' : 'ok';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' . esc_html( $check['count'] ) . ' finding' . ( 1 === (int) $check['count'] ? '' : 's' ) . '</span>';
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

		echo '<div class="snt-scroll-table">';
		echo '<table class="widefat striped" style="margin-top:0.5rem;"><thead><tr>';
		echo '<th scope="col" style="width:55%;">Subject</th>';
		echo '<th scope="col">Note</th>';
		echo '<th scope="col" style="width:90px;">Action</th>';
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
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		if ( $hidden > 0 ) {
			echo '<p class="sn-field-helper">+' . (int) $hidden . ' more findings — re-run scan after fixing the top batch.</p>';
		}

		echo '</div>'; // .sn-fieldset
	}
}
