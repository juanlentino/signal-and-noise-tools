<?php
/**
 * Signal & Noise Tools: Integrity > Trust checks, the classic leaf's renderer.
 *
 * Moved out of inc/integrity-trust-admin.php on 2026-10-11 (that file was over
 * the house line cap); the data it reads (the four keys, the readings, the
 * findings split) stays there and is shared with the S&N Home leaf
 * (apps/sn-dashboard/parts/leaves/tools-trust.php). Every reading comes from
 * snt_trust_reading(), so a check that ran and skipped reads "not measured"
 * with its reason here exactly as it does on S&N Home.
 *
 * @package SignalNoiseTools
 * @since 10.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Trust checks leaf. Hooked to sn_admin_trust_tab (delegator in
 * inc/admin-render-sections.php), same wiring as every other leaf.
 *
 * @since 10.47.0
 */
add_action( 'sn_admin_trust_tab', 'snt_trust_render_section' );

function snt_trust_render_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$scan       = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	$health_url = admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' );

	echo '<section aria-label="' . esc_attr__( 'Trust checks at a glance', 'signal-and-noise-tools' ) . '">';
	sn_admin_glance_grid( snt_trust_cards( $scan ) );
	echo '</section>';

	// Provenance state is intentionally NOT duplicated here: the Provenance leaf
	// beside this one owns the worker/genesis/pending/confirmed hero. This leaf
	// answers a different question: are the guarantees still verifiable.
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'What these four watch', 'signal-and-noise-tools' ) . '</h2>';

	if ( ! is_array( $scan ) ) {
		echo '<p class="sn-fieldset-intro">' . sprintf(
			/* translators: %s: link to the Health leaf, already escaped. */
			esc_html__( 'No health scan has run yet, so these four have no reading. They ride the same 24-hour cycle as every other check: run one from %s.', 'signal-and-noise-tools' ),
			'<a href="' . esc_url( $health_url ) . '">' . esc_html__( 'Measurement → Health', 'signal-and-noise-tools' ) . '</a>'
		) . '</p>';
	} else {
		$age = ! empty( $scan['scanned_at'] )
			? human_time_diff( (int) $scan['scanned_at'], time() )
			: '';
		echo '<p class="sn-fieldset-intro">' . sprintf(
			/* translators: 1: how long ago the scan ran; 2: link to the Health leaf, already escaped. */
			esc_html__( 'Read from the health scan that ran %1$s ago: this leaf never scans on its own. Re-run from %2$s.', 'signal-and-noise-tools' ),
			esc_html( $age ),
			'<a href="' . esc_url( $health_url ) . '">' . esc_html__( 'Measurement → Health', 'signal-and-noise-tools' ) . '</a>'
		) . '</p>';
	}

	$checks = is_array( $scan ) && isset( $scan['checks'] ) ? (array) $scan['checks'] : array();

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col" class="snt-col-20">' . esc_html__( 'Check', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-40">' . esc_html__( 'What it proves', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-40">' . esc_html__( 'Latest reading', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	// A skip is muted, not ok: unknown is never a pass, and not a finding either.
	$pill = array(
		'not_run'  => 'sn-pill--warn',
		'skipped'  => 'sn-pill--muted',
		'clear'    => 'sn-pill--ok',
		'findings' => 'sn-pill--warn',
	);
	foreach ( snt_trust_check_keys() as $key => $meta ) {
		$reading = snt_trust_reading( $checks[ $key ] ?? null );

		echo '<tr>';
		echo '<td><strong>' . esc_html( $meta['label'] ) . '</strong></td>';
		echo '<td>' . wp_kses( $meta['blurb'], array() ) . '</td>';
		echo '<td><span class="sn-pill ' . esc_attr( $pill[ $reading['state'] ] ) . '">' . esc_html( $reading['text'] ) . '</span>';
		if ( 'skipped' === $reading['state'] ) {
			// Unknown is never zero: the reason is the reading.
			echo '<div class="sn-trust-finding">' . esc_html( $reading['reason'] ) . '</div>';
		}
		if ( 'findings' === $reading['state'] ) {
			// The finding NOTES are the whole value when something is wrong: an
			// integrity failure that only says "1 finding" sends the reader back to
			// the Health tab to find out which leg broke.
			$split = snt_trust_findings_split( $checks[ $key ]['findings'] ?? array() );
			foreach ( $split['shown'] as $finding ) {
				$note    = (string) ( $finding['note'] ?? '' );
				$subject = (string) ( $finding['subject_label'] ?? '' );
				$url     = (string) ( $finding['subject_url'] ?? '' );
				echo '<div class="sn-trust-finding">';
				if ( '' !== $subject ) {
					echo '' !== $url
						? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $subject ) . '</code></a> '
						: '<code>' . esc_html( $subject ) . '</code> ';
				}
				echo esc_html( $note );
				echo '</div>';
			}
			// Name the count rather than just offering the link: "See all" makes
			// the reader subtract the shown rows from the pill above to learn how
			// much is behind it. House shape is "+N more".
			if ( $split['hidden'] > 0 ) {
				echo '<div class="sn-trust-finding"><a href="' . esc_url( $health_url ) . '">' . esc_html(
					sprintf(
						/* translators: %d: findings not shown here. */
						__( '+%d more on Health →', 'signal-and-noise-tools' ),
						$split['hidden']
					)
				) . '</a></div>';
			}
		}
		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset

	// Public-facing counterparts. These are the surfaces a READER uses to check
	// the same guarantees without trusting this admin at all, which is the point
	// of the whole apparatus, so they belong on this leaf rather than in Links.
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'The public side', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'The same guarantees, checkable by anyone without access to this admin.', 'signal-and-noise-tools' ) . '</p>';
	echo '<ul class="sn-trust-links">';
	echo '<li><a href="' . esc_url( home_url( '/verify/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verification docket', 'signal-and-noise-tools' ) . '</a>: ' . esc_html__( 'per-note signature, content hash, live match, and anchor, checked in the reader&rsquo;s own browser.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><a href="https://github.com/juanlentino/signal-and-noise-provenance" target="_blank" rel="noopener">' . esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . '</a>: ' . esc_html__( 'the signed records and their daily verify workflow.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><a href="' . esc_url( home_url( '/tdm-policy/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'TDM policy', 'signal-and-noise-tools' ) . '</a>: ' . esc_html__( 'the human-readable terms behind the reservation headers.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><a href="' . esc_url( home_url( '/license.xml' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'RSL license', 'signal-and-noise-tools' ) . '</a>: ' . esc_html__( 'machine-readable licensing terms.', 'signal-and-noise-tools' ) . '</li>';
	echo '</ul>';
	echo '</div>';
}
