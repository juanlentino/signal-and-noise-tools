<?php
/**
 * Signal & Noise Tools — [sn_maturity_roadmap], the HUB-WIDE roadmap
 * BOARD: one hard-framed row per family, three status columns
 * (done / planned / considering), readable both ways — scan a row for
 * one family's arc, scan a column for everything planned site-wide.
 * An empty cell renders an em-dash: a family with no future tense is
 * information, not a gap. An item moves LEFT as it matures, so the
 * page demonstrates the promotion flow it documents.
 *
 * Same family contract as every maturity sibling: static data behind a
 * filter seam, whitelisted statuses, escaped at build, its own front
 * stylesheet, and the security contract (no option names, endpoint
 * paths, tool or change-type slugs, or meta keys ever reach the public
 * page). 'considering' is an idea, never a commitment, and the copy
 * should read that way; 'planned' names its gate in the sentence.
 * Edits flow through the `sn_maturity_roadmap_board` filter, a
 * deliberate owner edit here, or — since the board-as-data release —
 * the sn_apply write door's 'roadmap_board' change type, which stores
 * an owner-approved override in an option. The override, when present
 * AND valid, replaces the static board wholesale (option-canonical,
 * the /resume pattern); anything invalid falls back to the static
 * board silently — the public page never renders a broken override.
 * The static array below stays the versioned default and the
 * disaster-recovery floor.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MATURITY_ROADMAP_STATUSES = array( 'done', 'planned', 'considering' );

// The board-as-data override option (written ONLY by sn_apply's
// 'roadmap_board' change type; never rendered, never echoed) and the
// override's structural bounds — generous editorial ceilings, not
// design targets.
const SN_MATURITY_ROADMAP_OPTION       = 'snt_maturity_roadmap_board';
const SN_MATURITY_ROADMAP_MAX_FAMILIES = 12;
const SN_MATURITY_ROADMAP_MAX_ITEMS    = 12;
const SN_MATURITY_ROADMAP_MAX_ITEM_LEN = 400;
const SN_MATURITY_ROADMAP_MAX_LABEL_LEN = 80;

/**
 * The STATIC board: family label → status → sentences, families in
 * render order. Every 'done' claim is verifiable against shipped
 * behavior; 'planned' names its gate; 'considering' commits to
 * nothing. This is the versioned default and the fallback whenever no
 * valid override option exists.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_static_board() {
	$board = array(
		__( 'Analytics', 'signal-and-noise-tools' )           => array(
			'done'        => array(
				__( 'First-party, cookieless measurement at the edge, with rollups, integrity-checked denominators, insights, and a weekly prose digest', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'A public stats page: the site\'s aggregate numbers published for readers, reusing the existing rollups read-only — no new collection', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Traffic rhythm flags: the deterministic cadence watch extended from cron to views, saying "this week is quiet" without ever profiling a reader', 'signal-and-noise-tools' ),
				__( 'An AI-attention section in the weekly digest: which crawler families read the site, and whether they touched the rights surfaces', 'signal-and-noise-tools' ),
			),
		),
		__( 'Proof of origin', 'signal-and-noise-tools' )     => array(
			'done'        => array(
				__( 'Notes carry a signed commit chain anchored to Bitcoin, and every accepted edit re-anchors the note as a new version', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend signing and anchoring beyond notes, to pages and then media', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'A standalone verifier anyone can run outside the site — "don\'t trust the site\'s own button" made literal', 'signal-and-noise-tools' ),
			),
		),
		__( 'AI', 'signal-and-noise-tools' )                  => array(
			'done'        => array(
				__( 'Two isolated agent doors — read-only and write — behind curated allowlists, kill switches, an audit trail, and rate limits', 'signal-and-noise-tools' ),
				__( 'Staged body edits: an AI may propose a sentence-scale change, server-side gates stage it as a revision, and only a person\'s acceptance makes it live', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Move the operative AI channel to the desktop platform\'s native agents, once that runner is stable enough to trust with the same fences', 'signal-and-noise-tools' ),
				__( 'Retire the legacy single-purpose tools the consolidated set absorbed, on usage evidence rather than on a date', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Scheduled read-only agent runs for recurring reports', 'signal-and-noise-tools' ),
				__( 'Richer edit primitives beyond sentence scale — the drafting boundary stands regardless of what is explored here', 'signal-and-noise-tools' ),
				__( 'A written threat model for any agent surface the page exposes: what a hostile paragraph could reach, before anything reachable can publish', 'signal-and-noise-tools' ),
			),
		),
		__( 'Machine learning', 'signal-and-noise-tools' )    => array(
			'done'        => array(
				__( 'A deterministic layer — related notes, topic clusters, cadence watch — computed from corpus statistics, with no model ever in the reader\'s browser', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend the deterministic layer pipeline by pipeline, as real editorial questions demand it', 'signal-and-noise-tools' ),
				__( 'Draft-time echoes: while writing, surface the most similar existing note, so overlap is a choice instead of a surprise', 'signal-and-noise-tools' ),
			),
			'considering' => array(),
		),
		__( 'Machine readability', 'signal-and-noise-tools' ) => array(
			'done'        => array(
				__( 'A crawler manifest in the site\'s own words, structured data on every surface, and machine-readable rights declarations', 'signal-and-noise-tools' ),
			),
			'planned'     => array(),
			'considering' => array(
				__( 'Provenance pointers in the machine surfaces, so an agent that reads the site can also verify it', 'signal-and-noise-tools' ),
				__( 'An in-page tool surface for verification: the page offers an agent the calls to check a signature and its anchor, so verifying travels with the content instead of waiting for anyone to adopt an API', 'signal-and-noise-tools' ),
				__( 'The corpus schema published as a machine surface: tier, number, and relation stated by the author rather than inferred by whatever reads the page', 'signal-and-noise-tools' ),
			),
		),
		__( 'Accessibility', 'signal-and-noise-tools' )       => array(
			'done'        => array(
				__( 'Structural scans with fingerprint-safe fixes, so a heading-hierarchy repair can never write over a block that moved', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Alt-text coverage for inline SVG artwork across the corpus', 'signal-and-noise-tools' ),
				__( 'An accessible treatment for third-party embeds', 'signal-and-noise-tools' ),
			),
			'considering' => array(),
		),
		__( 'Operations', 'signal-and-noise-tools' )          => array(
			'done'        => array(
				__( 'Cron, uptime, cache freshness, and deploy state watched from one dashboard that says "unknown" when it does not know', 'signal-and-noise-tools' ),
			),
			'planned'     => array(),
			'considering' => array(
				__( 'A morning brief: one narrated paragraph across health, cron, uptime, and deploys — the digest pattern pointed at operations', 'signal-and-noise-tools' ),
			),
		),
	);

	return $board;
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
		return array( 'board must be a non-empty object of family label → { done/planned/considering: sentence[] }.' );
	}
	if ( count( $board ) > SN_MATURITY_ROADMAP_MAX_FAMILIES ) {
		$problems[] = sprintf( 'board has %d families; the maximum is %d.', count( $board ), SN_MATURITY_ROADMAP_MAX_FAMILIES );
	}
	foreach ( $board as $family => $columns ) {
		$label = is_string( $family ) ? trim( $family ) : '';
		if ( '' === $label || strlen( $label ) > SN_MATURITY_ROADMAP_MAX_LABEL_LEN ) {
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
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) || '' === trim( $item ) || strlen( $item ) > SN_MATURITY_ROADMAP_MAX_ITEM_LEN ) {
					$problems[] = sprintf( 'family "%s" status "%s" carries an item that is not a non-empty string of at most %d characters.', $label, (string) $status, SN_MATURITY_ROADMAP_MAX_ITEM_LEN );
					continue;
				}
				if ( false !== strpos( $item, '<' ) ) {
					$problems[] = sprintf( 'family "%s" status "%s" carries an item containing markup — board copy is plain prose only.', $label, (string) $status );
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
 * The stored override, or null when absent/invalid. Absent and invalid
 * collapse deliberately: the public page's contract is "never render a
 * broken board", so an override that fails validation is IGNORED wholesale
 * rather than partially applied — the fallback is the static board, which
 * is always renderable.
 *
 * @return array<string,array<string,string[]>>|null
 */
