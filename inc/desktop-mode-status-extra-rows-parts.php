<?php
/**
 * Signal & Noise Tools: the extra status rows, the Zenodo, analytics 2.0, watches and credentials rows.
 *
 * Split from inc/desktop-mode-status-extra.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Zenodo DOIs: production DOIs minted out of the subjects that should have one,
 * and how many documents' last deposit failed (an error stored, no DOI).
 *
 * @return array{minted:int,total:int,failed:int}|null
 */
function snt_desktop_zenodo() {
	$z = function_exists( 'snt_ability_zenodo_status' ) ? snt_ability_zenodo_status() : null;
	// The counts come from stored post meta: they stand without a token (a
	// rotation must not hide minted DOIs or the subjects still waiting).
	if ( ! is_array( $z ) || ! isset( $z['total'] ) ) {
		return null;
	}
	$failed = count( array_filter( (array) ( $z['missing'] ?? array() ), static fn( $r ) => '' !== (string) ( is_array( $r ) ? ( $r['error'] ?? '' ) : '' ) ) );
	return array( 'minted' => (int) ( $z['minted'] ?? 0 ), 'total' => (int) $z['total'], 'failed' => $failed );
}

/**
 * The analytics 2.0 verdict the daily check stored (inc/analytics-generation.php),
 * in the card's terms. Days are 'M j' labels. PURE.
 *
 * ok: `days` clean days (the clean day through the last day that matched)
 * since `since`. Not ok: `reset` names the day that did not match, '' when
 * the second generation has never been verified. `frozen` once the legacy
 * write stopped; `bad` when it stopped on a verdict that was not good (the
 * analytics_v2_frozen_bad watch's state).
 *
 * @param mixed $v The stored option.
 * @return array{ok:bool,days:int,since:string,last:string,reset:string,frozen:bool,bad:bool,why:string}|null Null when no verdict is stored yet.
 */
function snt_desktop_analytics2_shape( $v ) {
	if ( ! is_array( $v ) || ! isset( $v['at'] ) ) {
		return null;
	}
	$from  = defined( 'SN_ANALYTICS_V2_FROM' ) ? SN_ANALYTICS_V2_FROM : '2026-10-05';
	$clean = max( $from, (string) ( $v['clean_from'] ?? '' ) );
	$ts    = static fn( $day ) => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ? (int) strtotime( $day . ' 00:00:00 UTC' ) : 0;
	$ok    = ! empty( $v['ok'] );
	$last  = $ts( $v['day'] ?? '' );
	$frozen = ! empty( $v['frozen'] );
	return array(
		'ok'     => $ok,
		'days'   => $ok && $last >= $ts( $clean ) ? (int) round( ( $last - $ts( $clean ) ) / DAY_IN_SECONDS ) + 1 : 0,
		'since'  => gmdate( 'M j', $ts( $clean ) ),
		'last'   => $ok && $last > 0 ? gmdate( 'M j', $last ) : '', // the last exact day; a sampled day after it is neither counted nor a reset.
		'reset'  => $clean > $from ? gmdate( 'M j', $ts( $clean ) - DAY_IN_SECONDS ) : '',
		'frozen' => $frozen,
		'bad'    => $frozen && ( ! $ok || empty( $v['events_ok'] ) ),
		'why'    => (string) ( $v['why'] ?? '' ),
	);
}

/**
 * The watches due now (snt_watches_ripe(), the sn-status{watches} read), as
 * id and label. PURE.
 *
 * @param array $ripe Ripe watch rows.
 * @return array<int,array{id:string,label:string}>
 */
function snt_desktop_watches_shape( array $ripe ) {
	$out = array();
	foreach ( $ripe as $w ) {
		if ( is_array( $w ) && '' !== (string) ( $w['label'] ?? '' ) ) {
			$out[] = array( 'id' => (string) ( $w['id'] ?? '' ), 'label' => (string) $w['label'] );
		}
	}
	return $out;
}

/**
 * Credentials that are wrong now: keyring rows whose last Verify all verdict
 * refused or errored (never verified is not a failure), and the Cloudflare
 * API token when the daily monitor found it not active or expiring within 14
 * days (the Dashboard row's threshold). Ids and states only, never a value.
 * PURE given its inputs.
 *
 * @param mixed $keyring snt_ability_keyring_status().
 * @param mixed $cf      sn_cf_monitor_read().
 * @param int   $now     Unix time.
 * @return array{keyring:array<int,array{id:string,verdict:string}>,cloudflare:array{status:string,expires:string}|null}
 */
function snt_desktop_credentials_shape( $keyring, $cf, $now ) {
	$failing = array();
	foreach ( (array) ( is_array( $keyring ) ? ( $keyring['rows'] ?? array() ) : array() ) as $r ) {
		$verdict = is_array( $r ) ? (string) ( $r['verdict'] ?? '' ) : '';
		if ( in_array( $verdict, array( 'refused', 'error' ), true ) ) {
			$failing[] = array( 'id' => (string) ( $r['id'] ?? '' ), 'verdict' => $verdict );
		}
	}
	$token = null;
	// Not run yet or not configured is no reading: nothing to say wrong.
	if ( is_array( $cf ) && ! empty( $cf['configured'] ) && is_array( $cf['token'] ?? null ) ) {
		$t      = $cf['token'];
		$status = empty( $t['verified'] ) ? (string) ( $t['status'] ?? 'unverified' ) : (string) ( $t['status'] ?? '' );
		$exp    = '' !== (string) ( $t['expires_on'] ?? '' ) ? (int) strtotime( (string) $t['expires_on'] ) : 0;
		// "changed": saved after the last reading and not verified yet; no reading, not a fault.
		if ( 'changed' !== $status && ( 'active' !== $status || ( $exp > 0 && $exp < (int) $now + 14 * DAY_IN_SECONDS ) ) ) {
			$token = array( 'status' => $status, 'expires' => $exp > 0 ? gmdate( 'M j', $exp ) : '' );
		}
	}
	return array( 'keyring' => $failing, 'cloudflare' => $token );
}
