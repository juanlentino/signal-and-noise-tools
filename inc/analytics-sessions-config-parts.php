<?php
/**
 * Signal & Noise Tools: Analytics Sessions, the config and funnel settings (the session config, the funnel list, the setting resolver, the parse error and its kind message).
 *
 * Split from inc/analytics-sessions.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable session-engine config. Constants are the defaults; the
 * 'sn_analytics_session_config' filter lets a site override any key.
 *
 * @return array{gap_sec:int,engaged_scroll:int,engaged_ms:int,row_cap:int}
 */
function sn_analytics_session_config() {
	$cfg = array(
		'gap_sec'        => SN_ANALYTICS_SESSION_GAP_SEC,
		'engaged_scroll' => SN_ANALYTICS_SESSION_ENGAGED_PCT,
		'engaged_ms'     => SN_ANALYTICS_SESSION_ENGAGED_MS,
		'row_cap'        => SN_ANALYTICS_SESSION_ROW_CAP,
	);
	$out = (array) apply_filters( 'sn_analytics_session_config', $cfg );
	// Coerce back to ints so a bad filter can't poison the SQL/int math.
	return array(
		'gap_sec'        => max( 60, (int) ( $out['gap_sec'] ?? $cfg['gap_sec'] ) ),
		'engaged_scroll' => max( 0, min( 100, (int) ( $out['engaged_scroll'] ?? $cfg['engaged_scroll'] ) ) ),
		'engaged_ms'     => max( 0, (int) ( $out['engaged_ms'] ?? $cfg['engaged_ms'] ) ),
		'row_cap'        => max( 100, (int) ( $out['row_cap'] ?? $cfg['row_cap'] ) ),
	);
}

/**
 * Auto-derived + optional owner-defined funnels. A site can configure named
 * funnels via Measurement → Analytics (Settings → analytics.funnels, S2 §3) or
 * add/override them in code via the 'sn_analytics_session_funnels' filter;
 * nothing is required for the view to work (transitions + quality render
 * regardless).
 *
 * Precedence: a non-empty, well-formed analytics.funnels setting REPLACES the
 * hardcoded two below; an empty/absent/corrupt setting falls back to them.
 * The filter always runs last, over either source, so code-level overrides
 * still win.
 *
 * @since 8.8.0
 * @since S2 (v9.42.0 arc) settings-defined funnels via analytics.funnels.
 * @return array List of array{title:string,steps:array}.
 */
function sn_analytics_session_funnels() {
	$hardcoded = array(
		array(
			'title' => __( 'Home → post → subscribe', 'signal-and-noise-tools' ),
			'steps' => array(
				array( 'match' => 'path', 'value' => '/', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/notes/', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'subscribe', 'prefix' => false ),
			),
		),
		array(
			// The site's commercial conversion path. The theme (v10.28.0) tags each
			// /contact email alias with data-sn-goal="contact-<alias>", so the final
			// step matches the whole contact-* family via the ce-prefix flag.
			'title' => __( 'Services → contact → email', 'signal-and-noise-tools' ),
			'steps' => array(
				array( 'match' => 'path', 'value' => '/services', 'prefix' => true ),
				array( 'match' => 'path', 'value' => '/contact', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'contact-', 'prefix' => true ),
			),
		),
	);

	$configured = sn_setting( 'analytics.funnels', array() );
	$funnels    = sn_analytics_funnels_resolve_setting( $configured, $hardcoded );

	return (array) apply_filters( 'sn_analytics_session_funnels', $funnels );
}

/**
 * Resolve the analytics.funnels setting against the hardcoded fallback.
 *
 * Defensive: a non-array setting, an empty setting, or a setting whose entries
 * don't carry the funnel shape sn_funnel_report() needs (not themselves an
 * array, missing 'title'/'steps', 'steps' not itself an array, or any step
 * ELEMENT not itself an array — a corrupt/hand-edited option value) all fall
 * back to the hardcoded defaults wholesale — never a partial/best-effort mix,
 * so a bad option value can't silently drop a funnel out from under
 * sn_funnel_report(), and a malformed entry can't reach the Visits view's
 * rendering loop at all. The per-step check matters under LIVE data: a string
 * step element would pass every outer check yet TypeError-fatal
 * sn_funnel_step_matches(array $step, …) the moment a visit has events.
 *
 * @since S2 (v9.42.0 arc)
 * @param mixed $configured The raw analytics.funnels setting value.
 * @param array $hardcoded  The hardcoded fallback funnel list.
 * @return array
 */
function sn_analytics_funnels_resolve_setting( $configured, array $hardcoded ) {
	if ( ! is_array( $configured ) || empty( $configured ) ) {
		return $hardcoded;
	}
	foreach ( $configured as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['title'], $entry['steps'] ) || ! is_array( $entry['steps'] ) ) {
			return $hardcoded;
		}
		foreach ( $entry['steps'] as $step ) {
			if ( ! is_array( $step ) ) {
				return $hardcoded;
			}
		}
	}
	return $configured;
}

