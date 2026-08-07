<?php
/**
 * Signal & Noise — the accessibility explainer ([sn_a11y_maturity]).
 * Sixth maturity sibling: the existing /accessibility/ page's claims,
 * restated in the family skeleton. Every claim here mirrors what that page
 * already publishes (skip links, keyboard, reduced motion, forced colors,
 * WCAG 2.1 AA self-assessed, and the named gaps) — this module adds FORMAT,
 * never new claims. Scope statuses deliberately mirror the published gaps
 * as 'planned' so the coverage map is honest by construction.
 *
 * @package SignalNoiseTools @since 10.11.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SN_A11Y_MATURITY_FORMATS  = array( 'full', 'table', 'principles', 'scope', 'compact' );
const SN_A11Y_MATURITY_STATUSES = array( 'live', 'planned', 'never' );

/** @return array<string,array{0:string,1:string,2:string}> */
function sn_a11y_maturity_layers() {
	return array(
		'reach' => array( __( 'Reach', 'signal-and-noise-tools' ), __( 'Can everyone get in?', 'signal-and-noise-tools' ), __( 'Skip links, landmarks, and a heading order a screen reader can walk - structure first, styling second', 'signal-and-noise-tools' ) ),
		'drive' => array( __( 'Drive', 'signal-and-noise-tools' ), __( 'Can everyone operate it?', 'signal-and-noise-tools' ), __( 'Full keyboard navigation with visible focus; nothing on the site requires a pointer', 'signal-and-noise-tools' ) ),
		'calm'  => array( __( 'Calm', 'signal-and-noise-tools' ), __( 'Does it respect settings?', 'signal-and-noise-tools' ), __( 'A reduced-motion preference is honored as an instruction, and forced-colors and high-contrast modes are supported rather than fought', 'signal-and-noise-tools' ) ),
		'read'  => array( __( 'Read', 'signal-and-noise-tools' ), __( 'Can everyone read it?', 'signal-and-noise-tools' ), __( 'Type scale, contrast, and spacing designed to a named standard - WCAG 2.1 AA, self-assessed, with the assessor named as the site itself', 'signal-and-noise-tools' ) ),
		'admit' => array( __( 'Admit', 'signal-and-noise-tools' ), __( 'What is still broken?', 'signal-and-noise-tools' ), __( 'The gaps are published next to the wins, with a way to report what the self-assessment missed', 'signal-and-noise-tools' ) ),
	);
}

/** @return array<string,array{0:string,1:string}> */
function sn_a11y_maturity_scope() {
	// Planned rows (SVG alt text, third-party embeds) moved to the hub-wide
	// [sn_maturity_roadmap] — the per-page scope shows what acts TODAY; the
	// roadmap owns the future tense. The seam still accepts 'planned' rows
	// via the filter for the release that flips one live.
	$scope = array(
		'keyboard' => array( __( 'Keyboard navigation', 'signal-and-noise-tools' ), 'live' ),
		'motion'   => array( __( 'Reduced motion', 'signal-and-noise-tools' ), 'live' ),
		'colors'   => array( __( 'Forced colors', 'signal-and-noise-tools' ), 'live' ),
	);
	return apply_filters( 'sn_a11y_maturity_scope', $scope );
}

/** @return string[] */
function sn_a11y_maturity_principles() {
	return array(
		__( 'Accessibility is a design-system requirement, not a bolt-on.', 'signal-and-noise-tools' ),
		__( 'Self-assessed means exactly that: the standard is named, and so is who checked.', 'signal-and-noise-tools' ),
		__( 'The gaps are published next to the wins.', 'signal-and-noise-tools' ),
		__( 'Motion asks permission: a reduced-motion setting is an instruction, not a suggestion.', 'signal-and-noise-tools' ),
		__( 'Forced colors are the reader\'s choice; the site adapts to them, never overrides them.', 'signal-and-noise-tools' ),
		__( 'Keyboard is a first-class input, not a fallback.', 'signal-and-noise-tools' ),
		__( 'A report from a reader outranks the self-assessment.', 'signal-and-noise-tools' ),
		__( 'Decoration never carries meaning alone.', 'signal-and-noise-tools' ),
	);
}

/** @return string */
function sn_a11y_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How accessibility works here', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Five layers, from structure a screen reader can walk to a public list of what is still broken. The standard is WCAG 2.1 AA, self-assessed, and the honest part is the last layer: the gaps are named, and a reader\'s report outranks the assessment.', 'signal-and-noise-tools' ) . '</p>';
}

/** @return string */
function sn_a11y_maturity_table_html() {
	$out = '<table class="sn-a11y-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_a11y_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-a11y-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** @return string */
function sn_a11y_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-a11y-maturity-principles">';
	foreach ( sn_a11y_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/** @return string */
function sn_a11y_maturity_scope_html() {
	$labels = array( 'live' => __( 'live', 'signal-and-noise-tools' ), 'planned' => __( 'planned', 'signal-and-noise-tools' ), 'never' => __( 'never', 'signal-and-noise-tools' ) );
	$out    = '<h3>' . esc_html__( 'Coverage', 'signal-and-noise-tools' ) . '</h3><div class="sn-a11y-maturity-scope">';
	foreach ( sn_a11y_maturity_scope() as $slug => $s ) {
		$status = ( isset( $s[1] ) && in_array( $s[1], SN_A11Y_MATURITY_STATUSES, true ) ) ? $s[1] : 'planned';
		$out   .= '<span class="sn-a11y-maturity-scope-badge sn-a11y-maturity-scope-badge--' . esc_attr( $status ) . '"><strong>' . esc_html( isset( $s[0] ) ? $s[0] : $slug ) . '</strong> ' . esc_html( $labels[ $status ] ) . '</span>';
	}
	return $out . '</div>';
}

/** @return string */
function sn_a11y_maturity_compact_html() {
	$out = '<p class="sn-a11y-maturity-compact-intro">' . esc_html__( 'Accessibility as a design-system requirement, to a named standard, with the remaining gaps published next to the wins.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-a11y-maturity-strip">';
	foreach ( sn_a11y_maturity_layers() as $slug => $l ) {
		$out .= '<span class="sn-a11y-maturity-badge sn-a11y-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $l[0] ) . '</span>';
	}
	return $out . '</div>';
}

/** Enqueue the front-end stylesheet (render-time only). */
function sn_a11y_maturity_enqueue() {
	wp_enqueue_style( 'sn-a11y-maturity-front', plugins_url( 'assets/a11y-maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ), array(), SNT_VERSION );
}

/**
 * [sn_a11y_maturity format="full|table|principles|scope|compact"]
 * @param array|string $atts
 * @return string
 */
function sn_a11y_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_a11y_maturity' );
	$format = in_array( $atts['format'], SN_A11Y_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_a11y_maturity_enqueue();
	$out = '<div class="sn-a11y-maturity sn-a11y-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_a11y_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_a11y_maturity_principles_html();
	} elseif ( 'scope' === $format ) {
		$out .= sn_a11y_maturity_scope_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_a11y_maturity_compact_html();
	} else {
		$out .= sn_a11y_maturity_intro_html() . sn_a11y_maturity_table_html() . sn_a11y_maturity_principles_html() . sn_a11y_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_a11y_maturity', 'sn_a11y_maturity_shortcode' );
