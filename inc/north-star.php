<?php
/**
 * The north star: weekly engaged readers.
 *
 * One number for what the site is for: people reading its core pages. A
 * reader is a human visitor-day (the analytics worker rotates the visitor hash
 * at midnight in its SN_ROTATE_TZ zone, America/New_York, not UTC, so the same
 * person on two such days counts twice, by design) with at least one core
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
const SNT_NSM_TREND     = 12; // weeks on Home's trend; the figures above keep four

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
 * who took a deliberate action (a tracked `download` or `outbound` event),
 * plus four named goals: a resume PDF download (a `download` on /resume), a
 * feed subscribe click (`subscribe`) and a share (`share_copy` or
 * `share_native`, fired by the theme's share row), and a signature check
 * (`verify`, the note's provenance chip).
 *
 * @param array $visits Visits from sn_sessionize(): lists of event rows.
 * @param array $cfg    snt_nsm_config() shape.
 * @return array{readers:int,deep:int,career:int,intent:int,resume_downloads:int,subscribes:int,shares:int,verifies:int}
 */
function snt_nsm_tally( array $visits, array $cfg ) {
	$acted = array(); // vid => true when a deliberate action fired
	$goals = array( 'resume_downloads' => array(), 'subscribes' => array(), 'shares' => array(), 'verifies' => array() ); // goal => vid => true
	foreach ( $visits as $visit ) {
		foreach ( $visit as $e ) {
			$vid = (string) ( $e['vid'] ?? '' );
			$p   = (string) ( $e['path'] ?? '' );
			$ce  = 'ce' === (string) ( $e['ev'] ?? '' ) ? (string) ( $e['ce'] ?? '' ) : '';
			if ( '' === $ce || '' === $vid ) {
				continue;
			}
			if ( in_array( $ce, array( 'download', 'outbound' ), true ) ) {
				$acted[ $vid ] = true;
			}
			if ( 'download' === $ce && 1 === preg_match( '#^/resume(/|$)#', $p ) ) {
				$goals['resume_downloads'][ $vid ] = true;
			} elseif ( 'subscribe' === $ce ) {
				$goals['subscribes'][ $vid ] = true;
			} elseif ( in_array( $ce, array( 'share_copy', 'share_native' ), true ) ) {
				$goals['shares'][ $vid ] = true;
			} elseif ( 'verify' === $ce ) {
				$goals['verifies'][ $vid ] = true;
			}
		}
	}
	$metrics = snt_nsm_page_metrics( $visits );
	$out = array(
		'readers'          => 0,
		'deep'             => 0,
		'career'           => 0,
		'intent'           => count( $acted ),
		'resume_downloads' => count( $goals['resume_downloads'] ),
		'subscribes'       => count( $goals['subscribes'] ),
		'shares'           => count( $goals['shares'] ),
		'verifies'         => count( $goals['verifies'] ),
	);
	foreach ( $metrics as $row ) {
		$read = 0;
		foreach ( $row['pages'] as $p => $m ) {
			if ( snt_nsm_is_core( $p, $cfg['prefixes'] ) && snt_nsm_is_read( $m, $cfg ) ) {
				++$read;
			}
		}
		$out['readers'] += $read > 0 ? 1 : 0;
		$out['deep']    += $read > 1 ? 1 : 0;
		foreach ( array_keys( $row['pages'] ) as $p ) {
			if ( 1 === preg_match( '#^/(resume|contact)(/|$)#', $p ) ) {
				++$out['career'];
				break;
			}
		}
	}
	return $out;
}

/**
 * THE per-page reading every north-star count shares (19.3.1: the tally and
 * the calibration each kept a copy): per visitor-day, the device and each
 * VIEWED page's [max scroll, summed dwell]. tm is a per-flush DELTA
 * (sn-beacon.js v10.44.4), so a read split by tab switches is the sum of its
 * slices. A page with scroll or time but no pageview is not a page read. PURE.
 *
 * @param array $visits Visits from sn_sessionize().
 * @return array<string,array{device:string,pages:array<string,array{0:float,1:float}>}>
 */
function snt_nsm_page_metrics( array $visits ) {
	$out  = array();
	$seen = array();
	foreach ( $visits as $visit ) {
		foreach ( $visit as $e ) {
			$vid = (string) ( $e['vid'] ?? '' );
			$p   = (string) ( $e['path'] ?? '' );
			if ( '' === $vid || '' === $p ) {
				continue;
			}
			$d = (string) ( $e['device'] ?? '' );
			if ( ! isset( $out[ $vid ] ) ) {
				$out[ $vid ] = array( 'device' => $d, 'pages' => array() );
			} elseif ( '' === $out[ $vid ]['device'] && '' !== $d ) {
				$out[ $vid ]['device'] = $d;
			}
			$cur = $out[ $vid ]['pages'][ $p ] ?? array( 0.0, 0.0 );
			$ev  = (string) ( $e['ev'] ?? '' );
			if ( 'sc' === $ev ) {
				$cur[0] = max( $cur[0], (float) ( $e['scroll'] ?? 0 ) );
			} elseif ( 'tm' === $ev ) {
				$cur[1] += (float) ( $e['dwell'] ?? 0 );
			} elseif ( 'pv' === $ev ) {
				$seen[ $vid ][ $p ] = true;
			}
			$out[ $vid ]['pages'][ $p ] = $cur;
		}
	}
	foreach ( $out as $vid => $row ) {
		$out[ $vid ]['pages'] = array_intersect_key( $row['pages'], $seen[ $vid ] ?? array() );
	}
	return $out;
}

/**
 * Engaged visitor-days: those with ANY viewed page that met the read floor
 * (snt_nsm_is_read, the north star's own rule; no section restriction). PURE.
 *
 * @param array $visits Visits from sn_sessionize().
 * @param array $cfg    snt_nsm_config() shape.
 * @return int
 */
function snt_nsm_engaged( array $visits, array $cfg ) {
	$n = 0;
	foreach ( snt_nsm_page_metrics( $visits ) as $row ) {
		foreach ( $row['pages'] as $m ) {
			if ( snt_nsm_is_read( $m, $cfg ) ) {
				++$n;
				break;
			}
		}
	}
	return $n;
}

/**
 * Is one page's [scroll, dwell] a read under the live rule? PURE.
 *
 * @param array $m   [max scroll, summed dwell ms].
 * @param array $cfg snt_nsm_config() shape.
 * @return bool
 */
function snt_nsm_is_read( array $m, array $cfg ) {
	return $m[0] >= $cfg['scroll'] || $m[1] >= $cfg['dwell_ms'];
}

/**
 * Split visits into rolling 7-day weeks ending at $now (week 0 = the last 7
 * days), by each visit's first event.
 *
 * @param array $visits Visits.
 * @param int   $now    Epoch seconds.
 * @return array<int,array> week index => visits
 */
function snt_nsm_weeks( array $visits, $now, $count = SNT_NSM_WEEKS ) {
	$count = max( 1, (int) $count );
	$weeks = array_fill( 0, $count, array() );
	foreach ( $visits as $v ) {
		$i = (int) floor( ( $now - (int) ( $v[0]['ts'] ?? 0 ) ) / ( 7 * 86400 ) );
		if ( $i >= 0 && $i < $count ) {
			$weeks[ $i ][] = $v;
		}
	}
	return $weeks;
}

require_once __DIR__ . '/north-star-reading.php';
require_once __DIR__ . '/north-star-settings.php';
require_once __DIR__ . '/north-star-return.php';
require_once __DIR__ . '/north-star-research.php';
require_once __DIR__ . '/north-star-sweep.php';
