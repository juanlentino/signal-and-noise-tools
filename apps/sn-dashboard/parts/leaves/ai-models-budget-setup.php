<?php
/**
 * S&N Dashboard, AI > Models & Budget: the Setup card.
 *
 * 2026-10-16 (the leaf anatomy, docs/kit-leaf-anatomy.md): the models, the
 * budget and the Jev credit are settings, changed once in a while, so the
 * whole `ai_settings_save` form (one form, one Save, the same names) is one
 * earned fold whose closed summary states the models and the cap; the
 * Workers AI token, a keyring row this leaf only reads, is a second fold that
 * opens while it is not set. One snt_kit_setup_card() call
 * (inc/openstation-kit-anatomy.php), listed in tests/sn-dashboard-no-folds.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Setup card.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_setup_html( array $d ) {
	$models  = sn_theme_ai_models();
	$vision  = sn_theme_ai_vision_models();
	$cap     = $d['budget'] > 0
		/* translators: %s: the monthly cap in USD. */
		? sprintf( __( '$%s a month', 'signal-and-noise-tools' ), number_format_i18n( $d['budget'], 2 ) )
		: __( 'no cap', 'signal-and-noise-tools' );
	// A model's name, its catalog note ("(previous default)") left to the select.
	$name    = static fn( array $list, $id ) => (string) preg_replace( '/\s*\(.*\)$/', '', (string) ( $list[ $id ] ?? $id ) );
	/* translators: 1: the prose model, 2: the vision model, 3: the cap ("$50.00 a month" or "no cap"). */
	$summary = sprintf( __( 'Prose %1$s; vision %2$s; %3$s', 'signal-and-noise-tools' ), $name( $models, $d['model'] ), $name( $vision, $d['alt_model'] ), $cap );
	$folds   = array(
		array(
			'title'   => __( 'Models and budget', 'signal-and-noise-tools' ),
			'summary' => $summary,
			'body'    => \snt_kit_form( 'ai_settings_save', models_budget_fields_html( $d, $models, $vision ), array( 'submit' => __( 'Save AI settings', 'signal-and-noise-tools' ), 'columns' => 'auto' ) ),
		),
	);
	// 15.3.1: the Workers AI token is a keyring row (Connections › Credentials);
	// this leaf reads it and says so.
	$token_set = '' !== (string) $d['embed_token'];
	$folds[]   = array(
		'title'   => __( 'Workers AI token', 'signal-and-noise-tools' ),
		'summary' => $token_set ? __( 'Set', 'signal-and-noise-tools' ) : __( 'Not set', 'signal-and-noise-tools' ),
		'body'    => '<p class="snt-hint">' . \snt_kit_esc( __( 'Workers AI token (semantic embeddings):', 'signal-and-noise-tools' ) ) . ' ' . ( $token_set ? \snt_kit_badge( 'ok', __( 'set', 'signal-and-noise-tools' ) ) : \snt_kit_badge( 'warn', __( 'not set', 'signal-and-noise-tools' ) ) ) . ' ' . \snt_kit_esc( __( 'Set under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'monitoring' ) ) . '.</p>',
		'open'    => ! $token_set,
	);
	return \snt_kit_setup_card( __( 'Setup', 'signal-and-noise-tools' ), $folds, __( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' ) );
}

/**
 * The `ai_settings_save` fields: the two models, the monthly budget, the Jev
 * credit and its cycle day, under the classic form's names.
 *
 * @param array                $d      From models_budget_data().
 * @param array<string,string> $models sn_theme_ai_models().
 * @param array<string,string> $vision sn_theme_ai_vision_models().
 * @return string
 */
function models_budget_fields_html( array $d, array $models, array $vision ) {
	$fields  = \snt_kit_field( 'select', 'theme_ai_model', __( 'Prose model', 'signal-and-noise-tools' ), $d['model'], array(
		'options' => $models,
		'hint'    => __( 'Used for AI-assisted prose features (drafts, insights, meta descriptions).', 'signal-and-noise-tools' ),
	) );
	$fields .= \snt_kit_field( 'select', 'theme_ai_alt_model', __( 'Vision model (alt text)', 'signal-and-noise-tools' ), $d['alt_model'], array(
		'options' => $vision,
	) );
	$fields .= '<p class="snt-hint">' . sprintf(
		/* translators: %s: the alt-text model filter name, wrapped in <code>. */
		\snt_kit_esc( __( 'Used to LOOK at images when suggesting alt text. The %s filter still overrides this for code-level pins.', 'signal-and-noise-tools' ) ),
		\snt_kit_code( 'snt_ai_alt_text_model', false )
	) . '</p>';
	$fields .= '<p class="snt-hint">' . \snt_kit_esc( \sn_ai_models_status_line() . ' ' . \sn_ai_prices_status_line() ) . '</p>';
	$fields .= \snt_kit_field( 'number', 'theme_ai_monthly_budget', __( 'Monthly budget (USD)', 'signal-and-noise-tools' ), number_format( $d['budget'], 2, '.', '' ), array(
		'min'  => 0,
		'step' => 0.5,
		'hint' => __( 'Set 0 to remove the cap.', 'signal-and-noise-tools' ),
	) );
	// 16.6.0: the Jev credit and its cycle day; the meter reads both.
	$fields .= \snt_kit_field( 'number', 'theme_jev_credit', __( 'Jev monthly credit (USD)', 'signal-and-noise-tools' ), number_format( (float) sn_setting( 'theme.jev_credit', 5 ), 2, '.', '' ), array( 'min' => 0, 'step' => 0.5, 'hint' => __( 'TypeSafe\'s credit, as the console states it. A readout, not a cap.', 'signal-and-noise-tools' ) ) );
	return $fields . \snt_kit_field( 'number', 'theme_jev_cycle_day', __( 'Jev credit renews on day', 'signal-and-noise-tools' ), (string) (int) sn_setting( 'theme.jev_cycle_day', 17 ), array( 'min' => 1, 'max' => 28, 'step' => 1 ) );
}
