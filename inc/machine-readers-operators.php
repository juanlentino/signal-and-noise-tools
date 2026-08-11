<?php
/**
 * Signal & Noise Tools — the operator map: one place that says which crawler
 * families and which referrer hosts belong to the same company.
 *
 * This is the NAMED GATE on the give-back ratio, quoted from the board row
 * itself: "landing once an explicit operator map names which crawler families
 * and which referrer hosts are the same company". Built alone and first,
 * because the ratio is a division and this is what makes its two sides
 * comparable at all.
 *
 * WHY A MAP AND NOT A STRING MATCH. The two vocabularies are unrelated by
 * construction:
 *
 *   crawler side  → snt_mr_valid_families(), a closed enum of user-agent
 *                   families the Worker classifies ('openai', 'google-ai', …)
 *   referrer side → the 'ai'-category brand labels in inc/analytics-sources.php
 *                   ('ChatGPT', 'Gemini', 'Le Chat', …)
 *
 * `GPTBot` and `chatgpt.com` are the same company and NOTHING in either list
 * says so. A string match happens to work for `perplexity`/`Perplexity` and
 * fails for every other pair — which is the worst kind of near-miss, because
 * the case that works makes the technique look sound. inc/analytics-sources.php
 * carries the same warning from the other end: its host list is the HUMAN
 * segment and "must never be reused as an 'is this an AI request?' predicate".
 *
 * THE ASYMMETRIES ARE REAL, and both sides are always declared even when empty:
 *   - Common Crawl crawls constantly and has no assistant to refer anyone.
 *   - Copilot refers readers, but its crawler is bingbot, which this site
 *     classifies as `search` — not an AI-training family. There is no AI family
 *     to attribute those crawls to, and inventing one would be a lie.
 * A ratio built on this must therefore distinguish "crawled, never referred"
 * (a real answer) from "no crawl data" (no answer). The map's job is to make
 * that distinction expressible; the ratio's job is to respect it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI-assistant referrer labels, mirrored from the 'ai'-category rules in
 * inc/analytics-sources.php. Declared here rather than derived, because
 * sn_analytics_source_rules() is a render-side fold that this data layer must
 * not depend on — and because a mirror that drifts is caught by the test that
 * compares the two, whereas a silent derivation would simply follow the drift.
 *
 * Extend BOTH or neither, the same rule the family and surface enums carry.
 *
 * @return string[]
 */
function snt_mr_ai_source_labels() {
	return array( 'ChatGPT', 'Claude', 'Perplexity', 'Gemini', 'Copilot', 'DeepSeek', 'Le Chat', 'Grok', 'Meta AI' );
}

/**
 * The map. operator key → { label, families[], sources[] }.
 *
 * `families` are crawler-side values from snt_mr_valid_families().
 * `sources` are referrer-side labels from snt_mr_ai_source_labels().
 * Either may be empty; see the asymmetry note above. Never both.
 *
 * @return array<string,array{label:string,families:string[],sources:string[]}>
 */
function snt_mr_operators() {
	return array(
		// Both sides present — the operators a give-back ratio can actually be
		// computed for.
		'openai'      => array( 'label' => 'OpenAI',            'families' => array( 'openai' ),      'sources' => array( 'ChatGPT' ) ),
		'anthropic'   => array( 'label' => 'Anthropic',         'families' => array( 'anthropic' ),   'sources' => array( 'Claude' ) ),
		'google'      => array( 'label' => 'Google',            'families' => array( 'google-ai' ),   'sources' => array( 'Gemini' ) ),
		'perplexity'  => array( 'label' => 'Perplexity',        'families' => array( 'perplexity' ),  'sources' => array( 'Perplexity' ) ),
		'meta'        => array( 'label' => 'Meta',              'families' => array( 'meta-ai' ),     'sources' => array( 'Meta AI' ) ),
		'mistral'     => array( 'label' => 'Mistral',           'families' => array( 'mistral' ),     'sources' => array( 'Le Chat' ) ),

		// Referrer side only: they send readers, but this site has no AI crawler
		// family to attribute crawls to. Microsoft's crawler is bingbot, which
		// classifies as `search`; DeepSeek and xAI have no distinguished family
		// in the Worker's enum. Their denominators are ABSENT, not zero.
		'microsoft'   => array( 'label' => 'Microsoft',         'families' => array(),                'sources' => array( 'Copilot' ) ),
		'deepseek'    => array( 'label' => 'DeepSeek',          'families' => array(),                'sources' => array( 'DeepSeek' ) ),
		'xai'         => array( 'label' => 'xAI',               'families' => array(),                'sources' => array( 'Grok' ) ),

		// Crawler side only: they read the site and have no consumer assistant
		// that could send a reader back. "Never sent a reader" is the TRUE and
		// meaningful answer for these, not a missing one.
		'amazon'      => array( 'label' => 'Amazon',            'families' => array( 'amazon-ai' ),   'sources' => array() ),
		'apple'       => array( 'label' => 'Apple',             'families' => array( 'apple-ai' ),    'sources' => array() ),
		'bytedance'   => array( 'label' => 'ByteDance',         'families' => array( 'bytedance' ),   'sources' => array() ),
		'cohere'      => array( 'label' => 'Cohere',            'families' => array( 'cohere' ),      'sources' => array() ),
		'allen-ai'    => array( 'label' => 'Allen Institute',   'families' => array( 'allen-ai' ),    'sources' => array() ),
		'commoncrawl' => array( 'label' => 'Common Crawl',      'families' => array( 'commoncrawl' ), 'sources' => array() ),
		'diffbot'     => array( 'label' => 'Diffbot',           'families' => array( 'diffbot' ),     'sources' => array() ),
	);
}

