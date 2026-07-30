<?php
/**
 * Signal & Noise — the operations explainer ([sn_ops_maturity]).
 * Fifth maturity sibling: how the site watches itself — scheduled health
 * scans, outside-in uptime probes, plain-language narration of scheduled
 * work, never-silent housekeeping, and gated, versioned change. Same idioms
 * as inc/ai-maturity-page.php. The principles here are the codified lessons
 * of real incidents (the healthy-readout trap, zero-vs-unknown, the silent
 * janitor) — that lineage is the page's authority.
 *
 * SECURITY CONTRACT (test-enforced in tests/maturity-family.php): model,
 * never levers — no check names, hook names, endpoint paths, hosts, vendor
 * names, or internal prefixes in any rendered format.
 *
 * @package SignalNoiseTools @since 10.11.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SN_OPS_MATURITY_FORMATS  = array( 'full', 'table', 'principles', 'scope', 'compact' );
const SN_OPS_MATURITY_STATUSES = array( 'live', 'planned', 'never' );

/** @return array<string,array{0:string,1:string,2:string}> */
function sn_ops_maturity_layers() {
	return array(
		'watch'   => array( __( 'Watch', 'signal-and-noise-tools' ), __( 'What checks the site?', 'signal-and-noise-tools' ), __( 'A scheduled health scan runs more than a dozen independent checks across the stack and raises a standing attention flag when anything reads wrong - a flag that stays up until a person clears the cause', 'signal-and-noise-tools' ) ),
		'probe'   => array( __( 'Probe', 'signal-and-noise-tools' ), __( 'Who watches from outside?', 'signal-and-noise-tools' ), __( 'Uptime is probed from outside the site\'s own infrastructure, because a server reporting itself healthy proves little about whether readers can reach it', 'signal-and-noise-tools' ) ),
		'narrate' => array( __( 'Narrate', 'signal-and-noise-tools' ), __( 'Can the site explain itself?', 'signal-and-noise-tools' ), __( 'Scheduled work leaves a plain-language narration of what ran and what changed, so "what happened overnight" is a read, not an investigation', 'signal-and-noise-tools' ) ),
		'clean'   => array( __( 'Clean', 'signal-and-noise-tools' ), __( 'What happens to leftovers?', 'signal-and-noise-tools' ), __( 'Housekeeping never deletes silently: the janitor reports what it removed and why, every time, and an empty report is itself a report', 'signal-and-noise-tools' ) ),
		'gate'    => array( __( 'Gate', 'signal-and-noise-tools' ), __( 'How does change arrive?', 'signal-and-noise-tools' ), __( 'Every change ships through automated review gates and lands versioned and tagged, with the changelog public - so what is running is always a fact, never a guess', 'signal-and-noise-tools' ) ),
	);
}

/** @return array<string,array{0:string,1:string}> */
function sn_ops_maturity_scope() {
	$scope = array(
		'health'    => array( __( 'Health scans', 'signal-and-noise-tools' ), 'live' ),
		'uptime'    => array( __( 'Outside-in uptime', 'signal-and-noise-tools' ), 'live' ),
		'narration' => array( __( 'Overnight narration', 'signal-and-noise-tools' ), 'live' ),
		'janitor'   => array( __( 'Never-silent housekeeping', 'signal-and-noise-tools' ), 'live' ),
		'gates'     => array( __( 'Release gates', 'signal-and-noise-tools' ), 'live' ),
	);
	return apply_filters( 'sn_ops_maturity_scope', $scope );
}

/** @return string[] */
function sn_ops_maturity_principles() {
	return array(
		__( 'A healthy readout must be able to go red: any check that cannot fail is decoration.', 'signal-and-noise-tools' ),
		__( 'Silence is not success - scheduled work reports what it did, and a quiet failure is treated as the worst kind.', 'signal-and-noise-tools' ),
		__( 'Zero and unknown are different answers, and the site never confuses them.', 'signal-and-noise-tools' ),
		__( 'An unreachable check reads unreachable, never failed and never passed.', 'signal-and-noise-tools' ),
		__( 'The site is watched from outside itself; self-reporting alone is not evidence.', 'signal-and-noise-tools' ),
		__( 'Nothing is deleted silently: housekeeping names its work.', 'signal-and-noise-tools' ),
		__( 'Machines review every change before a person ships it, and every release is tagged and reversible.', 'signal-and-noise-tools' ),
		__( 'Operational history is kept: what ran, when, and what it touched.', 'signal-and-noise-tools' ),
	);
}

/** @return string */
function sn_ops_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How this site runs itself', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Five layers of self-operation, each shaped by an incident that taught it something. The site checks itself on a schedule, gets checked from outside, narrates its own overnight work, cleans up out loud, and only changes through gates.', 'signal-and-noise-tools' ) . '</p>';
}

/** @return string */
function sn_ops_maturity_table_html() {
	$out = '<table class="sn-ops-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_ops_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-ops-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** @return string */
function sn_ops_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-ops-maturity-principles">';
	foreach ( sn_ops_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/** @return string */
function sn_ops_maturity_scope_html() {
	$labels = array( 'live' => __( 'live', 'signal-and-noise-tools' ), 'planned' => __( 'planned', 'signal-and-noise-tools' ), 'never' => __( 'never', 'signal-and-noise-tools' ) );
	$out    = '<h3>' . esc_html__( 'Coverage', 'signal-and-noise-tools' ) . '</h3><div class="sn-ops-maturity-scope">';
	foreach ( sn_ops_maturity_scope() as $slug => $s ) {
		$status = ( isset( $s[1] ) && in_array( $s[1], SN_OPS_MATURITY_STATUSES, true ) ) ? $s[1] : 'planned';
		$out   .= '<span class="sn-ops-maturity-scope-badge sn-ops-maturity-scope-badge--' . esc_attr( $status ) . '"><strong>' . esc_html( isset( $s[0] ) ? $s[0] : $slug ) . '</strong> ' . esc_html( $labels[ $status ] ) . '</span>';
	}
	return $out . '</div>';
}

/** @return string */
function sn_ops_maturity_compact_html() {
	$out = '<p class="sn-ops-maturity-compact-intro">' . esc_html__( 'The site checks itself on a schedule, gets checked from outside, narrates its own work, cleans up out loud, and only changes through gates.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-ops-maturity-strip">';
	foreach ( sn_ops_maturity_layers() as $slug => $l ) {
		$out .= '<span class="sn-ops-maturity-badge sn-ops-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $l[0] ) . '</span>';
	}
	return $out . '</div>';
}

/** Enqueue the front-end stylesheet (render-time only). */
function sn_ops_maturity_enqueue() {
	wp_enqueue_style( 'sn-ops-maturity-front', plugins_url( 'assets/ops-maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ), array(), SNT_VERSION );
}

/**
 * [sn_ops_maturity format="full|table|principles|scope|compact"]
 * @param array|string $atts
 * @return string
 */
function sn_ops_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_ops_maturity' );
	$format = in_array( $atts['format'], SN_OPS_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_ops_maturity_enqueue();
	$out = '<div class="sn-ops-maturity sn-ops-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_ops_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_ops_maturity_principles_html();
	} elseif ( 'scope' === $format ) {
		$out .= sn_ops_maturity_scope_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_ops_maturity_compact_html();
	} else {
		$out .= sn_ops_maturity_intro_html() . sn_ops_maturity_table_html() . sn_ops_maturity_principles_html() . sn_ops_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_ops_maturity', 'sn_ops_maturity_shortcode' );
