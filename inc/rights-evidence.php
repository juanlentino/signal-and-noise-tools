<?php
/**
 * Signal & Noise Tools: rights evidence, the half that fetches and posts.
 *
 * Daily on cron and on demand: for the last complete month, one record per
 * AI-training family the sensor saw, composed by inc/rights-evidence-compose.php,
 * signed and anchored by the provenance worker as `kind: rights-evidence`
 * (worker 1.21.0). The composed bytes are stored BEFORE the POST and re-sent
 * verbatim on a retry, so a lost response can never produce a second record
 * with different bytes under the same path (the worker answers 409 to that,
 * 200-with-existing to the same bytes). No WordPress row stands behind a
 * record: anchoring is read off the public ledger, not confirmed back.
 *
 * @package SignalNoiseTools
 * @since 17.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RIGHTS_EVIDENCE_OPTION = 'sn_rights_evidence';
const SN_RIGHTS_EVIDENCE_HOOK   = 'sn_rights_evidence_daily';

/** Worker URL and secret set, the sensor configured. */
function sn_rights_evidence_is_ready() {
	return function_exists( 'sn_prov_worker_url' ) && '' !== sn_prov_worker_url()
		&& function_exists( 'sn_prov_hmac_secret' ) && '' !== sn_prov_hmac_secret()
		&& function_exists( 'snt_mr_config' ) && null !== snt_mr_config();
}

/** The stored ledger: month => family => {uuid, content_hash, canonical?, status, ledger_path, at, error}. */
function sn_rights_evidence_data() {
	$d = get_option( SN_RIGHTS_EVIDENCE_OPTION, array() );
	return is_array( $d ) ? $d : array();
}

/**
 * The public ledger's index.json, for the reservation block.
 *
 * @return array|null null when unreachable or not JSON.
 */
function sn_rights_evidence_ledger_index() {
	if ( ! function_exists( 'sn_prov_integrity_ledger_base' ) || ! function_exists( 'sn_prov_integrity_http_fetch' ) ) {
		return null;
	}
	$res = sn_prov_integrity_http_fetch( sn_prov_integrity_ledger_base() . 'index.json' );
	if ( 200 !== (int) ( $res['code'] ?? 0 ) ) {
		return null;
	}
	$json = json_decode( (string) ( $res['body'] ?? '' ), true );
	return is_array( $json ) ? $json : null;
}

/**
 * Sign and POST one record through the provenance webhook.
 *
 * @param string $uuid      The record id.
 * @param string $canonical The canonical bytes.
 * @return array{code:int,body:array} code 0 on a transport error.
 */
function sn_rights_evidence_post( $uuid, $canonical ) {
	return sn_rights_evidence_signed_post(
		sn_prov_worker_url(),
		array(
			'canonical'    => $canonical,
			'content_hash' => hash( 'sha256', $canonical ),
			'note_uid'     => $uuid,
			'version'      => 1,
			'kind'         => SN_RIGHTS_EVIDENCE_KIND,
		)
	);
}

/**
 * POST a JSON body to a worker endpoint, HMAC-signed over the exact bytes
 * (X-SN-Signature), no redirects, behind the outbound gate. Shared by the
 * record post and the retraction (inc/rights-evidence-retract.php).
 *
 * @param string $url    The endpoint, gated as given.
 * @param array  $fields The body, encoded in key order.
 * @return array{code:int,body:array} code 0 on a transport error or a refused url.
 */
function sn_rights_evidence_signed_post( $url, array $fields ) {
	$secret = sn_prov_hmac_secret();
	if ( ! sn_prov_url_allowed( $url ) ) {
		return array( 'code' => 0, 'body' => array( 'error' => 'worker url refused by the outbound gate' ) );
	}
	$body     = wp_json_encode( $fields );
	$response = wp_remote_post( $url, array(
		'timeout'     => 20,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'        => $body,
	) );
	if ( is_wp_error( $response ) ) {
		return array( 'code' => 0, 'body' => array( 'error' => $response->get_error_message() ) );
	}
	$out = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return array( 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => is_array( $out ) ? $out : array() );
}

/**
 * The months on hold (YYYY-MM). Seeded once with 2026-09 (the reservation
 * block attests versions not in force for the month, and September's rights
 * stream is truncated by our own probes); add_option never overwrites, so an
 * owner who empties the list keeps it empty.
 *
 * @param bool $seed Store the seed when absent (the pass does; a read does not).
 * @return string[]
 */
function sn_rights_evidence_held( $seed = true ) {
	$held = get_option( 'sn_rights_evidence_hold', null );
	if ( null === $held ) {
		$held = array( '2026-09' );
		if ( $seed ) {
			add_option( 'sn_rights_evidence_hold', $held, '', false );
		}
	}
	return array_values( array_filter( (array) $held, 'is_string' ) );
}

