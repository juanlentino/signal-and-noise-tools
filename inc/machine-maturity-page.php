<?php
/**
 * Signal & Noise — the machine-readability explainer ([sn_machine_maturity]).
 * Fourth maturity sibling: how MACHINES read this site — the crawler manifest,
 * the rights terms, structured data, feeds, provenance-stamped artifacts, and
 * bounded agent access, all at the design level.
 *
 * LAYER ORDER (v10.71.0, the 'reserved' layer): the walk is ordered by WHEN IN
 * THE ENCOUNTER a machine meets the layer, not by how weighty the layer is.
 * The reservation is an HTTP response header, so it arrives with the first
 * byte of the first response, and the content signal rides the crawler
 * manifest — conventionally a crawler's first fetch. A machine therefore meets
 * the terms at discovery time, before it has parsed anything, which puts
 * 'reserved' at position 2, directly after 'indexed'. Appending it last would
 * have seated it beside 'agents' and implied the terms bind agents only —
 * the opposite of a reservation that rides every response.
 *
 * Same idioms as inc/ai-maturity-page.php:
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
		'reserved'   => array( __( 'Reserved', 'signal-and-noise-tools' ), __( 'Can machines know the terms?', 'signal-and-noise-tools' ), __( 'A machine-readable reservation rides every response and the crawler manifest carries the same signal, both pointing at one versioned policy that states the conditions in plain language, as a licence file, and as linked data for a machine that asks. The site counts who reads them, and so far no declared AI-training crawler has', 'signal-and-noise-tools' ) ),
		'structured' => array( __( 'Structured', 'signal-and-noise-tools' ), __( 'Can machines parse it?', 'signal-and-noise-tools' ), __( 'Structured data rides every meaningful page - articles, the music catalog, route metadata - so a machine parses what the page says instead of guessing at it', 'signal-and-noise-tools' ) ),
		'summarized' => array( __( 'Summarized', 'signal-and-noise-tools' ), __( 'Can machines answer from it?', 'signal-and-noise-tools' ), __( 'The manifest carries the site\'s own machine-readable summary and its recent notes, so an answer engine can quote this site\'s framing rather than reconstruct it', 'signal-and-noise-tools' ) ),
		'stamped'    => array( __( 'Stamped', 'signal-and-noise-tools' ), __( 'Can machines trust what they took?', 'signal-and-noise-tools' ), __( 'Artifacts that leave the site keep a way home: share-card images carry an embedded provenance stamp, and notes carry signed records any reader can verify', 'signal-and-noise-tools' ) ),
		'agents'     => array( __( 'Agent-readable', 'signal-and-noise-tools' ), __( 'Can agents work with it?', 'signal-and-noise-tools' ), __( 'AI agents get a dedicated, allowlisted interface to the site\'s own operational picture - a door rather than a scrape, bounded the same way the AI page describes', 'signal-and-noise-tools' ) ),
	);
}

/** @return array<string,array{0:string,1:string}> */
function sn_machine_maturity_scope() {
	$scope = array(
		'manifest'    => array( __( 'AI-crawler manifest', 'signal-and-noise-tools' ), 'live' ),
		'behaviour'   => array( __( 'Behavioural deviation detection', 'signal-and-noise-tools' ), 'live' ),
		'reservation' => array( __( 'TDM reservation', 'signal-and-noise-tools' ), 'live' ),
		'signal'      => array( __( 'Content signal', 'signal-and-noise-tools' ), 'live' ),
		'licence'     => array( __( 'Licence file', 'signal-and-noise-tools' ), 'live' ),
		'policy'      => array( __( 'Rights policy', 'signal-and-noise-tools' ), 'live' ),
		// v10.71.2: the policy's machine representation declares a locally
		// defined `sn:` prefix and uses ten terms from it. Those terms resolve
		// to published definitions at /ns/tdm — which is a coverage row of its
		// own, not an implementation detail of the row above it: a vocabulary
		// that does not resolve is the difference between linked data and a
		// URI that merely looks like one.
		'vocabulary'  => array( __( 'Terms vocabulary', 'signal-and-noise-tools' ), 'live' ),
		'schema'      => array( __( 'Structured data', 'signal-and-noise-tools' ), 'live' ),
		'feeds'       => array( __( 'Feeds', 'signal-and-noise-tools' ), 'live' ),
		'cards'       => array( __( 'Stamped share cards', 'signal-and-noise-tools' ), 'live' ),
		'agents'      => array( __( 'Agent door', 'signal-and-noise-tools' ), 'live' ),
		// v11.27.0: identity discovery. The signing identity already answers at
		// /.well-known/did.json; WebFinger (RFC 7033) is a second standard way to
		// ASK for it, resolving to the same did:web document and the same Ed25519
		// key. Its own row because coherence is the claim — one entity findable
		// several ways, all agreeing — not an implementation detail of the DID.
		// NodeInfo, WebFinger's usual companion, is deliberately absent: its schema
		// requires at least one federation protocol and this site speaks none.
		'identity'    => array( __( 'Identity discovery', 'signal-and-noise-tools' ), 'live' ),
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
		. '<p>' . esc_html__( 'Six layers, from being findable to being workable. Crawlers get a manifest written in the site\'s own words, every response states the terms it is served under, parsers get structured data, answer engines get a summary to quote, anything that leaves carries its origin, and agents get a bounded door instead of a scrape.', 'signal-and-noise-tools' ) . '</p>';
}

/** @return string */
function sn_machine_maturity_table_html() {
	$out = '<table class="sn-machine-maturity-table"><thead><tr><th>' . esc_html__( 'Layer', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Question', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Engine', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( sn_machine_maturity_layers() as $slug => $l ) {
		$out .= '<tr class="sn-machine-maturity-row--' . esc_attr( $slug ) . '"><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
	}
	return $out . '</tbody></table>';
}

/**
 * The rights-read count (R3 3B). The one non-static element on this page.
 *
 * Reads the durable snapshot and nothing else — no sensor call, no transient,
 * no query. If the snapshot module is not loaded (partial deploy), this section
 * is omitted entirely rather than rendered empty or guessed at: a page that
 * silently drops a claim is better than one that invents it.
 *
 * @return string
 */
function sn_machine_maturity_reads_html() {
	if ( ! function_exists( 'snt_mr_snapshot' ) || ! function_exists( 'snt_mr_rights_reads_sentence' ) ) {
		return '';
	}
	$sentence = snt_mr_rights_reads_sentence( snt_mr_snapshot() );
	if ( '' === trim( (string) $sentence ) ) {
		return '';
	}
	return '<h3>' . esc_html__( 'Who reads the terms', 'signal-and-noise-tools' ) . '</h3>'
		. '<p class="sn-machine-maturity-reads">' . esc_html( $sentence ) . '</p>';
}

/**
 * The give-back table (R3 3B). Reads the durable snapshot and nothing else.
 *
 * Rides the `full` format rather than getting a format of its own, deliberately:
 * tests/maturity-family.php sweeps a HARDCODED list of formats for lever leaks,
 * so a new format would be silently unswept.
 *
 * Operators with nothing measurable are shown rather than filtered. A row
 * dropped for having no data reads as "no such crawler" — a stronger claim than
 * the absence it stands in for.
 *
 * @return string
 */
function sn_machine_maturity_giveback_html() {
	if ( ! function_exists( 'snt_mr_snapshot' ) || ! function_exists( 'snt_mr_giveback_table' ) ) {
		return '';
	}
	$snap = snt_mr_snapshot();
	$rows = snt_mr_giveback_table( $snap, snt_mr_snapshot_referrals( $snap ) );
	if ( empty( $rows ) ) {
		return '';
	}
	// Partition before sorting. Rows that ANSWER the question render one each;
	// rows that cannot answer collapse into one sentence per reason. Live, the
	// first render was sixteen rows — three permanent non-answers followed by
	// thirteen identical "has not been measured yet" — every one true, the whole
	// section informationless. The fixtures always supplied referrals, so the
	// shape only ever appeared on the real page.
	$answered = array();
	$groups   = array( 'not_measurable' => array(), 'unmeasured' => array() );
	foreach ( $rows as $row ) {
		if ( isset( $groups[ $row['status'] ] ) ) {
			$groups[ $row['status'] ][] = $row;
			continue;
		}
		$answered[] = $row;
	}

	// Loudest first: read-and-never-repaid, then the ones that did repay, then
	// the ones that did not read at all. Ranking by ratio alone would file the
	// most interesting answer (0.0) beside the ones with no answer.
	$rank = array( 'none_returned' => 0, 'ok' => 1, 'no_crawls' => 2 );
	usort( $answered, function ( $a, $b ) use ( $rank ) {
		$ra = $rank[ $a['status'] ] ?? 9;
		$rb = $rank[ $b['status'] ] ?? 9;
		if ( $ra !== $rb ) {
			return $ra - $rb;
		}
		return (int) ( $b['crawls'] ?? 0 ) - (int) ( $a['crawls'] ?? 0 );
	} );

	$out = '<h3>' . esc_html__( 'Which machines send a reader back', 'signal-and-noise-tools' ) . '</h3>'
		. '<ul class="sn-machine-maturity-giveback">';
	foreach ( $answered as $row ) {
		$out .= '<li class="sn-machine-maturity-giveback__row sn-machine-maturity-giveback__row--' . esc_attr( $row['status'] ) . '">'
			. esc_html( snt_mr_giveback_sentence( $row ) ) . '</li>';
	}
	// The caveats trail the answers, always — a section that opens by explaining
	// what it cannot measure buries what it can.
	foreach ( array( 'unmeasured', 'not_measurable' ) as $status ) {
		if ( empty( $groups[ $status ] ) ) {
			continue;
		}
		$sentence = snt_mr_giveback_group_sentence( $groups[ $status ], $status );
		if ( '' !== $sentence ) {
			$out .= '<li class="sn-machine-maturity-giveback__row sn-machine-maturity-giveback__row--' . esc_attr( $status ) . '">'
				. esc_html( $sentence ) . '</li>';
		}
	}
	return $out . '</ul>';
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
	$out = '<p class="sn-machine-maturity-compact-intro">' . esc_html__( 'Machines get this site in the site\'s own words: a crawler manifest, the terms on every response, structured data, verifiable artifacts, and a bounded agent door.', 'signal-and-noise-tools' ) . '</p>'
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
		$out .= sn_machine_maturity_intro_html() . sn_machine_maturity_table_html() . sn_machine_maturity_reads_html() . sn_machine_maturity_giveback_html() . sn_machine_maturity_principles_html() . sn_machine_maturity_scope_html();
	}
	return $out . '</div>';
}
add_shortcode( 'sn_machine_maturity', 'sn_machine_maturity_shortcode' );
