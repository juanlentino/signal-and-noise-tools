<?php
/**
 * Signal & Noise — the maturity index ([sn_maturity_index]).
 * The hub for the maturity family: one card per system, each naming the
 * question its page answers, linking to the page. STATIC like every sibling;
 * item list runs through the `sn_maturity_index_items` filter so a new moat's
 * page (or a renamed slug) is a one-line change, per the standing convention:
 * every moat gets a maturity page, and net-new features flip badges.
 *
 * @package SignalNoiseTools @since 10.11.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The family: slug → [label, question, one-liner, target]. The target is a
 * PAGE SLUG (post_name) resolved to a permalink at render — v10.11.2, after
 * two rounds of hardcoded-path drift (owner slugs, then the whole family
 * re-parented under /maturity/). get_permalink() follows hierarchy, so
 * re-parenting or moving a page can never break the index again. A target
 * starting with '/' or 'http' is treated as an explicit path/URL instead
 * (the filter escape hatch). An unresolvable target renders an unlinked
 * card — never a dead link.
 * @return array<string,array{0:string,1:string,2:string,3:string}>
 */
function sn_maturity_index_items() {
	$items = array(
		'analytics'  => array( __( 'Analytics', 'signal-and-noise-tools' ), __( 'What does the site know about itself?', 'signal-and-noise-tools' ), __( 'First-party measurement, no third-party trackers, and zero never confused with unknown.', 'signal-and-noise-tools' ), 'analytics' ),
		'provenance' => array( __( 'Proof of origin', 'signal-and-noise-tools' ), __( 'What can the site prove about its work?', 'signal-and-noise-tools' ), __( 'Every note signed at publish, Bitcoin-anchored, and verifiable in the reader\'s own browser.', 'signal-and-noise-tools' ), 'proof-of-origin' ),
		'ai'         => array( __( 'AI', 'signal-and-noise-tools' ), __( 'How does AI participate, and where does it stop?', 'signal-and-noise-tools' ), __( 'A written voice spec, mechanical checks, human review, and note bodies that stay human, permanently.', 'signal-and-noise-tools' ), 'ai-maturity' ),
		'machine'    => array( __( 'Machine readability', 'signal-and-noise-tools' ), __( 'How do machines read this site?', 'signal-and-noise-tools' ), __( 'A crawler manifest in the site\'s own words, structured data, stamped artifacts, and a bounded agent door.', 'signal-and-noise-tools' ), 'machine-readability' ),
		'ops'        => array( __( 'Operations', 'signal-and-noise-tools' ), __( 'How does the site run itself?', 'signal-and-noise-tools' ), __( 'Health scans, outside-in probes, overnight narration, housekeeping out loud, and gated change.', 'signal-and-noise-tools' ), 'ops-maturity' ),
		'a11y'       => array( __( 'Accessibility', 'signal-and-noise-tools' ), __( 'Can everyone use it?', 'signal-and-noise-tools' ), __( 'A design-system requirement to a named standard, with the gaps published next to the wins.', 'signal-and-noise-tools' ), 'a11y-maturity' ),
		'ml'         => array( __( 'Machine learning', 'signal-and-noise-tools' ), __( 'What does the site compute about its own writing?', 'signal-and-noise-tools' ), __( 'Deterministic corpus math computed in place: scored candidates, never actions, and no model ever ships to a reader.', 'signal-and-noise-tools' ), 'ml-maturity' ),
	);
	return apply_filters( 'sn_maturity_index_items', $items );
}

/**
 * Resolve a card target to a URL. Page slug → get_permalink (hierarchy-proof);
 * explicit '/path/' or absolute URL → home_url()-anchored / passed through;
 * unresolvable → '' (the caller renders an unlinked card).
 *
 * @param string $target Page slug, site-relative path, or absolute URL.
 * @return string
 */
function sn_maturity_index_resolve_url( $target ) {
	$target = (string) $target;
	if ( '' === $target ) {
		return '';
	}
	if ( 0 === strpos( $target, 'http' ) ) {
		return $target;
	}
	if ( '/' === $target[0] ) {
		return home_url( $target );
	}
	$pages = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'name'           => $target,
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	) );
	return $pages ? (string) get_permalink( $pages[0] ) : '';
}

/** Enqueue the front-end stylesheet (render-time only). */
function sn_maturity_index_enqueue() {
	wp_enqueue_style( 'sn-maturity-index-front', plugins_url( 'assets/maturity-index-front.css', SNT_PATH . 'signal-and-noise-tools.php' ), array(), SNT_VERSION );
}

/**
 * [sn_maturity_index] — the hub. Returns, never echoes; static content only.
 * @param array|string $atts Unused; present for the shortcode signature.
 * @return string
 */
function sn_maturity_index_shortcode( $atts = array() ) {
	sn_maturity_index_enqueue();
	$out = '<div class="sn-maturity-index">'
		. '<p class="sn-maturity-index-intro">' . esc_html__( 'Each system this site runs on has a public page stating what it does, what it proves, and what it will never do. The pages update when the systems do.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-maturity-index-grid">';
	foreach ( sn_maturity_index_items() as $slug => $it ) {
		$label = esc_html( isset( $it[0] ) ? $it[0] : $slug );
		$q     = esc_html( isset( $it[1] ) ? $it[1] : '' );
		$line  = esc_html( isset( $it[2] ) ? $it[2] : '' );
		$url   = sn_maturity_index_resolve_url( isset( $it[3] ) ? (string) $it[3] : '' );
		$inner = '<strong>' . $label . '</strong><em>' . $q . '</em><span>' . $line . '</span>';
		if ( '' !== $url ) {
			$out .= '<a class="sn-maturity-index-card sn-maturity-index-card--' . esc_attr( $slug ) . '" href="' . esc_url( $url ) . '">' . $inner . '</a>';
		} else {
			$out .= '<div class="sn-maturity-index-card sn-maturity-index-card--unlinked sn-maturity-index-card--' . esc_attr( $slug ) . '">' . $inner . '</div>';
		}
	}
	return $out . '</div></div>';
}
add_shortcode( 'sn_maturity_index', 'sn_maturity_index_shortcode' );
