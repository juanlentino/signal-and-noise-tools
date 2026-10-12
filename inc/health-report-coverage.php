<?php
/**
 * Signal & Noise Tools: the "What this covers" sentence of a report-only
 * check, looked up by check key when the report is PAINTED.
 *
 * The sentence used to live only in the stored scan, so the Reports leaf and
 * the classic page printed whatever copy the last scan froze, including
 * "The usage tier below answers that" (the usage tier is another box),
 * "Colours" and em dashes. Fixing the producer heals one scan later, which is
 * the stored-figure bug the "38 of 21" count had. Both renderers now ask
 * here; the producers store the same words, so the health-scan ability
 * reads them too. A key this file does not know keeps its stored sentence.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The coverage sentence for a report.
 *
 * @param string              $key    Health check key.
 * @param array<string,mixed> $report The stored report (its own sentence is the fallback).
 * @return string
 */
function sn_health_report_coverage( $key, array $report = array() ) {
	switch ( (string) $key ) {
		case 'contrast_tokens':
			return __( 'Arithmetic tier: every theme-token pair scored as if rendered together. A failing row here is a "would fail", not a live defect, because this tier cannot know which pairs meet on screen; the usage tier answers that for pairings declared in stylesheets. Colors inlined in block markup and the computed cascade still need a real render, so a clean sweep across both tiers is still not proof of a clean site.', 'signal-and-noise-tools' );
		case 'motion_scan':
			return __( 'Declared tier: every animation and transition in the scanned front stylesheets, checked for a reduced-motion counterpart: gated behind no-preference, or set to none under reduce (in the same sheet or a global reset; the kinds are separate claims). Script-driven motion (JS class toggles, Web Animations, scroll handlers) is invisible to a stylesheet reader, so a clean sweep here is not proof a reduced-motion visitor sees no motion.', 'signal-and-noise-tools' );
	}
	return (string) ( $report['coverage'] ?? '' );
}
