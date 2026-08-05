<?php
/**
 * Signal & Noise Tools — the Copilot tool-invocation log.
 *
 * desktop-mode dispatches Ask AI tools server-side, so the plugin's own AI usage
 * log (sn_ai_usage_log) never sees a Copilot turn — it records our first-party
 * generate_text_result() calls only, keyed by a feature label, not a tool. That
 * left the v9.59.0 prune with no invocation evidence: it had to argue from
 * redundancy + payload size. This closes that gap.
 *
 * desktop-mode fires `desktop_mode_ai_tool_called` (Stable @ 0.5.1, verified at
 * v0.9.5) on every tool call, in our request, before execute() — carrying the
 * STRIPPED tool name (export_audit_log), the same shape the prune matches. We
 * record which tool the model CHOSE, so the next prune reads "never invoked in
 * N days" instead of guessing.
 *
 * THE PRIVACY RULE: names + counts + timestamps ONLY. The action also carries
 * `args`, which can hold the user's query fragments or content — we take the tool
 * name and NOTHING ELSE from the context. A test walks the stored payload for a
 * canary and is mutation-checked.
 *
 * The log is NOT exposed as a Copilot tool: a read-only ability would re-add the
 * per-turn rent this whole initiative removed. It is read via the accessor below
 * and surfaced on the AI-spend dashboard.
 *
 * @package signal-and-noise-tools
 * @since   9.60.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_AI_TOOL_INVOCATIONS_OPT', 'sn_ai_tool_invocations' );

// Bounded by the real tool count (~40-50). The cap only refuses NEW keys past it
// — a guard against a misbehaving upstream churning tool names, never a loss of
// data for a tool we already track.
define( 'SN_AI_TOOL_INVOCATIONS_CAP', 200 );

/**
 * Record one Copilot tool invocation: increment its count, stamp first/last.
 *
 * Hooked on desktop-mode's `desktop_mode_ai_tool_called`. Takes the tool name and
 * NOTHING ELSE from the context — never `args` (potential PII).
 *
 * v10.43.0: post-#475 OpenStation renames the action to
 * `openstation_ai_tool_called` (includes/ai-copilot/search.php:1322/1399/1753)
 * — dual-registered below via snt_os_compat_add_action(). This handler has a
 * real side effect (an option counter increment), so it guards against a
 * hypothetical future double-fire with snt_os_compat_seen_once(), keyed on
 * the full triggering payload — see inc/openstation-compat.php.
 *
 * @since 9.60.0
 * @param mixed $ctx The action payload: { tool_name, args, user_id, request_id }.
 * @return void
 */
