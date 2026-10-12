<?php
/**
 * Signal & Noise: the sentences Site › Redirects and Site › Broken links say,
 * once, for both twins: the classic page (inc/redirects-admin.php) and the
 * native leaves (apps/sn-dashboard/parts/leaves/site-redirects*.php,
 * site-broken-links*.php). 2026.10.4 audit: the two surfaces had drifted,
 * explaining the same list in different words, and the native empty state had
 * dropped "or something here links to it", contradicting its own intro.
 * Plain text; each caller escapes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "7 hits", "1 hit".
 *
 * @param int $n Hits.
 * @return string
 */
function sn_404_hits( $n ) {
	/* translators: %s: hits on a path. */
	return sprintf( _n( '%s hit', '%s hits', (int) $n, 'signal-and-noise-tools' ), number_format_i18n( (int) $n ) );
}

/**
 * The redirects finding: how many rules there are and how many leave the
 * site. The store keeps no hit count, so no traffic figure is offered.
 *
 * @param array<string,array<string,mixed>> $rules Every rule.
 * @return string
 */
function sn_redirects_finding( array $rules ) {
	$total = count( $rules );
	if ( 0 === $total ) {
		return __( 'No redirect rules yet; add the first one in Add a redirect.', 'signal-and-noise-tools' );
	}
	$off = count( array_filter( $rules, static fn( $r ) => (bool) preg_match( '#^https?://#i', (string) ( $r['to'] ?? '' ) ) ) );
	$n   = number_format_i18n( $total );
	if ( 0 === $off ) {
		/* translators: %s: rules. */
		return sprintf( _n( '%s rule, sending readers to a path on this site.', '%s rules, every one sending readers to a path on this site.', $total, 'signal-and-noise-tools' ), $n );
	}
	if ( $off === $total ) {
		/* translators: %s: rules. */
		return sprintf( _n( '%s rule, sending readers to another site.', '%s rules, every one sending readers to another site.', $total, 'signal-and-noise-tools' ), $n );
	}
	/* translators: 1: rules in total, 2: rules with an off-site target. */
	return sprintf( _n( '%1$s rules: %2$s sends readers to another site; the rest stay on this one.', '%1$s rules: %2$s send readers to another site; the rest stay on this one.', $off, 'signal-and-noise-tools' ), $n, number_format_i18n( $off ) );
}

/**
 * What lands in the broken-links list, said where the list is empty.
 *
 * @return string
 */
function sn_404_empty_sentence() {
	return __( 'No broken links. A path lands here when it resembles a published URL, or when something on this site links to it; automated probes are counted separately.', 'signal-and-noise-tools' );
}

/**
 * The broken-paths finding: how many, their hits, the busiest.
 *
 * @param array<string,int> $counts Path => hits, busiest first.
 * @return string
 */
function sn_404_broken_finding( array $counts ) {
	$n    = count( $counts );
	$top  = (string) array_key_first( $counts );
	$tops = (int) ( $counts[ $top ] ?? 0 );
	if ( 1 === $n ) {
		/* translators: 1: the path, 2: its hits ("7 hits"). */
		return sprintf( __( 'One broken path, %1$s, took %2$s.', 'signal-and-noise-tools' ), $top, sn_404_hits( $tops ) );
	}
	/* translators: 1: "2 broken paths", 2: "9 hits", 3: the busiest path, 4: its hits ("7 hits"). */
	return sprintf( __( '%1$s took %2$s; the busiest, %3$s, took %4$s.', 'signal-and-noise-tools' ), sprintf( _n( '%s broken path', '%s broken paths', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ) ), sn_404_hits( (int) array_sum( $counts ) ), $top, sn_404_hits( $tops ) );
}

/**
 * The automated-probe finding.
 *
 * @param int $n    Probed paths.
 * @param int $hits Their hits.
 * @return string
 */
function sn_404_probe_sentence( $n, $hits ) {
	/* translators: 1: automated probes in the 404 log, 2: the hits they made ("465 hits"). */
	return sprintf( _n( '%1$s automated probe made %2$s on paths that resemble nothing published here and that nothing here links to: scanner traffic, not broken links, so no action is needed.', '%1$s automated probes made %2$s on paths that resemble nothing published here and that nothing here links to: scanner traffic, not broken links, so no action is needed.', (int) $n, 'signal-and-noise-tools' ), number_format_i18n( (int) $n ), sn_404_hits( (int) $hits ) );
}
