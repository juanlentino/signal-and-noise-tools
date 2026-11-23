<?php
/**
 * Signal & Noise — AI models & budget (AI tab → Models & Budget sub-tab).
 *
 * 2026-11: the leaf is readings only. Its five settings (the two models, the
 * monthly budget, the Jev credit and its renewal day) are painted by
 * sn_admin_render_ai_models_form() (ai-models-form.php) on Settings > AI; what
 * stays here is the spend against the cap, the by-feature itemization, the
 * Copilot row, the Workers AI token's state and the embeddings comparison,
 * which is its own form now. The history below is how the settings got here.
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
 * SAVE PATH (of the form, wherever it is painted): sn_action=ai_settings_save →
 * sn_handle_ai_settings_save() (inc/admin-post-actions/theme-ai.php). Splitting
 * one form into two is normally the settings-subtree clobber trap, but
 * sn_handle_save_theme() writes with per-key sn_setting_update() rather than a
 * subtree write, so the two handlers cannot overwrite each other's keys. See
 * the note on sn_handle_ai_settings_save().
 *
 * Wide leaf (v10.47.0): sn_admin_render_section() emits a bare .sn-section, so
 * this leaf owns its own .sn-fieldset, the same contract as the Front-End form
 * it was extracted from, and the reason its rows can lay out across the full
 * content width.
 *
 * @package SignalNoiseTools
 * @since 10.46.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/settings-accounts-pointers.php'; // snt_settings_accounts_url(): the pane these boxes point at.

require_once dirname( __DIR__ ) . '/ai-model-catalog.php'; // the model defaults and lists (21.0.0).
require_once __DIR__ . '/ai-settings-parts.php'; // the embeddings sentence and the Copilot row both twins print (2026-10-12).
require_once __DIR__ . '/ai-models-form.php'; // the five settings, painted on Settings > AI (2026-11).

/**
 * Render the Models & Budget leaf: readings, and one line saying where its
 * settings went. Used as the sn_admin_render_section() callback for the AI
 * tab's 'models-budget' sub-tab. The name is the one the registry has had
 * since 10.46.0, when this painted the form.
 *
 * @since 10.46.0
 */
function sn_admin_render_ai_settings_form() {
	$budget = (float) sn_setting( 'theme.ai_monthly_budget', 0 );
	$spent  = function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0;

	// 2026-11: the five settings moved to Settings > AI (ai-models-form.php),
	// so no form wraps this leaf any more. A form here posting
	// ai_settings_save without its fields would remove the cap: the handler
	// writes the budget from `?? 0` on every save. The class stays on a div
	// for the field grid (assets/admin.css, .sn-ai-settings-form .sn-fieldset).
	echo '<div class="sn-ai-settings-form">';
	echo '<div class="sn-fieldset">';

	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Models &amp; budget', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html__( 'Which models this plugin calls, and the ceiling on what they may cost. Every AI feature here (drafts, insights, meta descriptions, alt text) draws on the same monthly budget. Copilot and agents run outside it.', 'signal-and-noise-tools' ) . '</p>';
	if ( function_exists( 'snt_settings_ai_moved_line' ) ) {
		snt_settings_ai_moved_line( 'models' );
	}
	echo '<p class="sn-field-helper">' . esc_html( sn_ai_models_status_line() . ' ' . sn_ai_prices_status_line() ) . '</p>';

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
			echo '<p class="sn-field-helper"><strong>' . esc_html__( 'The cap is reached. The AI features of this plugin pause until the next calendar month, or until you raise the monthly budget in Settings › AI. Copilot, Agents and the AI plugin keep running; their spend is listed separately and not counted against the cap.', 'signal-and-noise-tools' ) . '</strong></p>';
		}
	} else {
		echo '<p class="sn-field-helper">' . sprintf(
			/* translators: %s: spend so far this month. */
			esc_html__( 'No cap set: AI features never pause on cost. Spent this month: $%s.', 'signal-and-noise-tools' ),
			esc_html( number_format_i18n( $spent, 2 ) )
		) . '</p>';
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
	sn_admin_ai_copilot_row();

	// item 8: the Workers AI token for SHADOW semantic embeddings. 15.3.1: a
	// keyring row (Settings › Accounts and connections); this form reads it and says so.
	echo '<div class="sn-field">';
	echo '<span class="sn-field-label">' . esc_html__( 'Workers AI token (semantic embeddings)', 'signal-and-noise-tools' ) . '</span>';
	$embed_token = function_exists( 'snt_ml_embed_token' ) ? snt_ml_embed_token() : '';
	echo '<p class="sn-field-helper">' . ( '' !== $embed_token ? '<span class="sn-pill sn-pill--ok">' . esc_html__( 'set', 'signal-and-noise-tools' ) . '</span>' : '<span class="sn-pill sn-pill--warn">' . esc_html__( 'not set', 'signal-and-noise-tools' ) . '</span>' ) . ' ' . esc_html__( 'Set under', 'signal-and-noise-tools' ) . ' <a href="' . esc_url( snt_settings_accounts_url() ) . '">Settings › Accounts and connections</a>.</p>';
	if ( function_exists( 'snt_ml_embed_configured' ) ) {
		$embed_text = sn_admin_ai_embed_sentence( snt_ml_embed_configured(), function_exists( 'snt_ml_embed_account_id' ) ? (string) snt_ml_embed_account_id() : '' );
		if ( snt_ml_embed_configured() ) {
			echo '<p class="sn-field-helper"><span class="sn-pill sn-pill--ok">' . esc_html__( 'Configured', 'signal-and-noise-tools' ) . '</span> ' . esc_html( $embed_text ) . '</p>';
		} else {
			echo '<p class="sn-field-helper"><strong>' . esc_html__( 'Semantic embeddings', 'signal-and-noise-tools' ) . ':</strong> ' . esc_html( $embed_text ) . ' <a href="' . esc_url( snt_settings_accounts_url() ) . '">' . esc_html__( 'Settings › Accounts and connections', 'signal-and-noise-tools' ) . '</a>.</p>';
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
					esc_html__( '%1$d notes embedded (%2$d published and scored; %3$d scheduled, counted in the centroid only, since a scheduled note has no baseline artifact to diverge from).', 'signal-and-noise-tools' ),
					(int) ( $scope['embedded_total'] ?? 0 ),
					(int) ( $scope['scored_sources'] ?? 0 ),
					(int) ( $scope['scheduled_in_centroid'] ?? 0 )
				);
				echo '</p>';
			}
			$labels = array(
				'raw'             => __( 'Raw cosine', 'signal-and-noise-tools' ),
				'centered'        => __( 'Centered', 'signal-and-noise-tools' ),
				'centered_mutual' => __( 'Centered + mutual', 'signal-and-noise-tools' ),
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
		// Its own form since 2026-11: the button was a second action inside the settings form, which left this leaf.
		echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'ml_embed_compare' ) ) . '"><input type="hidden" name="action" value="sn_ml_embed_compare"><p><button type="submit" class="button">' . esc_html__( 'Run comparison', 'signal-and-noise-tools' ) . '</button></p></form>';
		echo '</div>';
	}

	echo '</div>'; // .sn-fieldset
	echo '</div>'; // .sn-ai-settings-form
}
