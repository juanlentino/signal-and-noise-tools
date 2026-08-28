<?php
/**
 * Signal & Noise — AI models & budget (AI tab → Models & Budget sub-tab).
 *
 * Extracted from inc/admin-forms/front-end.php in v10.46.0. Until then these
 * three settings were fields 8-10 of the Front-End render-knobs form, whose own
 * intro described its contents as "render knobs the companion theme reads via
 * filters". Two of them are not render knobs and the third is a hard cap on real
 * money — a monthly ceiling that pauses every AI feature in the plugin when it
 * is reached. Burying that in a form about reading speed and pagination was the
 * single clearest finding of the v10.45.0 admin IA audit.
 *
 * WHAT DID NOT MOVE: the `theme.` key namespace. `theme.ai_model`,
 * `theme.ai_alt_model` and `theme.ai_monthly_budget` keep their stored names —
 * renaming them would need a settings migration and would break the readers in
 * inc/analytics-render-settings.php and inc/ai-bootstrap/. The keys describe
 * where they were born, not where they are edited; only the editing surface
 * moved.
 *
 * SAVE PATH: sn_action=ai_settings_save → sn_handle_ai_settings_save()
 * (inc/admin-post-actions/theme-ai.php). Splitting one form into two is normally the
 * settings-subtree clobber trap, but sn_handle_save_theme() writes with per-key
 * sn_setting_update() rather than a subtree write, so the two handlers cannot
 * overwrite each other's keys. See the note on sn_handle_ai_settings_save().
 *
 * Wide leaf (v10.47.0): sn_admin_render_section() emits a bare .sn-section, so
 * this form owns its own .sn-fieldset — same contract as the Front-End form it
 * was extracted from, and the reason the field grid can lay out across the full
 * content width.
 *
 * @package SignalNoiseTools
 * @since 10.46.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the AI settings form. Used as the sn_admin_render_section() callback
 * for the AI tab's 'models-budget' sub-tab.
 *
 * @since 10.46.0
 */
