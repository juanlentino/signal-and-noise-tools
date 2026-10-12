<?php
/**
 * S&N Dashboard, Site → Broken links: the automated-probe ledger.
 *
 * 2026-10-11 (owner rule: no collapsible sections). The probed paths used to
 * sit inside a "Show the probed paths" fold. Now the section is open: one
 * finding sentence, the busiest probes as a capped ledger with a "+N more"
 * line, and the bulk dismiss. Weather, not a task, so no attention tone.
 * Required by site-broken-links.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** How many probed paths the ledger lists before "+N more" (the classic's 25). */
const REDIRECTS_PROBE_LIST_MAX = 25;

/**
 * The probe section: finding, capped ledger, Dismiss all probes.
 *
 * @param array<string,array<string,mixed>> $probes Busiest first.
 * @param int                               $hits   Their hit total.
 * @return string
 */
function broken_links_probes_html( array $probes, $hits ) {
	$n       = count( $probes );
	$finding = \sn_404_probe_sentence( $n, (int) $hits ); // the classic's sentence (inc/redirects-copy.php)
	$rows = array();
	foreach ( $probes as $path => $entry ) {
		$rows[] = array(
			'path' => (string) $path,
			'hits' => (int) ( $entry['count'] ?? 0 ),
		);
	}
	$ledger = \snt_kit_ledger(
		$finding,
		array(
			array( 'key' => 'path', 'label' => __( 'Path', 'signal-and-noise-tools' ) ),
			array( 'key' => 'hits', 'label' => __( 'Hits', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$rows,
		REDIRECTS_PROBE_LIST_MAX,
		// All its rows, no inner scroller, like the Broken paths list beside it:
		// measured live, a scrolled probes box was 644px against 1,153px (1.79x).
		/* translators: %s: probed paths not listed. */
		array( 'class' => 'snt-table-full', 'more' => __( '+%s more probed paths, the quietest; the list is capped, not complete.', 'signal-and-noise-tools' ) )
	);
	$dismiss = \snt_kit_action_button(
		__( 'Dismiss all probes', 'signal-and-noise-tools' ),
		'redirect_404_clear_probes',
		array(
			'confirm'       => __( 'Dismiss every automated probe from the log? Genuinely broken paths are kept.', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Dismiss probes', 'signal-and-noise-tools' ),
		)
	);
	return \snt_kit_section( __( 'Automated probes', 'signal-and-noise-tools' ), $ledger . $dismiss );
}
