<?php
/**
 * The vendor/purpose axes of the machine-readership sensor (v10.79.0).
 *
 * The Worker's `sn-rights-signals` v1.11.0 taxonomy adds two independent
 * dimensions beside the existing `family` field. This file mirrors the closed
 * halves of that contract and normalizes the open half.
 *
 * RULE 1, load-bearing: `family` is FROZEN. Nothing here changes what an
 * existing family value means or which requests it counts. A published number
 * (77 AI-training reads, 30d to 31 July 2026) depends on the old definition.
 * The only addition to the family enum is `unclassified-machine`, which carries
 * rows the Worker's frozen classifier would have DROPPED ENTIRELY — so no
 * existing value's population moves, and any query filtering the original 18
 * families returns the same rows it always did.
 *
 * @package Signal_and_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The closed purpose vocabulary, mirrored from machine-reader-taxonomy.json.
 * Extend BOTH or neither — the same rule the family and surface enums carry,
 * and tests/machine-readers-docs.php fails when code and docs drift.
 *
 * @return string[]
 */
function snt_mr_valid_purposes() {
	return array(
		'train', 'search', 'retrieval', 'user', 'archive', 'ops',
		'seo', 'feed', 'social', 'security', 'dev', 'ads', 'unknown',
	);
}

/**
 * Purposes that constitute AI consumption of the content, for the observed vs
 * declared read. Deliberately NOT the same question as the frozen
 * snt_mr_ai_training_families(): that one asks "which crawler families does the
 * operator publicly class as AI-training", this one asks "what was this read
 * for". `train` alone is the training claim; `retrieval` is AI use that is not
 * corpus collection and must not be added to it.
 *
 * @return string[]
 */
function snt_mr_ai_purposes() {
	return array( 'train', 'retrieval' );
}

/**
 * Vendor is an OPEN field — new organisations appear without a plugin release,
 * so it cannot be an allowlist without silently discarding real data. It is
 * therefore the one attacker-influenced string on this surface, and is
 * constrained by SHAPE instead: lowercase alphanumerics, dot and hyphen, capped
 * at 32 characters. Nothing that survives can carry markup, quotes, whitespace
 * or control bytes, so it cannot become an injection even before the render
 * lane escapes it, which it also does.
 *
 * @param mixed $vendor Raw value from the sensor response.
 * @return string Safe vendor slug, or '' when nothing survives.
 */
function snt_mr_normalize_vendor( $vendor ) {
	if ( ! is_string( $vendor ) ) {
		return '';
	}
	return substr( preg_replace( '/[^a-z0-9.-]/', '', strtolower( $vendor ) ), 0, 32 );
}

/**
 * Second sanitisation pass on a sampled unknown user-agent (RULE 2).
 *
 * The Worker already applies a strict allowlist before storing. This repeats it
 * rather than trusting it: the plugin treats its own Worker as untrusted input
 * everywhere else on this path (see the SSRF gate and the enum coercion in
 * snt_mr_normalize_rows), and a sampled UA is the single highest-value string
 * on the surface for anyone trying to reach an admin page. Belt and braces on
 * purpose — this is the one field where the v1.4.0 "safe by construction"
 * property no longer applies.
 *
 * @param mixed $ua Raw value from the sensor response.
 * @return string Safe to render (still escaped at output), or ''.
 */
function snt_mr_normalize_ua_sample( $ua ) {
	if ( ! is_string( $ua ) ) {
		return '';
	}
	$clean = preg_replace( '/[^A-Za-z0-9._\/+ -]/', ' ', $ua );
	$clean = trim( preg_replace( '/\s+/', ' ', $clean ) );
	return substr( $clean, 0, 96 );
}

/**
 * Normalize the Web Bot Auth signature state to a bounded vocabulary.
 *
 * NOT-MEASURED IS NOT UNSIGNED, and this is the whole point of the function.
 * Rows written before Worker v1.19.0 carry no value, and an older Worker sends
 * no column at all. Folding either into 'unsigned' would inflate the
 * did-not-sign population with every historical row and make adoption read
 * worse than it is — the same class of error as counting a never-measured zero
 * as a measured one.
 *
 * An unrecognized value reads as 'other' rather than being dressed up as a
 * state this plugin knows. If the Worker grows a fifth state, this surfaces
 * that it did instead of silently mislabelling it.
 *
 * @since 12.24.0
 * @param mixed $value Raw column value from the Worker's aggregate read.
 * @return string One of: unmeasured, unsigned, valid, invalid, unknown-key, other.
 */
function snt_mr_normalize_signed_agent( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( '' === $value ) {
		return 'unmeasured';
	}
	// The accepted set includes this function's OWN outputs, which makes it
	// idempotent. snt_mr_fetch() normalizes before returning, so any caller
	// that normalizes a fetched row runs the value through twice; without
	// 'unmeasured' and 'other' here, the second pass turned 'unmeasured' into
	// 'other' and reported history as an unrecognized state. A corruption that
	// yields a plausible value rather than an error is the worst kind.
	return in_array( $value, array( 'unsigned', 'valid', 'invalid', 'unknown-key', 'unmeasured', 'other' ), true )
		? $value
		: 'other';
}

