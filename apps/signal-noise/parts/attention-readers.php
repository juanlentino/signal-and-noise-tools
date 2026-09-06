<?php
/**
 * Signal & Noise app — the Attention queue's NINE READERS.
 *
 * THE SEAM: this is the only file in the queue that calls the estate. Its
 * siblings -- parts/attention.php -- hold the vocabulary, the composition, the
 * item shape and the descriptor, and touch no signal at all. Split at that
 * line, in that direction, because the two halves fail differently: a reader
 * breaks when the thing it reads changes shape, and the composition breaks
 * when the queue's own contract changes. tests/openstation-app-attention.php
 * pins the seam by name, so a tenth signal read from the wrong side of it is
 * a red test rather than a habit.
 *
 * EVERY READER, WITHOUT EXCEPTION:
 *
 *   1. gates on function_exists() -- an absent subsystem makes NO claim, and
 *      never a standing warning row (inc/watches.php:4-23: a row that is
 *      always there teaches its reader to stop looking);
 *   2. wraps its call in try/catch -- a subsystem that IS present and cannot
 *      answer yields one warning row saying so, never a zero;
 *   3. carries a stamp -- the measurement's own when it has one (a sweep, a
 *      scan, a probe, a capture), the read time when the fact is a live query
 *      and the store keeps no reading time;
 *   4. READS. It never starts a scan, a sweep, a probe or a re-check.
 *
 * The return contract is one shape:
 * `array( 'rows' => [...], 'stamp' => 'Y-m-d H:i:s'|'', 'unreadable' => bool )`.
 *
 * @package SignalNoiseTools
 * @since 13.103.0
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** How close a scheduled transition must be to be worth a row, in seconds. */
const ATTENTION_SOON = 86400;

/** How many citation rows the due-for-a-check query may read. */
const ATTENTION_CITATIONS_DUE_CAP = 50;

/** How stale a citation check may be before it is due, in days. */
const ATTENTION_CITATIONS_STALE_DAYS = 7;


