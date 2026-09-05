<?php
/**
 * Signal & Noise Tools — Content Health check: rights-signal anchoring gap.
 *
 * v10.39.0. Three things watch the rights surface and none of them watched
 * this one:
 *
 *   inc/health-check-rights-signals.php  is the LIVE surface still correct?
 *   verify:rights-signals (ledger CI)    is every ANCHORED claim sound?
 *   this file                            has the surface being served RIGHT
 *                                        NOW been anchored at all?
 *
 * A provenance worker that silently stopped re-anchoring leaves the first two
 * green forever: the live surface is still correct, and the old records are
 * still perfectly valid. Only the third question notices, and answering it
 * means comparing the live bytes against the newest ledger record.
 *
 * Detection is deliberately EXTERNAL rather than a heartbeat from the worker.
 * "Sweep completed" is a success-only readout, and anchorRightsSignals has
 * exactly that shape — a per-signal failure is caught, logged, and stepped
 * over — so a heartbeat would report healthy while one surface never anchored.
 *
 * The ledger's index.json (rights_signals section, added 2026-08-04) is what
 * makes this one cheap read instead of probing v1, v2, … until a 404.
 *
 * The pure evaluator is the tested surface (tests/health-check-rights-anchored.php)
 * and it owns the GRACE WINDOW too: the worker sweeps hourly, so a surface that
 * changed minutes ago is legitimately unanchored. A timing rule buried in a
 * fetch wrapper is a rule nobody can test.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RIGHTS_ANCHOR_INDEX_URL = 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/index.json';
const SN_RIGHTS_ANCHOR_STATE_OPT = 'snt_rights_anchor_drift';
/** Two hourly sweeps. One absorbs the legitimate edit→sweep gap; two missed is a stuck anchor. */
const SN_RIGHTS_ANCHOR_GRACE = 7200;

/**
 * Pure evaluator: live bodies + ledger rows + remembered state in, findings and
 * the next state out. Calls no WordPress function, so the fixture can drive
 * every timing path offline.
 *
 * Divergence alone is not a fault — the worker anchors on an hourly tick. What
 * is a fault is divergence that OUTLIVES the window, so the first sighting is
 * remembered and only a later scan can accuse. A live hash that changes again
 * re-stamps the clock: that is a fresh edit, not a stuck anchor.
 *
 * @param array<string,string> $live    slug => the exact bytes the surface served.
 *                                      A slug absent here was not fetched and is
 *                                      skipped, never accused.
 * @param array|null           $anchors index.json's rights_signals rows, or null
 *                                      when the ledger was unreachable.
 * @param array                $state   Previous SN_RIGHTS_ANCHOR_STATE_OPT value.
 * @param int                  $now     Unix time.
 * @param int                  $grace   Seconds of divergence tolerated. Passed in
 *                                      rather than filtered here — this function
 *                                      calls no WordPress function, which is what
 *                                      lets the fixture drive every timing path.
 * @return array{status:string,findings:array,state:array}
 */
function snt_rights_anchor_evaluate( $live, $anchors, $state, $now, $grace = SN_RIGHTS_ANCHOR_GRACE ) {
	// An outage is a gap in evidence, never evidence of a stuck anchor — the
	// sibling rights-drift and ledger-CI checks' shared convention. Returning
	// the state UNCHANGED matters: overwriting it with an empty array would
	// silently restart every grace clock on each unreachable scan, so a
	// permanently unreachable ledger could never accumulate a finding.
	if ( ! is_array( $anchors ) ) {
		return array( 'status' => 'advisory', 'findings' => array(), 'state' => is_array( $state ) ? $state : array() );
	}

	$anchored = array();
	foreach ( (array) $anchors as $row ) {
		if ( is_array( $row ) && isset( $row['slug'] ) ) {
			$anchored[ (string) $row['slug'] ] = (string) ( $row['content_hash'] ?? '' );
		}
	}

	$findings  = array();
	$nextState = array();
	foreach ( (array) $live as $slug => $body ) {
		$hash = hash( 'sha256', (string) $body );
		if ( isset( $anchored[ $slug ] ) && hash_equals( $anchored[ $slug ], $hash ) ) {
			continue; // anchored; any remembered drift for it is dropped.
		}
		$previous  = isset( $state[ $slug ] ) && is_array( $state[ $slug ] ) ? $state[ $slug ] : null;
		$sameDrift = $previous && isset( $previous['hash'] ) && $previous['hash'] === $hash;
		$firstSeen = $sameDrift ? (int) $previous['first_seen'] : (int) $now;
		$nextState[ $slug ] = array( 'hash' => $hash, 'first_seen' => $firstSeen );

		if ( ( $now - $firstSeen ) < (int) $grace ) {
			continue; // inside the window — the next hourly sweep should catch it.
		}
		$known = isset( $anchored[ $slug ] );
		$findings[] = array(
			'subject' => $slug,
			'note'    => $known
				? sprintf(
					'The served %s has not matched its anchored record since %s: the provenance worker\'s hourly sweep should have anchored it. Its ledger record is still valid, just no longer the surface being served.',
					$slug,
					gmdate( 'Y-m-d H:i', $firstSeen ) . 'Z'
				)
				: sprintf( 'The ledger has no record for %s at all, so nothing anchors the surface currently being served.', $slug ),
		);
	}

	return array( 'status' => 'ok', 'findings' => $findings, 'state' => $nextState );
}

