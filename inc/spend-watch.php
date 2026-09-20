<?php
/**
 * Signal & Noise Tools — Spend watch (owner-only health signals).
 *
 * The "Spend watched like uptime" planned row: GitHub Actions minutes and
 * AI spend as health signals with the health family's honesty contract.
 * The gate — "every number read from what the platforms actually report,
 * never estimated" — is structural here: there is no code path that
 * multiplies, projects, or defaults a figure. A platform read either
 * returns the number or the row says "unknown". Painted on AI › Models &
 * Budget (apps/sn-dashboard/parts/leaves/ai-models-budget-parts.php).
 *
 * Two optional credentials, each Better-Stack idiom (constant wins over a
 * non-autoloaded option; masked round-trip; the literal 'clear' removes):
 *
 * - GitHub fine-grained PAT with Plan:read (or classic with `user` scope)
 *   for the ENHANCED billing usage report — the legacy plan endpoint is
 *   410 Gone (retired 2026, enhanced billing platform) and is never
 *   called. ACCOUNT-WIDE minutes. NOTE the per-repo /timing API is NOT
 *   used anywhere — it returns total_ms:0 on some accounts.
 * - Anthropic organization admin key for the cost report. The response
 *   shape is summed defensively (every reported amount); a shape mismatch
 *   is "unknown", never a guess — verify on first configure.
 *
 * Unconfigured = the reader returns null and the box on AI › Models &
 * Budget reads "not set": "unknown" is for a credentialed read that
 * failed, never a guessed figure.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SPEND_GH_TOKEN_OPT   = 'sn_spend_gh_token';
const SN_SPEND_AI_KEY_OPT     = 'sn_spend_ai_admin_key';
const SN_SPEND_GH_TRANSIENT   = 'sn_spend_gh_usage';
// _v2 (v10.75.1): the unit fix must not serve a stale cents-as-dollars
// snapshot for up to 6h after install — a new key orphans the old cache.
const SN_SPEND_AI_TRANSIENT   = 'sn_spend_ai_cost_v2';
const SN_SPEND_TTL_OK         = 6 * HOUR_IN_SECONDS;
const SN_SPEND_TTL_FAIL       = 600;

/** Resolve the GitHub token: constant wins over option. */
function sn_spend_gh_token() {
	if ( defined( 'SN_SPEND_GH_TOKEN' ) && SN_SPEND_GH_TOKEN ) {
		return (string) SN_SPEND_GH_TOKEN;
	}
	return (string) get_option( SN_SPEND_GH_TOKEN_OPT, '' );
}

/** Resolve the Anthropic admin key: constant wins over option. */
function sn_spend_ai_key() {
	if ( defined( 'SN_SPEND_AI_ADMIN_KEY' ) && SN_SPEND_AI_ADMIN_KEY ) {
		return (string) SN_SPEND_AI_ADMIN_KEY;
	}
	return (string) get_option( SN_SPEND_AI_KEY_OPT, '' );
}

/** The GitHub login whose account billing is read. */
function sn_spend_gh_login() {
	return (string) apply_filters( 'sn_spend_gh_login', 'juanlentino' );
}

/**
 * Parse the ENHANCED billing usage report (the endpoint fine-grained PATs
 * can read: /users/{u}/settings/billing/usage, verified against the live
 * REST docs 2026-08-09). Usage only — the plan's included-minutes quota is
 * NOT reported here, so the caller must never pair this number with an
 * invented "of 3,000". Missing usageItems = unknown (null); an empty list
 * is a measured zero. Only Actions minute items count; netAmount is the
 * platform's own billed-dollars figure and is reported verbatim.
 *
 * @param mixed $data Decoded usage-report JSON.
 * @return array{used:int, billed:float}|null
 */
function sn_spend_gh_report_minutes( $data ) {
	if ( ! is_array( $data ) || ! isset( $data['usageItems'] ) || ! is_array( $data['usageItems'] ) ) {
		return null;
	}
	$used   = 0.0;
	$billed = 0.0;
	foreach ( $data['usageItems'] as $item ) {
		$product = strtolower( (string) ( $item['product'] ?? '' ) );
		$unit    = strtolower( (string) ( $item['unitType'] ?? '' ) );
		if ( false === strpos( $product, 'actions' ) || false === strpos( $unit, 'minute' ) ) {
			continue;
		}
		$used   += (float) ( $item['quantity'] ?? 0 );
		$billed += (float) ( $item['netAmount'] ?? 0 );
	}
	return array( 'used' => (int) round( $used ), 'billed' => round( $billed, 2 ) );
}

/**
 * Fetch (cached) account-wide Actions minutes. Snapshot shape:
 * {ok:bool, used?, included?, pct?} — ok=false caches SHORT so a retry can
 * tell a recorded failure from never-fetched.
 *
 * @return array|null Snapshot, or null when unconfigured.
 */
