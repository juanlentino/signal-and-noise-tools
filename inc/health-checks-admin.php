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

/**
 * Hard cap on how many AI calls one "Suggest all" click may fire (v6.39.2).
 * A section can list dozens of findings; without a cap, one click would
 * sequentially fire one AI call per finding (cost + provider rate pressure).
 * The button label shows min(count, this) and the JS honors the same ceiling.
 */
if ( ! defined( 'SNT_AI_SUGGEST_ALL_MAX' ) ) {
	define( 'SNT_AI_SUGGEST_ALL_MAX', 50 );
}

/**
 * Build the "Suggest all N" batch button markup, with N clamped to the cost
 * cap so the label never promises more AI calls than the JS will fire. The
 * cap is also emitted as a data attribute the JS reads to bound the batch.
 *
 * @param int $count Number of findings in the section.
 * @return string Button HTML.
 *
 * @since 6.39.2
 */
function snt_health_suggest_all_button_html( $count ) {
	$shown = min( (int) $count, SNT_AI_SUGGEST_ALL_MAX );
	return '<button type="button" class="button button-small sn-ml-auto" data-snt-suggest-all="1" data-snt-suggest-all-max="' . esc_attr( SNT_AI_SUGGEST_ALL_MAX ) . '">'
		/* translators: %d is the number of items to suggest */
		. esc_html( sprintf( __( 'Suggest all %d', 'signal-and-noise-tools' ), $shown ) )
		. '</button>';
}

add_action( 'sn_admin_health_tab', 'sn_health_render_admin_tab' );

// snt_health_format_elapsed() moved to inc/health-summary.php in v8.0.4
// (Insights adopted it, so it lives with the other shared projections).

/**
 * Build the first-glance hero cards for the Health tab, sourced ONLY from the
 * cached scan (no new accessor). Mirrors the dashboard's Health glance card
 * (inc/admin-tab-dashboard.php). Three cards when a scan exists — total findings,
 * checks-passed ratio, last-scan age — and a single "no scan" card otherwise.
 *
 * @param array|null $scan sn_health_last_scan() result.
 * @return array<int,array<string,mixed>> Cards for sn_admin_glance_grid().
 *
 * @since 6.44.0
 */
function snt_health_glance_cards( $scan ) {
	if ( ! is_array( $scan ) ) {
		return array(
			array(
				'label' => 'Health',
				'value' => 'no scan',
				'pill'  => array( 'kind' => 'warn', 'text' => 'run a scan' ),
			),
		);
	}

	$checks      = is_array( $scan['checks'] ?? null ) ? $scan['checks'] : array();
	$check_count = count( $checks );
	// Shared accessors (inc/health-summary.php) so this hero, the Dashboard-tab
	// glance card + attention strip, and the S&N Health widget never disagree.
	$total    = sn_health_finding_total( $scan );
	$advisory = sn_health_advisory_total( $scan );
	// v8.0.4: the passed RATIO uses the RAW count split so it always agrees
	// with the passing strip + findings section below (a check carrying
	// advisories is not "passing"); the PILL keys off real findings only —
	// advisories alone must not demand review.
	$passed_raw = 0;
	foreach ( $checks as $check ) {
		if ( 0 === (int) ( $check['count'] ?? 0 ) ) {
			$passed_raw++;
		}
	}
	$needs_review = count( sn_health_flagged_checks( $scan ) ) > 0;
	$age          = ! empty( $scan['scanned_at'] ) ? human_time_diff( (int) $scan['scanned_at'], time() ) . ' ago' : 'age unknown';

	$findings_meta = sprintf( 'across %d check%s', $check_count, 1 === $check_count ? '' : 's' );
	if ( $advisory > 0 ) {
		$findings_meta .= sprintf( ' · %d advisor%s', $advisory, 1 === $advisory ? 'y' : 'ies' );
	}

	return array(
		array(
			'label'     => 'Findings',
			'value'     => sprintf( '%d finding%s', $total, 1 === $total ? '' : 's' ),
			'pill'      => array(
				'kind' => $total > 0 ? 'warn' : 'ok',
				'text' => $total > 0 ? 'issues found' : 'all clear',
			),
			'meta_html' => esc_html( $findings_meta ),
		),
		array(
			'label' => 'Checks passed',
			'value' => sprintf( '%d / %d', $passed_raw, $check_count ),
			'pill'  => array(
				'kind' => $needs_review ? 'warn' : 'ok',
				'text' => $needs_review ? 'review' : 'clean',
			),
		),
		array(
			'label'     => 'Last scan',
			'value'     => $age,
			'meta_html' => esc_html( 'ran in ' . snt_health_format_elapsed( $scan['elapsed_ms'] ?? 0 ) ),
		),
	);
}

