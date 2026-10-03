<?php
/**
 * Signal & Noise Tools: alerts, the pure half. What counts as a spike or a
 * break, and the plain-text email that says so.
 *
 * Owner, 2026-10-03: "email me when something spikes or breaks". Everything
 * here is PURE given its inputs, so tests/alerts.php needs no WordPress; the
 * hourly cron in inc/alerts-cron.php gathers the stored rows and sends.
 *
 * WHY FLOORS, NOT ONLY RATIOS. The site gets about 50 human visits a week, so
 * most paths have a 7-day mean under one view a day: five times nothing is
 * still nothing, and a ratio alone would mail on a path's third view. The
 * absolute floor is what makes a spike mean "someone linked this", and the
 * ratio only takes over on the few paths that already have traffic.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ALERT_PATH_FLOOR = 15; // A path's human views today must pass this...
const SNT_ALERT_PATH_RATIO = 5;  // ...and this many times its prior 7-day daily mean.
const SNT_ALERT_SITE_FLOOR = 40; // The whole site today: about a normal week's visits in one day.
const SNT_ALERT_SITE_RATIO = 4;  // Lower than the path ratio: the site total is the steadier number.
const SNT_ALERT_BREAK_MIN  = 3;  // Stored 5xx on one front-end page in one day; one or two is a blip.
const SNT_ALERT_BASE_DAYS  = 7;  // The baseline window, absent days counted as zero.
const SNT_ALERT_KEEP_DAYS  = 14; // How long a sent stamp is kept.

/**
 * The thresholds, filterable (`snt_alerts_thresholds`). A filter can change a
 * number, never remove one.
 *
 * @return array<string,float>
 */
function snt_alerts_thresholds() {
	$base = array( 'path_floor' => SNT_ALERT_PATH_FLOOR, 'path_ratio' => SNT_ALERT_PATH_RATIO, 'site_floor' => SNT_ALERT_SITE_FLOOR, 'site_ratio' => SNT_ALERT_SITE_RATIO, 'break_min' => SNT_ALERT_BREAK_MIN );
	$got  = function_exists( 'apply_filters' ) ? (array) apply_filters( 'snt_alerts_thresholds', $base ) : $base;
	return array_map( 'floatval', array_intersect_key( $got, $base ) + $base );
}

/**
 * Text that came from a request (a path, a referrer host), made safe for a
 * plain-text email: printable ASCII only, bounded.
 *
 * @param string $text Untrusted text.
 * @return string
 */
function snt_alerts_clean( $text ) {
	return substr( (string) preg_replace( '/[^\x20-\x7E]/', '?', (string) $text ), 0, 120 );
}

/**
 * Which alerts are due and not yet sent.
 *
 * @param array<string,mixed> $in {
 *     today    string               The site-local day, YYYY-MM-DD.
 *     views    array<string,int>    Today's human views by path.
 *     history  array<string,int>    Human views by path, summed over the prior 7 days.
 *     errors   array<string,array<string,int>> Stored 5xx by UTC day, then path.
 *     sent     array<string,int>    Alert key => when it was mailed.
 *     excluded callable|null        The plugin's excluded-path rule.
 *     real     callable|null        Whether a path is a real page (home, published content, a known archive). Without it no break fires.
 * }
 * @param array<string,float> $t Thresholds, from snt_alerts_thresholds().
 * @return array<int,array<string,mixed>> Each: key, kind, path, day, value, baseline, line.
 */
