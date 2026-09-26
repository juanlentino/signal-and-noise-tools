<?php
/**
 * The north star: weekly engaged readers.
 *
 * One number for what the site is for: people reading its core pages. A
 * reader is a human visitor-day (the visitor hash rotates at UTC midnight, so
 * the same person on two days counts twice, by design) with at least one core
 * page read past the scroll OR the dwell floor. Which page groups are core and
 * both floors are settings (`north_star.*`). The supporting metrics ride along
 * so the number never stands alone.
 *
 * The pure part (config, matching, tally) has no WordPress side effects, so it
 * loads under the CLI test harness.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_NSM_CACHE_KEY = 'snt_nsm_reading';
const SNT_NSM_WEEKS     = 4;

/**
 * Page groups the owner can count as core reading: group => path prefixes.
 *
 * @return array<string,array{label:string,prefixes:string[]}>
 */
function snt_nsm_sections() {
	return array(
		'notes'      => array( 'label' => __( 'Notes', 'signal-and-noise-tools' ), 'prefixes' => array( '/notes/' ) ),
		'provenance' => array( 'label' => __( 'Provenance', 'signal-and-noise-tools' ), 'prefixes' => array( '/provenance', '/provhub' ) ),
		'resume'     => array( 'label' => __( 'Resume', 'signal-and-noise-tools' ), 'prefixes' => array( '/resume' ) ),
		'about'      => array( 'label' => __( 'About', 'signal-and-noise-tools' ), 'prefixes' => array( '/about' ) ),
	);
}

/**
 * The effective config, clamped. Unknown sections are dropped; an empty
 * selection falls back to every section rather than a star that reads zero.
 *
 * @return array{sections:string[],prefixes:string[],scroll:int,dwell_ms:int}
 */
function snt_nsm_config() {
	$raw  = function_exists( 'sn_setting' ) ? (array) sn_setting( 'north_star', array() ) : array();
	$all  = snt_nsm_sections();
	$keys = array_values( array_intersect( array_keys( $all ), (array) ( $raw['sections'] ?? array() ) ) );
	if ( array() === $keys ) {
		$keys = array_keys( $all );
	}
	$prefixes = array();
	foreach ( $keys as $k ) {
		$prefixes = array_merge( $prefixes, $all[ $k ]['prefixes'] );
	}
	return array(
		'sections' => $keys,
		'prefixes' => $prefixes,
		'scroll'   => max( 1, min( 100, (int) ( $raw['scroll'] ?? 50 ) ) ),
		'dwell_ms' => 1000 * max( 1, min( 600, (int) ( $raw['dwell_s'] ?? 30 ) ) ),
	);
}

/**
 * Is a path core reading? The notes index, its pages, tags and feed are
 * listings, not a note, so they never count.
 *
 * @param string   $path     Request path.
 * @param string[] $prefixes Core prefixes.
 * @return bool
 */
function snt_nsm_is_core( $path, array $prefixes ) {
	$path = (string) $path;
	if ( 1 === preg_match( '#^/notes/?$|^/notes/(page|tags|feed)(/|$)#', $path ) ) {
		return false;
	}
	foreach ( $prefixes as $p ) {
		if ( 0 === strpos( $path, $p ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Tally visits into distinct visitor-days: readers, deep readers (two or more
 * core pages read), visitors who reached /resume or /contact, and visitors
 * who took a deliberate action (a tracked `download` or `outbound` event).
 *
 * @param array $visits Visits from sn_sessionize(): lists of event rows.
 * @param array $cfg    snt_nsm_config() shape.
 * @return array{readers:int,deep:int,career:int,intent:int}
 */
function snt_nsm_tally( array $visits, array $cfg ) {
	$pages = array(); // vid => path => [scroll, dwell]
	$seen  = array(); // vid => pv paths
	$acted = array(); // vid => true when a deliberate action fired
	foreach ( $visits as $visit ) {
		foreach ( $visit as $e ) {
			$vid = (string) ( $e['vid'] ?? '' );
			$p   = (string) ( $e['path'] ?? '' );
			$ev  = (string) ( $e['ev'] ?? '' );
			if ( 'ce' === $ev && '' !== $vid && in_array( (string) ( $e['ce'] ?? '' ), array( 'download', 'outbound' ), true ) ) {
				$acted[ $vid ] = true;
			}
			if ( '' === $vid || '' === $p ) {
				continue;
			}
			$cur = $pages[ $vid ][ $p ] ?? array( 0.0, 0.0 );
			if ( 'sc' === $ev ) {
				$cur[0] = max( $cur[0], (float) ( $e['scroll'] ?? 0 ) );
			} elseif ( 'tm' === $ev ) {
				$cur[1] = max( $cur[1], (float) ( $e['dwell'] ?? 0 ) );
			} elseif ( 'pv' === $ev ) {
				$seen[ $vid ][ $p ] = true;
			}
			$pages[ $vid ][ $p ] = $cur;
		}
	}
	$out = array( 'readers' => 0, 'deep' => 0, 'career' => 0, 'intent' => count( $acted ) );
	foreach ( $pages as $vid => $paths ) {
		$read = 0;
		foreach ( $paths as $p => $m ) {
			if ( isset( $seen[ $vid ][ $p ] ) && snt_nsm_is_core( $p, $cfg['prefixes'] )
				&& ( $m[0] >= $cfg['scroll'] || $m[1] >= $cfg['dwell_ms'] ) ) {
				++$read;
			}
		}
		$out['readers'] += $read > 0 ? 1 : 0;
		$out['deep']    += $read > 1 ? 1 : 0;
		foreach ( array_keys( $seen[ $vid ] ?? array() ) as $p ) {
			if ( 1 === preg_match( '#^/(resume|contact)(/|$)#', $p ) ) {
				++$out['career'];
				break;
			}
		}
	}
	return $out;
}

/**
 * Split visits into rolling 7-day weeks ending at $now (week 0 = the last 7
 * days), by each visit's first event.
 *
 * @param array $visits Visits.
 * @param int   $now    Epoch seconds.
 * @return array<int,array> week index => visits
 */
function snt_nsm_weeks( array $visits, $now ) {
	$weeks = array_fill( 0, SNT_NSM_WEEKS, array() );
	foreach ( $visits as $v ) {
		$i = (int) floor( ( $now - (int) ( $v[0]['ts'] ?? 0 ) ) / ( 7 * 86400 ) );
		if ( $i >= 0 && $i < SNT_NSM_WEEKS ) {
			$weeks[ $i ][] = $v;
		}
	}
	return $weeks;
}

require_once __DIR__ . '/north-star-reading.php';
require_once __DIR__ . '/north-star-settings.php';
