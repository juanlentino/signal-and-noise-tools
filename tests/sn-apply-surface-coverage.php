<?php
/**
 * Surface coverage for the sn_apply family.
 *
 * Written BEFORE the files moved under inc/sn-apply/, so the same guard runs on
 * both layouts and the move has to prove it changed nothing. Copies the shape
 * used for admin-post-actions (v12.21.2), content-migrations (v12.21.3) and
 * ai-bootstrap (v12.21.4).
 *
 * What it protects:
 *
 *   1. Every family file is required EXACTLY ONCE, by the loader once the
 *      package exists, or by the bootstrap before that. Twice is a redeclare
 *      fatal; zero is a silently missing surface.
 *   2. snt_ability_sn_apply() is declared exactly once in the whole tree.
 *   3. The 86-function public surface is unchanged. Function names survive a
 *      move; that is the whole reason a move is safe, so it is asserted rather
 *      than assumed.
 *
 * The layout is DETECTED, not declared - a guard hardcoded to one arrangement
 * would go green for the wrong reason the moment the arrangement changed.
 *
 * Run: php tests/sn-apply-surface-coverage.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function eq( $e, $a, $m ) { ok( $e === $a, $m . ( $e === $a ? '' : ' (expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) . ')' ) ); }

$root = dirname( __DIR__ );

/** The 86 functions this family declared before the package move. */
$expected_surface = array(
	'snt_ability_sn_apply',
	'snt_sn_apply_apply_one',
	'snt_sn_apply_apply_staged_meta_for_post',
	'snt_sn_apply_audit_enrichment',
	'snt_sn_apply_autokey_excluded_types',
	'snt_sn_apply_batch_changes_impl',
	'snt_sn_apply_batch_edit_error',
	'snt_sn_apply_batch_edit_has_per_edit_fingerprint',
	'snt_sn_apply_batch_edit_types',
	'snt_sn_apply_batch_edits_impl',
	'snt_sn_apply_batch_overlap_error',
	'snt_sn_apply_block_delimiter_findings',
	'snt_sn_apply_block_edit_anchor_min_error',
	'snt_sn_apply_block_edit_compute',
	'snt_sn_apply_block_edit_compute_move',
	'snt_sn_apply_block_edit_impl',
	'snt_sn_apply_block_edit_locate',
	'snt_sn_apply_block_edit_payload_error',
	'snt_sn_apply_block_edit_prose_delta',
	'snt_sn_apply_block_edit_removal_range',
	'snt_sn_apply_block_edit_resolve_span',
	'snt_sn_apply_block_edit_scan_spans',
	'snt_sn_apply_block_edit_span_for_path',
	'snt_sn_apply_block_edit_unknown_block',
	'snt_sn_apply_canonical_target',
	'snt_sn_apply_change_is_insertion',
	'snt_sn_apply_change_types',
	'snt_sn_apply_changes_conflict',
	'snt_sn_apply_changes_conflict_error',
	'snt_sn_apply_changes_error',
	'snt_sn_apply_compute_block_replacement',
	'snt_sn_apply_create_draft_count_blocks',
	'snt_sn_apply_create_draft_preview',
	'snt_sn_apply_delete_draft_diff',
	'snt_sn_apply_dry_run_diff',
	'snt_sn_apply_effective_idempotency_key',
	'snt_sn_apply_ensure_rollback_snapshot',
	'snt_sn_apply_execute_write',
	'snt_sn_apply_gate1_fingerprint',
	'snt_sn_apply_gate1_roadmap_board',
	'snt_sn_apply_gate2_create_draft',
	'snt_sn_apply_gate2_delete_draft',
	'snt_sn_apply_gate2_dismiss',
	'snt_sn_apply_gate2_merge_tags',
	'snt_sn_apply_gate2_restore_revision',
	'snt_sn_apply_gate2_roadmap_board',
	'snt_sn_apply_gate2_schedule_cron_event',
	'snt_sn_apply_gate2_validation',
	'snt_sn_apply_gate_capability',
	'snt_sn_apply_gate_idempotency',
	'snt_sn_apply_granted_modes',
	'snt_sn_apply_idempotency_get_blob',
	'snt_sn_apply_idempotency_prune_rows',
	'snt_sn_apply_idempotency_record',
	'snt_sn_apply_idempotency_store_key',
	'snt_sn_apply_is_batch_target',
	'snt_sn_apply_is_owner_credential',
	'snt_sn_apply_link_prose_normalize',
	'snt_sn_apply_link_reshape_compute',
	'snt_sn_apply_link_reshape_impl',
	'snt_sn_apply_link_reshape_locate',
	'snt_sn_apply_link_reshape_pair_error',
	'snt_sn_apply_mode_support',
	'snt_sn_apply_pending_staged_meta_for_post',
	'snt_sn_apply_plan_batch_edits',
	'snt_sn_apply_plan_block_claim',
	'snt_sn_apply_plan_changes',
	'snt_sn_apply_plan_one_change',
	'snt_sn_apply_plan_prose_claim',
	'snt_sn_apply_resolve_tag_ids',
	'snt_sn_apply_resolve_target',
	'snt_sn_apply_restore_revision_precheck',
	'snt_sn_apply_revision_write_callback',
	'snt_sn_apply_roadmap_board_diff',
	'snt_sn_apply_sentence_pair_error',
	'snt_sn_apply_sentence_replace_impl',
	'snt_sn_apply_target_error_response',
	'snt_sn_apply_unlink_anchor_error',
	'snt_sn_apply_unlink_compute',
	'snt_sn_apply_unlink_impl',
	'snt_sn_apply_write_create_draft',
	'snt_sn_apply_write_delete_draft',
	'snt_sn_apply_write_preserving_schedule',
	'snt_sn_apply_write_restore_revision',
	'snt_sn_apply_write_roadmap_board',
	'snt_sn_apply_write_surfaces',
);

