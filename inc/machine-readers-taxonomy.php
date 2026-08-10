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
		'seo', 'feed', 'social', 'security', 'dev', 'unknown',
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
		'purpose'                => in_array( $purpose, $purposes, true ) ? $purpose : 'unknown',
		'taxonomy_version'       => substr( preg_replace( '/[^0-9.]/', '', $version ), 0, 12 ),
		'training_corpus_source' => '1' === (string) ( $row['training_corpus_source'] ?? '' ),
		'first_party'            => '1' === (string) ( $row['first_party'] ?? '' ),
		'ua_sample'              => snt_mr_normalize_ua_sample( $row['user_agent'] ?? ( $row['ua_sample'] ?? null ) ),
	);
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
