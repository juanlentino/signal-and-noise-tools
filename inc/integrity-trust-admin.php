<?php
/**
 * Signal & Noise Tools — Integrity → Trust checks leaf.
 *
 * WHY THIS EXISTS. Four of the eighteen Content-Health checks are not about
 * content at all — they are the site's trust surface:
 *
 *   provenance_integrity  the payload-hash / live-twin / ledger+key triangle
 *   ledger_ci             the public ledger's own daily verify workflow
 *   rights_signals        the live rights surface at the edge (tdmrep, RSL, TDM)
 *   rights_anchored       whether those rights bytes are still being re-anchored
 *
 * They were reachable only as four rows inside an eighteen-row Health tab,
 * interleaved with missing alt text and stale posts. Nothing said they belonged
 * together, and nothing connected them to the Provenance console sitting on
 * another tab entirely — even though that console is the thing they are checking.
 * The v10.46.0 IA audit named Tools a junk drawer; this is what gives the tab a
 * concept instead of padding.
 *
 * WHAT THIS DELIBERATELY IS NOT. It runs nothing. It fetches nothing. It
 * registers no route, no cron, no ability. Every number here is read out of the
 * health scan that already ran (`sn_health_last_scan()`, an autoload=no option),
 * so opening this leaf costs one option read. The four checks keep their single
 * home in the scan; this is a second VIEW of them, not a second COPY — which is
 * the same rule snt_analytics_render_mirrors() follows in the other direction.
 *
 * A stale reading is therefore possible and is stated on the page rather than
 * hidden: the scan age is shown, and re-running is a link to Measurement →
 * Health, not a button here (one scan surface, one owner).
 *
 * @package SignalNoiseTools
 * @since 10.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four health-check keys that make up the trust surface, in reading order:
 * what is claimed (provenance), whether the claim record is sound (ledger CI),
 * what machines are told (rights signals), and whether that telling is still
 * being anchored (rights anchored).
 *
 * Pure data so tests/integrity-trust-admin.php can assert every key still exists
 * in the health registry — a check that gets renamed or dropped must not leave a
 * silently empty card here.
 *
 * @since 10.47.0
 * @return array<string,array{label:string,blurb:string}>
 */
function snt_trust_check_keys() {
	return array(
		'provenance_integrity' => array(
			'label' => __( 'Provenance triangle', 'signal-and-noise-tools' ),
			'blurb' => __( 'Payload hash, the live .json twin, and the public ledger record plus its key file still agree.', 'signal-and-noise-tools' ),
		),
		'ledger_ci'            => array(
			'label' => __( 'Ledger CI', 'signal-and-noise-tools' ),
			'blurb' => __( 'The public ledger verifies itself daily. This is that workflow&rsquo;s latest verdict.', 'signal-and-noise-tools' ),
		),
		'rights_signals'       => array(
			'label' => __( 'Rights signals', 'signal-and-noise-tools' ),
			'blurb' => __( 'The TDM reservation, RSL licence, and robots Content-Signal lines are live at the edge.', 'signal-and-noise-tools' ),
		),
		'rights_anchored'      => array(
			'label' => __( 'Rights anchoring', 'signal-and-noise-tools' ),
			'blurb' => __( 'The rights bytes being served right now still match the newest ledger record.', 'signal-and-noise-tools' ),
		),
	);
}

/**
 * Build the glance cards for the trust checks out of a scan result. PURE — takes
 * the scan, returns card definitions for sn_admin_glance_grid(); no reads, no
 * output, so the card logic is unit-testable without a WP bootstrap.
 *
 * A check absent from the scan yields an explicit "not run" card rather than
 * being skipped: a missing trust check must be visible as missing, never as an
 * empty space that reads like a pass.
 *
 * @since 10.47.0
 * @param array|null $scan sn_health_last_scan() result.
 * @return array<int,array<string,mixed>>
 */
