<?php
/**
 * Signal & Noise Tools: abilities for the Internet Archive push and the AI
 * model lists (21.1.0). Three, all thin:
 *   - signal-noise/archive-push-existing (WRITE, rw door): start or resume
 *     the owner's run over notes that predate the keys.
 *   - signal-noise/archive-status (read): the push state as data.
 *   - signal-noise/ai-models-status (read): the two status lines the AI
 *     settings screens print, with the state behind them.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function snt_ability_archive_push_existing( $input = array() ) {
	unset( $input );
	return function_exists( 'sn_archive_existing_start' ) ? array( 'ok' => true ) + sn_archive_existing_start() : array( 'ok' => false, 'result' => 'unavailable', 'pending' => 0 );
}

function snt_ability_archive_status( $input = array() ) {
	unset( $input );
	if ( ! function_exists( 'sn_archive_existing_status_line' ) ) {
		return array( 'ok' => false, 'configured' => false, 'pending' => 0, 'line' => 'unavailable', 'captures' => (object) array(), 'watch' => '', 'run' => (object) array(), 'last' => (object) array(), 'failures' => array() );
	}
	$last = (array) get_option( SN_ARCHIVE_PUSH_LAST_OPT, array() );
	$fail = array();
	foreach ( (array) ( $last['failures'] ?? array() ) as $id => $f ) {
		$fail[] = array( 'post_id' => (int) $id ) + (array) $f;
	}
	unset( $last['failures'] );
	return array(
		'ok'         => true,
		'configured' => null !== sn_archive_push_keys(),
		'pending'    => count( sn_archive_existing_pending() ),
		'line'       => sn_archive_existing_status_line(),
		'captures'   => function_exists( 'sn_archive_confirm_counts' ) ? sn_archive_confirm_counts() : (object) array(),
		'watch'      => snt_watch_ripe_archive_push( array(), time() )['note'],
		'run'        => (object) (array) get_option( SN_ARCHIVE_EXISTING_OPT, array() ),
		'last'       => (object) $last,
		'failures'   => $fail,
	);
}

function snt_ability_ai_models_status( $input = array() ) {
	unset( $input );
	if ( ! function_exists( 'sn_ai_models_status_line' ) || ! function_exists( 'sn_ai_prices_status_line' ) ) {
		return array( 'ok' => false, 'models_line' => 'unavailable', 'prices_line' => 'unavailable' );
	}
	$models = (array) get_option( SN_AI_MODELS_OPT, array() );
	$prices = (array) get_option( SN_AI_PRICES_OPT, array() );
	return array(
		'ok'             => true,
		'models_line'    => sn_ai_models_status_line(),
		'prices_line'    => sn_ai_prices_status_line(),
		'models_fetched' => (int) ( $models['fetched'] ?? 0 ),
		'models_at'      => (object) array_map( 'intval', (array) ( $models['at'] ?? array() ) ),
		'models_counts'  => (object) array_map( 'count', array_filter( (array) ( $models['providers'] ?? array() ), 'is_array' ) ),
		'models_errors'  => (object) array_map( 'strval', (array) ( $models['errors'] ?? array() ) ),
		'prices_fetched' => (int) ( $prices['fetched'] ?? 0 ),
		'prices_count'   => count( (array) ( $prices['prices'] ?? array() ) ),
		'prices_held'    => array_values( array_filter( (array) ( $prices['held'] ?? array() ), 'is_string' ) ),
		'prices_error'   => (string) ( $prices['error'] ?? '' ),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/archive-push-existing', array(
		'label'               => 'Internet Archive: push the notes that predate the keys',
		'description'         => 'Starts, or resumes after a halt, the ONE-TIME run that asks the Internet Archive (Save Page Now) for a capture of every published note with no push on record: one note every five minutes on cron, oldest first. Owner-started only; do NOT call it on a schedule or to "refresh" captures, a note with any push record is never asked for again. Idempotent: `running` when a tick is already booked, `nothing` when every note has a record. Any refused or failed request halts the run (read archive-status for the reason); the failed note keeps its own single retry and is not picked again. Returns {result: started|running|nothing|unconfigured|unscheduled, pending}. Asks the archive for nothing itself; the first push is a minute out.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_archive_push_existing',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'result' => array( 'type' => 'string' ), 'pending' => array( 'type' => 'integer' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
	) );
	wp_register_ability( 'signal-noise/archive-status', array(
		'label'               => 'Internet Archive: push state',
		'description'         => 'The Internet Archive push as data: whether the keys are set, how many published notes have no push on record (`pending`), the run over older notes (`run`: state running|halted|done, reason, asked, last_tick), the last push (`last`) and the failures not since accepted for the same note (`failures`). `requested` means the archive took the job, NOT that the capture finished. `captures` is the outcome the Archive reports for those requests, asked hourly (captured, failed, unconfirmed after two days, waiting = asked and not yet answered). Never a key. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_archive_status',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'configured' => array( 'type' => 'boolean' ), 'pending' => array( 'type' => 'integer' ), 'line' => array( 'type' => 'string' ), 'captures' => array( 'type' => 'object' ), 'watch' => array( 'type' => 'string' ), 'run' => array( 'type' => 'object' ), 'last' => array( 'type' => 'object' ), 'failures' => array( 'type' => 'array' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
	wp_register_ability( 'signal-noise/ai-models-status', array(
		'label'               => 'AI model lists and prices: where they came from',
		'description'         => 'The two status lines the AI settings screens print (`models_line`, `prices_line`) and the state behind them: when each provider\'s model list was last read (`models_at`), how many rows each holds, why a provider was not read last time (`models_errors`, e.g. "not connected" when no key is set, which is not a fault), when the public price list was last read, how many models it prices, and which prices are held because they moved more than 4x. A cron firing that reports success only means the job did not crash; THIS is what says whether anything was read. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_ai_models_status',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'models_line' => array( 'type' => 'string' ), 'prices_line' => array( 'type' => 'string' ), 'models_fetched' => array( 'type' => 'integer' ), 'models_at' => array( 'type' => 'object' ), 'models_counts' => array( 'type' => 'object' ), 'models_errors' => array( 'type' => 'object' ), 'prices_fetched' => array( 'type' => 'integer' ), 'prices_count' => array( 'type' => 'integer' ), 'prices_held' => array( 'type' => 'array' ), 'prices_error' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
} );