/**
 * The fixed own-domain fetch targets, one per anchored surface. Extracted
 * pure so the suite can assert the table itself; still HARDCODED paths on
 * home_url, never configurable input — mirroring the sibling probe's
 * allowlist.
 *
 * @return array<string,string>
 */
function snt_rights_anchor_targets() {
	return array(
		'robots-txt'    => home_url( '/robots.txt' ),
		'tdmrep-json'   => home_url( '/.well-known/tdmrep.json' ),
		'license-xml'   => home_url( '/license.xml' ),
		'tdm-policy'    => home_url( '/tdm-policy/' ),
		// v5 (2026-08): the WebMCP bridge — the one script agents execute,
		// anchored like the terms it acts under. Design:
		// docs/webmcp-native-design.md.
		'webmcp-bridge' => home_url( '/webmcp/bridge.js' ),
	);
}

/**
 * The check: fetch the five surfaces + the ledger index, evaluate, persist the
 * grace state, pack.
 *
 * @return array sn_health_pack_check envelope.
 */
function snt_health_check_rights_anchored() {
	$label    = 'Rights signals are anchored';
	$fix_hint = 'The live rights surface has drifted from the public ledger for over two hourly sweeps: the provenance worker is not re-anchoring it. Check the sn-provenance worker\'s cron logs (Cloudflare observability) for rights-signals errors; the records already in the ledger remain valid.';

	if ( ! apply_filters( 'sn_health_rights_anchored_check_enabled', true ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'rights-anchored check disabled by filter' );
	}

	$targets = snt_rights_anchor_targets();

	if ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Probe skipped: the site host failed the SSRF guard. The check will retry on the next scan.' );
	}

	$args = array(
		'timeout'     => 5,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array( 'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' rights-anchor-check' ),
	);

	// A surface we could not fetch is OMITTED, not recorded as empty: hashing a
	// failed fetch would manufacture a divergence out of an outage.
	$live = array();
	foreach ( $targets as $slug => $url ) {
		$resp = wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			continue;
		}
		$live[ $slug ] = (string) wp_remote_retrieve_body( $resp );
	}

	if ( array() === $live && array() !== $targets ) {
		// Every live surface failed to fetch: the evaluator would loop over
		// nothing and report 'ok'. An outage on this side is the same gap in
		// evidence as one on the ledger side (v13.97.5, #1042).
		return sn_health_pack_check( $label, array(), $fix_hint, 'Probe skipped: none of the live rights surfaces could be fetched from this host, so there was nothing to compare against the ledger. The check retries on the next scan.' );
	}

	$anchors = null;
	$index   = wp_remote_get( SN_RIGHTS_ANCHOR_INDEX_URL, $args );
	if ( ! is_wp_error( $index ) && 200 === (int) wp_remote_retrieve_response_code( $index ) ) {
		$decoded = json_decode( (string) wp_remote_retrieve_body( $index ), true );
		if ( is_array( $decoded ) && isset( $decoded['rights_signals'] ) && is_array( $decoded['rights_signals'] ) ) {
			$anchors = $decoded['rights_signals'];
		}
	}

	$state  = get_option( SN_RIGHTS_ANCHOR_STATE_OPT, array() );
	$grace  = (int) apply_filters( 'sn_health_rights_anchor_grace', SN_RIGHTS_ANCHOR_GRACE );
	$result = snt_rights_anchor_evaluate( $live, $anchors, is_array( $state ) ? $state : array(), time(), $grace );
	update_option( SN_RIGHTS_ANCHOR_STATE_OPT, $result['state'], false );

	if ( 'advisory' === $result['status'] ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Probe skipped: the public ledger index was unreachable. An outage is a gap in evidence, never evidence of drift: the check will retry on the next scan.' );
	}
	return sn_health_pack_check( $label, $result['findings'], $fix_hint );
}