/**
 * Normalize the additive taxonomy fields on one sensor row.
 *
 * Same fail-into-the-enum discipline as the original normalizer: an
 * unrecognized purpose becomes 'unknown' rather than reaching the page, and the
 * booleans are read as the Worker writes them ('1'/'0' strings) without
 * treating an absent field as false-by-accident — a missing field means an
 * older Worker, which is a different answer from a measured zero.
 *
 * @param array $row Raw row from the sensor.
 * @return array{vendor:string,purpose:string,taxonomy_version:string,training_corpus_source:bool,first_party:bool,ua_sample:string}
 */
function snt_mr_normalize_taxonomy_fields( $row ) {
	$purposes = snt_mr_valid_purposes();
	$purpose  = is_string( $row['purpose'] ?? null ) ? $row['purpose'] : '';
	$version  = is_string( $row['taxonomy_version'] ?? null ) ? $row['taxonomy_version'] : '';

	return array(
		'vendor'                 => snt_mr_normalize_vendor( $row['vendor'] ?? null ),
		// v10.80.0: the taxonomy entry id, so the page can name the exact agent
		// (openai-gptbot) instead of leaving the reader to infer it from
		// vendor+purpose. Same shape constraint as vendor: it is an open field.
		'agent'                  => substr( preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) ( $row['agent'] ?? '' ) ) ), 0, 48 ),
		'purpose'                => in_array( $purpose, $purposes, true ) ? $purpose : 'unknown',
		'taxonomy_version'       => substr( preg_replace( '/[^0-9.]/', '', $version ), 0, 12 ),
		'training_corpus_source' => '1' === (string) ( $row['training_corpus_source'] ?? '' ),
		'first_party'            => '1' === (string) ( $row['first_party'] ?? '' ),
		'ua_sample'              => snt_mr_normalize_ua_sample( $row['user_agent'] ?? ( $row['ua_sample'] ?? null ) ),
		// v12.16.0: did this reader ask for markdown? Worker v1.18.0's blob10.
		// Same additive contract as the two booleans above — an older Worker
		// sends no such column, the value lands on false, and the readout
		// degrades to "nobody asked" rather than erroring.
		'markdown_requested'     => '1' === (string) ( $row['markdown_requested'] ?? '' ),
		// v12.24.0: did this reader PROVE who it is? Worker v1.19.0's blob11,
		// exposed by v1.20.0's read query. Four states, not a boolean —
		// 'invalid' and 'unknown-key' are the populations that would matter
		// first if verification ever became a gate, and a boolean erases both.
		'signed_agent'           => snt_mr_normalize_signed_agent( $row['signed_agent'] ?? null ),
	);
}

/**
 * Normalize rows from the RULE 3 rights-detail view.
 *
 * A different shape from the aggregate, so it needs its own normalizer:
 * snt_mr_normalize_rows() builds a fixed family/surface/day/hits array and
 * would silently discard path, user_agent and observed_at.
 *
 * This is the ONE place where a full, un-allowlisted User-Agent reaches
 * WordPress. It is stripped of control characters and hard-capped here, and the
 * renderer escapes every field at the sink. Path is capped too: the rights
 * surfaces are a closed set of short fixed URLs, so a long path is a malformed
 * row rather than a real one.
 *
 * @param mixed $data Decoded `data` member.
 * @return array<int,array{observed_at:string,path:string,user_agent:string,accept:string,vendor:string,purpose:string,family:string,hits:int}>
 */
function snt_mr_normalize_rights_rows( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}
	$families = snt_mr_valid_families();
	$purposes = snt_mr_valid_purposes();
	$clip     = static function ( $v, $cap ) {
		return substr( preg_replace( '/[\x00-\x1f\x7f]/', ' ', (string) $v ), 0, $cap );
	};

	$rows = array();
	foreach ( $data as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$family  = is_string( $row['family'] ?? null ) ? $row['family'] : '';
		$purpose = is_string( $row['purpose'] ?? null ) ? $row['purpose'] : '';
		$rows[]  = array(
			// ISO-8601 shape only; anything else becomes '' rather than reaching the page.
			'observed_at' => $clip( preg_replace( '/[^0-9TZ:.\-]/', '', (string) ( $row['observed_at'] ?? '' ) ), 32 ),
			'path'        => $clip( $row['path'] ?? '', 128 ),
			'user_agent'  => $clip( $row['user_agent'] ?? '', 512 ),
			'accept'      => $clip( $row['accept'] ?? '', 256 ),
			'vendor'      => snt_mr_normalize_vendor( $row['vendor'] ?? null ),
			'purpose'     => in_array( $purpose, $purposes, true ) ? $purpose : 'unknown',
			'family'      => in_array( $family, $families, true ) ? $family : 'other-bot',
			'hits'        => is_numeric( $row['hits'] ?? null ) ? max( 0, (int) $row['hits'] ) : 0,
		);
	}
	return $rows;
}

