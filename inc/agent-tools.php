<?php
/**
 * Signal & Noise Tools — Agent tools: every tool an agent can call on this
 * site, by door, and whether it does (15.6.0).
 *
 * Three doors. On the page, the WebMCP bridge (sn-rights-signals-worker
 * src/webmcp-bridge-client.mjs), whose calls are reported by browsers
 * through the beacon into the machine-readers dataset as family `webmcp`.
 * Through MCP, the native read and write doors and the remote twin, whose
 * calls the MCP call log records. Through Copilot, Desktop Mode's Ask AI,
 * whose calls the invocation log records. This module is the bridge's half
 * of the model; the other two doors have their own readers and the leaf
 * composes them. One model, two painters (native and classic).
 *
 * @package SignalNoiseTools
 * @since 15.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AGENT_TOOLS_DAYS = 30;

/**
 * The tools the bridge registers, in registration order. Mirrors the
 * worker's `snWebmcpTools()`; a tool seen in the rows but not here is
 * painted too, so the list is a floor for the table, never a filter.
 *
 * @return array<string,string> name => what it answers.
 */
function sn_agent_tools_bridge_registry() {
	return array(
		'verify-page'      => __( 'Provenance verdict for this page, computed in the browser', 'signal-and-noise-tools' ),
		'get-rights-terms' => __( 'The rights terms in force (ODRL) and their pointers', 'signal-and-noise-tools' ),
		'related-notes'    => __( 'The notes the kernel ranks closest to this one', 'signal-and-noise-tools' ),
		'get-site-map'     => __( 'What the site is, as structure (/notes/index.json)', 'signal-and-noise-tools' ),
		'get-citation'     => __( 'BibTeX and CSL-JSON for this note, with the anchor', 'signal-and-noise-tools' ),
	);
}

/**
 * The bridge's tools with their calls and outcomes over the window, from the
 * fetch's split (`$result['webmcp']`). Pure over that figure.
 *
 * @param array<string,mixed> $webmcp {calls, by_tool, outcomes}.
 * @return array<int,array{tool:string,what:string,calls:int,ok:int,absent:int,error:int,registered:bool}>
 */
function sn_agent_tools_bridge_rows( array $webmcp ) {
	$registry = sn_agent_tools_bridge_registry();
	$by_tool  = (array) ( $webmcp['by_tool'] ?? array() );
	$outcomes = (array) ( $webmcp['outcomes'] ?? array() );
	$names    = array_values( array_unique( array_merge( array_keys( $registry ), array_keys( $by_tool ) ) ) );
	$rows     = array();
	foreach ( $names as $name ) {
		$o      = (array) ( $outcomes[ $name ] ?? array() );
		$rows[] = array(
			'tool'       => (string) $name,
			'what'       => (string) ( $registry[ $name ] ?? __( 'Not in the registered list; seen in the rows', 'signal-and-noise-tools' ) ),
			'calls'      => (int) ( $by_tool[ $name ] ?? 0 ),
			'ok'         => (int) ( $o['ok'] ?? 0 ),
			'absent'     => (int) ( $o['absent'] ?? 0 ),
			'error'      => (int) ( $o['error'] ?? 0 ),
			'registered' => isset( $registry[ $name ] ),
		);
	}
	return $rows;
}

/**
 * The documents the bridge's tools read, each as a reading with a verdict:
 * the site map (built when, how many notes), the related manifest on the
 * latest note, and the bridge's anchoring (from the rights-anchoring check's
 * remembered state: a drift entry means the served bytes have not matched
 * their anchor since then).
 *
 * @return array<int,array{label:string,value:string,ok:bool}>
 */
