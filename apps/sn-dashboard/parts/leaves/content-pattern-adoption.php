<?php
/**
 * S&N Dashboard — Content → Pattern Adoption, painted from the kit.
 *
 * The classic leaf (inc/pattern-adoption-admin.php,
 * `snt_pattern_adoption_render_opportunities_section()`) paints a heading with
 * a candidate-count pill, when the scan ran, the intro, one scan form, then one
 * of three states: nothing before the first scan, the empty note, or the
 * review queue: one row per candidate with the post, the pattern, and the
 * Suggest + Dismiss pair the enqueued assets/health-suggest-actions.js drives.
 * Same reader, same form, same data attributes, the same sentences
 * (inc/content-scans-shared.php); the kit's parts.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-pattern-adoption-queue.php';
require_once __DIR__ . '/content-scan-parts.php';

/**
 * The count pill: warn when there is work, ok at zero.
 *
 * @param array<string,mixed> $scan From snt_pattern_adoption_last_scan().
 * @return string
 */
function pattern_adoption_count_badge( array $scan ) {
	$total = (int) ( $scan['counts']['pull_quote'] ?? 0 ) + (int) ( $scan['counts']['steps_enumerated'] ?? 0 );
	return \snt_kit_badge( $total > 0 ? 'warn' : 'ok', \snt_content_scan_count( $total ) );
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
	// not is_array(): a transient holding array() must read as "never scanned".
	$scanned = ! empty( $scan );
	$result  = '';
	if ( $scanned ) {
		$candidates = (array) ( $scan['candidates'] ?? array() );
		$result     = empty( $candidates )
			? \snt_kit_empty( __( 'No opportunities found.', 'signal-and-noise-tools' ), __( 'All eligible blocks are either already pattern-upgraded or have been dismissed.', 'signal-and-noise-tools' ) )
			: pattern_adoption_queue_html( $candidates );
	}
	$form = \snt_kit_form(
		'pattern_adoption_scan',
		'',
		array( 'submit' => $scanned ? __( 'Re-scan opportunities', 'signal-and-noise-tools' ) : __( 'Scan for opportunities', 'signal-and-noise-tools' ) )
	);
	return content_scan_layout(
		__( 'Pattern adoption', 'signal-and-noise-tools' ),
		$scanned ? $scan : null,
		$scanned ? pattern_adoption_count_badge( $scan ) : '',
		$form,
		\snt_kit_esc( \snt_pattern_adoption_intro() ),
		$result
	);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/pattern-adoption'] = __NAMESPACE__ . '\\paint_content_pattern_adoption';
		return $painters;
	}
);
