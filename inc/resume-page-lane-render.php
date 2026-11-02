<?php
/**
 * Signal & Noise Tools: /resume?lane= at render time (inc/resume-page-lanes.php).
 *
 * The stored body holds both lanes; this hides the one not asked for and marks
 * the switch's current link. It only adds attributes, never text, so the page
 * reads the same to the provenance checker whichever lane shows. Unknown or
 * missing `lane` is the music lane; the value is never echoed.
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
 * render_block_core/group: hide the other lane; label the switch and mark its
 * current link.
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
		if ( $m[1] === $active ) {
			return $html;
		}
		$p = new WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag() ) {
			$p->set_attribute( 'hidden', true );
		}
		return $p->get_updated_html();
	}
	if ( preg_match( '/\bsn-resume-switch\b/', $class ) ) {
		$p = new WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag() ) {
			$p->set_attribute( 'aria-label', 'Résumé version' );
		}
		while ( $p->next_tag( 'a' ) ) {
			if ( $active === $p->get_attribute( 'data-lane' ) ) {
				$p->set_attribute( 'aria-current', 'page' );
			}
		}
		return $p->get_updated_html();
	}
	return $html;
}
add_filter( 'render_block_core/group', 'sn_resume_lane_render', 10, 2 );
