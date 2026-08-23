<?php
/**
 * Signal & Noise — cookieless within-day session engine (v8.8.0).
 *
 * Reads the existing sn_pageviews Analytics Engine data as WITHIN-DAY visits.
 * index1 (the daily-rotating visitor hash) may be grouped ONLY inside a single
 * UTC day — never across days (the salt rotates at UTC midnight, so cross-day
 * stitching is impossible by construction). No cookie, no device storage, no
 * consent trigger. This is the deliberate, documented revision of the prior
 * "index1 is count-only" principle.
 *
 * Pure transforms only — NO top-level add_action/add_filter, so the module loads
 * standalone under the CLI test harness.
 *
 * @package SignalNoiseTools
 * @since 8.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SESSION_GAP_SEC     = 1800;  // >30 min idle starts a new visit.
const SN_ANALYTICS_SESSION_ENGAGED_PCT = 50;    // engaged read: scroll depth % floor.
const SN_ANALYTICS_SESSION_ENGAGED_MS  = 15000; // engaged read: dwell ms floor.
const SN_ANALYTICS_SESSION_ROW_CAP     = 50000; // max raw rows pulled per window.

// S2 §3 (settings-defined funnels): textarea-parser caps. Kept small — a
// funnel list is a curated set of named conversion paths, not an open log.
const SN_ANALYTICS_FUNNELS_MAX       = 10; // max funnels the parser will accept.
const SN_ANALYTICS_FUNNELS_MAX_STEPS = 8;  // max steps per funnel.

// Reason-surfacing task: the closed six-kind enum for sn_analytics_parse_funnels()
// errors. Order is the STABLE encoding sn_handle_analytics_funnels_save()
// (inc/admin-post-actions/analytics.php) packs into the flash code's <line>k<kindIndex>
// pairs, and inc/admin-flash-messages.php decodes back into a reason line —
// append new kinds at the END only; never reorder or remove an entry, or an
// already-redirected flash code would decode to the WRONG reason.
const SN_ANALYTICS_FUNNELS_ERR_KINDS = array( 'colon', 'name', 'long', 'step', 'few', 'many' );

/**
 * Filterable session-engine config. Constants are the defaults; the
 * 'sn_analytics_session_config' filter lets a site override any key.
 *
 * @return array{gap_sec:int,engaged_scroll:int,engaged_ms:int,row_cap:int}
 */
function sn_analytics_session_config() {
	$cfg = array(
		'gap_sec'        => SN_ANALYTICS_SESSION_GAP_SEC,
		'engaged_scroll' => SN_ANALYTICS_SESSION_ENGAGED_PCT,
		'engaged_ms'     => SN_ANALYTICS_SESSION_ENGAGED_MS,
		'row_cap'        => SN_ANALYTICS_SESSION_ROW_CAP,
	);
	$out = (array) apply_filters( 'sn_analytics_session_config', $cfg );
	// Coerce back to ints so a bad filter can't poison the SQL/int math.
	return array(
		'gap_sec'        => max( 60, (int) ( $out['gap_sec'] ?? $cfg['gap_sec'] ) ),
		'engaged_scroll' => max( 0, min( 100, (int) ( $out['engaged_scroll'] ?? $cfg['engaged_scroll'] ) ) ),
		'engaged_ms'     => max( 0, (int) ( $out['engaged_ms'] ?? $cfg['engaged_ms'] ) ),
		'row_cap'        => max( 100, (int) ( $out['row_cap'] ?? $cfg['row_cap'] ) ),
	);
}

/**
 * Auto-derived + optional owner-defined funnels. A site can configure named
 * funnels via Measurement → Analytics (Settings → analytics.funnels, S2 §3) or
 * add/override them in code via the 'sn_analytics_session_funnels' filter;
 * nothing is required for the view to work (transitions + quality render
 * regardless).
 *
 * Precedence: a non-empty, well-formed analytics.funnels setting REPLACES the
 * hardcoded two below; an empty/absent/corrupt setting falls back to them.
 * The filter always runs last, over either source, so code-level overrides
 * still win.
 *
 * @since 8.8.0
 * @since S2 (v9.42.0 arc) settings-defined funnels via analytics.funnels.
 * @return array List of array{title:string,steps:array}.
 */
