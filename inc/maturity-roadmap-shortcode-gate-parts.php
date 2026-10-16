<?php
/**
 * Signal & Noise Tools: [sn_maturity_roadmap], the hub-wide roadmap board, the write gate and the effective board (the banned tokens, the board problems, the effective board and its report, the fingerprint, the filtered board).
 *
 * Split from inc/maturity-roadmap-shortcode.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tokens that must never appear in board copy — the write-gate mirror of
 * the public page's leak sweep (tests/maturity-roadmap-shortcode.php's
 * SECURITY CONTRACT block): option names, endpoint paths, tool and
 * change-type slugs, internal prefixes. Rejecting them at the WRITE gate
 * keeps the sweep green by construction instead of by luck.
 *
 * @return string[]
 */
function sn_maturity_roadmap_banned_tokens() {
	return array( 'sn_mcp', 'snt_', '_sn_', 'wp-json', 'sn_apply', 'sn-apply', 'sentence_replace', 'restore_revision', 'roadmap_board', 'openstation', 'desktop_mode', 'MCP' );
}

/**
 * Validate a candidate board's structure and content. Returns a flat list
 * of human-readable problems — empty means valid. Shared by the read side
 * (an override that fails here is IGNORED, never partially rendered) and
 * sn_apply's 'roadmap_board' gate 2 (where each problem becomes an
 * error-severity finding that blocks the write).
 *
 * @param mixed $board Candidate board (family label → status → sentences).
 * @return string[] Problems; empty when the board is valid.
 */
function sn_maturity_roadmap_board_problems( $board ) {
	$problems = array();
	if ( ! is_array( $board ) || array() === $board ) {
		return array( 'board must be a non-empty object of family label → { done/planned/considering/later: sentence[] }.' );
	}
	if ( count( $board ) > SN_MATURITY_ROADMAP_MAX_FAMILIES ) {
		$problems[] = sprintf( 'board has %d families; the maximum is %d.', count( $board ), SN_MATURITY_ROADMAP_MAX_FAMILIES );
	}
	foreach ( $board as $family => $columns ) {
		$label = is_string( $family ) ? trim( $family ) : '';
		if ( '' === $label || mb_strlen( $label ) > SN_MATURITY_ROADMAP_MAX_LABEL_LEN ) { // #1227: message says "characters".
			$problems[] = sprintf( 'family label "%s" must be a non-empty string of at most %d characters.', (string) $family, SN_MATURITY_ROADMAP_MAX_LABEL_LEN );
		}
		foreach ( sn_maturity_roadmap_banned_tokens() as $token ) {
			if ( is_string( $family ) && false !== strpos( $family, $token ) ) {
				$problems[] = sprintf( 'family label "%s" contains a banned internal token.', $family );
				break;
			}
		}
		if ( ! is_array( $columns ) ) {
			$problems[] = sprintf( 'family "%s" must map to an object of status → sentence[].', $label );
			continue;
		}
		foreach ( $columns as $status => $items ) {
			if ( ! in_array( (string) $status, SN_MATURITY_ROADMAP_STATUSES, true ) ) {
				$problems[] = sprintf( 'family "%s" carries unknown status "%s" (allowed: %s).', $label, (string) $status, implode( ', ', SN_MATURITY_ROADMAP_STATUSES ) );
				continue;
			}
			if ( ! is_array( $items ) ) {
				$problems[] = sprintf( 'family "%s" status "%s" must be an array of sentences.', $label, (string) $status );
				continue;
			}
			if ( count( $items ) > SN_MATURITY_ROADMAP_MAX_ITEMS ) {
				$problems[] = sprintf( 'family "%s" status "%s" has %d items; the maximum is %d.', $label, (string) $status, count( $items ), SN_MATURITY_ROADMAP_MAX_ITEMS );
			}
			// The done column's tighter ceiling. The message names the fix,
			// per the door's standing rule that a refusal must say what to
			// do about it — here that is graduating the oldest shipped row
			// onto its family maturity page, which already states what acts
			// today, rather than deleting anything.
			if ( 'done' === (string) $status && count( $items ) > SN_MATURITY_ROADMAP_MAX_DONE ) {
				$problems[] = sprintf(
					'family "%s" has %d done rows; the maximum is %d. Graduate the oldest shipped row onto the %s maturity page and drop it from this board: the hub board is the planning surface, not the ledger.',
					$label,
					count( $items ),
					SN_MATURITY_ROADMAP_MAX_DONE,
					$label
				);
			}
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) || '' === trim( $item ) || mb_strlen( $item ) > SN_MATURITY_ROADMAP_MAX_ITEM_LEN ) { // #1227: message says "characters".
					$problems[] = sprintf( 'family "%s" status "%s" carries an item that is not a non-empty string of at most %d characters.', $label, (string) $status, SN_MATURITY_ROADMAP_MAX_ITEM_LEN );
					continue;
				}
				if ( false !== strpos( $item, '<' ) ) {
					$problems[] = sprintf( 'family "%s" status "%s" carries an item containing markup: board copy is plain prose only.', $label, (string) $status );
					continue;
				}
				foreach ( sn_maturity_roadmap_banned_tokens() as $token ) {
					if ( false !== strpos( $item, $token ) ) {
						$problems[] = sprintf( 'family "%s" status "%s" carries an item containing a banned internal token.', $label, (string) $status );
						break;
					}
				}
			}
		}
	}
	return $problems;
}

/**
 * The EFFECTIVE board (override-if-valid, else static) — pre-filter. This
 * is what sn_apply's 'roadmap_board' fingerprint binds to: it must hash
 * exactly the state a subsequent write would replace, so the filter (which
 * may be dynamic) deliberately stays outside it.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_effective_board() {
	$report = sn_maturity_roadmap_effective_report();
	return $report['merged'];
}

/**
 * The merge report against the CURRENT static board — what the Health check
 * and sn_apply's dry run consume.
 *
 * @return array{merged:array,conflicts:array,code_landed:array,override_held:array}
 */
function sn_maturity_roadmap_effective_report() {
	$static = sn_maturity_roadmap_static_board();
	$report = snt_roadmap_merge_report( $static );
	// A merged board that fails validation is not served: fall back to code,
	// the same posture the old override_board() had for an invalid option.
	// `invalid => true` is what stops that fallback from being silent — see
	// the Health check in inc/health-check-roadmap-drift.php, which reads it.
	if ( array() !== sn_maturity_roadmap_board_problems( $report['merged'] ) ) {
		return array( 'merged' => $static, 'conflicts' => array(), 'code_landed' => array(), 'override_held' => array(), 'invalid' => true );
	}
	return $report;
}

/**
 * The optimistic-concurrency fingerprint of a board state — sn_apply's
 * 'roadmap_board' change type refuses a write whose fingerprint does not
 * match the CURRENT effective board's (the stale-branch merge conflict,
 * exactly sentence_replace's content_hash binding for posts).
 *
 * @param array $board
 * @return string
 */
function sn_maturity_roadmap_board_fingerprint( $board ) {
	return md5( (string) wp_json_encode( $board ) );
}

/**
 * The rendered board: effective (override-aware) + the filter seam.
 * Signature and filter contract unchanged from every prior release —
 * existing consumers and the filter's test fixtures are untouched.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_board() {
	/**
	 * Filter the roadmap board. Family label → status → sentences;
	 * unknown statuses are dropped at render, everything is escaped at
	 * the point of build.
	 *
	 * @param array<string,array<string,string[]>> $board
	 */
	return apply_filters( 'sn_maturity_roadmap_board', sn_maturity_roadmap_effective_board() );
}