function sn_admin_render_ai_settings_form() {
	$model     = (string) sn_setting( 'theme.ai_model', 'claude-sonnet-5' );
	$alt_model = (string) sn_setting( 'theme.ai_alt_model', 'gemini-2.5-flash-lite' );
	$budget    = (float) sn_setting( 'theme.ai_monthly_budget', 0 );
	$spent     = function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0;

	echo '<form method="post" class="sn-ai-settings-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="ai_settings_save">';

	// v10.47.0: the leaf became 'wide', so the dispatcher emits a bare
	// .sn-section and this form owns its card — the same contract the Front-End
	// form follows. Live, the capped version rendered a ~620px card in a ~1200px
	// tab: two thirds of the leaf empty. .sn-ai-settings-form .sn-fieldset lays
	// the fields out as auto-fit columns (assets/admin.css), the Phase-4a
	// treatment where a multi-field form earns its width by making the FIELDS the
	// columns rather than stretching one.
	echo '<div class="sn-fieldset">';

	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Models &amp; budget', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'Which models this plugin calls, and the ceiling on what they may cost. Every AI feature here (drafts, insights, meta descriptions, alt text) draws on the same monthly budget.', 'signal-and-noise-tools' ) . '</p>';

	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="sn_theme_ai_model">' . esc_html__( 'Prose model', 'signal-and-noise-tools' ) . '</label>';
	echo '<select id="sn_theme_ai_model" name="theme_ai_model">';
	foreach ( sn_theme_ai_models() as $id => $label ) {
		echo '<option value="' . esc_attr( $id ) . '"' . selected( $model, $id, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="sn-field-helper">' . esc_html__( 'Used for AI-assisted prose features (drafts, insights, meta descriptions).', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="sn_theme_ai_alt_model">' . esc_html__( 'Vision model (alt text)', 'signal-and-noise-tools' ) . '</label>';
	echo '<select id="sn_theme_ai_alt_model" name="theme_ai_alt_model">';
	foreach ( sn_theme_ai_vision_models() as $id => $label ) {
		echo '<option value="' . esc_attr( $id ) . '"' . selected( $alt_model, $id, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	/* translators: %s: the alt-text model filter name, wrapped in <code>. */
	echo '<p class="sn-field-helper">' . sprintf( esc_html__( 'Used to LOOK at images when suggesting alt text. The %s filter still overrides this for code-level pins.', 'signal-and-noise-tools' ), '<code>snt_ai_alt_text_model</code>' ) . '</p>';
	echo '</div>';

	// The budget field carries the spend readout that used to be a sentence
	// fragment in a helper line. .sn-an-mirror-meter is the analytics settings
	// hub's existing spend bar — analytics-admin.css is enqueued on every SN
	// admin page (inc/admin-menu.php:121, deliberately: its rules are scoped to
	// .sn-an-* so loading it everywhere is inert), so this reuses the shipped
	// component rather than inventing a second meter that would drift from it.
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_ai_monthly_budget">' . esc_html__( 'Monthly budget (USD)', 'signal-and-noise-tools' ) . '</label>';
	echo '<input type="number" min="0" step="0.5" id="sn_theme_ai_monthly_budget" name="theme_ai_monthly_budget" value="' . esc_attr( number_format( $budget, 2, '.', '' ) ) . '">';
	echo '</div>';

	echo '<div class="sn-field">';
	if ( $budget > 0 ) {
		$pct_true  = (int) round( ( $spent / $budget ) * 100 );
		$pct_width = max( 0, min( 100, $pct_true ) );
		echo '<p class="sn-field-helper">' . sprintf(
			/* translators: 1: spend so far this month, 2: the configured budget, 3: percent of budget used. */
			esc_html__( 'Spent this month: $%1$s of $%2$s (%3$s%%).', 'signal-and-noise-tools' ),
			esc_html( number_format_i18n( $spent, 2 ) ),
			esc_html( number_format_i18n( $budget, 2 ) ),
			esc_html( number_format_i18n( $pct_true ) )
		) . '</p>';
		echo '<span class="sn-an-mirror-meter"><span style="width:' . esc_attr( (string) $pct_width ) . '%"></span></span>';
		if ( $spent >= $budget ) {
			echo '<p class="sn-field-helper"><strong>' . esc_html__( 'The cap is reached. AI features are paused until the next calendar month, or until you raise this number.', 'signal-and-noise-tools' ) . '</strong></p>';
		}
	} else {
		echo '<p class="sn-field-helper">' . sprintf(
			/* translators: %s: spend so far this month. */
			esc_html__( 'No cap set: AI features never pause on cost. Spent this month: $%s.', 'signal-and-noise-tools' ),
			esc_html( number_format_i18n( $spent, 2 ) )
		) . '</p>';
	}
	// v10.47.0: the unconditional "Set 0 for no limit…" line used to print directly
	// under the no-cap branch, which already says exactly that — the field told the
	// same fact twice in consecutive sentences. It now appears only in the branch
	// that has not already made the point.
	if ( $budget > 0 ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'Set 0 to remove the cap.', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '</div>';

	// v13.21.0: the same estimated month, itemized by the feature label every
	// call carries (the "AI spend itemized by door" row). Same source as the
	// total above — the durable rollup, not the capped FIFO log — so the lines
	// sum to it. Absent entirely when the month holds nothing (the spend-watch
	// unconfigured-is-absent precedent). NOT in the Health spend section: that
	// surface's contract is platform-reported-never-estimated, and this figure
	// is estimated from tokens x list pricing like the total it breaks down.
	$by_feature = function_exists( 'snt_ai_spend_this_month_by_feature' )
		? snt_ai_spend_this_month_by_feature()
		: array();
	if ( array() !== $by_feature ) {
		echo '<div class="sn-field">';
		echo '<p class="sn-field-label">' . esc_html__( 'This month, by feature', 'signal-and-noise-tools' ) . '</p>';
		echo '<ul class="sn-ai-spend-by-feature">';
		foreach ( $by_feature as $feature_slug => $feature_cost ) {
			// Sub-cent features get four decimals: a real $0.004 rendered as
			// "$0.00" would print a measured spend as a zero.
			$decimals = $feature_cost < 0.01 ? 4 : 2;
			echo '<li><span>' . esc_html( $feature_slug ) . '</span><span>$' . esc_html( number_format_i18n( $feature_cost, $decimals ) ) . '</span></li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	// item 8: the Workers AI token for SHADOW semantic embeddings. Shipped in
	// v11.22.0 with NO control to set it — the same failure v10.84.0 made with
	// the page-signing gate, which then sat unreachable for thirty releases.
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_ml_embeddings_token">' . esc_html__( 'Workers AI token (semantic embeddings)', 'signal-and-noise-tools' ) . '</label>';
	$embed_token = function_exists( 'snt_ml_embed_token' ) ? snt_ml_embed_token() : '';
	echo '<input type="text" id="sn_ml_embeddings_token" name="sn_ml_embeddings_token" class="regular-text" value="' . esc_attr( sn_mask_secret( $embed_token ) ) . '" placeholder="' . esc_attr__( 'Paste a token; type clear to remove', 'signal-and-noise-tools' ) . '">';
	echo '<p class="sn-field-helper">' . esc_html__( 'Cloudflare dashboard → My Profile → API Tokens → Create Custom Token. The permission is under the ACCOUNT scope (not User or Zone), named "Workers AI", set to Read. The account ID is shared with Analytics.', 'signal-and-noise-tools' ) . '</p>';
	if ( function_exists( 'snt_ml_embed_configured' ) ) {
		if ( snt_ml_embed_configured() ) {
			echo '<p class="sn-field-helper"><span class="sn-pill sn-pill--ok">' . esc_html__( 'Configured', 'signal-and-noise-tools' ) . '</span> ' . esc_html__( 'Embeddings run in SHADOW mode: they are computed and compared against the existing ranking, and nothing the site serves uses them yet.', 'signal-and-noise-tools' ) . '</p>';
		} elseif ( '' === ( function_exists( 'snt_ml_embed_account_id' ) ? snt_ml_embed_account_id() : '' ) ) {
			echo '<p class="sn-field-helper"><span class="sn-pill sn-pill--warn">' . esc_html__( 'No Cloudflare account ID — set it under Measurement → Analytics first.', 'signal-and-noise-tools' ) . '</span></p>';
		} else {
			echo '<p class="sn-field-helper"><span class="sn-pill">' . esc_html__( 'Not configured.', 'signal-and-noise-tools' ) . '</span></p>';
		}
	}
	echo '</div>';

	// item 8: the RUNNER. v11.22.0 shipped the instrument with nothing calling
	// it, so the comparison existed and could not be read.
	if ( function_exists( 'snt_ml_embed_configured' ) && snt_ml_embed_configured() ) {
		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">' . esc_html__( 'TF-IDF vs embeddings', 'signal-and-noise-tools' ) . '</label>';
		$cmp = get_transient( 'snt_ml_embed_compare' );
		if ( is_array( $cmp ) && empty( $cmp['ok'] ) ) {
			echo '<p class="sn-field-helper"><span class="sn-pill sn-pill--warn">' . esc_html( (string) ( $cmp['error'] ?? '' ) ) . '</span></p>';
		} elseif ( is_array( $cmp ) && ! empty( $cmp['ok'] ) ) {
			$res  = (array) ( $cmp['result'] ?? array() );
			$vars = (array) ( $res['variants'] ?? array() );
			$scope = (array) ( $res['scope'] ?? array() );
			if ( $scope ) {
				echo '<p class="sn-field-helper">';
				printf(
					/* translators: 1: embedded total, 2: scored sources, 3: scheduled notes. */
					esc_html__( '%1$d notes embedded (%2$d published and scored; %3$d scheduled, counted in the centroid only — a scheduled note has no baseline artifact to diverge from).', 'signal-and-noise-tools' ),
					(int) ( $scope['embedded_total'] ?? 0 ),
					(int) ( $scope['scored_sources'] ?? 0 ),
					(int) ( $scope['scheduled_in_centroid'] ?? 0 )
				);
				echo '</p>';
			}
			$labels = array(
				'raw'             => __( 'Raw cosine', 'signal-and-noise-tools' ),
				'centered'        => __( 'Centred', 'signal-and-noise-tools' ),
				'centered_mutual' => __( 'Centred + mutual', 'signal-and-noise-tools' ),
			);
			echo '<div class="snt-scroll-table"><table class="widefat striped"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Variant', 'signal-and-noise-tools' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Divergence', 'signal-and-noise-tools' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Hub share', 'signal-and-noise-tools' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Distinct targets', 'signal-and-noise-tools' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $labels as $key => $label ) {
				if ( ! isset( $vars[ $key ] ) ) { continue; }
				$v   = (array) $vars[ $key ];
				$hub = (array) ( $v['hub'] ?? array() );
				$is_rec = ( ( $res['recommended'] ?? '' ) === $key );
				echo '<tr>';
				echo '<td>' . esc_html( $label ) . ( $is_rec ? ' <span class="sn-pill sn-pill--ok">' . esc_html__( 'recommended', 'signal-and-noise-tools' ) . '</span>' : '' ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( 100 * (float) ( $v['divergence'] ?? 0 ), 1 ) ) . '%</td>';
				// Hub share is the metric the FIRST run lacked: one note occupied
				// half the results and divergence could not see it.
				echo '<td>' . esc_html( number_format_i18n( 100 * (float) ( $hub['hub_share'] ?? 0 ), 1 ) ) . '% (' . esc_html( (string) ( $hub['top_count'] ?? 0 ) ) . ' of ' . esc_html( (string) ( $hub['sources'] ?? 0 ) ) . ')</td>';
				echo '<td>' . esc_html( (string) ( $hub['distinct_targets'] ?? 0 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			$div = (array) ( $res['divergent'] ?? array() );
			if ( $div ) {
				// FOLDED by default: the three-row variant summary above is the
				// decision, and an unbounded pair list pushed the form (and the
				// Save button) off the panel. snt-scroll-table caps at 50vh,
				// which is still ~550px here — a cap is not the same as not
				// taking the space.
				echo '<details class="sn-disclosure">';
				echo '<summary>' . sprintf(
					/* translators: %d: number of notes with pairs only embeddings found. */
					esc_html( _n( '%d note has a pair TF-IDF does not find', '%d notes have pairs TF-IDF does not find', count( $div ), 'signal-and-noise-tools' ) ),
					count( $div )
				) . '</summary>';
				echo '<div class="snt-scroll-table"><table class="widefat striped"><thead><tr>';
				echo '<th scope="col">' . esc_html__( 'Note', 'signal-and-noise-tools' ) . '</th>';
				echo '<th scope="col">' . esc_html__( 'Found only by embeddings', 'signal-and-noise-tools' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( array_slice( $div, 0, 25 ) as $row ) {
					$names = array();
					foreach ( (array) $row['only_embedding'] as $o ) { $names[] = (string) $o['title']; }
					echo '<tr><td>' . esc_html( (string) $row['title'] ) . '</td><td>' . esc_html( implode( ' · ', $names ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
				echo '</details>';
			} else {
				echo '<p class="sn-field-helper">' . esc_html__( 'No divergence at all: TF-IDF already found every pair the embeddings did. That is a real answer, and it argues against adopting a hosted model.', 'signal-and-noise-tools' ) . '</p>';
			}
		} else {
			echo '<p class="sn-field-helper">' . esc_html__( 'Not run yet. This embeds every published note once (cached by content hash) and compares both rankings.', 'signal-and-noise-tools' ) . '</p>';
		}
		echo '<p><button type="submit" name="sn_action" value="ml_embed_compare" class="button">' . esc_html__( 'Run comparison', 'signal-and-noise-tools' ) . '</button></p>';
		echo '</div>';
	}

	echo '<div class="sn-fieldset-actions">';
	echo '<p class="sn-fieldset-actions-hint">' . esc_html__( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' ) . '</p>';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save AI settings', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';

	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
