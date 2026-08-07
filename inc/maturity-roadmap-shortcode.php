<?php
/**
 * Signal & Noise Tools — [sn_maturity_roadmap], the HUB-WIDE roadmap for
 * the maturity pages: what is DONE, what is PLANNED, what is merely being
 * CONSIDERED, across every family the hub indexes (analytics, provenance,
 * AI, the machine layer, accessibility, operations).
 *
 * Same pattern as every maturity sibling: static data behind a filter
 * seam, whitelisted statuses, escaped at build, its own front stylesheet,
 * and the family's security contract (no option names, endpoint paths,
 * tool or change-type slugs, or meta keys ever reach the public page).
 * Items carry an AREA kicker so one list can span the hub without
 * flattening into mush. 'considering' is an idea, never a commitment,
 * and the copy should read that way; item edits flow through the
 * `sn_maturity_roadmap_items` filter or a deliberate owner edit here.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MATURITY_ROADMAP_STATUSES = array( 'done', 'planned', 'considering' );

/**
 * The roadmap: status → items, each `array( area, sentence )`, in render
 * order. Every 'done' claim is verifiable against shipped behavior;
 * 'planned' names its gate; 'considering' commits to nothing.
 *
 * @return array<string,array<int,array{0:string,1:string}>>
 */
function sn_maturity_roadmap_items() {
	$items = array(
		'done'        => array(
			array( __( 'Analytics', 'signal-and-noise-tools' ), __( 'First-party, cookieless measurement at the edge, with rollups, integrity-checked denominators, insights, and a weekly prose digest', 'signal-and-noise-tools' ) ),
			array( __( 'Provenance', 'signal-and-noise-tools' ), __( 'Notes carry a signed commit chain anchored to Bitcoin, and every accepted edit re-anchors the note as a new version', 'signal-and-noise-tools' ) ),
			array( __( 'AI', 'signal-and-noise-tools' ), __( 'Two isolated agent doors — read-only and write — behind curated allowlists, kill switches, an audit trail, and rate limits', 'signal-and-noise-tools' ) ),
			array( __( 'AI', 'signal-and-noise-tools' ), __( 'Staged body edits: an AI may propose a sentence-scale change, server-side gates stage it as a revision, and only a person\'s acceptance makes it live', 'signal-and-noise-tools' ) ),
			array( __( 'Machine layer', 'signal-and-noise-tools' ), __( 'A deterministic layer — related notes, topic clusters, cadence watch — computed from corpus statistics, with no model ever in the reader\'s browser', 'signal-and-noise-tools' ) ),
			array( __( 'Accessibility', 'signal-and-noise-tools' ), __( 'Structural scans with fingerprint-safe fixes, so a heading-hierarchy repair can never write over a block that moved', 'signal-and-noise-tools' ) ),
			array( __( 'Operations', 'signal-and-noise-tools' ), __( 'Cron, uptime, cache freshness, and deploy state watched from one dashboard that says "unknown" when it does not know', 'signal-and-noise-tools' ) ),
		),
		'planned'     => array(
			array( __( 'AI', 'signal-and-noise-tools' ), __( 'Move the operative AI channel to the desktop platform\'s native agents, once that runner is stable enough to trust with the same fences', 'signal-and-noise-tools' ) ),
			array( __( 'AI', 'signal-and-noise-tools' ), __( 'Retire the legacy single-purpose tools the consolidated set absorbed, on usage evidence rather than on a date', 'signal-and-noise-tools' ) ),
			array( __( 'Machine layer', 'signal-and-noise-tools' ), __( 'Extend the deterministic layer pipeline by pipeline, as real editorial questions demand it', 'signal-and-noise-tools' ) ),
			array( __( 'Analytics', 'signal-and-noise-tools' ), __( 'A public stats page: the site\'s aggregate numbers published for readers, reusing the existing rollups read-only — no new collection', 'signal-and-noise-tools' ) ),
			array( __( 'Provenance', 'signal-and-noise-tools' ), __( 'Extend signing and anchoring beyond notes, to pages and then media', 'signal-and-noise-tools' ) ),
			array( __( 'Accessibility', 'signal-and-noise-tools' ), __( 'Alt-text coverage for inline SVG artwork across the corpus', 'signal-and-noise-tools' ) ),
			array( __( 'Accessibility', 'signal-and-noise-tools' ), __( 'An accessible treatment for third-party embeds', 'signal-and-noise-tools' ) ),
		),
		'considering' => array(
			array( __( 'AI', 'signal-and-noise-tools' ), __( 'Scheduled read-only agent runs for recurring reports', 'signal-and-noise-tools' ) ),
			array( __( 'AI', 'signal-and-noise-tools' ), __( 'Richer edit primitives beyond sentence scale — the drafting boundary stands regardless of what is explored here', 'signal-and-noise-tools' ) ),
			array( __( 'Analytics', 'signal-and-noise-tools' ), __( 'Traffic rhythm flags: the deterministic cadence watch extended from cron to views, saying "this week is quiet" without ever profiling a reader', 'signal-and-noise-tools' ) ),
			array( __( 'Analytics', 'signal-and-noise-tools' ), __( 'An AI-attention section in the weekly digest: which crawler families read the site, and whether they touched the rights surfaces', 'signal-and-noise-tools' ) ),
		),
	);

	/**
	 * Filter the hub-wide roadmap items. Unknown statuses are dropped at
	 * render; each item is `array( area, sentence )`, escaped at the
	 * point of build.
	 *
	 * @param array<string,array<int,array{0:string,1:string}>> $items
	 */
	return apply_filters( 'sn_maturity_roadmap_items', $items );
}

