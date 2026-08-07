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
 * Edits flow through the `sn_maturity_roadmap_board` filter or a
 * deliberate owner edit here.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MATURITY_ROADMAP_STATUSES = array( 'done', 'planned', 'considering' );

/**
 * The board: family label → status → sentences, families in render
 * order. Every 'done' claim is verifiable against shipped behavior;
 * 'planned' names its gate; 'considering' commits to nothing.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_board() {
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
			),
		),
		__( 'Machine learning', 'signal-and-noise-tools' )    => array(
			'done'        => array(
				__( 'A deterministic layer — related notes, topic clusters, cadence watch — computed from corpus statistics, with no model ever in the reader\'s browser', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend the deterministic layer pipeline by pipeline, as real editorial questions demand it', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Draft-time echoes: while writing, surface the most similar existing note, so overlap is a choice instead of a surprise', 'signal-and-noise-tools' ),
			),
		),
		__( 'Machine readability', 'signal-and-noise-tools' ) => array(
			'done'        => array(
				__( 'A crawler manifest in the site\'s own words, structured data on every surface, and machine-readable rights declarations', 'signal-and-noise-tools' ),
			),
			'planned'     => array(),
			'considering' => array(
				__( 'Provenance pointers in the machine surfaces, so an agent that reads the site can also verify it', 'signal-and-noise-tools' ),
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

	/**
	 * Filter the roadmap board. Family label → status → sentences;
	 * unknown statuses are dropped at render, everything is escaped at
	 * the point of build.
	 *
	 * @param array<string,array<string,string[]>> $board
	 */
	return apply_filters( 'sn_maturity_roadmap_board', $board );
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
 * safe for any public maturity page. The wrapper carries the
 * wide-breakout class; the stylesheet caps it at the site's 1320px
 * frame so the board earns its width without fighting the theme.
 *
 * @param array|string $atts Shortcode attributes (unused; reserved).
 * @return string
 */
function sn_maturity_roadmap_shortcode( $atts = array() ) {
	sn_maturity_roadmap_enqueue();
	return '<div class="sn-maturity-roadmap sn-maturity-roadmap--wide">' . sn_maturity_roadmap_html() . '</div>';
}
add_shortcode( 'sn_maturity_roadmap', 'sn_maturity_roadmap_shortcode' );
