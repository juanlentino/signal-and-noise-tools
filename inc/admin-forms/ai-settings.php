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
 * inc/analytics-render-settings.php and inc/ai-bootstrap.php. The keys describe
 * where they were born, not where they are edited; only the editing surface
 * moved.
 *
 * SAVE PATH: sn_action=ai_settings_save → sn_handle_ai_settings_save()
 * (inc/admin-post-actions.php). Splitting one form into two is normally the
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

	echo '<div class="sn-fieldset-actions">';
	echo '<p class="sn-fieldset-actions-hint">' . esc_html__( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' ) . '</p>';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save AI settings', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';

	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