function sn_analytics_session_funnels() {
	$hardcoded = array(
		array(
			'title' => __( 'Home → post → subscribe', 'signal-and-noise-tools' ),
			'steps' => array(
				array( 'match' => 'path', 'value' => '/', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/notes/', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'subscribe', 'prefix' => false ),
			),
		),
		array(
			// The site's commercial conversion path. The theme (v10.28.0) tags each
			// /contact email alias with data-sn-goal="contact-<alias>", so the final
			// step matches the whole contact-* family via the ce-prefix flag.
			'title' => __( 'Services → contact → email', 'signal-and-noise-tools' ),
			'steps' => array(
				array( 'match' => 'path', 'value' => '/services', 'prefix' => true ),
				array( 'match' => 'path', 'value' => '/contact', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'contact-', 'prefix' => true ),
			),
		),
	);

	$configured = sn_setting( 'analytics.funnels', array() );
	$funnels    = sn_analytics_funnels_resolve_setting( $configured, $hardcoded );

	return (array) apply_filters( 'sn_analytics_session_funnels', $funnels );
}

/**
 * Resolve the analytics.funnels setting against the hardcoded fallback.
 *
 * Defensive: a non-array setting, an empty setting, or a setting whose entries
 * don't carry the funnel shape sn_funnel_report() needs (not themselves an
 * array, missing 'title'/'steps', 'steps' not itself an array, or any step
 * ELEMENT not itself an array — a corrupt/hand-edited option value) all fall
 * back to the hardcoded defaults wholesale — never a partial/best-effort mix,
 * so a bad option value can't silently drop a funnel out from under
 * sn_funnel_report(), and a malformed entry can't reach the Visits view's
 * rendering loop at all. The per-step check matters under LIVE data: a string
 * step element would pass every outer check yet TypeError-fatal
 * sn_funnel_step_matches(array $step, …) the moment a visit has events.
 *
 * @since S2 (v9.42.0 arc)
 * @param mixed $configured The raw analytics.funnels setting value.
 * @param array $hardcoded  The hardcoded fallback funnel list.
 * @return array
 */
function sn_analytics_funnels_resolve_setting( $configured, array $hardcoded ) {
	if ( ! is_array( $configured ) || empty( $configured ) ) {
		return $hardcoded;
	}
	foreach ( $configured as $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['title'], $entry['steps'] ) || ! is_array( $entry['steps'] ) ) {
			return $hardcoded;
		}
		foreach ( $entry['steps'] as $step ) {
			if ( ! is_array( $step ) ) {
				return $hardcoded;
			}
		}
	}
	return $configured;
}

/**
 * Build one sn_analytics_parse_funnels() error entry — both the flat "Line N:
 * reason" string existing callers/tests expect (unchanged shape, unchanged
 * bytes) AND a parallel structured {line,kind,message} record.
 *
 * The "Line N: " prefix is a fixed, NON-translated literal (not run through
 * __()) so it renders identically regardless of the site's locale — only
 * $reason (the human-readable part after the prefix) is translated.
 *
 * Reason-surfacing task: $kind is the new machine-stable member of
 * SN_ANALYTICS_FUNNELS_ERR_KINDS naming WHICH of the six rejections this is.
 * sn_analytics_parse_funnels() pushes ['message'] onto its flat $errors list
 * (unchanged consumer contract) and the whole return value onto its new
 * $errors_detail list — sn_handle_analytics_funnels_save()
 * (inc/admin-post-actions/analytics.php) reads $kind to encode the flash code without
 * ever regexing the human string.
 *
 * @since S2 (v9.42.0 arc); $kind param added (reason-surfacing task).
 * @param int    $line_num 1-based source line number.
 * @param string $kind     One of SN_ANALYTICS_FUNNELS_ERR_KINDS.
 * @param string $reason   Already-translated explanation text.
 * @return array{line:int,kind:string,message:string}
 */
function sn_analytics_funnels_error( $line_num, $kind, $reason ) {
	$line_num = (int) $line_num;
	return array(
		'line'    => $line_num,
		'kind'    => (string) $kind,
		'message' => 'Line ' . $line_num . ': ' . $reason,
	);
}

/**
 * The single-sourced reason text (no "Line N: " prefix) for one
 * SN_ANALYTICS_FUNNELS_ERR_KINDS entry. sn_analytics_parse_funnels() calls
 * this directly for the five kinds whose wording never varies (colon / name /
 * long / step / few), so the flash-code round trip — inc/admin-post-actions/analytics.php
 * encodes the $kind, inc/admin-flash-messages.php later decodes it back
 * through THIS SAME function — can never drift from what the parser actually
 * said.
 *
 * 'many' bundles TWO parser call sites (too many funnels vs too many steps,
 * each with its own configured max baked into a sprintf'd number) into one
 * kind — the flash code has no room to carry which sub-case or number fired,
 * so this returns a generic fallback the RENDERER uses for that kind; the
 * parser keeps emitting its own precise, numbered message directly (not
 * through this fn) into $parsed['errors'] for the actual reject-and-explain path.
 *
 * @since (reason-surfacing task)
 * @param string $kind One of SN_ANALYTICS_FUNNELS_ERR_KINDS.
 * @return string Translated reason text, or '' for an unrecognized kind.
 */