/**
 * Months skipped while held, oldest first. Kept until composed and posted.
 *
 * @return string[]
 */
function sn_rights_evidence_backlog() {
	return array_values( array_filter( (array) get_option( 'sn_rights_evidence_backlog', array() ), 'is_string' ) );
}

/** @param string[] $months */
function sn_rights_evidence_backlog_set( array $months ) {
	$months = array_values( array_unique( $months ) );
	sort( $months );
	update_option( 'sn_rights_evidence_backlog', $months, false );
}

/**
 * The oldest backlog month that is no longer held and can still be acted on,
 * as a month array with `in_window`; null when none. Inside the sensor's
 * 90-day window a month can be composed; past it, only its stored unposted
 * bytes can be re-sent, so it qualifies only while it has some. A month past
 * the window with nothing stored stays listed (the read shows it) and is
 * skipped.
 *
 * @param int      $now  Unix time.
 * @param string[] $held The hold list.
 * @return array|null
 */
function sn_rights_evidence_backlog_target( $now, array $held ) {
	foreach ( sn_rights_evidence_backlog() as $ym ) {
		$start = strtotime( $ym . '-01T00:00:00Z' );
		if ( in_array( $ym, $held, true ) || false === $start ) {
			continue;
		}
		$in_window = $now - $start <= 89 * DAY_IN_SECONDS;
		if ( $in_window || sn_rights_evidence_unposted( $ym ) ) {
			return array( 'month' => $ym, 'start' => gmdate( 'Y-m-01', $start ), 'end' => gmdate( 'Y-m-t', $start ), 'in_window' => $in_window );
		}
	}
	return null;
}

/**
 * Families of a month whose composed bytes are stored and not on the ledger.
 *
 * @param string $ym YYYY-MM.
 * @return string[]
 */
function sn_rights_evidence_unposted( $ym ) {
	$out = array();
	foreach ( (array) ( sn_rights_evidence_data()[ $ym ] ?? array() ) as $family => $e ) {
		if ( is_array( $e ) && '' !== (string) ( $e['canonical'] ?? '' ) && '' === (string) ( $e['ledger_path'] ?? '' ) ) {
			$out[] = (string) $family;
		}
	}
	return $out;
}

/**
 * POST one stored entry and fold the worker's answer into it.
 *
 * @param array $entry {uuid, canonical, ...}.
 * @param int   $now   Unix time.
 * @return array{0:array,1:string} The entry, and 'posted' or 'failed'.
 */
function sn_rights_evidence_send( array $entry, $now ) {
	$r    = sn_rights_evidence_post( $entry['uuid'], $entry['canonical'] );
	$kind = 'failed';
	if ( $r['code'] >= 200 && $r['code'] < 300 ) {
		$entry['status']      = (string) ( $r['body']['ots_status'] ?? 'pending' ); // The ledger's word, on a fresh record and on a re-send alike.
		$entry['ledger_path'] = (string) ( $r['body']['ledger_path'] ?? '' );
		$entry['error']       = '';
		unset( $entry['canonical'] ); // The ledger holds the bytes now.
		$kind = 'posted';
	} elseif ( 409 === $r['code'] ) {
		// The path exists with other bytes: the ledger's record is the record.
		// Terminal, never retried; the path is deterministic from the id.
		$entry['status']      = 'conflict';
		$entry['ledger_path'] = SN_RIGHTS_EVIDENCE_KIND . '/' . $entry['uuid'] . '/v1.json';
		$entry['error']       = '409 ' . (string) ( $r['body']['error'] ?? '' );
		unset( $entry['canonical'] );
	} else {
		$entry['status'] = 'unanchored';
		$entry['error']  = $r['code'] . ' ' . (string) ( $r['body']['error'] ?? '' );
	}
	$entry['at'] = $now;
	return array( $entry, $kind );
}

/**
 * The daily pass: compose what a month still lacks, post what is composed
 * and not yet on the ledger. Idempotent by (month, family).
 *
 * Order: refresh stored records from the ledger (F5), queue a held month,
 * readiness, the backlog target, the lock, re-send stored bytes, compose.
 *
 * @param int|null $now Unix time; null for time().
 * @return array{ok:bool,month:string,composed:int,posted:int,anchored:int,failed:int,error:string}
 */
