<?php
/**
 * Signal & Noise Tools: analytics rows, the "(unmatched)" breakdown (2026-10-29,
 * local door only). With path "(unmatched)", the path rows that are not site
 * content come back one per cleaned path instead of collapsed: `path` stays
 * "(unmatched)", and the name is in `visitor_path`, a field named for what it
 * is. The owner decided (2026-10-29) that a cleaned path may reach a model's
 * context; the cleaner (inc/unmatched-paths-clean.php) bounds it: lowercase,
 * every email, id, hash, number or token a placeholder (judged by its parts
 * too), nothing outside [a-z0-9._~-], 200 characters at most. Words survive,
 * so a hyphenated sentence can: the field is named for what it is and read as
 * data. The remote twin refuses it: the shared callback does, and only the
 * local registration opts in (snt_ability_analytics_rows_local(), below).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/unmatched-paths.php';

/**
 * Stored path rows into the breakdown. PURE given its callables: the probe test
 * and the suggester (the 404 log's, in production) and the 404 log itself.
 *
 * @param array<int,array<string,mixed>> $rows     Stored rows (path, maybe day, the sums).
 * @param array                          $q        From snt_arows_validate(), unmatched set.
 * @param array<string,bool>             $real     Canonical content paths.
 * @param callable                       $suggest  fn( string $clean ): string.
 * @param callable                       $is_probe fn( string $clean ): bool.
 * @param array<string,array>            $log      The 404 log.
 * @return array{rows:array,row_count:int,truncated:bool}
 */
function snt_arows_shape_unmatched( array $rows, array $q, array $real, callable $suggest, callable $is_probe, array $log ) {
	$kept  = array();
	$clean = array();
	foreach ( $rows as $r ) {
		$stored = (string) ( $r['path'] ?? '' );
		// The default answer's test (snt_arows_clean()), so the rows' views sum to its "(unmatched)" row.
		if ( isset( $real[ sn_analytics_canonical_path( (string) strtok( strtok( $stored, '?' ), '#' ) ) ] ) ) {
			continue;
		}
		$c           = snt_unmatched_clean_path( $stored );
		$clean[ $c ] = true;
		$kept[]      = array( 'path' => $c ) + $r;
	}
	// The shared shaper merges, sorts and cuts; the cleaned paths are its allowlist.
	$out  = snt_arows_shape( $kept, $q, $clean );
	$info = array();
	foreach ( array_keys( $clean ) as $c ) {
		$info[] = array( 'path' => $c );
	}
	$info = array_column( snt_unmatched_join_404( $info, $log ), null, 'path' );
	foreach ( $out['rows'] as $i => $row ) {
		$c     = (string) $row['path'];
		$probe = (bool) $is_probe( $c );
		$hit   = $probe ? '' : (string) $suggest( $c );
		$out['rows'][ $i ] = array(
			'path'             => '(unmatched)',
			'visitor_path'     => $c,
		) + array_diff_key( $row, array( 'path' => true ) ) + array(
			'probe'            => $probe,
			'suggested_target' => isset( $real[ $c ] ) ? $c : ( '' === $hit ? null : $hit ),
			'status'           => $info[ $c ]['status'] ?? null,
			'referrer_host'    => $info[ $c ]['referrer'] ?? null,
		);
	}
	return $out;
}

/**
 * The breakdown as the ability serves it, with the production callables.
 *
 * @param array $rows Stored rows.
 * @param array $q    Validated query.
 * @return array{rows:array,row_count:int,truncated:bool}
 */
function snt_arows_unmatched( array $rows, array $q ) {
	$candidates = function_exists( 'sn_404_published_paths' ) ? sn_404_published_paths() : array();
	return snt_arows_shape_unmatched(
		$rows,
		$q,
		snt_arows_real_paths(),
		static fn( $c ) => function_exists( 'sn_404_suggest_target' ) ? sn_404_suggest_target( $c, $candidates ) : '',
		static fn( $c ) => function_exists( 'sn_404_should_capture' ) && ! sn_404_should_capture( $c ),
		function_exists( 'sn_404_log_all' ) ? sn_404_log_all() : array()
	);
}

/**
 * The breakdown's row fields, added to the local output schema only.
 *
 * @return array<string,array>
 */
function snt_arows_unmatched_row_schema() {
	$null_or = static fn( $t ) => array( 'type' => array( $t, 'null' ) );
	return array(
		'visitor_path'     => array( 'type' => 'string' ),
		'probe'            => array( 'type' => 'boolean' ),
		'suggested_target' => $null_or( 'string' ),
		'status'           => $null_or( 'integer' ),
		'referrer_host'    => $null_or( 'string' ),
	);
}

/**
 * The local registration's execute: the shared callback with the breakdown
 * allowed. The shared callback, which the remote twin runs, refuses it, so a
 * door wired to it later fails closed (2026-10-29; the remote door stays off
 * the breakdown until the owner decides otherwise, phase C).
 *
 * @param mixed $input The ability input.
 * @return array|WP_Error
 */
function snt_ability_analytics_rows_local( $input = null ) {
	return snt_ability_analytics_rows( $input, true );
}