function sn_spend_gh_usage() {
	if ( '' === sn_spend_gh_token() ) {
		return null;
	}
	$cached = get_transient( SN_SPEND_GH_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	// v10.75.3: the enhanced usage report is the ONLY door. The legacy plan
	// endpoint is 410 Gone under GitHub's enhanced billing platform —
	// owner-caught in httpdiag: every refresh fired a permanently dead
	// request before the fallback succeeded. No token type revives a
	// retired endpoint; never "fall back" to a corpse. (The fixture greps
	// this file for the dead path, so it is deliberately not named here.)
	$res  = wp_safe_remote_get(
		'https://api.github.com/users/' . rawurlencode( sn_spend_gh_login() ) . '/settings/billing/usage?year=' . gmdate( 'Y' ) . '&month=' . gmdate( 'n' ),
		array(
			'headers'     => array(
				'Authorization' => 'Bearer ' . sn_spend_gh_token(),
				'Accept'        => 'application/vnd.github+json',
			),
			'timeout'     => 6,
			'redirection' => 0,
		)
	);
	$snap = array( 'ok' => false );
	if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
		$report = sn_spend_gh_report_minutes( json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
		if ( null !== $report ) {
			$snap = array( 'ok' => true, 'src' => 'usage' ) + $report;
		}
	}
	set_transient( SN_SPEND_GH_TRANSIENT, $snap, $snap['ok'] ? SN_SPEND_TTL_OK : SN_SPEND_TTL_FAIL );
	return $snap;
}

/**
 * Sum every reported amount in a cost-report response, defensively: the
 * exact shape may evolve, but an "amount" the platform reported is the only
 * thing ever counted. No amounts found = null (unknown), never $0.00 — a
 * shape mismatch must not impersonate a free month.
 *
 * UNIT (v10.75.1, owner-caught: the first live read showed $12,038.82 —
 * cents rendered as dollars): the cost report's documented contract is
 * "decimal strings in lowest units (cents)". The conversion to dollars
 * happens exactly once, here at the sum — never per-amount, never again
 * downstream.
 *
 * @param mixed $data Decoded cost-report JSON.
 * @return float|null Total in DOLLARS, or null when nothing was reported.
 */
function sn_spend_ai_sum_amounts( $data ) {
	$sum   = 0.0;
	$found = false;
	$walk  = function ( $node ) use ( &$walk, &$sum, &$found ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $value ) {
			if ( 'amount' === $key && is_numeric( $value ) ) {
				$sum  += (float) $value;
				$found = true;
			} elseif ( is_array( $value ) ) {
				$walk( $value );
			}
		}
	};
	$walk( $data );
	return $found ? round( $sum / 100, 2 ) : null;
}

/**
 * Fetch (cached) the month-to-date AI cost from the Anthropic admin API.
 *
 * @return array|null {ok:bool, total?:float}, or null when unconfigured.
 */
function sn_spend_ai_cost() {
	if ( '' === sn_spend_ai_key() ) {
		return null;
	}
	$cached = get_transient( SN_SPEND_AI_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	// Pagination (v10.75.1): the report buckets daily and pages — a
	// single-page read of a month silently UNDER-counts. Follow next_page
	// while has_more; a failure on ANY page yields unknown, because a
	// partial sum must never impersonate the month total. The page bound is
	// a runaway stop far above a month of daily buckets, not a quota.
	$base = 'https://api.anthropic.com/v1/organizations/cost_report?limit=31&starting_at=' . rawurlencode( gmdate( 'Y-m-01\T00:00:00\Z' ) );
	$args = array(
		'headers'     => array(
			'x-api-key'         => sn_spend_ai_key(),
			'anthropic-version' => '2023-06-01',
		),
		'timeout'     => 6,
		'redirection' => 0,
	);
	$cents_found = false;
	$total       = 0.0;
	$page        = '';
	$snap        = array( 'ok' => false );
	for ( $i = 0; $i < 12; $i++ ) {
		$res = wp_safe_remote_get( $base . ( '' === $page ? '' : '&page=' . rawurlencode( $page ) ), $args );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			$cents_found = false; // partial data -> unknown
			break;
		}
		$data       = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$page_total = sn_spend_ai_sum_amounts( $data );
		if ( null !== $page_total ) {
			$cents_found = true;
			$total      += $page_total;
		}
		if ( empty( $data['has_more'] ) || empty( $data['next_page'] ) ) {
			break;
		}
		$page = (string) $data['next_page'];
	}
	if ( $cents_found ) {
		$snap = array( 'ok' => true, 'total' => round( $total, 2 ) );
	}
	set_transient( SN_SPEND_AI_TRANSIENT, $snap, $snap['ok'] ? SN_SPEND_TTL_OK : SN_SPEND_TTL_FAIL );
	return $snap;
}