/* ── layout detection ──────────────────────────────────────────────────── */
$packaged = is_dir( $root . '/inc/sn-apply' );
$family   = $packaged
	? glob( $root . '/inc/sn-apply/*.php' )
	: glob( $root . '/inc/sn-apply-*.php' );
$loader   = $root . '/inc/abilities-sn-apply.php';

ok( is_array( $family ) && count( $family ) >= 13, 'the family is found: ' . count( (array) $family ) . ' files, layout=' . ( $packaged ? 'packaged' : 'flat' ) );
ok( file_exists( $loader ), 'the public path inc/abilities-sn-apply.php still exists (it is what tests and the bootstrap require)' );

/* ── who requires the family? the loader once packaged, else the bootstrap ── */
$requirer_path = $packaged ? $loader : ( $root . '/signal-and-noise-tools.php' );
$requirer      = (string) file_get_contents( $requirer_path );
$requirer_name = str_replace( $root . '/', '', $requirer_path );

/**
 * Count how many times a family file is required by the given source text.
 * Matches the basename so it is indifferent to SNT_PATH vs __DIR__ spelling.
 */
function sasc_require_count( $source, $basename ) {
	// The basename must be preceded by a path separator or a quote. Without
	// that boundary 'revision.php' also matches inside 'restore-revision.php'
	// and the guard reports a phantom duplicate - which it did, on the first
	// run against the packaged layout.
	return preg_match_all(
		'/require(_once)?[^;\n]*[\'"\/]' . preg_quote( $basename, '/' ) . '/',
		$source
	);
}

$missing = array();
$dupes   = array();
foreach ( $family as $f ) {
	$b = basename( $f );
	$n = sasc_require_count( $requirer, $b );
	if ( 0 === $n ) { $missing[] = $b; }
	if ( $n > 1 )   { $dupes[]   = $b . " (x$n)"; }
}
ok( array() === $missing, 'every family file is required by ' . $requirer_name . ( $missing ? ' - MISSING: ' . implode( ', ', $missing ) : '' ) );
ok( array() === $dupes,   'and none is required twice (a second require_once is dead weight; a second require is a fatal)' . ( $dupes ? ' - ' . implode( ', ', $dupes ) : '' ) );

/* ── NEGATIVE CONTROL: dropping one require must be detected ──────────────
   Simulated against the source text rather than by editing the repo, so the
   control runs on every sweep instead of once during development. */
$victim  = basename( $family[0] );
$broken  = preg_replace( '/^.*' . preg_quote( $victim, '/' ) . '.*$/m', '', $requirer );
ok( 0 === sasc_require_count( $broken, $victim ), 'NEGATIVE CONTROL: with its require line removed, ' . $victim . ' reads as MISSING - the guard can fail' );

/* ── one declaration of the public entry point ─────────────────────────── */
$decls = 0;
foreach ( array_merge( $family, array( $loader ) ) as $f ) {
	$decls += preg_match_all( '/^function\s+snt_ability_sn_apply\s*\(/m', (string) file_get_contents( $f ) );
}
eq( 1, $decls, 'snt_ability_sn_apply() is declared exactly once across the family' );

/* ── the function surface survived ─────────────────────────────────────── */
$found = array();
foreach ( array_merge( $family, array( $loader ) ) as $f ) {
	if ( preg_match_all( '/^function\s+([a-z0-9_]+)\s*\(/m', (string) file_get_contents( $f ), $m ) ) {
		$found = array_merge( $found, $m[1] );
	}
}
sort( $found );
$expected = $expected_surface;
sort( $expected );

$lost  = array_values( array_diff( $expected, $found ) );
$added = array_values( array_diff( $found, $expected ) );
ok( array() === $lost, 'no function was LOST' . ( $lost ? ': ' . implode( ', ', $lost ) : ' (' . count( $expected ) . ' pinned)' ) );
ok( array() === $added, 'no function appeared unannounced' . ( $added ? ': ' . implode( ', ', $added ) . ' - if deliberate, add it to $expected_surface' : '' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