/**
 * Render the roadmap sections. Escaped HTML; statuses outside the
 * whitelist never render; an emptied status is omitted, not rendered
 * hollow.
 *
 * @return string
 */
function sn_maturity_roadmap_html() {
	$headings = array(
		'done'        => __( 'Done', 'signal-and-noise-tools' ),
		'planned'     => __( 'Planned', 'signal-and-noise-tools' ),
		'considering' => __( 'Considering', 'signal-and-noise-tools' ),
	);

	$items = sn_maturity_roadmap_items();
	$out   = '<h3>' . esc_html__( 'Roadmap', 'signal-and-noise-tools' ) . '</h3>';
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		$rows = isset( $items[ $status ] ) && is_array( $items[ $status ] ) ? $items[ $status ] : array();
		if ( empty( $rows ) ) {
			continue;
		}
		$out .= '<div class="sn-maturity-roadmap-group sn-maturity-roadmap-group--' . esc_attr( $status ) . '">'
			. '<h4><span class="sn-maturity-roadmap-badge sn-maturity-roadmap-badge--' . esc_attr( $status ) . '">' . esc_html( $headings[ $status ] ) . '</span></h4><ul>';
		foreach ( $rows as $row ) {
			$area = is_array( $row ) ? (string) ( $row[0] ?? '' ) : '';
			$text = is_array( $row ) ? (string) ( $row[1] ?? '' ) : (string) $row;
			$out .= '<li>'
				. ( '' !== $area ? '<span class="sn-maturity-roadmap-area">' . esc_html( $area ) . '</span>' : '' )
				. esc_html( $text ) . '</li>';
		}
		$out .= '</ul></div>';
	}
	return $out;
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
 * safe for any public maturity page.
 *
 * @param array|string $atts Shortcode attributes (unused; reserved).
 * @return string
 */
function sn_maturity_roadmap_shortcode( $atts = array() ) {
	sn_maturity_roadmap_enqueue();
	return '<div class="sn-maturity-roadmap">' . sn_maturity_roadmap_html() . '</div>';
}
add_shortcode( 'sn_maturity_roadmap', 'sn_maturity_roadmap_shortcode' );
