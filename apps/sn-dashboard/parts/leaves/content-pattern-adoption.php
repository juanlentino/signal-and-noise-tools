<?php
/**
 * S&N Dashboard — Content → Pattern Adoption, painted from the kit.
 *
 * The classic leaf (inc/pattern-adoption-admin.php,
 * `snt_pattern_adoption_render_opportunities_section()`) paints a heading with
 * an opportunity-count pill, the intro, one scan form, then one of three
 * states: nothing before the first scan, the empty note, or a collapsed
 * review queue — one row per candidate with the post, the pattern, and the
 * Suggest + Dismiss pair the enqueued assets/health-suggest-actions.js drives.
 * Same reader, same form, same data attributes; the kit's parts.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-pattern-adoption-queue.php';

/**
 * The count pill the classic heading carries: warn when there is work, ok at zero.
 *
 * @param array<string,mixed> $scan From snt_pattern_adoption_last_scan().
 * @return string
 */
function pattern_adoption_count_badge( array $scan ) {
	$total = (int) ( $scan['counts']['pull_quote'] ?? 0 ) + (int) ( $scan['counts']['steps_enumerated'] ?? 0 );
	return \snt_kit_badge(
		$total > 0 ? 'warn' : 'ok',
		sprintf(
			/* translators: %d is the count of pattern-adoption opportunities found */
			_n( '%d opportunity', '%d opportunities', $total, 'signal-and-noise-tools' ),
			$total
		)
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_pattern_adoption( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$scan    = \snt_pattern_adoption_last_scan();
	// Classic: inc/pattern-adoption-admin.php tests truthiness (`if ( $last_scan )`),
	// not is_array() — a transient holding array() must read as "never scanned".
	$scanned = ! empty( $scan );
	$body    = ( $scanned ? pattern_adoption_count_badge( $scan ) : '' )
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Scans existing /notes posts for blockquote and ordered-list blocks that could be upgraded to the v9.2.0 pull-quote and steps-enumerated patterns. Pure structural detection: no AI calls. Editorial: every upgrade is reviewed before apply.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( $scanned ) {
		$candidates = (array) ( $scan['candidates'] ?? array() );
		$body      .= empty( $candidates )
			? \snt_kit_empty( __( 'No opportunities found.', 'signal-and-noise-tools' ), __( 'All eligible blocks are either already pattern-upgraded or have been dismissed.', 'signal-and-noise-tools' ) )
			: pattern_adoption_queue_html( $candidates );
	}
	// The scan is the section's action: last, under the queue it refills.
	$body .= \snt_kit_form(
		'pattern_adoption_scan',
		'',
		array( 'submit' => $scanned ? __( 'Re-scan opportunities', 'signal-and-noise-tools' ) : __( 'Scan for opportunities', 'signal-and-noise-tools' ) )
	);
	return \snt_kit_section( __( 'Pattern adoption', 'signal-and-noise-tools' ), $body );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/pattern-adoption'] = __NAMESPACE__ . '\\paint_content_pattern_adoption';
		return $painters;
	}
);