function sn_health_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ai_available             = function_exists( 'snt_ai_is_available' ) && snt_ai_is_available();
	$suggest_supported_checks = array( 'missing_alt', 'drift_time_phrases', 'orphaned_media', 'pattern_adoption_pull_quote', 'pattern_adoption_steps_enumerated', 'unlinked_mentions', 'link_opportunities' );

	$last_scan = sn_health_last_scan();

	// ── First-glance hero (v6.44.0). Replaces the bare intro <p> + the v6.42.0
	// rail status box: the headline numbers (findings, checks-passed, scan age)
	// read better here than squeezed into a 300px rail. Full-width, no shell. ──
	echo '<section aria-label="Health at a glance">';
	sn_admin_glance_grid( snt_health_glance_cards( $last_scan ) );
	echo '</section>';

	// ── Run scan (v10.46.0). The v8.0.1 .sn-health-actions grid paired this card
	// with the pattern-adoption Opportunities card so neither sat 820px-capped
	// with a dead right column. Opportunities has left for its own Content →
	// Pattern Adoption leaf, so that grid would have exactly one child — and an
	// auto-fit grid with one child stretches it edge to edge, which is precisely
	// the bare-stretched lone form the Phase-4b width rule forbids. The wrapper
	// goes with it and the card falls back to its own capped .sn-fieldset width.
	// (.sn-health-actions rules removed from assets/admin.css in the same
	// change — this was their only call site.) ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Run scan</h2>';
	echo '<p class="sn-fieldset-intro">Sweeps posts, media, and links for content issues; AI-assisted fixes appear inline when a provider is configured. Results persist until the next scan.</p>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="health_scan" class="button button-primary">' . esc_html( $last_scan ? 'Re-run scan' : 'Run scan' ) . '</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';

	if ( ! $last_scan ) {
		// The hero already shows the "no scan" card — nothing more to render.
		return;
	}

	// ── Split checks: with-findings get a full-width card + table; passing checks
	// collapse into a compact pass board (was a full fieldset each just to say
	// "No findings."). ──
	$with_findings = array();
	$passing       = array();
	foreach ( $last_scan['checks'] as $key => $check ) {
		if ( (int) $check['count'] > 0 ) {
			$with_findings[ $key ] = $check;
		} else {
			$passing[ $key ] = $check;
		}
	}

	// ── Findings: one full-width card + table per check with issues. ──
	if ( ! empty( $with_findings ) ) {
		echo '<h2 class="sn-section-h">Findings</h2>';
		// v6.47.0: scope a full-width uncap to the findings cards only (NOT the
		// short scan form above), so the wide 4-column finding tables use the page
		// width instead of staying 820px-capped with dead space beside them.
		echo '<div class="sn-health-findings">';
		foreach ( $with_findings as $key => $check ) {
			echo '<div class="sn-fieldset">';

			echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
			echo esc_html( $check['label'] );
			echo '<span class="sn-pill sn-pill--warn">' . esc_html( $check['count'] ) . ' finding' . ( 1 === (int) $check['count'] ? '' : 's' ) . '</span>';
			if ( $ai_available && in_array( $key, $suggest_supported_checks, true ) ) {
				echo snt_health_suggest_all_button_html( (int) $check['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
			}
			echo '</h2>';

			if ( ! empty( $check['fix_hint'] ) ) {
				echo '<p class="sn-fieldset-intro">' . esc_html( $check['fix_hint'] ) . '</p>';
			}

			// Cap visible rows at 50.
			$visible = array_slice( $check['findings'], 0, 50 );
			$hidden  = count( $check['findings'] ) - count( $visible );

			$show_ai_col = $ai_available && in_array( $key, $suggest_supported_checks, true );
			echo '<div class="snt-scroll-table">';
			echo '<table class="widefat striped snt-mt-half"><thead><tr>';
			echo '<th scope="col" class="' . ( $show_ai_col ? 'snt-col-40' : 'snt-col-55' ) . '">Subject</th>';
			echo '<th scope="col">Note</th>';
			echo '<th scope="col" class="snt-col-90px">Action</th>';
			if ( $show_ai_col ) {
				echo '<th scope="col" class="snt-col-280px">' . esc_html__( 'AI fix', 'signal-and-noise-tools' ) . '</th>';
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
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sn_health_render_suggest_cell() returns markup with every attribute esc_attr-escaped and the label esc_html-escaped.
					echo sn_health_render_suggest_cell( $key, $f );
					echo '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';

			if ( $hidden > 0 ) {
				echo '<p class="sn-field-helper">+' . (int) $hidden . ' more findings: re-run scan after fixing the top batch.</p>';
			}

			echo '</div>'; // .sn-fieldset
		}
		echo '</div>'; // .sn-health-findings
	}

	// ── Passing checks: ONE strip (v8.0.1). The v6.44.0 pass board rendered a
	// full card per clean check (label + "clear" + green pill = the same fact
	// three times, ×10 in the all-clear state). One heading carries the count,
	// one ok pill carries the color, and the check names collapse to chips. ──
	if ( ! empty( $passing ) ) {
		$pass_count  = count( $passing );
		$check_count = count( $last_scan['checks'] );
		$heading     = ( $pass_count === $check_count )
			? sprintf( 'All %d check%s passing', $pass_count, 1 === $pass_count ? '' : 's' )
			: sprintf( '%d of %d checks passing', $pass_count, $check_count );
		echo '<div class="sn-fieldset sn-health-passing">';
		echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
		echo esc_html( $heading );
		echo '<span class="sn-pill sn-pill--ok">pass</span>';
		echo '</h2>';
		echo '<p class="sn-health-passing__names">';
		foreach ( $passing as $check ) {
			echo '<span class="sn-badge">' . esc_html( (string) $check['label'] ) . '</span>';
		}
		echo '</p>';
		echo '</div>'; // .sn-health-passing
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
	} elseif ( 'unlinked_mentions' === $check_key || 'link_opportunities' === $check_key ) {
		// v7.4.0 (mentions) / v8.1.0 (semantic pairs): one button per
		// (source, target) pair. Suggest re-derives everything server-side
		// from the two ids — no scan payload rides the button, so a stale
		// finding degrades to a clean 409.
		$attrs['data-check']     = $check_key;
		$attrs['data-post-id']   = (int) ( $finding['subject_id'] ?? 0 );
		$attrs['data-target-id'] = (int) ( $finding['target_id'] ?? 0 );
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
	$html .= '>' . esc_html__( 'Suggest', 'signal-and-noise-tools' ) . '</button>';

	return $html;
}