/**
 * Build one sn_analytics_parse_funnels() error entry — both the flat "Line N:
 * reason" string existing callers/tests expect (unchanged shape, unchanged
 * bytes) AND a parallel structured {line,kind,message} record.
 *
 * The "Line N: " prefix is a fixed, NON-translated literal (not run through
 * __()) so it renders identically regardless of the site's locale — only
 * $reason (the human-readable part after the prefix) is translated.
 *
 * Reason-surfacing task: $kind is the new machine-stable member of
 * SN_ANALYTICS_FUNNELS_ERR_KINDS naming WHICH of the six rejections this is.
 * sn_analytics_parse_funnels() pushes ['message'] onto its flat $errors list
 * (unchanged consumer contract) and the whole return value onto its new
 * $errors_detail list — sn_handle_analytics_funnels_save()
 * (inc/admin-post-actions/analytics.php) reads $kind to encode the flash code without
 * ever regexing the human string.
 *
 * @since S2 (v9.42.0 arc); $kind param added (reason-surfacing task).
 * @param int    $line_num 1-based source line number.
 * @param string $kind     One of SN_ANALYTICS_FUNNELS_ERR_KINDS.
 * @param string $reason   Already-translated explanation text.
 * @return array{line:int,kind:string,message:string}
 */
function sn_analytics_funnels_error( $line_num, $kind, $reason ) {
	$line_num = (int) $line_num;
	return array(
		'line'    => $line_num,
		'kind'    => (string) $kind,
		'message' => 'Line ' . $line_num . ': ' . $reason,
	);
}

/**
 * The single-sourced reason text (no "Line N: " prefix) for one
 * SN_ANALYTICS_FUNNELS_ERR_KINDS entry. sn_analytics_parse_funnels() calls
 * this directly for the five kinds whose wording never varies (colon / name /
 * long / step / few), so the flash-code round trip — inc/admin-post-actions/analytics.php
 * encodes the $kind, inc/admin-flash-messages.php later decodes it back
 * through THIS SAME function — can never drift from what the parser actually
 * said.
 *
 * 'many' bundles TWO parser call sites (too many funnels vs too many steps,
 * each with its own configured max baked into a sprintf'd number) into one
 * kind — the flash code has no room to carry which sub-case or number fired,
 * so this returns a generic fallback the RENDERER uses for that kind; the
 * parser keeps emitting its own precise, numbered message directly (not
 * through this fn) into $parsed['errors'] for the actual reject-and-explain path.
 *
 * @since (reason-surfacing task)
 * @param string $kind One of SN_ANALYTICS_FUNNELS_ERR_KINDS.
 * @return string Translated reason text, or '' for an unrecognized kind.
 */
function sn_analytics_funnels_kind_message( $kind ) {
	switch ( $kind ) {
		case 'colon':
			return __( 'missing ":": expected "Name: /step > /step".', 'signal-and-noise-tools' );
		case 'name':
			return __( 'funnel name is empty.', 'signal-and-noise-tools' );
		case 'long':
			return __( 'line is too long (name max 80 chars, steps max 200).', 'signal-and-noise-tools' );
		case 'step':
			return __( 'a step contains a space or ":": check for an extra ":" earlier in the line.', 'signal-and-noise-tools' );
		case 'few':
			return __( 'needs at least 2 steps.', 'signal-and-noise-tools' );
		case 'many':
			return __( 'too many funnels or steps: the limit was exceeded and this line wasn\'t saved.', 'signal-and-noise-tools' );
	}
	return '';
}