function sn_maturity_roadmap_override_board() {
	$stored = get_option( SN_MATURITY_ROADMAP_OPTION, null );
	if ( ! is_array( $stored ) || array() !== sn_maturity_roadmap_board_problems( $stored ) ) {
		return null;
	}
	return $stored;
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
	$override = sn_maturity_roadmap_override_board();
	return null !== $override ? $override : sn_maturity_roadmap_static_board();
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

/**
 * Render the board. Escaped HTML; statuses outside the whitelist never
 * render; an empty cell renders an em-dash, never collapses.
 *
 * @return string
 */
function sn_maturity_roadmap_html() {
	$headings = array(
		'done'        => __( 'Done', 'signal-and-noise-tools' ),
		'planned'     => __( 'Planned', 'signal-and-noise-tools' ),
		'considering' => __( 'Considering', 'signal-and-noise-tools' ),
	);

	$out = '<h3>' . esc_html__( 'Roadmap', 'signal-and-noise-tools' ) . '</h3>'
		. '<table class="sn-maturity-roadmap-board"><thead><tr>'
		. '<th class="sn-maturity-roadmap-board__family-h">' . esc_html__( 'Family', 'signal-and-noise-tools' ) . '</th>';
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		$out .= '<th><span class="sn-maturity-roadmap-badge sn-maturity-roadmap-badge--' . esc_attr( $status ) . '">' . esc_html( $headings[ $status ] ) . '</span></th>';
	}
	$out .= '</tr></thead><tbody>';

	foreach ( sn_maturity_roadmap_board() as $family => $columns ) {
		$out .= '<tr><td class="sn-maturity-roadmap-board__family" data-label="' . esc_attr__( 'Family', 'signal-and-noise-tools' ) . '">' . esc_html( (string) $family ) . '</td>';
		foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
			$rows = isset( $columns[ $status ] ) && is_array( $columns[ $status ] ) ? $columns[ $status ] : array();
			$out .= '<td class="sn-maturity-roadmap-board__cell sn-maturity-roadmap-board__cell--' . esc_attr( $status ) . '" data-label="' . esc_attr( $headings[ $status ] ) . '">';
			if ( empty( $rows ) ) {
				$out .= '<span class="sn-maturity-roadmap-board__empty" aria-label="' . esc_attr__( 'nothing here', 'signal-and-noise-tools' ) . '">&mdash;</span>';
			} else {
				$out .= '<ul>';
				foreach ( $rows as $row ) {
					$out .= '<li>' . esc_html( (string) $row ) . '</li>';
				}
				$out .= '</ul>';
			}
			$out .= '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table>';
}

/** Enqueue the front stylesheet; shortcode-render time only. */
function sn_maturity_roadmap_enqueue() {
	wp_enqueue_style(
		'sn-maturity-roadmap-front',
		plugins_url( 'assets/maturity-roadmap-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_maturity_roadmap] — returns (never echoes), static content only,
 * safe for any public maturity page. The wrapper rides `alignfull`
 * (the constrained layout's own exemption) and the stylesheet caps it
 * at the site's 1320px frame, so the board earns its width WITH the
 * theme's layout system instead of against it.
 *
 * @param array|string $atts Shortcode attributes (unused; reserved).
 * @return string
 */
function sn_maturity_roadmap_shortcode( $atts = array() ) {
	sn_maturity_roadmap_enqueue();
	// `alignfull` is the theme's own escape hatch from the constrained
	// layout: `.is-layout-constrained` clamps every non-align child to the
	// content width WITH forced auto margins, so a margin-calc breakout
	// silently loses (measured live: the board rendered 760px). Speaking
	// the layout system's dialect wins; the stylesheet then caps at 1320.
	return '<div class="sn-maturity-roadmap sn-maturity-roadmap--wide alignfull">' . sn_maturity_roadmap_html() . '</div>';
}
add_shortcode( 'sn_maturity_roadmap', 'sn_maturity_roadmap_shortcode' );
