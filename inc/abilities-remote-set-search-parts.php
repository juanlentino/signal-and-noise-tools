<?php
/**
 * Signal & Noise Tools: the remote set, the Search Console twins (v13.61.0, v13.67.0).
 *
 * Split from inc/abilities-remote-set.php (house line cap, 2026-10-13); a pure
 * move, no code changed. The file header there carries the isolation rules every
 * twin here follows. Loaded by require_once at the end of that file, in the
 * order the registrations always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v13.61.0 — weave Phase 4.
function snt_ability_perm_remote_search_performance() {
	return sn_remote_analytics_allows( 'signal-noise/remote-search-performance' );
}
function snt_ability_perm_remote_search_drift() {
	return sn_remote_analytics_allows( 'signal-noise/remote-search-drift' );
}
// v13.67.0 — owner ruling 2026-09-01: the cross-exam joins the door.
function snt_ability_perm_remote_search_crossexam() {
	return sn_remote_analytics_allows( 'signal-noise/remote-search-crossexam' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'snt_search_console_abilities' ) ) {
		return;
	}
	/* ── v13.61.0 — measurement weave Phase 4: two Search Console twins.
	 * The output_schema is READ FROM THE SAME TABLE the admin registration
	 * reads (snt_search_console_abilities()), so byte-identity is by
	 * construction, not by copy; tests/abilities-remote-set.php still pins
	 * the pair with ===. search_crossexam joined at v13.67.0 by owner ruling
	 * (2026-09-01): a WINDOW verdict over two public-facing instruments, no
	 * paths in its payload, and the ledger side is counts only.
	 * ───────────────────────────────────────────────────────────────── */
	$table = snt_search_console_abilities();
	$twins = array(
		'signal-noise/remote-search-performance' => array(
			'admin' => 'signal-noise/search-performance',
			'label' => 'Search Console: the stored window (remote)',
			'perm'  => 'snt_ability_perm_remote_search_performance',
			'exec'  => 'snt_ability_search_performance',
		),
		'signal-noise/remote-search-drift'       => array(
			'admin' => 'signal-noise/search-drift',
			'label' => 'Search Console: position drift (remote)',
			'perm'  => 'snt_ability_perm_remote_search_drift',
			'exec'  => 'snt_ability_search_drift',
		),
		'signal-noise/remote-search-crossexam'   => array(
			'admin' => 'signal-noise/search-crossexam',
			'label' => 'Search Console x crawler ledger: do the instruments agree? (remote)',
			'perm'  => 'snt_ability_perm_remote_search_crossexam',
			'exec'  => 'snt_ability_search_crossexam',
		),
	);
	foreach ( $twins as $slug => $t ) {
		if ( ! isset( $table[ $t['admin'] ] ) ) {
			continue;
		}
		wp_register_ability( $slug, array(
			'label'               => $t['label'],
			'description'         => 'Remote-scoped twin of ' . $t['admin'] . '. ' . $table[ $t['admin'] ]['description']
				. ' Reachable only by a principal holding the sn_read_remote_analytics capability, and only while the remote door is explicitly enabled.',
			'category'            => 'diagnostics',
			'permission_callback' => $t['perm'],
			'execute_callback'    => $t['exec'],
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => $table[ $t['admin'] ]['output'],
			'meta'                => array(
				'show_in_rest' => false, // the surface, not a setting — see file header (#1215).
				'mcp'          => array( 'public' => false, 'type' => 'tool' ),
				'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}
} );