function snt_alerts_evaluate( array $in, array $t ) {
	$today    = (string) ( $in['today'] ?? '' );
	$views    = (array) ( $in['views'] ?? array() );
	$history  = (array) ( $in['history'] ?? array() );
	$sent     = (array) ( $in['sent'] ?? array() );
	$excluded = $in['excluded'] ?? null;
	$junk     = static function ( $path ) use ( $excluded ) {
		return '/wp-json' === $path || ( is_callable( $excluded ) && call_user_func( $excluded, $path ) );
	};
	$real     = $in['real'] ?? null;
	$found    = array();
	$add      = static function ( $kind, $path, $day, $value, $baseline, $line ) use ( &$found, $sent ) {
		$key = $kind . '|' . $path . '|' . $day;
		if ( ! isset( $sent[ $key ] ) ) {
			$found[] = compact( 'key', 'kind', 'path', 'day', 'value', 'baseline', 'line' );
		}
	};

	$total = 0;
	foreach ( $views as $path => $n ) {
		$path = (string) $path;
		if ( $junk( $path ) ) {
			continue;
		}
		$total += (int) $n;
		$mean   = (int) ( $history[ $path ] ?? 0 ) / SNT_ALERT_BASE_DAYS;
		$line   = max( $t['path_floor'], $t['path_ratio'] * $mean );
		if ( (int) $n > $line ) {
			$add( 'spike', $path, $today, (int) $n, round( $mean, 1 ), $line );
		}
	}
	$mean = array_sum( array_map( 'intval', $history ) ) / SNT_ALERT_BASE_DAYS;
	$line = max( $t['site_floor'], $t['site_ratio'] * $mean );
	if ( $total > $line ) {
		$add( 'spike_site', '', $today, $total, round( $mean, 1 ), $line );
	}
	// A break is keyed on the day the errors HAPPENED, not the day they were
	// noticed: the edge rollup stores a day after it ends, so the same rows are
	// read on two calendar days and must mail once. Only a REAL page breaks: the
	// stored 5xx rows are full of scanner probes (/_security/_authenticate,
	// /model/info, /Dockerfile) that no excluded-path rule can list, so the
	// test is positive (the path resolves to content), never a deny list.
	foreach ( (array) ( $in['errors'] ?? array() ) as $day => $paths ) {
		foreach ( (array) $paths as $path => $n ) {
			if ( (int) $n >= $t['break_min'] && ! $junk( (string) $path ) && is_callable( $real ) && call_user_func( $real, (string) $path ) ) {
				$add( 'break', (string) $path, (string) $day, (int) $n, 0, $t['break_min'] );
			}
		}
	}
	return $found;
}

/**
 * Sent stamps with the old ones dropped, so the option stays bounded.
 *
 * @param array<string,int> $sent Key => unix time.
 * @param int               $now  Unix time.
 * @return array<string,int>
 */
function snt_alerts_prune( array $sent, $now ) {
	$cut = (int) $now - SNT_ALERT_KEEP_DAYS * 86400;
	return array_filter( array_map( 'intval', $sent ), static fn( $at ) => $at >= $cut );
}

/**
 * The email. Short: what fired, the numbers, the baseline, where to look.
 *
 * @param array<int,array<string,mixed>> $alerts  From snt_alerts_evaluate().
 * @param array<int,array<string,mixed>> $sources Today's top sources (value, views), or empty.
 * @param string                         $site    Site name.
 * @param string                         $where   The dashboard URL.
 * @return array{0:string,1:string} Subject, body.
 */
function snt_alerts_compose( array $alerts, array $sources, $site, $where ) {
	$lines  = array();
	$spikes = 0;
	foreach ( $alerts as $a ) {
		$path = snt_alerts_clean( $a['path'] );
		if ( 'break' === $a['kind'] ) {
			$lines[] = sprintf( 'BREAK: %s answered a server error %d times on %s (UTC), in the stored edge 5xx rollup. The alert line is %d.', $path, $a['value'], $a['day'], $a['line'] );
			continue;
		}
		++$spikes;
		$lines[] = sprintf( 'SPIKE: %s has %d human views today (%s). The prior 7-day mean is %s a day; the alert line is more than %s.', 'spike_site' === $a['kind'] ? 'the whole site' : $path, $a['value'], $a['day'], $a['baseline'], round( $a['line'], 1 ) );
	}
	$body = "Signal & Noise alert\n\n" . implode( "\n", $lines ) . "\n";
	if ( $spikes > 0 ) {
		$top = array();
		foreach ( array_slice( $sources, 0, 5 ) as $s ) {
			$top[] = snt_alerts_clean( $s['value'] ?? '' ) . ' ' . (int) ( $s['views'] ?? 0 );
		}
		$body .= "\n" . ( $top ? 'Top sources today, site-wide (views): ' . implode( ', ', $top ) . '.' : 'No sources are stored for today yet.' );
		$body .= "\nVisits with no referrer (apps, RSS readers, privacy browsers) show as direct.\n";
	}
	$body .= "\nWhere to look: " . $where . "\nEach alert mails once for its day. Switch alerts off in Connections, Cron.\n";
	$breaks = count( $alerts ) - $spikes;
	$what   = array_filter( array( $spikes ? $spikes . ( 1 === $spikes ? ' spike' : ' spikes' ) : '', $breaks ? $breaks . ( 1 === $breaks ? ' break' : ' breaks' ) : '' ) );
	return array( sprintf( '[%s] Alert: %s', $site, implode( ', ', $what ) ), $body );
}
