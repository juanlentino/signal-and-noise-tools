<?php
/**
 * Signal & Noise Tools, Machine Readers: the one narration sentence.
 *
 * Session 3 lane R2. A pure, string-returning summarizer over the same
 * normalized rows the tables render, for narrator surfaces that can only
 * afford one line.
 *
 * The narrator's denominator contract holds here without exception: a crawler
 * read is a READ. It is never a "visit", never a "visitor", and it is never
 * summed with a human beacon count, because the two come from different
 * sensors measuring different things. The sentence therefore names its own
 * window and its own unit, and the AI-training subset is stated as DECLARED
 * (public declarations crossed with self-reported user agents), never proven.
 *
 * Silence is the other half of the contract: an unconfigured sensor, a failed
 * read, or a genuinely quiet window returns '' so a caller composes nothing
 * rather than a fabricated claim.
 *
 * Paired fixture: tests/machine-readers-narration.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One plain sentence about machine readership over a window, or '' when there
 * is nothing honest to say.
 *
 * Returns '' when the payload is not a successful read (unconfigured sensor,
 * network failure, non-200, bad schema) or when the window's rows sum to zero
 * reads. Stale rows riding along a failed read are ignored: ok=false means the
 * numbers are not current, and a stale number narrated as current is exactly
 * the fabrication this guard exists to prevent.
 *
 * The returned string is plain prose carrying integers only, never a
 * worker-supplied string, so nothing here can smuggle markup. It still passes
 * through the caller's own escape at whatever sink it lands in.
 *
 * Pure: reads the payload, returns a new string, never mutates the input.
 *
 * @param mixed $result snt_mr_fetch() shape: {ok:bool,rows:array,error:?string}.
 * @param int   $days   Window the rows cover; clamped 1..90 like the fetch it describes.
 * @return string One sentence, or '' for silence.
 */
function snt_mr_narration_sentence( $result, $days = 30 ) {
	// The aggregate helpers live in the render module. Absent (a partial load),
	// the honest answer is silence, not a half-counted sentence.
	if ( ! is_callable( 'snt_mr_sum_hits_by' ) || ! is_callable( 'snt_mr_ai_training_families' ) ) {
		return '';
	}
	if ( ! is_array( $result ) || empty( $result['ok'] ) || ! isset( $result['rows'] ) || ! is_array( $result['rows'] ) ) {
		return '';
	}

	$totals = snt_mr_sum_hits_by( $result['rows'], 'family' );
	$total  = 0;
	foreach ( $totals as $hits ) {
		$total += (int) $hits;
	}
	if ( $total < 1 ) {
		return ''; // A quiet window is quiet. Zeros are not news.
	}

	$ai = 0;
	foreach ( snt_mr_ai_training_families() as $family ) {
		$ai += (int) ( $totals[ $family ] ?? 0 );
	}

	// Same clamp as snt_mr_fetch(): the sentence may only name a window the
	// sensor could actually have answered for.
	$days = max( 1, min( 90, (int) $days ) );

	if ( $ai > 0 ) {
		return sprintf(
			/* translators: 1: total machine reads, 2: window length in days, 3: reads from declared AI-training families. */
			_n(
				'Machine readers read the site %1$s time over the %2$s-day window; declared AI-training families accounted for %3$s of those reads.',
				'Machine readers read the site %1$s times over the %2$s-day window; declared AI-training families accounted for %3$s of those reads.',
				$total,
				'signal-and-noise-tools'
			),
			number_format_i18n( $total ),
			$days,
			number_format_i18n( $ai )
		);
	}

	// Zero is stated, never implied by omission: a window with no AI-training
	// reads is a finding, and leaving the clause out would read as an oversight.
	return sprintf(
		/* translators: 1: total machine reads, 2: window length in days. */
		_n(
			'Machine readers read the site %1$s time over the %2$s-day window; none of those reads came from a declared AI-training family.',
			'Machine readers read the site %1$s times over the %2$s-day window; none of those reads came from a declared AI-training family.',
			$total,
			'signal-and-noise-tools'
		),
		number_format_i18n( $total ),
		$days
	);
}