function sn_analytics_funnels_kind_message( $kind ) {
	switch ( $kind ) {
		case 'colon':
			return __( 'missing ":": expected "Name: /step > /step".', 'signal-and-noise-tools' );
		case 'name':
			return __( 'funnel name is empty.', 'signal-and-noise-tools' );
		case 'long':
			return __( 'line is too long (name max 80 chars, steps max 200).', 'signal-and-noise-tools' );
		case 'step':
			return __( 'a step contains a space or ":": check for an extra ":" earlier in the line.', 'signal-and-noise-tools' );
		case 'few':
			return __( 'needs at least 2 steps.', 'signal-and-noise-tools' );
		case 'many':
			return __( 'too many funnels or steps: the limit was exceeded and this line wasn\'t saved.', 'signal-and-noise-tools' );
	}
	return '';
}

/**
 * Parse the Measurement → Analytics "Session funnels" textarea into the funnel
 * shape sn_analytics_session_funnels() returns — one funnel per line:
 *
 *     Name: /entry > /step > /goal
 *
 * Every parsed step is an exact-match path step (array{match:'path',value,
 * prefix:false}) — the textarea format has no syntax for prefix matching or
 * custom-event ('ce') goals; those remain code-only via the
 * 'sn_analytics_session_funnels' filter.
 *
 * Rejections (each recorded as one errors[] entry naming the 1-based line
 * number, in the caller-facing text a flash notice can show verbatim, AND one
 * errors_detail[] entry — reason-surfacing task — carrying the same info
 * structured as {line,kind,message}):
 *   - a line with no ':' separator                              (kind: colon)
 *   - an empty funnel name                                      (kind: name)
 *   - a step that (after leading-slash normalization) contains whitespace or a
 *     ':' — a well-formed path step has neither, so this catches malformed
 *     shapes upstream (most commonly a double-colon line like "Name:: /a > /b",
 *     whose OWN extra ':' used to survive into the first step as "/: /a")
 *                                                                 (kind: step)
 *   - fewer than 2 steps                                         (kind: few)
 *   - more than SN_ANALYTICS_FUNNELS_MAX_STEPS steps             (kind: many)
 *   - more than SN_ANALYTICS_FUNNELS_MAX funnel lines            (kind: many)
 *   - name over 80 chars or steps segment over 200 chars         (kind: long)
 *
 * @since S2 (v9.42.0 arc); errors_detail added (reason-surfacing task).
 * @param string $raw Raw textarea content (already wp_unslash()ed by the caller).
 * @return array{funnels:array,errors:array<string>,errors_detail:array<array{line:int,kind:string,message:string}>}
 */
