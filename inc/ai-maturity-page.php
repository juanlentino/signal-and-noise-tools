<?php
/**
 * Signal & Noise — the AI-maturity explainer ([sn_ai_maturity]).
 * The "how AI participates here" public case-study: the six-layer walk (spec →
 * generate → check → review → mark → bound), the honesty principles, and a
 * coverage map — the third sibling of inc/analytics-maturity-page.php and
 * inc/provenance-maturity-page.php: same format whitelist idiom (full | table |
 * principles | scope | compact), same render-time front-end stylesheet
 * (assets/ai-maturity-front.css, enqueued only when the shortcode renders),
 * STATIC by design — no live counts, no per-reader data.
 *
 * SECURITY CONTRACT (test-enforced in tests/ai-maturity-page.php): this page
 * describes the MODEL, never the LEVERS. No option names, no wp-config
 * constants, no endpoint paths, no tool slugs, no meta keys, no throttle
 * numbers, no allowlist counts appear in any rendered format — the fixture
 * asserts their absence, so a future edit that leaks one reds CI.
 *
 * One deliberate divergence from the siblings: the scope-status whitelist
 * gains 'never' (live | planned | never), because "Note bodies — never" is a
 * commitment, not a roadmap gap, and the page should be able to say so.
 *
 * @package SignalNoiseTools @since 10.10.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The format whitelist. Unknown values fall back to 'full' — pinned. */
const SN_AI_MATURITY_FORMATS = array( 'full', 'table', 'principles', 'scope', 'compact' );

/** Scope-status whitelist; unknown statuses render as 'planned', never raw. */
const SN_AI_MATURITY_STATUSES = array( 'live', 'planned', 'never' );

/**
 * The layer rows: slug → [label, question, engine], in walk order. Every
 * engine claim is verifiable against shipped behavior; none names a lever.
 * @return array<string,array{0:string,1:string,2:string}>
 */
