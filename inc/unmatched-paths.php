<?php
/**
 * Signal & Noise Tools: unmatched paths, the pure half (2026-10-29).
 *
 * The daily analytics table keeps every path a beacon reported (minus the
 * excluded admin and file paths), and the readers have always collapsed the
 * ones that are not site content into "(unmatched)". This names them, for the
 * owner only: the Integrity report and the local analytics_rows breakdown.
 *
 * A stored path is text a stranger chose, so it is CLEANED before anyone sees
 * it: lowercased, slashes collapsed, every segment that looks like an email,
 * an id, a hash, a number or a token replaced by a placeholder, anything
 * outside [a-z0-9._~-] replaced by {text}, and capped at 200 characters. What
 * survives is plain words, never an address, a secret or markup. The query
 * string and fragment never reach storage (the beacon sends
 * location.pathname, the analytics Worker strips them again); they are
 * stripped here once more anyway.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/unmatched-paths-clean.php';

/**
 * Stored daily rows {path, class, first_day, last_day, views, visits} into the
 * report: the non-content paths, cleaned and merged by cleaned path, split into
 * people (human and suspect, not probe-shaped) and automated (probe-shaped, or
 * seen only as bot). Sorted by human views, then the most recent. PURE: the
 * probe test and the suggester come in as callables (the 404 log's own,
 * sn_404_should_capture() and sn_404_suggest_target(), in production).
 *
 * @param array<int,array<string,mixed>> $rows     Stored rows, any paths.
 * @param array<string,bool>             $real     Canonical content paths.
 * @param callable                       $suggest  fn( string $clean ): string, a content path to redirect to, or ''.
 * @param callable                       $is_probe fn( string $clean ): bool.
 * @param int                            $suggest_limit People rows that get a suggestion (the rows shown): the suggester compares against every page, so it runs after the sort, not per stored path.
 * @return array{people:array,automated:array}
 */
function snt_unmatched_report_rows( array $rows, array $real, callable $suggest, callable $is_probe, $suggest_limit = PHP_INT_MAX ) {
	$by = array();
	foreach ( $rows as $r ) {
		$stored = (string) ( $r['path'] ?? '' );
		if ( '' === $stored || isset( $real[ sn_analytics_canonical_path( (string) strtok( strtok( $stored, '?' ), '#' ) ) ] ) ) {
			continue;
		}
		$clean = snt_unmatched_clean_path( $stored );
		$class = in_array( $r['class'] ?? '', array( 'human', 'suspect', 'bot' ), true ) ? $r['class'] : 'bot';
		$m     = $by[ $clean ] ?? array( 'path' => $clean, 'human' => 0, 'suspect' => 0, 'bot' => 0, 'visits' => 0, 'first_seen' => '', 'last_seen' => '' );
		$m[ $class ] += (int) ( $r['views'] ?? 0 );
		$m['visits'] += (int) ( $r['visits'] ?? 0 );
		$first = (string) ( $r['first_day'] ?? '' );
		$last  = (string) ( $r['last_day'] ?? '' );
		$m['first_seen'] = '' === $m['first_seen'] || ( '' !== $first && $first < $m['first_seen'] ) ? $first : $m['first_seen'];
		$m['last_seen']  = $last > $m['last_seen'] ? $last : $m['last_seen'];
		$by[ $clean ] = $m;
	}
	$people    = array();
	$automated = array();
	foreach ( $by as $clean => $m ) {
		$m['class']   = $m['human'] > 0 ? 'human' : ( $m['suspect'] > 0 ? 'suspect' : 'bot' );
		$m['probe']   = (bool) $is_probe( $clean );
		// A spelling of a page (capitals, doubled slashes): stored apart, the
		// same page once cleaned. Said, so the row is not read as a broken link.
		$m['variant'] = isset( $real[ $clean ] );
		$m['suggest'] = $m['variant'] ? $clean : null; // the rest, below, after the sort.
		$m['status']  = null; // set by snt_unmatched_join_404() when the 404 log saw it.
		$m['referrer'] = null;
		if ( ! $m['probe'] && 'bot' !== $m['class'] ) {
			$people[] = $m;
		} else {
			$automated[] = $m;
		}
	}
	$order = static fn( $a, $b ) => ( $b['human'] <=> $a['human'] ) ?: ( strcmp( $b['last_seen'], $a['last_seen'] ) ?: strcmp( $a['path'], $b['path'] ) );
	usort( $people, $order );
	foreach ( array_slice( array_keys( $people ), 0, max( 0, (int) $suggest_limit ) ) as $i ) {
		if ( ! $people[ $i ]['variant'] ) {
			$hit                    = (string) $suggest( $people[ $i ]['path'] );
			$people[ $i ]['suggest'] = '' === $hit ? null : $hit;
		}
	}
	usort( $automated, static fn( $a, $b ) => ( ( $b['human'] + $b['suspect'] + $b['bot'] ) <=> ( $a['human'] + $a['suspect'] + $a['bot'] ) ) ?: strcmp( $a['path'], $b['path'] ) );
	return array( 'people' => $people, 'automated' => $automated );
}

/**
 * What the 404 log saw for each row: status 404 and the referrer's hostname
 * (never the full referring URL, which can carry a query) when the log holds
 * the path, matched case-insensitively. A path the log does not hold keeps
 * status null: it was served as a page that is not a note, or its 404 came
 * before the log or was evicted. PURE.
 *
 * @param array<int,array<string,mixed>> $rows Report rows.
 * @param array<string,array>            $log  sn_404_log_all(): path => {count, first_seen, last_seen, referer}.
 * @return array<int,array<string,mixed>>
 */
function snt_unmatched_join_404( array $rows, array $log ) {
	$by = array();
	foreach ( $log as $path => $entry ) {
		$by[ snt_unmatched_clean_path( (string) $path ) ] = is_array( $entry ) ? $entry : array();
	}
	foreach ( $rows as $i => $row ) {
		$entry = $by[ $row['path'] ] ?? null;
		if ( null === $entry ) {
			continue;
		}
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( (string) ( $entry['referer'] ?? '' ), PHP_URL_HOST ) ) );
		$rows[ $i ]['status']   = 404;
		// A strict hostname or nothing: the analytics rule (snt_arows_host()).
		$rows[ $i ]['referrer'] = preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host ) ? $host : null;
	}
	return $rows;
}
