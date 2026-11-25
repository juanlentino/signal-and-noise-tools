<?php
/**
 * S&N Dashboard: Settings > AI, painted from the kit. The third pane of the
 * Settings tab, the native twin of inc/admin-forms/settings-ai.php: nine
 * settings, gathered from three leaves that keep their readings and one line
 * each saying where the settings went.
 *
 * 1. Models and budget, from AI > Models & Budget: `ai_settings_save`, the
 *    five fields of models_budget_fields_html() (ai-models-budget-setup.php)
 *    in ONE form, because the handler writes all five on every save. It was a
 *    closed Setup fold there; a setting on a settings pane is open.
 * 2. Scheduled reads, from Connections > Cron: the daily run switch
 *    (`scheduled_reads_save`). Run now and the last-run tally stayed on Cron.
 * 3. The MCP doors, from AI > MCP Clients: the read door's credential, the
 *    write door's and the remote door's switch, painted by the same three
 *    functions that leaf called (mcp_connect_controls_html(),
 *    ai-mcp-connect.php), so every warning, hidden field and confirmed Unbind
 *    is the one it was.
 *
 * A save is answered on the tab and leaf it was posted from
 * (sn-dashboard.os.php, the `post` action), so it lands back on this pane.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once dirname( __DIR__, 4 ) . '/inc/ai-model-catalog.php'; // the model defaults and lists.
require_once __DIR__ . '/ai-models-budget-setup.php'; // models_budget_fields_html(): the five fields.
require_once __DIR__ . '/ai-mcp-connect.php'; // the doors' status and their three controls.
require_once __DIR__ . '/settings-switches.php'; // Each door's switch, in that door's box.

/**
 * The models and budget box: the five fields, one Save. Only the three
 * values the fields show are read; the leaf's spend readers are not.
 *
 * @return string
 */
function settings_ai_models_html() {
	$d = array(
		'model'     => (string) \sn_setting( 'theme.ai_model', \sn_ai_default_model() ),
		'alt_model' => (string) \sn_setting( 'theme.ai_alt_model', \sn_ai_default_vision_model() ),
		'budget'    => (float) \sn_setting( 'theme.ai_monthly_budget', 0 ),
	);
	return \snt_kit_section(
		__( 'Models and budget', 'signal-and-noise-tools' ),
		\snt_kit_form( 'ai_settings_save', models_budget_fields_html( $d, \sn_theme_ai_models(), \sn_theme_ai_vision_models() ), array( 'submit' => __( 'Save AI settings', 'signal-and-noise-tools' ), 'columns' => 'auto' ) ),
		__( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' )
	);
}

/**
 * The scheduled reads box: the daily run switch, its hint and Save. This is
 * the one form that posts `scheduled_reads_save` without
 * `snt_scheduled_reads_now`, so it is the one that carries the switch.
 *
 * @return string
 */
function settings_ai_reads_html() {
	$field = \snt_kit_field( 'checkbox', 'snt_scheduled_reads_enabled', __( 'Run a fixed set of read-door abilities daily and keep a two-week outcome history', 'signal-and-noise-tools' ), \snt_scheduled_reads_enabled(), array( 'value' => '1', 'hint' => \snt_scheduled_reads_about() ) );
	return \snt_kit_section( __( 'Scheduled reads', 'signal-and-noise-tools' ), \snt_kit_form( 'scheduled_reads_save', $field, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ) );
}

/**
 * The pane: models and budget beside scheduled reads, then the three doors'
 * controls in door order. A box paints only when its module is loaded.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_ai( array $ctx ) {
	unset( $ctx );
	$boxes = array();
	if ( function_exists( 'sn_theme_ai_models' ) && function_exists( 'sn_theme_ai_vision_models' ) ) {
		$boxes[] = settings_ai_models_html();
	}
	if ( function_exists( 'snt_scheduled_reads_enabled' ) && function_exists( 'snt_scheduled_reads_about' ) ) {
		$boxes[] = settings_ai_reads_html();
	}
	return \snt_kit_grid( $boxes, 320 ) . mcp_connect_controls_html( mcp_connect_status() );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/ai'] = __NAMESPACE__ . '\\paint_settings_ai';
		return $painters;
	}
);
