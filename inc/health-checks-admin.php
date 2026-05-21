<?php
/**
 * Signal & Noise Tools — Health Checks admin tab.
 *
 * Renders the Content Health tab with a "Run scan" button + the
 * cached results from sn_health_last_scan(). One form action:
 * `health_scan` triggers sn_health_run_scan(), redirects with a
 * flash, the next GET shows the fresh result.
 *
 * @package SignalNoiseTools
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_health_tab', 'sn_health_render_admin_tab' );
add_action( 'admin_init', 'sn_health_handle_post' );

function sn_health_handle_post() {
	if ( ! isset( $_POST['sn_action'] ) || 'health_scan' !== $_POST['sn_action'] ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	check_admin_referer( 'sn_health' );

	sn_health_run_scan();

	$base_url = admin_url( 'admin.php?page=sn-health' );
	wp_safe_redirect( add_query_arg( 'sn_flash', 'health_scanned', $base_url ) );
	exit;
}

function sn_health_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'signal-noise-tools' ) );
	}

	$last_scan = sn_health_last_scan();

	echo '<div class="sn-health">';

	// Header + run button.
	echo '<p class="sn-field-helper">' . esc_html__( 'Detection-only scans of your post + attachment graph. v1 finds problems; the editor is the fix surface. Results cache for 24 hours.', 'signal-noise-tools' ) . '</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-health' ) ) . '" style="margin-bottom:1rem;">';
	wp_nonce_field( 'sn_health' );
	echo '<button type="submit" name="sn_action" value="health_scan" class="button button-primary">';
	echo esc_html( $last_scan ? __( 'Re-run scan', 'signal-noise-tools' ) : __( 'Run scan', 'signal-noise-tools' ) );
	echo '</button>';
	if ( $last_scan ) {
		echo ' <span class="description" style="margin-left:0.75rem;">';
		printf(
			/* translators: 1: human-readable time, 2: elapsed ms */
			esc_html__( 'Last scanned %1$s (%2$s in %3$dms).', 'signal-noise-tools' ),
			esc_html( wp_date( 'Y-m-d H:i:s', (int) $last_scan['scanned_at'] ) ),
			esc_html( human_time_diff( (int) $last_scan['scanned_at'], time() ) . ' ago' ),
			(int) $last_scan['elapsed_ms']
		);
		echo '</span>';
	}
	echo '</form>';

	if ( ! $last_scan ) {
		echo '<div class="sn-card"><p>' . esc_html__( 'No scan has run yet. Click "Run scan" above to populate findings.', 'signal-noise-tools' ) . '</p></div>';
		echo '</div>';
		return;
	}

	// One section per check.
	foreach ( $last_scan['checks'] as $key => $check ) {
		echo '<div class="sn-card" style="margin-bottom:1rem;">';
		echo '<h2 style="display:flex;align-items:baseline;gap:0.5rem;">';
		echo esc_html( $check['label'] );
		echo ' <span class="sn-health-count" style="font-size:0.85em;color:' . ( $check['count'] > 0 ? '#d63638' : '#46b450' ) . ';">';
		echo esc_html( sprintf( '(%d %s)', $check['count'], 1 === $check['count'] ? 'finding' : 'findings' ) );
		echo '</span>';
		echo '</h2>';

		if ( 0 === $check['count'] ) {
			echo '<p style="color:#46b450;">' . esc_html__( '✓ No findings.', 'signal-noise-tools' ) . '</p>';
			echo '</div>';
			continue;
		}

		if ( ! empty( $check['fix_hint'] ) ) {
			echo '<p class="description" style="margin-bottom:0.75rem;">' . esc_html( $check['fix_hint'] ) . '</p>';
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col" style="width:55%;">' . esc_html__( 'Subject', 'signal-noise-tools' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Note', 'signal-noise-tools' ) . '</th>';
		echo '<th scope="col" style="width:90px;">' . esc_html__( 'Action', 'signal-noise-tools' ) . '</th>';
		echo '</tr></thead><tbody>';

		// Cap visible rows at 50; deep lists collapse into a "+N more" indicator.
		$visible = array_slice( $check['findings'], 0, 50 );
		$hidden  = count( $check['findings'] ) - count( $visible );

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
				echo '<a href="' . esc_url( $f['edit_url'] ) . '" class="button button-small">' . esc_html__( 'Edit', 'signal-noise-tools' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $hidden > 0 ) {
			echo '<p class="description">';
			printf(
				/* translators: %d: number of additional findings hidden in the table */
				esc_html__( '+%d more findings — re-run scan after fixing the top batch.', 'signal-noise-tools' ),
				$hidden
			);
			echo '</p>';
		}

		echo '</div>';
	}

	echo '</div>'; // .sn-health
}
