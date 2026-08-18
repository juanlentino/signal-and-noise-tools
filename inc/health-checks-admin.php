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
	//
	// v10.83.0: report-only checks leave the NUMERATOR but not the DENOMINATOR.
	// They raise zero findings by design, so the raw split counted them as
	// passing — a verdict they cannot earn. sn_health_passing_checks() drops
	// them, sn_health_check_total() still counts every check the scan ran, and
	// the meta line names the difference so 17/19 is never read as two failures.
	$passed_raw   = count( sn_health_passing_checks( $scan ) );
	$report_count = count( sn_health_report_checks( $scan ) );
	$needs_review = count( sn_health_flagged_checks( $scan ) ) > 0;
	$age          = ! empty( $scan['scanned_at'] ) ? human_time_diff( (int) $scan['scanned_at'], time() ) . ' ago' : 'age unknown';

	$findings_meta = sprintf( 'across %d check%s', $check_count, 1 === $check_count ? '' : 's' );
	if ( $advisory > 0 ) {
		$findings_meta .= sprintf( ' · %d advisor%s', $advisory, 1 === $advisory ? 'y' : 'ies' );
	}

	// v11.12.1: name EVERY bucket that is not in the numerator, not just the
	// report-only one. "17 / 21 · 2 report-only not counted" left two checks
	// unexplained and read as two failures, when one was a finding and one was
	// an advisory the page elsewhere calls "never alarming". The line now closes
	// the arithmetic: passed + what this names === total.
	$passed_meta = sn_health_passed_meta( $scan );

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
			'label'     => 'Checks passed',
			'value'     => sprintf( '%d / %d', $passed_raw, $check_count ),
			'pill'      => array(
				'kind' => $needs_review ? 'warn' : 'ok',
				'text' => $needs_review ? 'review' : 'clean',
			),
			'meta_html' => '' !== $passed_meta ? esc_html( $passed_meta ) : '',
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

	// ── v11.13.0: HEALTH IS DEFECTS ONLY. ──
	//
	// The scan still runs all 21 checks and the envelope is untouched — every
	// MCP consumer keeps its shape. What changed is that this TAB now renders
	// one surface of it. The page had grown four kinds of thing under a single
	// fraction (defects, worklists, measurements, scan meta) and seven
	// disclaimers, four of which existed only to say the numbers above them do
	// not mean what they look like. Ownership now lives in one map
	// (inc/health-check-surfaces.php) instead of in prose on the page.
	$health_scan = is_array( $last_scan ) ? $last_scan : null;
	if ( is_array( $health_scan ) ) {
		$health_scan['checks'] = sn_health_checks_for_surface( $last_scan, 'health' );
	}

	// ── First-glance hero (v6.44.0). Replaces the bare intro <p> + the v6.42.0
	// rail status box: the headline numbers (findings, checks-passed, scan age)
	// read better here than squeezed into a 300px rail. Full-width, no shell. ──
	echo '<section aria-label="Health at a glance">';
	sn_admin_glance_grid( snt_health_glance_cards( $health_scan ) );
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

	// ── Split checks THREE ways (v10.83.0, was two). ──
	//
	//   findings  — count > 0. Demand action, so they come first and stay open.
	//   reports   — carry a `report` payload. Measure and publish; they cannot
	//               fail, so they are neither a finding nor a pass. Before this
	//               split they fell into `passing` and rendered as a single
	//               green chip, which is how contrast_tokens shipped a full
	//               pair table that was invisible in admin.
	//   passing   — zero findings, no report. Ask nothing; collapsed.
	//
	// The report test is STRUCTURAL (sn_health_check_has_report), never a key
	// list, so the next report-only check has a home the day it ships.
	//
	// v11.13.0: all three read the HEALTH surface. `reports` is now always
	// empty here by construction — contrast_tokens and motion_scan render on
	// Integrity — but the split is kept rather than deleted, so a future
	// report-only DEFECT still has a home and the structural test above keeps
	// meaning something.
	$with_findings = array();
	$reports       = sn_health_report_checks( $health_scan );
	$passing       = sn_health_passing_checks( $health_scan );
	foreach ( $health_scan['checks'] as $key => $check ) {
		// A report check is excluded EXPLICITLY, not left to the invariant that
		// report-only checks carry zero findings. That invariant is a
		// convention of the current producer (sn_health_check_contrast_tokens
		// hardcodes an empty findings array), not something sn_health_pack_check
		// enforces — and if a future report ever reported count>0 it would
		// render in Findings AND Reports at once, which is exactly the "neither
		// a finding nor a pass" contract broken. Two lines now beat a
		// contradiction later.
		if ( sn_health_check_has_report( $check ) ) {
			continue;
		}
		if ( (int) ( $check['count'] ?? 0 ) > 0 ) {
			$with_findings[ $key ] = $check;
		}
	}

	// ── Findings: faults grouped by family, advisories folded (IA H5). The
	// section moved to inc/health-render-findings.php when the loop grew a
	// second shape; this file keeps the glance, the run-scan card, and the
	// three-way split. ──
	sn_health_render_findings_section( $with_findings, $ai_available, $suggest_supported_checks );

	// ── Reports: report-only payloads, between the findings that demand action
	// and the passing disclosure that asks nothing (v10.83.0). ──
	sn_health_render_reports_section( $reports );

	// ── Passing checks: ONE collapsed disclosure (v10.83.0; the v8.0.1 open
	// strip before it, the v6.44.0 card-per-check grid before that). Nineteen
	// name chips in an open row is a wall the eye cannot parse, and it sat
	// between the reader and everything below it. The summary line carries the
	// whole message a healthy site needs; the names are one click away, grouped
	// by family. ──
	sn_health_render_passing_section( $passing, sn_health_check_total( $health_scan ), count( $reports ) );

	// ── Where the rest went (v11.13.0). Relocating a check must never make it
	// silently disappear — silence read as freshness is the exact failure this
	// whole arc is about. Every check the scan still runs is named here with
	// its new home, so a reader who remembers seeing it can find it. ──
	sn_health_render_elsewhere_section( $last_scan );
} // end function sn_health_render_admin_tab

