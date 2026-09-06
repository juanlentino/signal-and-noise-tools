<?php
/**
 * S&N Dashboard — AI → Copilot Usage, painted from the kit.
 *
 * The classic leaf (inc/ai-tool-invocation-log.php:163, `snt_ai_tool_invocations_render()`)
 * is entirely read-only: no forms, no `$_GET`/`$_REQUEST` reads, no links. It
 * reads `snt_ai_tool_invocations_ranked()` (tools ranked by call count desc,
 * ties broken alphabetically by name) and prints either a dedicated empty-state
 * card (when `distinct === 0`) or a summary line ("N calls across M tools")
 * followed by one row per tool: its name and its call count. Same reads, same
 * ranking, same two states — the kit's parts instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The ranked usage data, read the way the classic leaf reads it.
 *
 * @return array{tools:array<int,array{name:string,n:int,last:int}>,calls:int,distinct:int}
 */
function copilot_usage_data() {
	return function_exists( 'snt_ai_tool_invocations_ranked' )
		? \snt_ai_tool_invocations_ranked()
		: array( 'tools' => array(), 'calls' => 0, 'distinct' => 0 );
}

/**
 * The summary line: "N calls across M tools", numbers bolded as on the classic card.
 *
 * @param array{calls:int,distinct:int} $ranked From copilot_usage_data().
 * @return string
 */
function copilot_usage_summary_html( array $ranked ) {
	$calls    = '<strong>' . \snt_kit_esc( number_format_i18n( $ranked['calls'] ) ) . '</strong>';
	$distinct = '<strong>' . \snt_kit_esc( number_format_i18n( $ranked['distinct'] ) ) . '</strong>';
	return '<p class="snt-prose">' . sprintf(
		/* translators: 1: total AI tool calls (bold), 2: distinct tool count (bold). */
		\snt_kit_esc( __( '%1$s calls across %2$s tools', 'signal-and-noise-tools' ) ),
		$calls,
		$distinct
	) . '</p>';
}

/**
 * The ranked tool list: one row per tool, name (as `<os-code>`, matching the
 * classic leaf's `<code>` treatment and the in-leaf idiom for machine
 * identifiers, e.g. connections-music-parts.php) and call count, in the
 * classic's deterministic order (already sorted by copilot_usage_data()).
 *
 * @param array<int,array{name:string,n:int,last:int}> $tools From copilot_usage_data()['tools'].
 * @return string
 */
function copilot_usage_list_html( array $tools ) {
	$items = '';
	foreach ( $tools as $tool ) {
		$name  = \snt_kit_code( (string) ( $tool['name'] ?? '' ), false );
		$count = \snt_kit_tag(
			'span',
			array( 'class' => 'snt-list__value' ),
			\snt_kit_esc( number_format_i18n( (int) ( $tool['n'] ?? 0 ) ) )
		);
		$items .= \snt_kit_tag( 'li', array( 'class' => 'snt-list__row' ), $name . $count );
	}
	return \snt_kit_tag( 'ul', array( 'class' => 'snt-list' ), $items );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_ai_copilot_usage( array $ctx ) {
	unset( $ctx );
	$ranked = copilot_usage_data();

	if ( 0 === $ranked['distinct'] ) {
		// Single msgid, matching the classic leaf exactly (inc/ai-tool-invocation-log.php:169)
		// so any existing/future translation of it still applies here.
		$inner = \snt_kit_empty(
			__( 'No Ask AI tool calls recorded yet. Counts appear here once Desktop Mode’s Copilot has run (logging started in v9.60.0).', 'signal-and-noise-tools' )
		);
		return \snt_kit_section( __( 'Copilot tool usage', 'signal-and-noise-tools' ), $inner );
	}

	$inner = copilot_usage_summary_html( $ranked ) . copilot_usage_list_html( $ranked['tools'] );
	return \snt_kit_section( __( 'Copilot tool usage', 'signal-and-noise-tools' ), $inner );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/copilot-usage'] = __NAMESPACE__ . '\\paint_ai_copilot_usage';
		return $painters;
	}
);