function sn_agent_tools_documents() {
	$out = array();

	$map = function_exists( 'sn_site_map_read' ) ? sn_site_map_read() : null;
	if ( is_array( $map ) ) {
		$built = strtotime( (string) ( $map['built_at'] ?? '' ) );
		$out[] = array(
			'label' => __( '/notes/index.json', 'signal-and-noise-tools' ),
			'value' => sprintf( /* translators: 1: notes, 2: pages, 3: relative time. */ __( '%1$s notes, %2$s pages, built %3$s ago', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $map['counts']['notes'] ?? 0 ) ), number_format_i18n( (int) ( $map['counts']['pages'] ?? 0 ) ), $built ? human_time_diff( $built, time() ) : '?' ),
			'ok'    => true,
		);
	} else {
		$out[] = array( 'label' => __( '/notes/index.json', 'signal-and-noise-tools' ), 'value' => __( 'not built yet; builds at the next publish or the daily rebuild', 'signal-and-noise-tools' ), 'ok' => false );
	}

	$latest = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' ) );
	$id     = (int) ( $latest[0] ?? 0 );
	if ( $id > 0 && function_exists( 'sn_related_manifest' ) ) {
		$m     = sn_related_manifest( $id );
		$out[] = array(
			'label' => __( 'Related manifest', 'signal-and-noise-tools' ),
			'value' => ! empty( $m['built'] ) ? sprintf( /* translators: %s: count. */ __( 'on the latest note, %s related', 'signal-and-noise-tools' ), number_format_i18n( count( (array) $m['related'] ) ) ) : __( 'the relatedness index is not built', 'signal-and-noise-tools' ),
			'ok'    => ! empty( $m['built'] ),
		);
	} else {
		$out[] = array( 'label' => __( 'Related manifest', 'signal-and-noise-tools' ), 'value' => __( 'no published note to carry one', 'signal-and-noise-tools' ), 'ok' => false );
	}

	$state = defined( 'SN_RIGHTS_ANCHOR_STATE_OPT' ) ? get_option( SN_RIGHTS_ANCHOR_STATE_OPT, array() ) : array();
	$drift = is_array( $state ) && isset( $state['webmcp-bridge'] ) && is_array( $state['webmcp-bridge'] ) ? $state['webmcp-bridge'] : null;
	$out[] = array(
		'label' => __( 'The bridge', 'signal-and-noise-tools' ),
		'value' => null === $drift
			? __( 'served bytes match the anchored record (rights-anchoring check)', 'signal-and-noise-tools' )
			: sprintf( /* translators: %s: relative time. */ __( 'served bytes have not matched their anchor for %s; the sweep anchors hourly', 'signal-and-noise-tools' ), human_time_diff( (int) ( $drift['first_seen'] ?? time() ), time() ) ),
		'ok'    => null === $drift,
	);
	return $out;
}

/**
 * The bridge's half of the leaf's model: window, rows, documents, and the
 * caption every painter carries. Reads the sensor through the same fetch the
 * Machine Readers leaf uses (cached 15 minutes), never a second call.
 *
 * @return array{days:int,ok:bool,calls:int,rows:array<int,array<string,mixed>>,documents:array<int,array<string,mixed>>,caption:string,error:string}
 */
function sn_agent_tools_bridge_model() {
	$days   = SN_AGENT_TOOLS_DAYS;
	$result = function_exists( 'snt_mr_fetch' ) ? snt_mr_fetch( $days ) : array( 'ok' => false, 'error' => 'sensor module not loaded' );
	$webmcp = ! empty( $result['ok'] ) && is_array( $result['webmcp'] ?? null ) ? $result['webmcp'] : array( 'calls' => 0, 'by_tool' => array(), 'outcomes' => array() );
	return array(
		'days'      => $days,
		'ok'        => ! empty( $result['ok'] ),
		'calls'     => (int) ( $webmcp['calls'] ?? 0 ),
		'rows'      => sn_agent_tools_bridge_rows( $webmcp ),
		'documents' => sn_agent_tools_documents(),
		'caption'   => __( 'Reported by browsers: each call is one beacon from the page that made it, rate-limited per IP at the edge. A signal for decisions, never an audit.', 'signal-and-noise-tools' ),
		'error'     => empty( $result['ok'] ) ? (string) ( $result['error'] ?? '' ) : '',
	);
}
