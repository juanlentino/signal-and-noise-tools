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
 * an id, a hash, a number or a token (judged whole and by its parts, so a
 * separator cannot hide one) replaced by a placeholder, anything outside
 * [a-z0-9._~-] replaced by {text}, and capped at 200 characters. What
 * survives is lowercase words, digits and the separators: no markup, line
 * break or recognizable secret, but words are words, so a hyphenated sentence
 * survives and is read as data. An address with no "@" (a dotted name) is
 * not recognized. The query
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
 * seen only as bot: by its page views, or by its visits where it has none).
 * Sorted by human views, then the most recent (visits first among rows with no
 * human view, so a feed is not ranked behind a suspect-only row). PURE: the probe
 * test and the suggester come in as callables (the 404 log's own,
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
		$m     = $by[ $clean ] ?? array( 'path' => $clean, 'human' => 0, 'suspect' => 0, 'bot' => 0, 'visits' => 0, 'first_seen' => '', 'last_seen' => '', 'respelled' => false, 'visits_by' => array( 'human' => 0, 'suspect' => 0, 'bot' => 0 ) );
		// Stored as something the cleaned path is not (capitals, an encoded
		// slash): asking the site about the cleaned path would ask about a URL
		// this visitor never requested (/Provhub 404s where /provhub serves).
		$m['respelled'] = $m['respelled'] || preg_replace( '#/+#', '/', sn_analytics_canonical_path( (string) strtok( strtok( $stored, '?' ), '#' ) ) ) !== $clean;
		$m[ $class ] += (int) ( $r['views'] ?? 0 );
		$m['visits'] += (int) ( $r['visits'] ?? 0 );
		$m['visits_by'][ $class ] += (int) ( $r['visits'] ?? 0 );
		$first = (string) ( $r['first_day'] ?? '' );
		$last  = (string) ( $r['last_day'] ?? '' );
		$m['first_seen'] = '' === $m['first_seen'] || ( '' !== $first && $first < $m['first_seen'] ) ? $first : $m['first_seen'];
		$m['last_seen']  = $last > $m['last_seen'] ? $last : $m['last_seen'];
		$by[ $clean ] = $m;
	}
	$people    = array();
	$automated = array();
	foreach ( $by as $clean => $m ) {
		// No page views at all (a feed fetch, or any other event-only row: every
		// event counts a visit, only a page view counts a view): classed by its
		// visits, or a feed reader reads as a scanner and never gets the live check.
		$n          = $m['human'] + $m['suspect'] + $m['bot'] > 0 ? $m : $m['visits_by'];
		$m['class'] = $n['human'] > 0 ? 'human' : ( $n['suspect'] > 0 ? 'suspect' : 'bot' );
		unset( $m['visits_by'] );
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
	// ponytail: visits only break a tie at zero human views; a zero-view path
	// still ranks below every path with a human view, so past
	// SNT_UNMATCHED_PROBE_MAX people rows (43 on 2026-10-31) it is not checked
	// live. Rank by visits if that day comes.
	$order = static fn( $a, $b ) => ( $b['human'] <=> $a['human'] ) ?: ( ( 0 === $a['human'] ? $b['visits'] <=> $a['visits'] : 0 ) ?: ( strcmp( $b['last_seen'], $a['last_seen'] ) ?: strcmp( $a['path'], $b['path'] ) ) );
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

/**
 * What the site answers for the top rows, checked live: status is the code
 * the request got, and `served` says a 2xx (the site serves it: a theme route,
 * a feed) or a 3xx (a redirect already sends it on: Site › Redirects, the
 * theme or WordPress's own guess). PURE given the probe. A row the probe
 * cannot ask about honestly (a placeholder in it, another spelling of a page,
 * or a stored spelling the cleaned path is not) or that did not answer keeps
 * what the 404 log said. Where a redirect lands is not checked.
 *
 * @param array<int,array<string,mixed>> $rows  Report rows, best first.
 * @param callable                       $probe fn( string $clean ): ?int, the status code or null.
 * @param int                            $limit Rows asked about.
 * @return array<int,array<string,mixed>>
 */
function snt_unmatched_check_live( array $rows, callable $probe, $limit ) {
	$asked = 0;
	foreach ( $rows as $i => $row ) {
		$rows[ $i ]['served'] = null;
		if ( $asked >= $limit || ! empty( $row['variant'] ) || ! empty( $row['respelled'] ) || false !== strpos( (string) $row['path'], '{' ) ) {
			continue;
		}
		++$asked;
		$code = $probe( (string) $row['path'] );
		if ( ! is_int( $code ) ) {
			continue;
		}
		$rows[ $i ]['status'] = $code;
		$rows[ $i ]['served'] = $code < 300 ? 'page' : ( $code < 400 ? 'redirect' : null );
	}
	return $rows;
}
