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
		case 'indexing':
			return __( 'Stored tier: the weekly URL Inspection map, the internal link graph (notes and pages, body links only) and the post table, read when the scan runs. Only published notes and pages the sitemap lists count; tag archives, /verify and anything marked noindex never do. Google can take weeks on a new URL, so a row here is a page to request, not a defect, and a URL inspected before its last edit can read stale until the next weekly run. Check now inspects the request list and the rows you marked, live, at most 20 seconds a press (Google takes 3 to 15 seconds a page); the map date in the headline stays the weekly run\'s. A mark records the day you marked the row, not the day you clicked Request indexing in Search Console, which the site cannot see; a marked row with no crawl since waits quietly for 14 days.', 'signal-and-noise-tools' );
		case 'unmatched_paths':
			return __( 'Stored tier: every path the analytics kept since the rollup began, minus admin and file paths, read when the scan runs. The 50 most viewed people paths are checked live once per scan (the broken-links probe: same host, no redirect followed, cached a day): a path the site serves (a theme route, a feed) or already redirects is listed apart under "Served by the site". Other status and the referrer come from the 404 log, which holds 404s only, at most 200 at a time, and only since it was added; "not in the 404 log" is a path the log never held that was not checked live (past the first 50, a placeholder in it, or stored in another spelling). Where a redirect lands is not checked. Views are sample-corrected and classed as the analytics class them; a path with no page views at all (a feed) is classed by its visits. Paths are cleaned: lowercased, and any email, id, hash, number or token replaced by a placeholder.', 'signal-and-noise-tools' );
	}
	return (string) ( $report['coverage'] ?? '' );
}
