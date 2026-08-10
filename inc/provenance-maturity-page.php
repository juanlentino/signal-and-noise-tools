<?php
/**
 * Signal & Noise — the provenance-maturity explainer ([sn_provenance_maturity]).
 * The "how provenance works" public case-study: the four-layer walk (canonical →
 * signed → anchored → verifiable), the honesty principles, and a coverage map —
 * a literal sibling of inc/analytics-maturity-page.php ([sn_analytics_maturity]):
 * same format whitelist idiom (full | table | principles | scope | compact), same
 * render-time front-end stylesheet (assets/provenance-maturity-front.css,
 * enqueued only when the shortcode actually renders), STATIC by design — no live
 * counts, no per-reader data — so it publishes on a public page as the portfolio
 * artifact. The coverage map is the expansion seam: sn_prov_maturity_scope()
 * runs through the `sn_prov_maturity_scope` filter, so the planned site-provenance
 * arcs (Pages, Media — see the 2026-07-29 design spec) flip a status flag instead
 * of re-coding markup. All output escaped at the point of build; the shortcode
 * returns, never echoes.
 * @package SignalNoiseTools @since 10.5.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The format whitelist. Unknown values fall back to 'full' — pinned; the raw
 * attribute value never reaches a class attribute.
 */
const SN_PROV_MATURITY_FORMATS = array( 'full', 'table', 'principles', 'scope', 'compact' );

/** The scope-status whitelist; unknown statuses render as 'planned', never raw. */
const SN_PROV_MATURITY_STATUSES = array( 'live', 'planned' );

/**
 * The layer rows: slug → [label, question, engine], in walk order. Every engine
 * claim is verifiable against the shipped modules (provenance-core / -did /
 * -webhook / -verify) and the CHANGELOG.
 * @return array<string,array{0:string,1:string,2:string}>
 */
