<?php
/**
 * Signal & Noise: the models and budget form, painted on Settings > AI
 * (inc/admin-forms/settings-ai.php).
 *
 * Five settings that sat on AI > Models & Budget until 2026-11: the prose
 * model, the vision model, the monthly budget, the Jev credit and the day it
 * renews. Labels, ids, hints, bounds and names are the ones that leaf's form
 * had; the leaf keeps its readings (inc/admin-forms/ai-settings.php).
 *
 * SAVE PATH, unchanged: sn_action=ai_settings_save, sn_handle_ai_settings_save()
 * (inc/admin-post-actions/theme-ai.php). ALL FIVE FIELDS STAY IN THIS ONE
 * FORM: the handler writes each of them on every save, the budget from
 * `?? 0`, the credit from `?? 5` and the day from `?? 17`, so a form that left
 * one out would reset it (tests/lib/whole-forms.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The form, a box headed "Models and budget".
 *
 * @return void
 */
function sn_admin_render_ai_models_form() {
	$model     = (string) sn_setting( 'theme.ai_model', sn_ai_default_model() );
	$alt_model = (string) sn_setting( 'theme.ai_alt_model', sn_ai_default_vision_model() );
	$budget    = (float) sn_setting( 'theme.ai_monthly_budget', 0 );

	echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'ai_settings_save' ) ) . '" class="sn-ai-settings-form">';
	echo '<input type="hidden" name="action" value="sn_ai_settings_save">';
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Models and budget', 'signal-and-noise-tools' ) . '</h2>';

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

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_ai_monthly_budget">' . esc_html__( 'Monthly budget (USD)', 'signal-and-noise-tools' ) . '</label>';
	echo '<input type="number" min="0" step="0.5" id="sn_theme_ai_monthly_budget" name="theme_ai_monthly_budget" value="' . esc_attr( number_format( $budget, 2, '.', '' ) ) . '">';
	echo '<p class="sn-field-helper">' . esc_html__( 'Set 0 to remove the cap.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';
	// 16.6.0: the Jev credit and its cycle day, the same two fields the native pane carries.
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_jev_credit">' . esc_html__( 'Jev monthly credit (USD)', 'signal-and-noise-tools' ) . '</label>';
	echo '<input type="number" min="0" step="0.5" id="sn_theme_jev_credit" name="theme_jev_credit" value="' . esc_attr( number_format( (float) sn_setting( 'theme.jev_credit', 5 ), 2, '.', '' ) ) . '">';
	echo '</div>';
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_theme_jev_cycle_day">' . esc_html__( 'Jev credit renews on day', 'signal-and-noise-tools' ) . '</label>';
	echo '<input type="number" min="1" max="28" step="1" id="sn_theme_jev_cycle_day" name="theme_jev_cycle_day" value="' . esc_attr( (string) (int) sn_setting( 'theme.jev_cycle_day', 17 ) ) . '">';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<p class="sn-fieldset-actions-hint">' . esc_html__( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' ) . '</p>';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save AI settings', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';

	echo '</div>'; // .sn-fieldset
	echo '</form>';
}