function sn_rights_evidence_run( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$out = array( 'ok' => false, 'month' => '', 'composed' => 0, 'posted' => 0, 'anchored' => 0, 'failed' => 0, 'error' => '' );
	// Read-only against the public ledger, so it runs held or not, ready or not.
	sn_rights_evidence_refresh();
	// A held month composes and posts nothing: the ledger is append-only, so a
	// month waits until the owner lifts it. It is queued in the backlog FIRST,
	// before readiness, so an outage on the day the month closes cannot drop
	// it; a lifted backlog month goes first, one month per pass. All before the
	// lock, so a held pass never blocks another. rights-evidence-now runs this.
	$held    = sn_rights_evidence_held();
	$current = sn_rights_evidence_month( $now );
	if ( in_array( $current['month'], $held, true ) ) {
		sn_rights_evidence_backlog_set( array_merge( sn_rights_evidence_backlog(), array( $current['month'] ) ) );
	}
	if ( ! sn_rights_evidence_is_ready() ) {
		$out['error'] = 'not-ready';
		return $out;
	}
	$month        = sn_rights_evidence_backlog_target( $now, $held );
	$from_backlog = null !== $month;
	if ( ! $from_backlog ) {
		$month = $current + array( 'in_window' => true );
		if ( in_array( $month['month'], $held, true ) ) {
			$out['month'] = $month['month'];
			$out['error'] = 'held: ' . $month['month'];
			return $out;
		}
	}
	$out['month'] = $month['month'];
	// One pass at a time: cron and rights-evidence-now overlapping would compose
	// the same month twice with different composed_at, and the loser's bytes
	// would draw a 409 from the ledger.
	if ( get_transient( 'sn_rights_evidence_lock' ) ) {
		$out['error'] = 'a pass is already running';
		return $out;
	}
	set_transient( 'sn_rights_evidence_lock', 1, 5 * MINUTE_IN_SECONDS );
	$data   = sn_rights_evidence_data();
	$stored = (array) ( $data[ $month['month'] ] ?? array() );
	// Stored bytes first, for every family of the month whatever the sensor
	// says today: they are the record, and they need no sensor read.
	foreach ( $stored as $family => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( '' !== (string) ( $entry['ledger_path'] ?? '' ) ) {
			$out['anchored']++;
			continue; // On the ledger; the bytes are immutable now.
		}
		if ( '' === (string) ( $entry['canonical'] ?? '' ) ) {
			continue;
		}
		$sent = sn_rights_evidence_send( $entry, $now );
		$out[ $sent[1] ]++;
		$data[ $month['month'] ][ $family ] = $sent[0];
		update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false );
	}
	if ( ! empty( $month['in_window'] ) ) {
		// Skip what holds bytes or a ledger path; an entry with neither is recomposed.
		$skip         = array_keys( array_filter( $stored, static fn( $e ) => is_array( $e ) && ( '' !== (string) ( $e['canonical'] ?? '' ) || '' !== (string) ( $e['ledger_path'] ?? '' ) ) ) );
		$composed     = sn_rights_evidence_compose_month( $month, $now, $skip );
		$out['error'] = (string) $composed['error'];
		$site         = home_url( '/' );
		foreach ( $composed['payloads'] as $family => $payload ) {
			$canonical = sn_prov_canonical_json( $payload );
			$entry     = array(
				'uuid'         => sn_rights_evidence_uuid( $family, $month['month'], $site ),
				'content_hash' => hash( 'sha256', $canonical ),
				'canonical'    => $canonical,
				'status'       => 'composed',
				'ledger_path'  => '',
				'at'           => $now,
				'error'        => '',
			);
			$out['composed']++;
			$data[ $month['month'] ][ $family ] = $entry;
			update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false ); // Stored BEFORE the POST.
			$sent = sn_rights_evidence_send( $entry, $now );
			$out[ $sent[1] ]++;
			$data[ $month['month'] ][ $family ] = $sent[0];
			update_option( SN_RIGHTS_EVIDENCE_OPTION, $data, false );
		}
	}
	delete_transient( 'sn_rights_evidence_lock' );
	$out['ok'] = '' === $out['error'] && 0 === $out['failed'];
	// A backlog month leaves only when the pass was clean AND nothing of it is
	// left unposted, whichever family it belongs to.
	if ( $from_backlog && $out['ok'] && ! sn_rights_evidence_unposted( $month['month'] ) ) {
		sn_rights_evidence_backlog_set( array_diff( sn_rights_evidence_backlog(), array( $month['month'] ) ) );
	}
	return $out;
}

add_action( SN_RIGHTS_EVIDENCE_HOOK, 'sn_rights_evidence_run' );
add_action( 'init', static function () {
	if ( sn_rights_evidence_is_ready() && ! wp_next_scheduled( SN_RIGHTS_EVIDENCE_HOOK ) ) {
		wp_schedule_event( time() + 3600, 'daily', SN_RIGHTS_EVIDENCE_HOOK );
	}
} );
