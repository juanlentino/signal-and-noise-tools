<?php
/**
 * Signal & Noise — the ML-maturity explainer ([sn_ml_maturity]).
 * The "how this site computes about its own writing" public case-study: the
 * six-layer walk (corpus → model → compute → surface → draft → decide), the
 * honesty principles, and a coverage map — the seventh member of the maturity
 * family, mirroring inc/ai-maturity-page.php: same format whitelist idiom
 * (full | table | principles | scope | compact), same render-time front-end
 * stylesheet (assets/ml-maturity-front.css, enqueued only when the shortcode
 * renders), STATIC by design — no live counts, no per-reader data.
 *
 * SECURITY CONTRACT (test-enforced in tests/ml-maturity-page.php): this page
 * describes the MODEL, never the LEVERS. No meta keys, no tool slugs, no
 * implementation file names, no hook names, no tuned threshold numbers appear
 * in any rendered format — the fixture asserts their absence, so a future
 * edit that leaks one reds CI. Naming the mathematics (TF-IDF, BM25, cosine)
 * is deliberate: the math is the model, and the model is the claim.
 *
 * The scope map carries the program's THREE NEVERS as 'never' badges —
 * commitments, not roadmap gaps; they move only by deliberate owner edit:
 * provenance verdicts, reader profiling, models in the reader's browser.
 *
 * @package SignalNoiseTools @since 10.18.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The format whitelist. Unknown values fall back to 'full' — pinned. */
const SN_ML_MATURITY_FORMATS = array( 'full', 'table', 'principles', 'scope', 'compact' );

/** Scope-status whitelist; unknown statuses render as 'planned', never raw. */
const SN_ML_MATURITY_STATUSES = array( 'live', 'planned', 'never' );

/**
 * The layer rows: slug → [label, question, engine], in walk order. Every
 * engine claim is verifiable against shipped behavior; none names a lever.
 * @return array<string,array{0:string,1:string,2:string}>
 */