/**
 * The last integrity sweep's failing notes.
 *
 * PURE over stored state: sn_prov_integrity_state() is one option read and the
 * failure sentences are sn_prov_integrity_failure_sentence()'s single table,
 * so the queue, the health check and the app's re-check verdict say the same
 * thing about the same leg. Findings ACCRUE across the rotation (ten notes per
 * sweep), which is why each row carries the note's own `last_checked` rather
 * than the sweep's: a row is "flagged as of when this note was last looked at".
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_integrity() {
	if ( ! function_exists( 'sn_prov_integrity_state' ) || ! function_exists( 'sn_prov_integrity_failure_sentence' ) ) {
		return attention_read();
	}
	try {
		$state = \sn_prov_integrity_state();
		if ( ! is_array( $state ) ) {
			// Null is "no sweep has ever run": the reader is installed and has
			// nothing to read, which is not the same as a clean fleet.
			return attention_unreadable();
		}
		$door  = attention_door( 'sn-tools', 'trust' );
		$rows  = array();
		$notes = isset( $state['notes'] ) && is_array( $state['notes'] ) ? $state['notes'] : array();
		foreach ( $notes as $pid => $note ) {
			$failures = ( isset( $note['failures'] ) && is_array( $note['failures'] ) ) ? array_values( $note['failures'] ) : array();
			if ( array() === $failures ) {
				continue;
			}
			$said = array();
			foreach ( $failures as $code ) {
				$said[] = (string) \sn_prov_integrity_failure_sentence( (string) $code );
			}
			$rows[] = attention_row( array(
				'kind'       => 'integrity',
				'key'        => (string) (int) $pid,
				'title'      => attention_post_title( (int) $pid, (string) ( $note['title'] ?? '' ) ),
				'subtitle'   => implode( '; ', $said ),
				'tone'       => 'danger',
				'stamp'      => attention_stamp( $note['last_checked'] ?? '' ),
				'source'     => __( 'The provenance integrity sweep', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Trust checks in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => (int) $pid,
			) );
		}
		// The FLEET-level key verdict rides the sweep summary, not a note: the
		// ledger's key file disagreeing with this site (or gone, or unreachable)
		// is one row about every signed subject at once, filed under the sweep.
		$keys = (string) ( $state['last_sweep']['keys'] ?? '' );
		if ( in_array( $keys, array( 'key_mismatch', 'keys_missing', 'keys_unreachable' ), true ) ) {
			// Word for word the sentences sn_prov_integrity_findings() files for
			// the same verdicts: the queue says what the findings say.
			$said = array(
				'key_mismatch'     => __( 'The public ledger\'s keys/provenance-keys.json no longer serves the published key id with the published key bytes (key mismatch): readers can no longer independently verify signatures.', 'signal-and-noise-tools' ),
				'keys_missing'     => __( 'The public ledger\'s keys/provenance-keys.json has 404ed for three consecutive sweeps: the key file is absent from the ledger, not blipping. Readers cannot independently cross-check signatures until it is restored.', 'signal-and-noise-tools' ),
				'keys_unreachable' => __( 'The public ledger\'s keys/provenance-keys.json could not be reached (unreachable: an outage, not drift, not a key rotation).', 'signal-and-noise-tools' ),
			);
			$rows[] = attention_row( array(
				'kind'       => 'integrity',
				'key'        => 'keys',
				'title'      => __( 'The ledger\'s key file', 'signal-and-noise-tools' ),
				'subtitle'   => $said[ $keys ],
				'tone'       => 'keys_unreachable' === $keys ? 'warning' : 'danger',
				'stamp'      => attention_stamp( $state['last_sweep']['swept_at'] ?? '' ),
				'source'     => __( 'The provenance integrity sweep', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Trust checks in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => 0,
			) );
		}
		return attention_read( $rows, attention_stamp( $state['last_sweep']['swept_at'] ?? '' ) );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Commits still unanchored or in flight.
 *
 * sn_prov_admin_status(), NOT snt_prov_anchor_overview(): the overview queries
 * `post_type => post` only, so a signed page never appears in it, and it does
 * not report `unanchored` at all. This reader covers both statuses and both
 * subject kinds, which is what the reconcile and integrity sweeps cover.
 *
 * There is no "long-pending" threshold anywhere in the estate, so none is
 * invented here: the row says how long, and lets the reader judge.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_anchors() {
	if ( ! function_exists( 'sn_prov_admin_status' ) ) {
		return attention_read();
	}
	try {
		$status = \sn_prov_admin_status();
		if ( ! is_array( $status ) ) {
			return attention_unreadable();
		}
		$door    = attention_door( 'sn-tools', 'provenance' );
		$rows    = array();
		$newest  = '';
		foreach ( (array) ( $status['pending'] ?? array() ) as $commit ) {
			if ( ! is_array( $commit ) ) {
				continue;
			}
			$state = (string) ( $commit['status'] ?? '' );
			if ( 'pending' !== $state && 'unanchored' !== $state ) {
				continue;
			}
			$pid     = (int) ( $commit['post_id'] ?? 0 );
			$version = (int) ( $commit['version'] ?? 0 );
			$stamp   = attention_stamp( $commit['committed_at'] ?? '' );
			$since   = '' !== $stamp ? $stamp . ' UTC' : __( 'an unrecorded time', 'signal-and-noise-tools' );
			$rows[]  = attention_row( array(
				'kind'       => 'anchors',
				'key'        => $pid . '-v' . $version,
				'title'      => attention_post_title( $pid, (string) ( $commit['note_uid'] ?? '' ) ),
				'subtitle'   => 'unanchored' === $state
					/* translators: 1: commit version. 2: a UTC timestamp. */
					? sprintf( __( 'v%1$d unanchored since %2$s', 'signal-and-noise-tools' ), $version, $since )
					/* translators: 1: commit version. 2: a UTC timestamp. */
					: sprintf( __( 'v%1$d pending since %2$s', 'signal-and-noise-tools' ), $version, $since ),
				// Unanchored is the Worker never answering; pending is a proof
				// in flight, which is the system working.
				'tone'       => 'unanchored' === $state ? 'warning' : 'neutral',
				'stamp'      => $stamp,
				'source'     => __( 'The signed commit chain', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Provenance in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => $pid,
			) );
			if ( $stamp > $newest ) {
				$newest = $stamp;
			}
		}
		return attention_read( $rows, $newest );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Notes whose last edge probe found a stale render.
 *
 * The probe log is a twenty-row SITE-WIDE buffer, newest first by insertion,
 * so this measures the last twenty saves and never the site: a note with no
 * row here was evicted, not verified. Only the newest row per post counts —
 * an older stale verdict a later fresh one replaced is history.
 *
 * The two filters are the summary's own, applied at READ time for its reason
 * (inc/cloudflare-purge-verify.php:330-348): a row from before the detector
 * fix measured with a broken instrument, and a manual zone purge is the
 * operator moving the diagnostic they were reading.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_edge() {
	if ( ! defined( 'SN_CF_PROBE_LOG_OPT' ) || ! defined( 'SN_CF_PROBE_ALGO' ) || ! function_exists( 'get_option' ) ) {
		return attention_read();
	}
	try {
		$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
		if ( ! is_array( $log ) ) {
			return attention_unreadable();
		}
		$now    = attention_now();
		$door   = attention_door( 'sn-connections', 'cloudflare' );
		$seen   = array();
		$rows   = array();
		$newest = '';
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || (int) ( $entry['algo'] ?? 1 ) < SN_CF_PROBE_ALGO ) {
				continue;
			}
			if ( 'manual_zone_purge' === (string) ( $entry['source'] ?? '' ) ) {
				continue;
			}
			$pid = (int) ( $entry['post_id'] ?? 0 );
			if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true; // Newest first: the first row for a post is its verdict.
			if ( 'stale' !== (string) ( $entry['result'] ?? '' ) ) {
				continue;
			}
			$time     = (int) ( $entry['time'] ?? 0 );
			$stamp    = attention_stamp( $time );
			$headline = function_exists( 'snt_cf_freshness_headline' )
				? (string) \snt_cf_freshness_headline( 'stale' )
				: __( 'Edge served a stale render', 'signal-and-noise-tools' );
			$phrase   = function_exists( 'snt_cf_freshness_phrase' )
				? (string) \snt_cf_freshness_phrase( 'stale', $time, $now )
				: '';
			$subtitle = '' !== $phrase ? $headline . ', ' . $phrase : $headline;
			if ( ! empty( $entry['escalated'] ) ) {
				$subtitle .= ' ' . __( 'A zone purge was forced.', 'signal-and-noise-tools' );
			}
			$rows[] = attention_row( array(
				'kind'       => 'edge',
				'key'        => (string) $pid,
				'title'      => attention_post_title( $pid, (string) ( $entry['url'] ?? '' ) ),
				'subtitle'   => $subtitle,
				'tone'       => 'warning',
				'stamp'      => $stamp,
				'source'     => __( 'The purge verification log (newest twenty, site-wide)', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Cloudflare in S&N Dashboard', 'signal-and-noise-tools' ),
				'post_id'    => $pid,
			) );
			if ( $stamp > $newest ) {
				$newest = $stamp;
			}
		}
		return attention_read( $rows, $newest );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Citations nobody has checked, and citations whose check has gone stale.
 *
 * Two figures, never one: `never_checked` is a row nobody has looked at and
 * "due" is a row whose look has aged out, and the store keeps them apart
 * deliberately (a tier missing from a readout would be indistinguishable from
 * a tier nobody measured). The due read is bounded, so the row says "or more"
 * when it hits its bound rather than reporting the bound as the total.
 *
 * The two SQL reads are exactly where the sandbox's SQLite refuses, which is
 * the unreadable row's first real case.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_citations() {
	if ( ! function_exists( 'sn_cit_counts' ) || ! function_exists( 'sn_cit_due_for_check' ) ) {
		return attention_read();
	}
	try {
		$counts = \sn_cit_counts();
		$due    = \sn_cit_due_for_check( ATTENTION_CITATIONS_DUE_CAP, ATTENTION_CITATIONS_STALE_DAYS );
		if ( ! is_array( $counts ) || ! is_array( $due ) ) {
			return attention_unreadable();
		}
		// A live query: the stamp is when it was read, not when the rows were
		// written -- the table itself carries no reading time.
		$stamp  = attention_stamp( attention_now() );
		$door   = attention_door( 'sn-tools', 'citations' );
		$label  = __( 'Open Citations in S&N Dashboard', 'signal-and-noise-tools' );
		$source = __( 'The citations table, read just now', 'signal-and-noise-tools' );
		$rows   = array();
		$never  = (int) ( $counts['never_checked'] ?? 0 );
		if ( $never > 0 ) {
			$rows[] = attention_row( array(
				'kind'       => 'citations',
				'key'        => 'never-checked',
				'title'      => __( 'Citations never checked', 'signal-and-noise-tools' ),
				/* translators: %d: a count of citation rows. */
				'subtitle'   => sprintf( _n( '%d citation has never been checked', '%d citations have never been checked', $never, 'signal-and-noise-tools' ), $never ),
				'tone'       => 'warning',
				'stamp'      => $stamp,
				'source'     => $source,
				'door'       => $door,
				'door_label' => $label,
			) );
		}
		$due_n = count( $due );
		if ( $due_n > 0 ) {
			$rows[] = attention_row( array(
				'kind'       => 'citations',
				'key'        => 'due',
				'title'      => __( 'Citations due for a check', 'signal-and-noise-tools' ),
				'subtitle'   => $due_n >= ATTENTION_CITATIONS_DUE_CAP
					/* translators: 1: a bounded count. 2: the staleness window in days. */
					? sprintf( __( '%1$d or more citations are due for a check (unchecked, or checked over %2$d days ago)', 'signal-and-noise-tools' ), $due_n, ATTENTION_CITATIONS_STALE_DAYS )
					/* translators: 1: a count. 2: the staleness window in days. */
					: sprintf( __( '%1$d citations are due for a check (unchecked, or checked over %2$d days ago)', 'signal-and-noise-tools' ), $due_n, ATTENTION_CITATIONS_STALE_DAYS ),
				// A due date, not a failure: the verifier drains ten an hour.
				'tone'       => 'neutral',
				'stamp'      => $stamp,
				'source'     => $source,
				'door'       => $door,
				'door_label' => $label,
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Fragments and posts with a transition inside the next twenty-four hours.
 *
 * sn_admin_schedule_ordered_rows() already merges the two sources into one
 * list ordered by the soonest FUTURE boundary, with "nothing pending" (ts 0)
 * sorted last — so the window is a filter over its output and never a second
 * ordering rule that could disagree with the admin leaf's.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_schedule() {
	if ( ! function_exists( 'sn_admin_schedule_ordered_rows' ) || ! function_exists( 'sn_schedule_all' ) || ! function_exists( 'sn_schedule_future_posts' ) ) {
		return attention_read();
	}
	try {
		$ordered = \sn_admin_schedule_ordered_rows( \sn_schedule_all(), \sn_schedule_future_posts() );
		if ( ! is_array( $ordered ) ) {
			return attention_unreadable();
		}
		$now   = attention_now();
		$stamp = attention_stamp( $now );
		$door  = attention_door( 'sn-connections', 'scheduled-content' );
		$label = __( 'Open Scheduled in S&N Dashboard', 'signal-and-noise-tools' );
		$rows  = array();
		foreach ( $ordered as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$ts = (int) ( $entry['ts'] ?? 0 );
			if ( $ts <= 0 || $ts > $now + ATTENTION_SOON ) {
				continue;
			}
			$row  = isset( $entry['row'] ) && is_array( $entry['row'] ) ? $entry['row'] : array();
			$when = attention_stamp( $ts ) . ' UTC';
			if ( 'post' === (string) ( $entry['kind'] ?? '' ) ) {
				$pid    = (int) ( $row['id'] ?? 0 );
				$rows[] = attention_row( array(
					'kind'       => 'schedule',
					'key'        => 'post-' . $pid,
					'title'      => '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : attention_post_title( $pid ),
					/* translators: %s: a UTC timestamp. */
					'subtitle'   => sprintf( __( 'publishes at %s', 'signal-and-noise-tools' ), $when ),
					'tone'       => 'neutral',
					'stamp'      => $stamp,
					'source'     => __( 'The scheduled-content queue', 'signal-and-noise-tools' ),
					'door'       => $door,
					'door_label' => $label,
					'post_id'    => $pid,
				) );
				continue;
			}
			// A fragment: the boundary that fires is whichever of the two the
			// ordered row resolved to, so the verb is read back off the row
			// rather than assumed to be the start.
			$opens  = function_exists( 'sn_admin_schedule_next_transition_ts' )
				? (int) \sn_admin_schedule_next_transition_ts( $row['starts_at'] ?? null, null ) === $ts
				: false;
			$ref    = (int) ( $row['target_ref'] ?? 0 );
			$rows[] = attention_row( array(
				'kind'       => 'schedule',
				'key'        => 'fragment-' . (int) ( $row['id'] ?? 0 ),
				'title'      => $ref > 0 ? attention_post_title( $ref, __( '(unlinked fragment)', 'signal-and-noise-tools' ) ) : __( '(unlinked fragment)', 'signal-and-noise-tools' ),
				'subtitle'   => $opens
					/* translators: %s: a UTC timestamp. */
					? sprintf( __( 'opens at %s', 'signal-and-noise-tools' ), $when )
					/* translators: %s: a UTC timestamp. */
					: sprintf( __( 'closes at %s', 'signal-and-noise-tools' ), $when ),
				'tone'       => 'neutral',
				'stamp'      => $stamp,
				'source'     => __( 'The scheduled-content queue', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => $label,
				'post_id'    => $ref,
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * Posts and pages waiting for review.
 *
 * wp_count_posts() is a SITE-WIDE count: it does not carry the author scope
 * the post sections put on their own unpublished half, and WordPress offers
 * no `perm` that would (`readable` guards `private` and leaves draft, pending
 * and scheduled unrestricted). So the row is offered only to a reader who may
 * edit others' posts of that type — for anyone else the honest surface is the
 * section's own Pending pill, which IS scoped.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_pending() {
	if ( ! function_exists( 'wp_count_posts' ) || ! function_exists( 'get_post_type_object' ) ) {
		return attention_read();
	}
	try {
		$stamp = attention_stamp( attention_now() );
		$rows  = array();
		foreach ( array( 'post' => 'notes', 'page' => 'pages' ) as $type => $section ) {
			$object = get_post_type_object( $type );
			$others = is_object( $object ) && isset( $object->cap->edit_others_posts ) ? (string) $object->cap->edit_others_posts : '';
			if ( '' === $others || ! function_exists( 'current_user_can' ) || ! current_user_can( $others ) ) {
				continue;
			}
			$counts = wp_count_posts( $type );
			if ( ! is_object( $counts ) ) {
				return attention_unreadable();
			}
			$n = (int) ( $counts->pending ?? 0 );
			if ( $n <= 0 ) {
				continue;
			}
			$rows[] = attention_row( array(
				'kind'       => 'pending',
				'key'        => $type,
				'title'      => 'page' === $type
					? __( 'Pages waiting for review', 'signal-and-noise-tools' )
					: __( 'Posts waiting for review', 'signal-and-noise-tools' ),
				'subtitle'   => 'page' === $type
					/* translators: %d: a count of pages. */
					? sprintf( _n( '%d page is pending review', '%d pages are pending review', $n, 'signal-and-noise-tools' ), $n )
					/* translators: %d: a count of posts. */
					: sprintf( _n( '%d post is pending review', '%d posts are pending review', $n, 'signal-and-noise-tools' ), $n ),
				'tone'       => 'neutral',
				'stamp'      => $stamp,
				'source'     => __( 'The post counts, read just now', 'signal-and-noise-tools' ),
				'section'    => $section,
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * The last health scan's failing checks.
 *
 * READS the cached scan; it never runs one. sn_health_flagged_checks() is the
 * same pure accessor the desktop badge counts, so it is already narrowed to
 * the `health` surface and already excludes the advisory tier — which is also
 * why the integrity rows above are not double-counted here (they live on the
 * `integrity` surface, outside this tally).
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_health() {
	if ( ! function_exists( 'sn_health_last_scan' ) || ! function_exists( 'sn_health_flagged_checks' ) ) {
		return attention_read();
	}
	try {
		$scan = \sn_health_last_scan();
		if ( ! is_array( $scan ) ) {
			// Never scanned: the reader exists and has nothing to read.
			return attention_unreadable();
		}
		$stamp   = attention_stamp( $scan['scanned_at'] ?? '' );
		$door    = attention_health_door();
		$rows    = array();
		$flagged = (array) \sn_health_flagged_checks( $scan );
		foreach ( $flagged as $key => $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$n    = (int) ( $check['count'] ?? 0 );
			$hint = trim( (string) ( $check['fix_hint'] ?? '' ) );
			$rows[] = attention_row( array(
				'kind'       => 'health',
				'key'        => (string) $key,
				'title'      => '' !== (string) ( $check['label'] ?? '' ) ? (string) $check['label'] : (string) $key,
				'subtitle'   => '' !== $hint
					/* translators: 1: a count of findings. 2: the check's own fix hint. */
					? sprintf( __( '%1$d findings. %2$s', 'signal-and-noise-tools' ), $n, $hint )
					/* translators: %d: a count of findings. */
					: sprintf( _n( '%d finding', '%d findings', $n, 'signal-and-noise-tools' ), $n ),
				'tone'       => 'danger',
				'stamp'      => $stamp,
				'source'     => __( 'The last health scan', 'signal-and-noise-tools' ),
				'door'       => $door,
				'door_label' => __( 'Open Health in S&N Dashboard', 'signal-and-noise-tools' ),
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * The watches that are ripe.
 *
 * A watch is SILENT until its state ripens, which is the same argument this
 * whole queue is built on, so a ripe watch belongs here and a pending one
 * does not. The row's own `read` string is its door — there is no leaf.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_watches() {
	if ( ! function_exists( 'snt_watches_ripe' ) ) {
		return attention_read();
	}
	try {
		$now  = attention_now();
		$ripe = \snt_watches_ripe( $now );
		if ( ! is_array( $ripe ) ) {
			return attention_unreadable();
		}
		$stamp = attention_stamp( $now );
		$rows  = array();
		foreach ( $ripe as $watch ) {
			if ( ! is_array( $watch ) ) {
				continue;
			}
			$note = trim( (string) ( $watch['note'] ?? '' ) );
			$rows[] = attention_row( array(
				'kind'     => 'watches',
				'key'      => (string) ( $watch['id'] ?? '' ),
				'title'    => (string) ( $watch['label'] ?? ( $watch['id'] ?? '' ) ),
				'subtitle' => '' !== $note ? $note : __( 'ripe', 'signal-and-noise-tools' ),
				'tone'     => 'warning',
				'stamp'    => $stamp,
				/* translators: %s: where to read the watch, e.g. "sn-status{watches}". */
				'source'   => sprintf( __( 'A ripe watch; read it at %s', 'signal-and-noise-tools' ), (string) ( $watch['read'] ?? '' ) ),
			) );
		}
		return attention_read( $rows, $stamp );
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

/**
 * A machine-reader snapshot that has gone stale.
 *
 * THREE states, not two: snt_mr_snapshot_is_stale() answers true, false, or
 * NULL when there has never been a measurement — and absent is not stale. Only
 * an explicit `true` yields a row; null yields nothing here, because "nobody
 * has measured the readers" is a fact about the snapshot job, which the health
 * scan's own machine_reader_liveness check already carries.
 *
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_readers() {
	if ( ! function_exists( 'snt_mr_snapshot' ) || ! function_exists( 'snt_mr_snapshot_is_stale' ) ) {
		return attention_read();
	}
	try {
		$snap  = \snt_mr_snapshot();
		$stale = \snt_mr_snapshot_is_stale( $snap );
		$stamp = is_array( $snap ) ? attention_stamp( $snap['captured_at'] ?? '' ) : '';
		if ( true !== $stale ) {
			return attention_read( array(), $stamp );
		}
		$hours = defined( 'SN_MR_SNAPSHOT_STALE_AFTER' ) ? (int) ( \SN_MR_SNAPSHOT_STALE_AFTER / 3600 ) : 6;
		return attention_read(
			array(
				attention_row( array(
					'kind'       => 'readers',
					'key'        => 'snapshot',
					'title'      => __( 'Machine-reader snapshot', 'signal-and-noise-tools' ),
					'subtitle'   => '' !== $stamp
						/* translators: 1: a UTC timestamp. 2: the staleness window in hours. */
						? sprintf( __( 'Captured %1$s UTC, older than the %2$d-hour window', 'signal-and-noise-tools' ), $stamp, $hours )
						/* translators: %d: the staleness window in hours. */
						: sprintf( __( 'Older than the %d-hour window', 'signal-and-noise-tools' ), $hours ),
					'tone'       => 'warning',
					'stamp'      => $stamp,
					'source'     => __( 'The machine-reader snapshot', 'signal-and-noise-tools' ),
					'door'       => attention_door( 'sn-monitoring', 'machine-readers' ),
					'door_label' => __( 'Open Machine readers in S&N Dashboard', 'signal-and-noise-tools' ),
				) ),
			),
			$stamp
		);
	} catch ( \Throwable $e ) {
		return attention_unreadable();
	}
}