function snt_ai_record_tool_invocation( $ctx ) {
	$name = is_array( $ctx ) ? (string) ( $ctx['tool_name'] ?? '' ) : '';
	if ( '' === $name ) {
		return;
	}

	$identity = array(
		$name,
		is_array( $ctx ) ? ( $ctx['args'] ?? null ) : null,
		is_array( $ctx ) ? ( $ctx['user_id'] ?? null ) : null,
		is_array( $ctx ) ? ( $ctx['request_id'] ?? null ) : null,
	);
	if ( function_exists( 'snt_os_compat_seen_once' )
		&& snt_os_compat_seen_once( 'ai_tool_called:' . md5( serialize( $identity ) ) ) ) {
		return;
	}

	$log = get_option( SN_AI_TOOL_INVOCATIONS_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	if ( isset( $log[ $name ] ) && is_array( $log[ $name ] ) ) {
		$log[ $name ]['n']    = (int) ( $log[ $name ]['n'] ?? 0 ) + 1;
		$log[ $name ]['last'] = time();
	} else {
		if ( count( $log ) >= SN_AI_TOOL_INVOCATIONS_CAP ) {
			return; // Refuse to grow past the cap; known tools still increment above.
		}
		$now            = time();
		$log[ $name ]   = array(
			'n'     => 1,
			'first' => $now,
			'last'  => $now,
		);
	}

	update_option( SN_AI_TOOL_INVOCATIONS_OPT, $log, false );
}
snt_os_compat_add_action( 'desktop_mode_ai_tool_called', 'openstation_ai_tool_called', 'snt_ai_record_tool_invocation' );

/**
 * The tool-invocation map: `[ tool_name => [ n, first, last ] ]`.
 *
 * Returns array() when nothing has been recorded — an unmeasured log is an empty
 * answer, never null.
 *
 * @since 9.60.0
 * @return array<string,array{n:int,first:int,last:int}>
 */
function snt_ai_tool_invocations() {
	$log = get_option( SN_AI_TOOL_INVOCATIONS_OPT, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * The invocation log shaped for display: tools ranked by call count (desc, ties
 * by name), plus the total calls and distinct-tool count.
 *
 * `distinct === 0` is the empty-state signal the view keys on.
 *
 * @since 9.61.0
 * @return array{tools:array<int,array{name:string,n:int,last:int}>,calls:int,distinct:int}
 */
function snt_ai_tool_invocations_ranked() {
	$tools = array();
	$calls = 0;
	foreach ( snt_ai_tool_invocations() as $name => $rec ) {
		$n      = (int) ( is_array( $rec ) ? ( $rec['n'] ?? 0 ) : 0 );
		$calls += $n;
		$tools[] = array(
			'name' => (string) $name,
			'n'    => $n,
			'last' => (int) ( is_array( $rec ) ? ( $rec['last'] ?? 0 ) : 0 ),
		);
	}
	usort( $tools, static function ( $a, $b ) {
		return $b['n'] <=> $a['n'] ?: strcmp( $a['name'], $b['name'] );
	} );
	return array(
		'tools'    => $tools,
		'calls'    => $calls,
		'distinct' => count( $tools ),
	);
}

/**
 * Render the Copilot tool-usage view: an owner-facing list of which Ask AI tools
 * have been invoked and how often, for the dashboard Diagnostics area.
 *
 * Ships before there is data on purpose (the logger needs to accrue first), so
 * the empty state is first-class: a clean note, never a blank or a fatal. Every
 * value is escaped — a tool name is a fixed identifier today, but the log records
 * whatever upstream sends, so it is never trusted raw.
 *
 * @since 9.61.0
 * @return void
 */
function snt_ai_tool_invocations_render() {
	$ranked = snt_ai_tool_invocations_ranked();

	echo '<h2 class="sn-section-h">Copilot tool usage</h2>';

	if ( 0 === $ranked['distinct'] ) {
		echo '<div class="sn-card sn-ai-usage sn-ai-usage--empty">';
		echo '<p class="sn-ai-usage__empty">' . esc_html__( 'No Ask AI tool calls recorded yet. Counts appear here once Desktop Mode’s Copilot has run (logging started in v9.60.0).', 'signal-and-noise-tools' ) . '</p>';
		echo '</div>';
		return;
	}

	echo '<div class="sn-card sn-ai-usage">';
	echo '<p class="sn-ai-usage__summary">' . sprintf(
		/* translators: 1: total AI tool calls, 2: distinct tool count. Both wrapped in <strong>. */
		esc_html__( '%1$s calls across %2$s tools', 'signal-and-noise-tools' ),
		'<strong>' . esc_html( number_format_i18n( $ranked['calls'] ) ) . '</strong>',
		'<strong>' . esc_html( number_format_i18n( $ranked['distinct'] ) ) . '</strong>'
	) . '</p>';
	echo '<ul class="sn-ai-usage__list">';
	foreach ( $ranked['tools'] as $tool ) {
		echo '<li class="sn-ai-usage__row">';
		echo '<code class="sn-ai-usage__name">' . esc_html( $tool['name'] ) . '</code>';
		echo '<span class="sn-ai-usage__count">' . esc_html( number_format_i18n( $tool['n'] ) ) . '</span>';
		echo '</li>';
	}
	echo '</ul>';
	echo '</div>';
}