/**
 * Families deliberately NOT attributed to an AI operator, each with its reason.
 *
 * This list is not documentation, it is half of a completeness check: the test
 * asserts mapped ∪ unmapped == snt_mr_valid_families() exactly, so a family
 * added to the enum without a decision fails the suite instead of quietly
 * dropping out of every ratio. A family missing from a denominator is invisible
 * — it makes the site look less crawled, which is the flattering direction.
 *
 * @return array<string,string> family => why it is not an operator
 */
function snt_mr_unmapped_families() {
	return array(
		'search'               => 'a search-engine crawler; indexing is not AI consumption, and its operator may also run an assistant under a different family',
		'seo'                  => 'a third-party SEO tool crawling on somebody else\'s behalf, not an AI company reading for itself',
		'feed'                 => 'a feed reader fetching syndication, which is the site working as intended',
		'uptime'               => 'a monitor, frequently this site\'s own, and counting it would inflate the denominator with self-traffic',
		'other-bot'            => 'the catch-all bucket; by definition it is not one company, so attributing it to any operator would be a fabrication',
		'unclassified-machine' => 'machine traffic the classifier could not attribute at all — the honest answer is that we do not know who this was',
	);
}

/**
 * Which operator owns a crawler family.
 *
 * @param string $family A snt_mr_valid_families() value.
 * @return string|null Operator key, or null when the family belongs to no AI
 *                     operator (or is not a family at all). Never a guess.
 */
function snt_mr_operator_for_family( $family ) {
	if ( ! is_string( $family ) || '' === $family ) {
		return null;
	}
	foreach ( snt_mr_operators() as $key => $op ) {
		if ( in_array( $family, $op['families'], true ) ) {
			return $key;
		}
	}
	return null;
}

/**
 * Which operator owns an AI referrer label.
 *
 * Exact match on the brand label inc/analytics-sources.php resolves a host to —
 * never the host itself, and never a substring test. The label is the closed
 * value; the host list behind it can grow without touching this map.
 *
 * @param string $label An 'ai'-category label, e.g. 'ChatGPT'.
 * @return string|null Operator key, or null. Never a guess.
 */
function snt_mr_operator_for_source( $label ) {
	if ( ! is_string( $label ) || '' === $label ) {
		return null;
	}
	foreach ( snt_mr_operators() as $key => $op ) {
		if ( in_array( $label, $op['sources'], true ) ) {
			return $key;
		}
	}
	return null;
}

/**
 * Whether both sides of an operator are populated — i.e. whether a give-back
 * ratio is even askable for it.
 *
 * An operator with no families has no denominator: its ratio is UNKNOWN, which
 * is a different rendering from "crawled and never referred" (a real zero
 * numerator over a real denominator). Callers should branch on this rather than
 * discovering an empty array mid-division.
 *
 * @param string $key Operator key.
 * @return bool
 */
function snt_mr_operator_is_measurable( $key ) {
	$ops = snt_mr_operators();
	if ( ! isset( $ops[ $key ] ) ) {
		return false;
	}
	return ! empty( $ops[ $key ]['families'] );
}