function sn_analytics_parse_funnels( $raw ) {
	$raw = (string) $raw;
	if ( '' === trim( $raw ) ) {
		return array(
			'funnels'       => array(),
			'errors'        => array(),
			'errors_detail' => array(),
		);
	}

	$funnels       = array();
	$errors        = array();
	$errors_detail = array();
	$lines_seen    = 0;
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $i => $raw_line ) {
		$line_num = $i + 1;
		$line     = trim( (string) $raw_line );
		if ( '' === $line ) {
			continue;
		}

		++$lines_seen;
		if ( $lines_seen > SN_ANALYTICS_FUNNELS_MAX ) {
			$err = sn_analytics_funnels_error(
				$line_num,
				'many',
				sprintf(
					/* translators: %d: max funnel count */
					__( 'too many funnels (max %d): this line was skipped.', 'signal-and-noise-tools' ),
					SN_ANALYTICS_FUNNELS_MAX
				)
			);
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		if ( false === strpos( $line, ':' ) ) {
			$err             = sn_analytics_funnels_error( $line_num, 'colon', sn_analytics_funnels_kind_message( 'colon' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		list( $name_raw, $steps_raw ) = explode( ':', $line, 2 );
		$name                         = trim( $name_raw );
		if ( '' === $name ) {
			$err             = sn_analytics_funnels_error( $line_num, 'name', sn_analytics_funnels_kind_message( 'name' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}
		// Length clamps (S2 §8, final review): admin-only + escaped everywhere,
		// so the risk is option bloat, not injection — but a 10k-char paste is
		// never a funnel. 80/200 comfortably exceed any real name/path.
		if ( strlen( $name ) > 80 || strlen( $steps_raw ) > 200 ) {
			$err             = sn_analytics_funnels_error( $line_num, 'long', sn_analytics_funnels_kind_message( 'long' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		$steps    = array();
		$bad_step = false;
		foreach ( explode( '>', $steps_raw ) as $step_raw ) {
			$step = trim( $step_raw );
			if ( '' === $step ) {
				continue;
			}
			if ( '/' !== substr( $step, 0, 1 ) ) {
				$step = '/' . $step;
			}
			// A well-formed path step has neither whitespace nor a ':' — either one
			// means the line's shape was wrong upstream (the double-colon case: the
			// first colon splits name/steps, so a SECOND colon rides along inside
			// what looks like the first step's value instead of erroring cleanly).
			if ( 1 === preg_match( '/[\s:]/', $step ) ) {
				$bad_step = true;
				break;
			}
			$steps[] = $step;
		}

		if ( $bad_step ) {
			$err             = sn_analytics_funnels_error( $line_num, 'step', sn_analytics_funnels_kind_message( 'step' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		if ( count( $steps ) < 2 ) {
			$err             = sn_analytics_funnels_error( $line_num, 'few', sn_analytics_funnels_kind_message( 'few' ) );
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}
		if ( count( $steps ) > SN_ANALYTICS_FUNNELS_MAX_STEPS ) {
			$err = sn_analytics_funnels_error(
				$line_num,
				'many',
				sprintf(
					/* translators: %d: max step count */
					__( 'too many steps (max %d).', 'signal-and-noise-tools' ),
					SN_ANALYTICS_FUNNELS_MAX_STEPS
				)
			);
			$errors[]        = $err['message'];
			$errors_detail[] = $err;
			continue;
		}

		$funnels[] = array(
			'title' => $name,
			'steps' => array_map(
				function ( $path ) {
					return array(
						'match'  => 'path',
						'value'  => $path,
						'prefix' => false,
					);
				},
				$steps
			),
		);
	}

	return array(
		'funnels'       => $funnels,
		'errors'        => $errors,
		'errors_detail' => $errors_detail,
	);
}

/**
 * Serialize a funnel list back into the "Name: /a > /b" textarea format
 * sn_analytics_parse_funnels() reads — the inverse operation, used to prefill
 * the Session funnels card with the CURRENTLY configured analytics.funnels
 * setting so the owner edits what is actually live, not a blank box.
 *
 * A funnel is REPRESENTABLE — and serializes as one line — only when its
 * emitted line would parse back to the SAME funnel:
 *   - every step is {match:'path', prefix:false} (the textarea format has no
 *     syntax for 'ce' goals or prefix matching),
 *   - every step value starts with '/' and contains no whitespace, '>' or ':'
 *     (a '>' would re-parse as EXTRA steps — silent corruption; ':' or
 *     whitespace would make the parser REJECT the line, wedging every future
 *     save on a line the owner never typed),
 *   - the title contains no ':' and no newline (a second colon would re-split
 *     name/steps at the wrong place; a newline would split into bogus lines).
 * Anything else is OMITTED entirely — inventing a comment syntax the parser
 * doesn't understand would be dishonest (it would look editable but silently
 * vanish on save). Such funnels stay active via the
 * 'sn_analytics_session_funnels' filter; the render layer's help text says so.
 *
 * ROUND-TRIP GUARANTEE: for any $funnels that is itself the ['funnels'] output
 * of sn_analytics_parse_funnels() (i.e. already normalized — trimmed titles,
 * leading-slash path values, exact-match steps, within the count/step caps),
 * parse( to_text( $funnels ) )['funnels'] === $funnels. Parser output always
 * satisfies the representability rules above, so nothing it produced is ever
 * omitted.
 *
 * @since S2 (v9.42.0 arc)
 * @param array $funnels List of array{title:string,steps:array}.
 * @return string Textarea-ready text, one funnel per line (possibly '').
 */
function sn_analytics_funnels_to_text( array $funnels ) {
	$lines = array();
	foreach ( $funnels as $funnel ) {
		if ( ! is_array( $funnel ) ) {
			continue;
		}
		$title = isset( $funnel['title'] ) ? trim( (string) $funnel['title'] ) : '';
		$steps = isset( $funnel['steps'] ) && is_array( $funnel['steps'] ) ? $funnel['steps'] : array();
		if ( '' === $title || 1 === preg_match( '/[:\r\n]/', $title ) || empty( $steps ) ) {
			continue;
		}

		$values        = array();
		$representable = true;
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || 'path' !== ( $step['match'] ?? '' ) || ! empty( $step['prefix'] ) ) {
				$representable = false;
				break;
			}
			$value = (string) ( $step['value'] ?? '' );
			// Round-trip guard: only a value the parser could itself have produced
			// re-parses to itself (leading '/', no whitespace/'>'/':').
			if ( '/' !== substr( $value, 0, 1 ) || 1 === preg_match( '/[\s>:]/', $value ) ) {
				$representable = false;
				break;
			}
			$values[] = $value;
		}

		if ( ! $representable || count( $values ) < 2 ) {
			continue;
		}
		$lines[] = $title . ': ' . implode( ' > ', $values );
	}
	return implode( "\n", $lines );
}

/**
 * Group raw events into within-day visits.
 *
 * @param array $rows    Rows with keys vid, ts (int epoch), ev, path, ref, ce, scroll, dwell.
 * @param int   $gap_sec Idle gap that starts a new visit. Default from config.
 * @return array List of visits; each visit is an ordered list of event rows.
 */
function sn_sessionize( array $rows, $gap_sec = SN_ANALYTICS_SESSION_GAP_SEC ) {
	$gap_sec = max( 1, (int) $gap_sec );

	// Bucket by visitor id.
	$by_vid = array();
	foreach ( $rows as $r ) {
		$vid = isset( $r['vid'] ) ? (string) $r['vid'] : '';
		if ( '' === $vid ) {
			continue;
		}
		$r['ts']          = (int) ( $r['ts'] ?? 0 );
		$by_vid[ $vid ][] = $r;
	}

	$visits = array();
	foreach ( $by_vid as $events ) {
		usort(
			$events,
			function ( $a, $b ) {
				return $a['ts'] <=> $b['ts'];
			}
		);
		$current = array();
		$prev_ts = null;
		foreach ( $events as $e ) {
			if ( null !== $prev_ts && ( $e['ts'] - $prev_ts ) > $gap_sec ) {
				$visits[]  = $current;
				$current   = array();
			}
			$current[] = $e;
			$prev_ts   = $e['ts'];
		}
		// $events is never empty (only populated vids reach $by_vid), so the inner
		// loop always ran and $current holds at least the final event.
		$visits[] = $current;
	}
	return $visits;
}

/**
 * Summarize one visit (list of ordered events) into a struct.
 *
 * Known simplification: if a path is viewed more than once in a visit, scroll
 * and dwell are attributed to that path in aggregate (max), not to a specific
 * repeat view — acceptable at this site's volume.
 *
 * @param array $events         Ordered events of a single visit.
 * @param int   $engaged_scroll Scroll % floor for an engaged read.
 * @param int   $engaged_ms     Dwell ms floor for an engaged read.
 * @return array Visit summary struct.
 */
function sn_visit_summary( array $events, $engaged_scroll = SN_ANALYTICS_SESSION_ENGAGED_PCT, $engaged_ms = SN_ANALYTICS_SESSION_ENGAGED_MS ) {
	$path       = array();
	$goals      = array();
	$max_scroll = array(); // path => max scroll %
	$max_dwell  = array(); // path => max dwell ms
	$first_ts   = null;
	$last_ts    = null;
	$seq        = array(); // compact ordered events for funnel matching

	foreach ( $events as $e ) {
		$ts       = (int) ( $e['ts'] ?? 0 );
		$first_ts = ( null === $first_ts ) ? $ts : min( $first_ts, $ts );
		$last_ts  = ( null === $last_ts ) ? $ts : max( $last_ts, $ts );
		$type     = (string) ( $e['ev'] ?? '' );
		$p        = (string) ( $e['path'] ?? '' );
		$seq[]    = array( 'ev' => $type, 'path' => $p, 'ce' => (string) ( $e['ce'] ?? '' ) );

		if ( 'pv' === $type ) {
			$path[] = $p;
		} elseif ( 'sc' === $type ) {
			$max_scroll[ $p ] = max( $max_scroll[ $p ] ?? 0, (float) ( $e['scroll'] ?? 0 ) );
		} elseif ( 'tm' === $type ) {
			$max_dwell[ $p ] = max( $max_dwell[ $p ] ?? 0, (float) ( $e['dwell'] ?? 0 ) );
		} elseif ( 'ce' === $type ) {
			$name = (string) ( $e['ce'] ?? '' );
			if ( '' !== $name ) {
				$goals[] = $name;
			}
		}
	}

	$engaged = false;
	foreach ( $path as $p ) {
		if ( ( $max_scroll[ $p ] ?? 0 ) >= $engaged_scroll && ( $max_dwell[ $p ] ?? 0 ) >= $engaged_ms ) {
			$engaged = true;
			break;
		}
	}

	return array(
		'entry'     => $path ? $path[0] : '',
		'exit'      => $path ? $path[ count( $path ) - 1 ] : '',
		'path'      => $path,
		'pageviews' => count( $path ),
		'duration'  => ( null === $first_ts ) ? 0 : ( $last_ts - $first_ts ),
		'engaged'   => $engaged,
		'goals'     => array_values( array_unique( $goals ) ),
		'events'    => $seq,
	);
}

/**
 * Keep only visits that contain at least one pageview.
 *
 * A "visit" = a within-day index1 group with >= 1 pageview. Groups made only of
 * server events (srv:1 / RSS ce), scroll, or timing beacons with NO pageview are
 * not visits and belong to the Events view — an RSS feed reader polling hourly
 * would otherwise gap-split into dozens of phantom pageview-less "visits" and
 * corrupt bounce / pages-per-visit / median-duration.
 *
 * @param array $summaries Visit summaries from sn_visit_summary().
 * @return array Re-indexed list of summaries with pageviews >= 1.
 */
function sn_pageview_visits( array $summaries ) {
	$visits = array();
	foreach ( $summaries as $s ) {
		if ( (int) ( $s['pageviews'] ?? 0 ) >= 1 ) {
			$visits[] = $s;
		}
	}
	return $visits;
}

/**
 * Aggregate visit-quality metrics from a list of visit summaries.
 *
 * @param array $summaries Visit summaries from sn_visit_summary().
 * @return array{visits:int,bounce_rate:float,pages_per_visit:float,median_duration:int,engaged_visits:int,engaged_rate:float}
 */
function sn_session_metrics( array $summaries ) {
	$n = count( $summaries );
	if ( 0 === $n ) {
		return array(
			'visits'          => 0,
			'bounce_rate'     => 0.0,
			'pages_per_visit' => 0.0,
			'median_duration' => 0,
			'engaged_visits'  => 0,
			'engaged_rate'    => 0.0,
		);
	}

	$bounces    = 0;
	$pv_total   = 0;
	$engaged    = 0;
	$durations  = array();
	foreach ( $summaries as $s ) {
		$pv = (int) ( $s['pageviews'] ?? 0 );
		$pv_total += $pv;
		if ( $pv <= 1 ) {
			$bounces++;
		}
		if ( ! empty( $s['engaged'] ) ) {
			$engaged++;
		}
		$durations[] = (int) ( $s['duration'] ?? 0 );
	}

	sort( $durations );
	$mid    = intdiv( $n, 2 );
	$median = ( 0 === $n % 2 )
		? (int) round( ( $durations[ $mid - 1 ] + $durations[ $mid ] ) / 2 )
		: $durations[ $mid ];

	return array(
		'visits'          => $n,
		'bounce_rate'     => $bounces / $n,
		'pages_per_visit' => $pv_total / $n,
		'median_duration' => $median,
		'engaged_visits'  => $engaged,
		'engaged_rate'    => $engaged / $n,
	);
}

/**
 * Count consecutive page-to-page transitions across visits, most common first.
 *
 * @param array $summaries Visit summaries (each with a 'path' list).
 * @param int   $limit     Max transitions to return.
 * @return array List of array{from:string,to:string,count:int}.
 */
function sn_session_paths( array $summaries, $limit = 20 ) {
	$counts = array(); // "from\x00to" => count
	foreach ( $summaries as $s ) {
		$path = isset( $s['path'] ) ? array_values( (array) $s['path'] ) : array();
		for ( $i = 1, $len = count( $path ); $i < $len; $i++ ) {
			$key            = $path[ $i - 1 ] . "\x00" . $path[ $i ];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}
	arsort( $counts );
	$out = array();
	foreach ( $counts as $key => $count ) {
		list( $from, $to ) = explode( "\x00", $key, 2 );
		$out[] = array(
			'from'  => $from,
			'to'    => $to,
			'count' => (int) $count,
		);
		if ( count( $out ) >= (int) $limit ) {
			break;
		}
	}
	return $out;
}

/**
 * Does one compact event (from a summary 'events' list) satisfy a funnel step?
 *
 * @param array $step  array{match:string,value:string,prefix?:bool}.
 * @param array $event array{ev:string,path:string,ce:string}.
 * @return bool
 */
function sn_funnel_step_matches( array $step, array $event ) {
	$match  = (string) ( $step['match'] ?? '' );
	$value  = (string) ( $step['value'] ?? '' );
	$prefix = ! empty( $step['prefix'] );

	if ( 'path' === $match ) {
		if ( 'pv' !== (string) ( $event['ev'] ?? '' ) ) {
			return false;
		}
		$path = (string) ( $event['path'] ?? '' );
		return $prefix ? ( 0 === strncmp( $path, $value, strlen( $value ) ) ) : ( $path === $value );
	}
	if ( 'ce' === $match ) {
		if ( 'ce' !== (string) ( $event['ev'] ?? '' ) ) {
			return false;
		}
		$ce = (string) ( $event['ce'] ?? '' );
		// Honor the documented prefix flag, mirroring the 'path' branch — so a
		// single step (e.g. value 'contact-') captures a whole goal family
		// (contact-research, contact-press, …) the theme emits per alias.
		return $prefix ? ( 0 === strncmp( $ce, $value, strlen( $value ) ) ) : ( $ce === $value );
	}
	return false;
}

/**
 * Aggregate ordered-step completion across visits.
 *
 * @param array $summaries Visit summaries (each with an ordered 'events' list).
 * @param array $funnel    Ordered steps.
 * @return array List of array{label:string,reached:int,rate:float,drop:int}.
 */
function sn_funnel_report( array $summaries, array $funnel ) {
	$steps = array_values( $funnel );
	$n     = count( $steps );
	if ( 0 === $n ) {
		return array();
	}
	$reached = array_fill( 0, $n, 0 );

	foreach ( $summaries as $s ) {
		$events = isset( $s['events'] ) ? (array) $s['events'] : array();
		$idx    = 0;
		foreach ( $events as $e ) {
			if ( $idx >= $n ) {
				break;
			}
			if ( sn_funnel_step_matches( $steps[ $idx ], (array) $e ) ) {
				$reached[ $idx ]++;
				$idx++;
			}
		}
	}

	$first = $reached[0] > 0 ? $reached[0] : 0;
	$out   = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$label = (string) ( $steps[ $i ]['value'] ?? ( 'step ' . ( $i + 1 ) ) );
		$prev  = $i > 0 ? $reached[ $i - 1 ] : $reached[ $i ];
		$out[] = array(
			'label'   => $label,
			'reached' => $reached[ $i ],
			'rate'    => ( $first > 0 ) ? ( $reached[ $i ] / $first ) : 0.0,
			'drop'    => ( $i > 0 ) ? max( 0, $prev - $reached[ $i ] ) : 0,
		);
	}
	return $out;
}

/**
 * Attribute converting visits to their entry page.
 *
 * For each visit whose goals include one matching $goal_value (prefix or exact),
 * credit its entry page once — so the result answers "where did the visitors who
 * actually converted first land?" (e.g. did /services feed the contact form, or
 * did people reach /contact directly?). A visit is counted once even if it fired
 * two matching goals: this counts converting VISITS, mirroring sn_funnel_report's
 * visit semantics, not raw conversion events. Runs over the same within-day
 * summaries the Visits view already holds — no extra query. Cookieless and
 * aggregate: only the entry PATH is retained, never an identity.
 *
 * @param array  $summaries  Visit summaries (each with 'entry' + 'goals').
 * @param string $goal_value Goal name, or a family prefix (e.g. 'contact-').
 * @param bool   $prefix     Match $goal_value as a prefix. Default true.
 * @param int    $limit      Max rows returned (<= 0 = no cap). Default 10.
 * @return array List of array{entry:string,conversions:int}, desc by conversions.
 */
function sn_goal_attribution( array $summaries, $goal_value, $prefix = true, $limit = 10 ) {
	$value = (string) $goal_value;
	$vlen  = strlen( $value );
	$tally = array();
	foreach ( $summaries as $s ) {
		$goals = isset( $s['goals'] ) ? (array) $s['goals'] : array();
		$hit   = false;
		foreach ( $goals as $g ) {
			$g = (string) $g;
			if ( $prefix ? ( 0 === strncmp( $g, $value, $vlen ) ) : ( $g === $value ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$entry           = (string) ( $s['entry'] ?? '' );
		$entry           = ( '' !== $entry ) ? $entry : '(unknown)';
		$tally[ $entry ] = ( $tally[ $entry ] ?? 0 ) + 1;
	}
	$rows = array();
	foreach ( $tally as $entry => $count ) {
		$rows[] = array( 'entry' => $entry, 'conversions' => $count );
	}
	// Desc by conversions; entry name asc as a stable tiebreak (deterministic output).
	usort(
		$rows,
		function ( $a, $b ) {
			return ( $a['conversions'] === $b['conversions'] )
				? strcmp( $a['entry'], $b['entry'] )
				: ( $b['conversions'] - $a['conversions'] );
		}
	);
	if ( $limit > 0 && count( $rows ) > $limit ) {
		$rows = array_slice( $rows, 0, $limit );
	}
	return $rows;
}

/**
 * Build the AE SQL that pulls raw human events for sessionization. Returns ''
 * (so the caller no-ops) if the window or class is invalid. class is strictly
 * whitelisted and dates are format-validated — the only interpolated values —
 * so the string is injection-safe against the AE SQL API.
 *
 * @param string $from  Window start, 'Y-m-d'.
 * @param string $to    Window end, 'Y-m-d'.
 * @param string $class Traffic class (human/suspect/bot).
 * @param int    $cap   Row cap (LIMIT).
 * @return string SQL, or '' when inputs are invalid.
 */
function sn_analytics_session_sql( $from, $to, $class, $cap ) {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from )
		|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return '';
	}
	$allowed = defined( 'SN_ANALYTICS_CLASSES' ) ? SN_ANALYTICS_CLASSES : array( 'human', 'suspect', 'bot' );
	if ( ! in_array( (string) $class, $allowed, true ) ) {
		return '';
	}
	$cap     = max( 1, (int) $cap );
	$dataset = defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews';

	return implode(
		' ',
		array(
			'SELECT index1 AS vid, toUnixTimestamp(timestamp) AS ts,',
			'blob1 AS ev, blob2 AS path, blob3 AS ref, blob16 AS ce,',
			'double1 AS scroll, double2 AS dwell',
			'FROM ' . $dataset,
			// AE's SQL types are strict: the DateTime `timestamp` column cannot be
			// compared to a String literal (>= 422s), so wrap the validated bounds
			// in toDateTime(). $from/$to are regex-checked Y-m-d above.
			"WHERE timestamp >= toDateTime('{$from} 00:00:00') AND timestamp <= toDateTime('{$to} 23:59:59')",
			"AND blob7 = '{$class}'",
			"AND blob1 IN ('pv','sc','tm','ce')",
			// No ORDER BY: AE resolves ORDER BY against SELECT aliases (not raw
			// columns), so `index1`/`timestamp` both 422. sn_sessionize sorts each
			// visitor's events by ts in PHP anyway; the row cap only binds far above
			// this site's volume, where the `capped` flag already warns.
			"LIMIT {$cap}",
		)
	);
}

/**
 * Fetch + sessionize + summarize a window into visit summaries. Returns an
 * array with the summaries plus a 'capped' flag (true when the row cap was hit,
 * so the view can warn instead of silently truncating).
 *
 * @param string $from  Window start, 'Y-m-d'.
 * @param string $to    Window end, 'Y-m-d'.
 * @param string $class Traffic class.
 * @return array{summaries:array,visits:array,capped:bool,configured:bool}
 */
function sn_analytics_fetch_session_events( $from, $to, $class ) {
	$cfg = sn_analytics_session_config();
	$sql = sn_analytics_session_sql( $from, $to, $class, $cfg['row_cap'] );
	if ( '' === $sql || ! function_exists( 'sn_analytics_query' ) ) {
		return array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => false );
	}
	$rows = sn_analytics_query( $sql );
	if ( ! is_array( $rows ) ) {
		return array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => false );
	}
	$capped = count( $rows ) >= $cfg['row_cap'];
	$visits = sn_sessionize( $rows, $cfg['gap_sec'] );
	$summaries = array();
	foreach ( $visits as $v ) {
		$summaries[] = sn_visit_summary( $v, $cfg['engaged_scroll'], $cfg['engaged_ms'] );
	}
	return array( 'summaries' => $summaries, 'visits' => $visits, 'capped' => $capped, 'configured' => true );
}