function sn_prov_maturity_layers() {
	return array(
		'canonical'  => array( __( 'Canonical', 'signal-and-noise-tools' ), __( 'What exactly is signed?', 'signal-and-noise-tools' ), __( 'Deterministic normalization (sn-normalize-v1) of the published text into canonical JSON - recursively sorted keys, stable bytes - hashed with SHA-256; the same bytes any independent implementation can rebuild', 'signal-and-noise-tools' ) ),
		'signed'     => array( __( 'Signed', 'signal-and-noise-tools' ), __( 'Who vouches for it?', 'signal-and-noise-tools' ), __( 'An Ed25519 signature over those exact bytes, the public key published as did:web and pinned off-site by an external key mirror and a DNS record - trust that leaves the site. The key mirror publishes history, not just the current key: retired keys stay listed inside dated validity windows so signatures made under them still verify, and the next key is committed by hash before it is ever used', 'signal-and-noise-tools' ) ),
		'anchored'   => array( __( 'Anchored', 'signal-and-noise-tools' ), __( 'When did it exist?', 'signal-and-noise-tools' ), __( 'OpenTimestamps commits each version hash into Bitcoin, and every edit appends a new version to a public git ledger - a chain that records what changed and when', 'signal-and-noise-tools' ) ),
		'verifiable' => array( __( 'Verifiable', 'signal-and-noise-tools' ), __( 'Can a reader check?', 'signal-and-noise-tools' ), __( 'A verifier at /verify runs in the reader\'s own browser (WebCrypto) and settles four plain-language verdicts - signature, content hash, live match, Bitcoin anchor - with no trust in this server', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The coverage map: slug → [label, status], statuses whitelisted live|planned.
 * THE EXPANSION SEAM: the site-provenance arcs (Pages, then Media) ship their
 * page-facing story by filtering a status to 'live' — the markup never changes.
 * @return array<string,array{0:string,1:string}>
 */
function sn_prov_maturity_scope() {
	// Planned rows (Pages, Media) moved to the hub-wide [sn_maturity_roadmap]
	// — the per-page scope shows what is anchored TODAY; the roadmap owns the
	// future tense. The seam still accepts 'planned' rows via the filter for
	// the release that flips one live.
	$scope = array(
		'notes' => array( __( 'Notes', 'signal-and-noise-tools' ), 'live' ),
	);
	return apply_filters( 'sn_prov_maturity_scope', $scope );
}

/**
 * The honesty principles: eight, each verifiable against shipped behavior.
 * Raw strings; escaped at the point of build.
 * @return string[]
 */
function sn_prov_maturity_principles() {
	return array(
		__( 'Provenance proves integrity and time, never truth - a signed mistake is still a mistake, provably mine and provably dated.', 'signal-and-noise-tools' ),
		__( 'Verification runs in the reader\'s browser, never on this server - a site vouching for itself proves nothing.', 'signal-and-noise-tools' ),
		__( 'Key trust leaves the site: the public key is pinned by an external mirror and a DNS record, so a quietly swapped key is catchable.', 'signal-and-noise-tools' ),
		__( 'Every version is kept: edits append to the chain, never overwrite it.', 'signal-and-noise-tools' ),
		__( 'Network failure never impersonates cryptographic failure: an unreachable check reads unreachable, never failed.', 'signal-and-noise-tools' ),
		__( 'Unpublished and password-protected content never reaches the ledger, the credential, or the card.', 'signal-and-noise-tools' ),
		__( 'The Bitcoin anchor walk names itself honestly: an attestation across independent sources, not an inclusion proof.', 'signal-and-noise-tools' ),
		__( 'No certificate authority, no annual ritual: trust is open keys plus Bitcoin, both inspectable by anyone.', 'signal-and-noise-tools' ),
	);
}

/** The intro section (full format only). @return string Escaped HTML. */
function sn_prov_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How content provenance works', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Four layers, each answering one question. Everything is deterministic - normalization, signing, anchoring - and the final judgment belongs to the reader: verification happens client-side, in your browser, against keys and anchors that live outside this site.', 'signal-and-noise-tools' ) . '</p>';
}

/** The layer table. @return string Escaped HTML. */
function sn_prov_maturity_table_html() {
	$out = '<table class="sn-prov-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_prov_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-prov-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** The principles section (heading + list). @return string Escaped HTML. */
function sn_prov_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-prov-maturity-principles">';
	foreach ( sn_prov_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/**
 * The coverage section: one badge per surface, status-classed. A status outside
 * the whitelist renders as 'planned' — filter output never reaches the class
 * attribute raw. @return string Escaped HTML.
 */
function sn_prov_maturity_scope_html() {
	$out = '<h3>' . esc_html__( 'Coverage', 'signal-and-noise-tools' ) . '</h3><div class="sn-prov-maturity-scope">';
	foreach ( sn_prov_maturity_scope() as $slug => $s ) {
		$status = ( isset( $s[1] ) && in_array( $s[1], SN_PROV_MATURITY_STATUSES, true ) ) ? $s[1] : 'planned';
		$out   .= '<span class="sn-prov-maturity-scope-badge sn-prov-maturity-scope-badge--' . esc_attr( $status ) . '"><strong>' . esc_html( isset( $s[0] ) ? $s[0] : $slug ) . '</strong> ' . esc_html( 'live' === $status ? __( 'live', 'signal-and-noise-tools' ) : __( 'planned', 'signal-and-noise-tools' ) ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * The compact strip: one sentence + a badge per layer (the maturity-badge
 * markup idiom, on this surface's own public classes). @return string Escaped HTML.
 */
function sn_prov_maturity_compact_html() {
	$out = '<p class="sn-prov-maturity-compact-intro">' . esc_html__( 'Every published Note is canonicalized, signed, Bitcoin-anchored, and verifiable in your own browser - provenance honest by construction.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-prov-maturity-strip">';
	foreach ( sn_prov_maturity_layers() as $slug => $l ) {
		$out .= '<span class="sn-prov-maturity-badge sn-prov-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $l[0] ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * Enqueue the front-end stylesheet. Called from the shortcode callback only, so
 * the CSS ships exactly when the explainer renders (wp_enqueue_style mid-page
 * prints the tag via the footer queue; repeat calls are core-deduped by handle).
 */
function sn_prov_maturity_enqueue() {
	wp_enqueue_style(
		'sn-prov-maturity-front',
		plugins_url( 'assets/provenance-maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_provenance_maturity format="full|table|principles|scope|compact"] — the
 * explainer. Returns (never echoes) per the shortcode contract; safe for a
 * public page (static content only). Unknown formats fall back to 'full'.
 *
 * @param array|string $atts Shortcode attributes (core passes '' when bare).
 * @return string
 */
function sn_prov_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_provenance_maturity' );
	$format = in_array( $atts['format'], SN_PROV_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_prov_maturity_enqueue();
	$out = '<div class="sn-prov-maturity sn-prov-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_prov_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_prov_maturity_principles_html();
	} elseif ( 'scope' === $format ) {
		$out .= sn_prov_maturity_scope_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_prov_maturity_compact_html();
	} else {
		$out .= sn_prov_maturity_intro_html() . sn_prov_maturity_table_html() . sn_prov_maturity_principles_html() . sn_prov_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_provenance_maturity', 'sn_prov_maturity_shortcode' );
