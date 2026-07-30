<?php
/**
 * Signal & Noise — the machine-readability explainer ([sn_machine_maturity]).
 * Fourth maturity sibling: how MACHINES read this site — the crawler manifest,
 * structured data, feeds, provenance-stamped artifacts, and bounded agent
 * access, all at the design level. Same idioms as inc/ai-maturity-page.php:
 * whitelisted format attr, render-time-only stylesheet, STATIC, escaped at
 * build, returns never echoes, scope map behind a filter.
 *
 * SECURITY CONTRACT (test-enforced in tests/maturity-family.php): model,
 * never levers — no endpoint paths, option names, hook names, worker hosts,
 * or internal prefixes in any rendered format.
 *
 * @package SignalNoiseTools @since 10.11.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SN_MACHINE_MATURITY_FORMATS  = array( 'full', 'table', 'principles', 'scope', 'compact' );
const SN_MACHINE_MATURITY_STATUSES = array( 'live', 'planned', 'never' );

/** @return array<string,array{0:string,1:string,2:string}> */
function sn_machine_maturity_layers() {
	return array(
		'indexed'    => array( __( 'Indexed', 'signal-and-noise-tools' ), __( 'Can machines find it?', 'signal-and-noise-tools' ), __( 'Sitemaps, feeds, and an AI-crawler manifest at the site root name what exists and what matters, in formats built for readers that are not people', 'signal-and-noise-tools' ) ),
		'structured' => array( __( 'Structured', 'signal-and-noise-tools' ), __( 'Can machines parse it?', 'signal-and-noise-tools' ), __( 'Structured data rides every meaningful page - articles, the music catalog, route metadata - so a machine parses what the page says instead of guessing at it', 'signal-and-noise-tools' ) ),
		'summarized' => array( __( 'Summarized', 'signal-and-noise-tools' ), __( 'Can machines answer from it?', 'signal-and-noise-tools' ), __( 'The manifest carries the site\'s own machine-readable summary and its recent notes, so an answer engine can quote this site\'s framing rather than reconstruct it', 'signal-and-noise-tools' ) ),
		'stamped'    => array( __( 'Stamped', 'signal-and-noise-tools' ), __( 'Can machines trust what they took?', 'signal-and-noise-tools' ), __( 'Artifacts that leave the site keep a way home: share-card images carry an embedded provenance stamp, and notes carry signed records any reader can verify', 'signal-and-noise-tools' ) ),
		'agents'     => array( __( 'Agent-readable', 'signal-and-noise-tools' ), __( 'Can agents work with it?', 'signal-and-noise-tools' ), __( 'AI agents get a dedicated, allowlisted interface to the site\'s own operational picture - a door rather than a scrape, bounded the same way the AI page describes', 'signal-and-noise-tools' ) ),
	);
}

/** @return array<string,array{0:string,1:string}> */
function sn_machine_maturity_scope() {
	$scope = array(
		'manifest' => array( __( 'AI-crawler manifest', 'signal-and-noise-tools' ), 'live' ),
		'schema'   => array( __( 'Structured data', 'signal-and-noise-tools' ), 'live' ),
		'feeds'    => array( __( 'Feeds', 'signal-and-noise-tools' ), 'live' ),
		'cards'    => array( __( 'Stamped share cards', 'signal-and-noise-tools' ), 'live' ),
		'agents'   => array( __( 'Agent door', 'signal-and-noise-tools' ), 'live' ),
	);
	return apply_filters( 'sn_machine_maturity_scope', $scope );
}

/** @return string[] */
function sn_machine_maturity_principles() {
	return array(
		__( 'Built for machine readers on purpose: what an answer engine should say about this site is written here, not reconstructed elsewhere.', 'signal-and-noise-tools' ),
		__( 'The manifest is the site speaking in its own words; a crawler that ignores it gets the same truth the slower way.', 'signal-and-noise-tools' ),
		__( 'Structured data describes what the page already says, never more.', 'signal-and-noise-tools' ),
		__( 'Nothing machine-facing is cloaked: machines and people read the same content.', 'signal-and-noise-tools' ),
		__( 'What leaves the site carries its origin with it.', 'signal-and-noise-tools' ),
		__( 'Agent access is a door, not a scrape: allowlisted, audited, and revocable.', 'signal-and-noise-tools' ),
		__( 'Machine readability is publishing, so it gets the same review bar as prose.', 'signal-and-noise-tools' ),
		__( 'A format nobody can verify is decoration: every machine-facing claim has a way to check it.', 'signal-and-noise-tools' ),
	);
}

/** @return string */
function sn_machine_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How machines read this site', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Five layers, from being findable to being workable. Crawlers get a manifest written in the site\'s own words, parsers get structured data, answer engines get a summary to quote, anything that leaves carries its origin, and agents get a bounded door instead of a scrape.', 'signal-and-noise-tools' ) . '</p>';
}

/** @return string */
function sn_machine_maturity_table_html() {
	$out = '<table class="sn-machine-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_machine_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-machine-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** @return string */
function sn_machine_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-machine-maturity-principles">';
	foreach ( sn_machine_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/** @return string */
function sn_machine_maturity_scope_html() {
	$labels = array( 'live' => __( 'live', 'signal-and-noise-tools' ), 'planned' => __( 'planned', 'signal-and-noise-tools' ), 'never' => __( 'never', 'signal-and-noise-tools' ) );
	$out    = '<h3>' . esc_html__( 'Coverage', 'signal-and-noise-tools' ) . '</h3><div class="sn-machine-maturity-scope">';
	foreach ( sn_machine_maturity_scope() as $slug => $s ) {
		$status = ( isset( $s[1] ) && in_array( $s[1], SN_MACHINE_MATURITY_STATUSES, true ) ) ? $s[1] : 'planned';
		$out   .= '<span class="sn-machine-maturity-scope-badge sn-machine-maturity-scope-badge--' . esc_attr( $status ) . '"><strong>' . esc_html( isset( $s[0] ) ? $s[0] : $slug ) . '</strong> ' . esc_html( $labels[ $status ] ) . '</span>';
	}
	return $out . '</div>';
}

/** @return string */
function sn_machine_maturity_compact_html() {
	$out = '<p class="sn-machine-maturity-compact-intro">' . esc_html__( 'Machines get this site in the site\'s own words: a crawler manifest, structured data, verifiable artifacts, and a bounded agent door.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-machine-maturity-strip">';
	foreach ( sn_machine_maturity_layers() as $slug => $l ) {
		$out .= '<span class="sn-machine-maturity-badge sn-machine-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $l[0] ) . '</span>';
	}
	return $out . '</div>';
}

/** Enqueue the front-end stylesheet (render-time only). */
function sn_machine_maturity_enqueue() {
	wp_enqueue_style( 'sn-machine-maturity-front', plugins_url( 'assets/machine-maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ), array(), SNT_VERSION );
}

/**
 * [sn_machine_maturity format="full|table|principles|scope|compact"]
 * @param array|string $atts
 * @return string
 */
function sn_machine_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_machine_maturity' );
	$format = in_array( $atts['format'], SN_MACHINE_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_machine_maturity_enqueue();
	$out = '<div class="sn-machine-maturity sn-machine-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_machine_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_machine_maturity_principles_html();
	} elseif ( 'scope' === $format ) {
		$out .= sn_machine_maturity_scope_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_machine_maturity_compact_html();
	} else {
		$out .= sn_machine_maturity_intro_html() . sn_machine_maturity_table_html() . sn_machine_maturity_principles_html() . sn_machine_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_machine_maturity', 'sn_machine_maturity_shortcode' );