function snt_trust_cards( $scan ) {
	$checks = is_array( $scan ) && isset( $scan['checks'] ) && is_array( $scan['checks'] ) ? $scan['checks'] : array();
	$cards  = array();

	foreach ( snt_trust_check_keys() as $key => $meta ) {
		if ( ! isset( $checks[ $key ] ) || ! is_array( $checks[ $key ] ) ) {
			$cards[] = array(
				'label' => $meta['label'],
				'value' => __( 'not run', 'signal-and-noise-tools' ),
				'pill'  => array( 'kind' => 'warn', 'text' => __( 'no reading', 'signal-and-noise-tools' ) ),
			);
			continue;
		}
		$count  = (int) ( $checks[ $key ]['count'] ?? 0 );
		$cards[] = array(
			'label' => $meta['label'],
			'value' => $count > 0
				/* translators: %d: number of findings for one trust check. */
				? sprintf( _n( '%d finding', '%d findings', $count, 'signal-and-noise-tools' ), $count )
				: __( 'clear', 'signal-and-noise-tools' ),
			'pill'  => array(
				'kind' => $count > 0 ? 'warn' : 'ok',
				'text' => $count > 0 ? __( 'needs a look', 'signal-and-noise-tools' ) : __( 'holding', 'signal-and-noise-tools' ),
			),
		);
	}

	return $cards;
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

	// Provenance state is intentionally NOT duplicated here — the Provenance leaf
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

	foreach ( snt_trust_check_keys() as $key => $meta ) {
		$has   = isset( $checks[ $key ] ) && is_array( $checks[ $key ] );
		$count = $has ? (int) ( $checks[ $key ]['count'] ?? 0 ) : -1;

		echo '<tr>';
		echo '<td><strong>' . esc_html( $meta['label'] ) . '</strong></td>';
		echo '<td>' . wp_kses( $meta['blurb'], array() ) . '</td>';
		echo '<td>';
		if ( ! $has ) {
			echo '<span class="sn-pill sn-pill--warn">' . esc_html__( 'not run', 'signal-and-noise-tools' ) . '</span>';
		} elseif ( 0 === $count ) {
			echo '<span class="sn-pill sn-pill--ok">' . esc_html__( 'clear', 'signal-and-noise-tools' ) . '</span>';
		} else {
			echo '<span class="sn-pill sn-pill--warn">' . esc_html(
				/* translators: %d: number of findings. */
				sprintf( _n( '%d finding', '%d findings', $count, 'signal-and-noise-tools' ), $count )
			) . '</span>';
			// The finding NOTES are the whole value when something is wrong — an
			// integrity failure that only says "1 finding" sends the reader back to
			// the Health tab to find out which leg broke.
			foreach ( array_slice( (array) ( $checks[ $key ]['findings'] ?? array() ), 0, 3 ) as $finding ) {
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
			if ( count( (array) ( $checks[ $key ]['findings'] ?? array() ) ) > 3 ) {
				echo '<div class="sn-trust-finding"><a href="' . esc_url( $health_url ) . '">' . esc_html__( 'See all on Health →', 'signal-and-noise-tools' ) . '</a></div>';
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
	// of the whole apparatus — so they belong on this leaf rather than in Links.
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'The public side', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'The same guarantees, checkable by anyone without access to this admin.', 'signal-and-noise-tools' ) . '</p>';
	echo '<ul class="sn-trust-links">';
	echo '<li><a href="' . esc_url( home_url( '/verify/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verification docket', 'signal-and-noise-tools' ) . '</a>. ' . esc_html__( 'per-note signature, content hash, live match, and anchor, checked in the reader&rsquo;s own browser.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><a href="https://github.com/juanlentino/signal-and-noise-provenance" target="_blank" rel="noopener">' . esc_html__( 'Public ledger', 'signal-and-noise-tools' ) . '</a>. ' . esc_html__( 'the signed records and their daily verify workflow.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><a href="' . esc_url( home_url( '/tdm-policy/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'TDM policy', 'signal-and-noise-tools' ) . '</a>. ' . esc_html__( 'the human-readable terms behind the reservation headers.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><a href="' . esc_url( home_url( '/license.xml' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'RSL licence', 'signal-and-noise-tools' ) . '</a>. ' . esc_html__( 'machine-readable licensing terms.', 'signal-and-noise-tools' ) . '</li>';
	echo '</ul>';
	echo '</div>';
}