/**
 * Does this response predate the taxonomy? Distinguishes "an older Worker is
 * deployed" from "every read this window happened to be unclassified" — the
 * realtime-zero-vs-null distinction, which `??` would quietly collapse.
 *
 * @param array $rows Normalized rows.
 * @return bool True when NO row carries a taxonomy version.
 */
function snt_mr_taxonomy_absent( $rows ) {
	foreach ( (array) $rows as $r ) {
		if ( '' !== (string) ( $r['taxonomy_version'] ?? '' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The four `signed_agent` states that constitute a MEASUREMENT.
 *
 * Named once, here, beside the normalizer that produces them. 'unmeasured' and
 * 'other' are deliberately absent: the first is silence and the second is a
 * state this plugin does not know, and neither is evidence about an agent.
 * A second copy of this list elsewhere is how the two folds drift apart.
 *
 * @since 13.43.0
 * @return array<int,string>
 */
function snt_mr_measured_signed_states() {
	return array( 'valid', 'invalid', 'unknown-key', 'unsigned' );
}

/**
 * Fold `signed_agent` against the dimensions it shares a row with.
 *
 * WHY. The sensor has carried `signed_agent` beside `agent`, `surface`,
 * `family` and `day` since Worker v1.20.0, but every read surface projected it
 * away: the admin KPI collapses it to `valid / measured`, and the summary
 * ability did not carry it at all. On 2026-08-30 the full-week reading was
 * 311 / 13,238 and NOTHING could say whether that was one agent or fifteen —
 * the question that decides whether a licence handshake is an ecosystem surface
 * or a bilateral arrangement. The data was never missing; the fold was.
 *
 * `by_surface` exists for a second reason. Worker v1.22.0 began serving
 * /webmcp/bridge.js on every HTML page on 2026-08-28, mid-measurement. Verified
 * hits landing on `agent-discovery` are the same agents fetching a NEW endpoint,
 * which is not the finding "more agents adopted signatures" — and a scalar
 * cannot tell those apart.
 *
 * NULL, NOT ZEROS. An all-unmeasured window returns null. Returning a zeroed
 * block would assert a measurement that was never taken, and no consumer could
 * separate it from a measured zero — the same false-zero the identity KPI has
 * guarded since v12.26.0. Measured-with-none-verified is the DIFFERENT answer,
 * and it returns a real block whose leaderboards are empty arrays.
 *
 * Reads `signed_agent` as it stands on the row. snt_mr_fetch() has already
 * normalized it; re-normalizing here would be the v12.24.1 double-normalize bug.
 *
 * @since 13.43.0
 * @param array $rows Normalized aggregate rows.
 * @return array{measured:int,valid:int,invalid:int,unknown_key:int,unsigned:int,by_agent:array,by_surface:array}|null
 */
function snt_mr_identity_breakdown( $rows ) {
	$states = snt_mr_measured_signed_states();
	$out    = array(
		'measured'    => 0,
		'valid'       => 0,
		'invalid'     => 0,
		'unknown_key' => 0,
		'unsigned'    => 0,
		'by_agent'    => array(),
		'by_surface'  => array(),
	);
	$agents   = array();
	$surfaces = array();

	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$state = (string) ( $row['signed_agent'] ?? '' );
		if ( ! in_array( $state, $states, true ) ) {
			continue;
		}
		$hits              = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$out['measured']  += $hits;
		// The payload key drops the hyphen the wire format uses; every other
		// state is already a legal identifier.
		$key         = 'unknown-key' === $state ? 'unknown_key' : $state;
		$out[ $key ] += $hits;

		// ONLY verified hits reach the leaderboards. An agent that FAILED
		// verification listed beside one that passed would read as proof of the
		// opposite of what it is.
		if ( 'valid' !== $state ) {
			continue;
		}
		$agent   = (string) ( $row['agent'] ?? '' );
		$surface = (string) ( $row['surface'] ?? '' );
		$agent   = '' === $agent ? '(unnamed)' : $agent;
		$surface = '' === $surface ? '(unnamed)' : $surface;

		$agents[ $agent ]     = ( $agents[ $agent ] ?? 0 ) + $hits;
		$surfaces[ $surface ] = ( $surfaces[ $surface ] ?? 0 ) + $hits;
	}

	if ( 0 === $out['measured'] ) {
		return null;
	}

	arsort( $agents );
	arsort( $surfaces );
	foreach ( $agents as $name => $hits ) {
		$out['by_agent'][] = array(
			'agent' => (string) $name,
			'hits'  => (int) $hits,
		);
	}
	foreach ( $surfaces as $name => $hits ) {
		$out['by_surface'][] = array(
			'surface' => (string) $name,
			'hits'    => (int) $hits,
		);
	}
	return $out;
}