function sn_ml_maturity_layers() {
	return array(
		'corpus'  => array( __( 'Corpus', 'signal-and-noise-tools' ), __( 'What does it learn from?', 'signal-and-noise-tools' ), __( 'Only this site\'s own published notes. The vocabulary, the weights, and every ranking are rebuilt from the corpus itself - no external dataset, no third-party service, and no reader data, ever', 'signal-and-noise-tools' ) ),
		'model'   => array( __( 'Model', 'signal-and-noise-tools' ), __( 'What is the model?', 'signal-and-noise-tools' ), __( 'Classical text statistics - term weighting, document similarity, and ranking functions of the TF-IDF and BM25 family - computed exactly. No neural network, no training run, no weights file: the same corpus in always produces the same answer out', 'signal-and-noise-tools' ) ),
		'compute' => array( __( 'Compute', 'signal-and-noise-tools' ), __( 'Where does it run?', 'signal-and-noise-tools' ), __( 'Inside the site, when a note publishes and on a nightly schedule. Results are computed once and stored, so no reader request ever waits on the model', 'signal-and-noise-tools' ) ),
		'surface' => array( __( 'Surface', 'signal-and-noise-tools' ), __( 'What does it produce?', 'signal-and-noise-tools' ), __( 'Ranked candidates: related notes, near-duplicate pairs, keyword suggestions, and internal-link suggestions. Every output is a scored suggestion, never an action', 'signal-and-noise-tools' ) ),
		'draft'   => array( __( 'Draft', 'signal-and-noise-tools' ), __( 'Where does AI meet it?', 'signal-and-noise-tools' ), __( 'Above the kernel sits the AI tier, which drafts prose around what the kernel ranks. The tiers never trade jobs: the statistics never write, and the generators never rank', 'signal-and-noise-tools' ) ),
		'decide'  => array( __( 'Decide', 'signal-and-noise-tools' ), __( 'Who decides?', 'signal-and-noise-tools' ), __( 'A person. Nothing the kernel produces changes the site by itself - candidates wait for a human to accept, edit, or dismiss them', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The coverage map: slug → [label, status], statuses whitelisted
 * live|planned|never. THE EXPANSION SEAM: future consumers flip a status
 * through the `sn_ml_maturity_scope` filter instead of re-coding markup.
 * The three 'never' entries are the program's standing commitments and only
 * ever move by deliberate owner edit.
 * @return array<string,array{0:string,1:string}>
 */
function sn_ml_maturity_scope() {
	$scope = array(
		'related'   => array( __( 'Related notes', 'signal-and-noise-tools' ), 'live' ),
		'cousins'   => array( __( 'Near-duplicate pairs', 'signal-and-noise-tools' ), 'live' ),
		'keywords'  => array( __( 'Keyword candidates', 'signal-and-noise-tools' ), 'live' ),
		'links'     => array( __( 'Link candidates', 'signal-and-noise-tools' ), 'live' ),
		'analytics' => array( __( 'Topic-level analytics', 'signal-and-noise-tools' ), 'planned' ),
		'cadence'   => array( __( 'Ops cadence flags', 'signal-and-noise-tools' ), 'planned' ),
		'search'    => array( __( 'Ranked search', 'signal-and-noise-tools' ), 'live' ), // v10.20.1: flipped with theme v11.2.0's ⌘K ranked palette.
		'verdicts'  => array( __( 'Provenance verdicts', 'signal-and-noise-tools' ), 'never' ),
		'readers'   => array( __( 'Reader profiling', 'signal-and-noise-tools' ), 'never' ),
		'browser'   => array( __( 'Models in the reader\'s browser', 'signal-and-noise-tools' ), 'never' ),
	);
	return apply_filters( 'sn_ml_maturity_scope', $scope );
}

/**
 * The honesty principles: eight, each verifiable against shipped behavior.
 * Raw strings; escaped at the point of build.
 * @return string[]
 */
function sn_ml_maturity_principles() {
	return array(
		__( 'The kernel computes, the AI drafts, a person decides - three jobs that never trade places.', 'signal-and-noise-tools' ),
		__( 'Deterministic by construction: the same corpus always yields the same ranking, and any score can be recomputed by hand and checked.', 'signal-and-noise-tools' ),
		__( 'It models the writing, never the audience: the corpus is the site\'s own published work, and reader data never enters it.', 'signal-and-noise-tools' ),
		__( 'Every output is a candidate with a score, never an action. Nothing auto-writes.', 'signal-and-noise-tools' ),
		__( 'Readers download finished pages, not models: everything is precomputed server-side, and nothing model-shaped ships to a browser.', 'signal-and-noise-tools' ),
		__( 'Statistics never verify: provenance verdicts belong to cryptography alone, and no heuristic participates in one.', 'signal-and-noise-tools' ),
		__( 'Empty is an answer: a scan that finds nothing says so, and a model that has not been built yet reports not-built - never zero.', 'signal-and-noise-tools' ),
		__( 'Small enough to explain: every step is arithmetic a reader could follow, which is the point - intelligence the site can vouch for line by line.', 'signal-and-noise-tools' ),
	);
}

/** The intro section (full format only). @return string Escaped HTML. */
function sn_ml_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How this site computes about its own writing', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Six layers, from a corpus of the site\'s own notes to a person deciding. A deterministic kernel ranks and suggests, the AI tier drafts prose around what it ranks, and nothing either tier produces changes the site without a human decision.', 'signal-and-noise-tools' ) . '</p>';
}

/** The layer table. @return string Escaped HTML. */
function sn_ml_maturity_table_html() {
	$out = '<table class="sn-ml-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_ml_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-ml-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** The principles section (heading + list). @return string Escaped HTML. */
function sn_ml_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-ml-maturity-principles">';
	foreach ( sn_ml_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/**
 * The coverage section: one badge per consumer, status-classed. A status
 * outside the whitelist renders as 'planned' — filter output never reaches
 * the class attribute raw. @return string Escaped HTML.
 */
function sn_ml_maturity_scope_html() {
	$labels = array(
		'live'    => __( 'live', 'signal-and-noise-tools' ),
		'planned' => __( 'planned', 'signal-and-noise-tools' ),
		'never'   => __( 'never', 'signal-and-noise-tools' ),
	);
	$out = '<h3>' . esc_html__( 'Where the kernel is allowed', 'signal-and-noise-tools' ) . '</h3><div class="sn-ml-maturity-scope">';
	foreach ( sn_ml_maturity_scope() as $slug => $s ) {
		$status = ( isset( $s[1] ) && in_array( $s[1], SN_ML_MATURITY_STATUSES, true ) ) ? $s[1] : 'planned';
		$out   .= '<span class="sn-ml-maturity-scope-badge sn-ml-maturity-scope-badge--' . esc_attr( $status ) . '"><strong>' . esc_html( isset( $s[0] ) ? $s[0] : $slug ) . '</strong> ' . esc_html( $labels[ $status ] ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * The compact strip: one sentence + a badge per layer.
 * @return string Escaped HTML.
 */
function sn_ml_maturity_compact_html() {
	$out = '<p class="sn-ml-maturity-compact-intro">' . esc_html__( 'A deterministic kernel, built only from this site\'s own notes, ranks and suggests; the AI drafts around it; a person decides. No model ever ships to a reader.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-ml-maturity-strip">';
	foreach ( sn_ml_maturity_layers() as $slug => $l ) {
		$out .= '<span class="sn-ml-maturity-badge sn-ml-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $l[0] ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * Enqueue the front-end stylesheet. Called from the shortcode callback only,
 * so the CSS ships exactly when the explainer renders.
 */
function sn_ml_maturity_enqueue() {
	wp_enqueue_style(
		'sn-ml-maturity-front',
		plugins_url( 'assets/ml-maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_ml_maturity format="full|table|principles|scope|compact"] — the
 * explainer. Returns (never echoes) per the shortcode contract; safe for a
 * public page (static content only). Unknown formats fall back to 'full'.
 *
 * @param array|string $atts Shortcode attributes (core passes '' when bare).
 * @return string
 */
function sn_ml_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_ml_maturity' );
	$format = in_array( $atts['format'], SN_ML_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_ml_maturity_enqueue();
	$out = '<div class="sn-ml-maturity sn-ml-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_ml_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_ml_maturity_principles_html();
	} elseif ( 'scope' === $format ) {
		$out .= sn_ml_maturity_scope_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_ml_maturity_compact_html();
	} else {
		$out .= sn_ml_maturity_intro_html() . sn_ml_maturity_table_html() . sn_ml_maturity_principles_html() . sn_ml_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_ml_maturity', 'sn_ml_maturity_shortcode' );