/**
 * A short, quiet index of the checks that run but render elsewhere.
 *
 * Deliberately not a table — but the counts DO show. The first draft omitted
 * them on the theory that any number here would compete with the defect count
 * above; that was over-correction. v11.12.0 exists precisely so a declared-
 * Evergreen post that is stale by measurement stops being invisible, and a bare
 * name with no number re-hides it one release later. A worklist count is not a
 * defect count, and the page says which is which.
 *
 * @param array|null $scan
 * @return void Echoes.
 */
function sn_health_render_elsewhere_section( $scan ) {
	$groups = array(
		'integrity' => array( 'Integrity → Trust checks and Reports', 'proof and measurement: they publish rather than flag' ),
		'deploy'    => array( 'Deploy Status', 'facts about a repo or worker, not this site\'s content' ),
		'worklist'  => array( 'the scan door and Analytics recommendations', 'opportunities that never resolve and never block — a queue, not a fault' ),
	);
	$rows = array();
	foreach ( $groups as $surface => $meta ) {
		$checks = sn_health_checks_for_surface( $scan, $surface );
		if ( ! $checks ) {
			continue;
		}
		$labels = array();
		foreach ( $checks as $key => $check ) {
			$n       = (int) ( $check['count'] ?? 0 );
			$label   = (string) ( $check['label'] ?? $key );
			$labels[] = $n > 0 ? sprintf( '%s (%s)', $label, number_format_i18n( $n ) ) : $label;
		}
		sort( $labels );
		$rows[] = sprintf(
			'<li><strong>%s</strong> — %s: %s</li>',
			esc_html( $meta[0] ),
			esc_html( $meta[1] ),
			esc_html( implode( ', ', $labels ) )
		);
	}
	if ( ! $rows ) {
		return;
	}
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Also scanned, shown elsewhere', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'These still run on every scan. They are not defects, so they do not belong to a number that should read zero — but nothing here is hidden.', 'signal-and-noise-tools' ) . '</p>';
	echo '<ul class="sn-health-elsewhere">' . implode( '', $rows ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each row assembled from esc_html() above.
	echo '</div>';
}

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
		// v10.77.0: this was a BINARY (inline_img ? inline : attachment). The
		// check now emits inline_svg, attachment_alt_quality and
		// inline_img_alt_quality too, and every one of them would have fallen
		// into the attachment branch -- handing the vision-based alt suggester
		// a POST id as an attachment id. Route by exact subject type, and
		// return no button for the types that have no AI suggest path:
		//   - inline_svg: the fix is markup (<title>/aria-label), not a caption
		//     derived from pixels, and there is no attachment to look at.
		//   - *_alt_quality: alt already exists; replacing it is a human
		//     rewrite through the staged-revision path.
		$subject = isset( $finding['subject_type'] ) ? (string) $finding['subject_type'] : '';
		if ( 'inline_img' === $subject ) {
			$attrs['data-check']     = 'missing_alt_inline';
			$attrs['data-post-id']   = (int) ( $finding['subject_id'] ?? 0 );
			$attrs['data-image-src'] = (string) ( $finding['subject_url'] ?? '' );
		} elseif ( 'attachment' === $subject ) {
			// Attachment case — subject_id IS the attachment ID.
			$attrs['data-check']         = 'missing_alt';
			$attrs['data-attachment-id'] = (int) ( $finding['subject_id'] ?? 0 );
		} else {
			return '';
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
