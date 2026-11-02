<?php
/**
 * Signal & Noise Tools: /resume?lane= at render time (inc/resume-page-lanes.php).
 *
 * The stored body holds both lanes; this names each lane as a region, hides
 * the one not asked for and marks the switch's current link. It only adds
 * attributes, never text, so the page reads the same to the provenance
 * checker whichever lane shows. Unknown or missing `lane` is the music lane;
 * the value is never echoed. On a coded link (/r/CODE) or a preview, every
 * lane link (the switch and the hunks) points back at that address with its
 * lane, so changing lane keeps the code; on /resume/ itself nothing changes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The lane this request asked for (the page and its JSON Resume). @return string */
function sn_resume_active_lane() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view choice, allowlisted.
	return sn_resume_lane_param( isset( $_GET['lane'] ) ? sanitize_key( wp_unslash( $_GET['lane'] ) ) : '' );
}

/**
 * Where lane links point on this request: '' on /resume/ (the stored links
 * stand), '/r/CODE' on a coded link (the code matched its strict pattern), or
 * a preview's own address without its lane.
 *
 * @return string
 */
function sn_resume_lane_link_base() {
	$code = function_exists( 'sn_link_code_requested' ) ? sn_link_code_requested() : '';
	if ( '' !== $code ) {
		return '/r/' . $code;
	}
	return function_exists( 'is_preview' ) && is_preview() ? (string) remove_query_arg( 'lane' ) : '';
}

/**
 * Point the processor's current a[data-lane] at $base with its allowlisted
 * lane, keeping its fragment. Attribute-only; a no-op when $base is ''.
 *
 * @param WP_HTML_Tag_Processor $p
 * @param string                $base sn_resume_lane_link_base().
 */
function sn_resume_lane_relink( $p, $base ) {
	$lane = $p->get_attribute( 'data-lane' );
	if ( '' === $base || ! is_string( $lane ) ) {
		return;
	}
	$href = (string) $p->get_attribute( 'href' );
	$hash = strpos( $href, '#' );
	$to   = 'operations' === sn_resume_lane_param( $lane ) ? add_query_arg( 'lane', 'operations', $base ) : $base;
	$p->set_attribute( 'href', $to . ( false === $hash ? '' : substr( $href, $hash ) ) );
}

/**
 * render_block_core/group: name each lane, hide the other one; label the
 * switch and mark its current link.
 *
 * @param string $html  Rendered group.
 * @param array  $block Parsed block.
 * @return string
 */
function sn_resume_lane_render( $html, $block ) {
	$class = (string) ( $block['attrs']['className'] ?? '' );
	if ( false === strpos( $class, 'sn-resume-' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}
	$active = sn_resume_active_lane();
	if ( preg_match( '/\bsn-resume-variant--(music|operations)\b/', $class, $m ) ) {
		$p = new WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag() ) {
			// A landmark named as the switch names it, so a screen reader can find each résumé.
			$p->set_attribute( 'role', 'region' );
			$p->set_attribute( 'aria-label', SN_RESUME_LANE_LABELS[ $m[1] ] . ' résumé' );
			if ( $m[1] !== $active ) {
				$p->set_attribute( 'hidden', true );
			}
			$base = sn_resume_lane_link_base();
			while ( '' !== $base && $p->next_tag( 'a' ) ) {
				sn_resume_lane_relink( $p, $base ); // the hunks, in both lanes.
			}
		}
		return $p->get_updated_html();
	}
	if ( preg_match( '/\bsn-resume-switch\b/', $class ) ) {
		$p = new WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag() ) {
			$p->set_attribute( 'aria-label', 'Résumé version' );
		}
		$base = sn_resume_lane_link_base();
		while ( $p->next_tag( 'a' ) ) {
			if ( $active === $p->get_attribute( 'data-lane' ) ) {
				$p->set_attribute( 'aria-current', 'page' );
			}
			sn_resume_lane_relink( $p, $base );
		}
		return $p->get_updated_html();
	}
	return $html;
}
add_filter( 'render_block_core/group', 'sn_resume_lane_render', 10, 2 );