function sn_ai_maturity_layers() {
	return array(
		'spec'     => array( __( 'Spec', 'signal-and-noise-tools' ), __( 'What is the voice?', 'signal-and-noise-tools' ), __( 'A written register spec every AI surface must satisfy: the excerpt reads as the opening of the argument, never a summary about it; a banned list of machine tells; per-surface length and form rules, anchored by a verbatim example of the target register', 'signal-and-noise-tools' ) ),
		'generate' => array( __( 'Generate', 'signal-and-noise-tools' ), __( 'Who drafts?', 'signal-and-noise-tools' ), __( 'Models draft the surfaces around the work, from instructions that carry the spec itself - so the register is encoded where the words are made, not corrected after the fact', 'signal-and-noise-tools' ) ),
		'check'    => array( __( 'Check', 'signal-and-noise-tools' ), __( 'What catches drift?', 'signal-and-noise-tools' ), __( 'A mechanical pass over every draft: lengths, sentence caps, banned phrases, repeated openers. A draft that fails form never spends a person\'s attention', 'signal-and-noise-tools' ) ),
		'review'   => array( __( 'Review', 'signal-and-noise-tools' ), __( 'Who decides?', 'signal-and-noise-tools' ), __( 'A person reads the drafts and decides. Approved text is applied verbatim through a write path that records a revision, and anything over a limit is rejected whole rather than trimmed to fit', 'signal-and-noise-tools' ) ),
		'mark'     => array( __( 'Mark', 'signal-and-noise-tools' ), __( 'What tracks the unreviewed?', 'signal-and-noise-tools' ), __( 'Text generated automatically at publish carries an internal unreviewed mark until a person edits or approves it - "no human has read this yet" is a recorded state, never a guess', 'signal-and-noise-tools' ) ),
		'bound'    => array( __( 'Bound', 'signal-and-noise-tools' ), __( 'What can an agent touch?', 'signal-and-noise-tools' ), __( 'AI agents work the site over the Model Context Protocol through two isolated doors, one read-only and one write, each behind a curated allowlist, an independent kill switch, an audit trail, and rate limits. Neither door serves an unauthenticated caller - both refuse the call before any work is done, and neither is advertised among the site\'s public interfaces. Destructive operations are excluded by construction', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The coverage map: slug → [label, status], statuses whitelisted
 * live|planned|never. THE EXPANSION SEAM: future AI surfaces flip a status
 * through the `sn_ai_maturity_scope` filter instead of re-coding markup.
 * 'never' is a commitment and should only ever move by deliberate owner edit.
 * @return array<string,array{0:string,1:string}>
 */
function sn_ai_maturity_scope() {
	$scope = array(
		'metadata'   => array( __( 'Excerpts & SEO surfaces', 'signal-and-noise-tools' ), 'live' ),
		'alt'        => array( __( 'Image alt text', 'signal-and-noise-tools' ), 'live' ),
		'links'      => array( __( 'Internal link suggestions', 'signal-and-noise-tools' ), 'live' ),
		'tags'       => array( __( 'Tag suggestions', 'signal-and-noise-tools' ), 'live' ),
		// v10.54.0's sentence_replace: an AI may PROPOSE a sentence-scale
		// edit, staged as a revision behind four server-side gates; only a
		// person's acceptance makes it live. The drafting commitment below
		// is untouched — proposing a bounded edit to existing prose is not
		// drafting the work.
		'body_edits' => array( __( 'Sentence-level edit proposals (staged, human-accepted)', 'signal-and-noise-tools' ), 'live' ),
		'ranking'    => array( __( 'Relevance ranking & similarity', 'signal-and-noise-tools' ), 'never' ),
		'bodies'     => array( __( 'Note bodies (drafting them)', 'signal-and-noise-tools' ), 'never' ),
	);
	return apply_filters( 'sn_ai_maturity_scope', $scope );
}

/**
 * The honesty principles: eight, each verifiable against shipped behavior.
 * Raw strings; escaped at the point of build.
 * @return string[]
 */
function sn_ai_maturity_principles() {
	return array(
		__( 'AI drafts the surfaces around the work, never the work itself: it may propose a sentence-level edit, but nothing reaches a published body except through a staged revision a person accepts.', 'signal-and-noise-tools' ),
		__( 'A person decides. Automatic text is never passed off as reviewed - it carries an unreviewed mark until a human touches it.', 'signal-and-noise-tools' ),
		__( 'Voice is enforced by a written spec and a mechanical check, not by taste on the day.', 'signal-and-noise-tools' ),
		__( 'A blocklist alone just relocates the machine tell, so the spec encodes the wanted behavior first and bans second.', 'signal-and-noise-tools' ),
		__( 'Reviewed text is never silently altered: anything over a limit is rejected whole, never trimmed to fit.', 'signal-and-noise-tools' ),
		__( 'Agents get the smallest door that does the job: reads and writes are split, and every write is bounded and audited, with a revision to walk back.', 'signal-and-noise-tools' ),
		__( 'Either door can be darkened instantly, server-side, and no agent credential can disable its own leash.', 'signal-and-noise-tools' ),
		__( 'What AI may not touch is a decision, not an accident: destructive operations and site-structure levers sit outside agent reach by construction.', 'signal-and-noise-tools' ),
		// 2026-08-14: arrived here by GRADUATION, not by authoring. This was a
		// done row on the hub roadmap board; the done column has a ceiling that
		// forces the oldest shipped rows onto their family page, and this claim
		// was the one that could make the move without being lost — a written
		// document survives the board, whereas a mechanism with no document to
		// point at would have simply vanished with its row. Stated as a
		// standing commitment rather than a past event, because the model is
		// re-argued for each new surface rather than written once.
		//
		// Bound by this file's SECURITY CONTRACT like every other principle:
		// it names the PRACTICE, never a gate, a path, or a residual — the
		// threat model itself is not a public document, and a principle that
		// enumerated what it found would defeat the point of having one.
		__( 'No agent surface ships unargued: each is walked against a written threat model first - what a hostile paragraph could reach, gate by gate - and the risks that remain are ranked and carried forward by name rather than closed by assertion.', 'signal-and-noise-tools' ),
	);
}

/** The intro section (full format only). @return string Escaped HTML. */
function sn_ai_maturity_intro_html() {
	return '<h2>' . esc_html__( 'How AI participates in making this site', 'signal-and-noise-tools' ) . '</h2>'
		. '<p>' . esc_html__( 'Six layers, from a written voice spec to bounded agent access. Generators draft the surfaces around the work, a mechanical check and a human decision settle what stands, and agents reach the site only through doors built to be narrow.', 'signal-and-noise-tools' ) . '</p>';
}

/** The layer table. @return string Escaped HTML. */
function sn_ai_maturity_table_html() {
	$out = '<table class="sn-ai-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_ai_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-ai-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/** The principles section (heading + list). @return string Escaped HTML. */
function sn_ai_maturity_principles_html() {
	$out = '<h3>' . esc_html__( 'Honest by construction', 'signal-and-noise-tools' ) . '</h3><ul class="sn-ai-maturity-principles">';
	foreach ( sn_ai_maturity_principles() as $p ) {
		$out .= '<li>' . esc_html( $p ) . '</li>';
	}
	return $out . '</ul>';
}

/**
 * The coverage section: one badge per surface, status-classed. A status
 * outside the whitelist renders as 'planned' — filter output never reaches
 * the class attribute raw. @return string Escaped HTML.
 */
function sn_ai_maturity_scope_html() {
	$labels = array(
		'live'    => __( 'live', 'signal-and-noise-tools' ),
		'planned' => __( 'planned', 'signal-and-noise-tools' ),
		'never'   => __( 'never', 'signal-and-noise-tools' ),
	);
	$out = '<h3>' . esc_html__( 'Where AI is allowed', 'signal-and-noise-tools' ) . '</h3><div class="sn-ai-maturity-scope">';
	foreach ( sn_ai_maturity_scope() as $slug => $s ) {
		$status = ( isset( $s[1] ) && in_array( $s[1], SN_AI_MATURITY_STATUSES, true ) ) ? $s[1] : 'planned';
		$out   .= '<span class="sn-ai-maturity-scope-badge sn-ai-maturity-scope-badge--' . esc_attr( $status ) . '"><strong>' . esc_html( isset( $s[0] ) ? $s[0] : $slug ) . '</strong> ' . esc_html( $labels[ $status ] ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * The compact strip: one sentence + a badge per layer.
 * @return string Escaped HTML.
 */
function sn_ai_maturity_compact_html() {
	$out = '<p class="sn-ai-maturity-compact-intro">' . esc_html__( 'Every AI-drafted surface must satisfy a written voice spec and a mechanical check, and a person decides whether it stands. The work itself stays human.', 'signal-and-noise-tools' ) . '</p>'
		. '<div class="sn-ai-maturity-strip">';
	foreach ( sn_ai_maturity_layers() as $slug => $l ) {
		$out .= '<span class="sn-ai-maturity-badge sn-ai-maturity-badge--' . esc_attr( $slug ) . '">' . esc_html( $l[0] ) . '</span>';
	}
	return $out . '</div>';
}

/**
 * Enqueue the front-end stylesheet. Called from the shortcode callback only,
 * so the CSS ships exactly when the explainer renders.
 */
function sn_ai_maturity_enqueue() {
	wp_enqueue_style(
		'sn-ai-maturity-front',
		plugins_url( 'assets/ai-maturity-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_ai_maturity format="full|table|principles|scope|compact"] — the
 * explainer. Returns (never echoes) per the shortcode contract; safe for a
 * public page (static content only). Unknown formats fall back to 'full'.
 *
 * @param array|string $atts Shortcode attributes (core passes '' when bare).
 * @return string
 */
function sn_ai_maturity_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'format' => 'full' ), $atts, 'sn_ai_maturity' );
	$format = in_array( $atts['format'], SN_AI_MATURITY_FORMATS, true ) ? $atts['format'] : 'full';
	sn_ai_maturity_enqueue();
	$out = '<div class="sn-ai-maturity sn-ai-maturity--' . esc_attr( $format ) . '">';
	if ( 'table' === $format ) {
		$out .= sn_ai_maturity_table_html();
	} elseif ( 'principles' === $format ) {
		$out .= sn_ai_maturity_principles_html();
	} elseif ( 'scope' === $format ) {
		$out .= sn_ai_maturity_scope_html();
	} elseif ( 'compact' === $format ) {
		$out .= sn_ai_maturity_compact_html();
	} else {
		$out .= sn_ai_maturity_intro_html() . sn_ai_maturity_table_html() . sn_ai_maturity_principles_html() . sn_ai_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_ai_maturity', 'sn_ai_maturity_shortcode' );
