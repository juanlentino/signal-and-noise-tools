<?php
/**
 * Signal & Noise Tools — Spend watch (owner-only health signals).
 *
 * The "Spend watched like uptime" planned row: GitHub Actions minutes and
 * AI spend as health signals with the health family's honesty contract.
 * The gate — "every number read from what the platforms actually report,
 * never estimated" — is structural here: there is no code path that
 * multiplies, projects, or defaults a figure. A platform read either
 * returns the number or the tile says "unknown".
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
 * Unconfigured = the section is absent entirely (the uptime-widget
 * precedent): "unknown" is for a credentialed read that failed, not a nag
 * to configure one.
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

/**
 * The Spend section for the S&N Health widget. '' when neither credential
 * is configured; otherwise one line per configured signal — the reported
 * number or "unknown", nothing else.
 *
 * @return string Escaped-at-build HTML.
 */
function sn_spend_watch_health_section() {
	$gh = sn_spend_gh_usage();
	$ai = sn_spend_ai_cost();
	if ( null === $gh && null === $ai ) {
		return '';
	}
	$html = '<div class="sn-aw-spend"><p class="sn-aw-trend-l">' . esc_html__( 'Spend', 'signal-and-noise-tools' ) . '</p>';
	if ( null !== $gh ) {
		if ( ! empty( $gh['ok'] ) ) {
			// Enhanced-report source: usage only — the plan quota is not
			// reported by this endpoint, so no "of N" is ever shown.
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: 1: minutes used, 2: billed dollars */
					__( 'Actions minutes used (account, month to date): %1$s — $%2$s billed', 'signal-and-noise-tools' ),
					number_format_i18n( (int) $gh['used'] ),
					number_format( (float) $gh['billed'], 2 )
				)
			) . '</p>';
		} else {
			$html .= '<p>' . esc_html__( 'Actions minutes: unknown (billing read failed).', 'signal-and-noise-tools' ) . '</p>';
		}
	}
	if ( null !== $ai ) {
		$html .= ! empty( $ai['ok'] )
			? '<p>' . esc_html(
				sprintf(
					/* translators: %s: month-to-date cost in USD */
					__( 'AI spend (month to date): $%s', 'signal-and-noise-tools' ),
					number_format( (float) $ai['total'], 2 )
				)
			) . '</p>'
			: '<p>' . esc_html__( 'AI spend: unknown (cost read failed).', 'signal-and-noise-tools' ) . '</p>';
	}
	return $html . '</div>';
}

/**
 * Save both credentials from the monitoring form (Better Stack contract:
 * masked round-trip never writes, the literal 'clear' deletes, fresh value
 * stores non-autoloaded and drops the snapshot).
 *
 * @param array $post The posted monitoring form.
 */
function sn_spend_watch_handle_save( $post ) {
	$fields = array(
		SN_SPEND_GH_TOKEN_OPT => array( 'const' => 'SN_SPEND_GH_TOKEN', 'transient' => SN_SPEND_GH_TRANSIENT ),
		SN_SPEND_AI_KEY_OPT   => array( 'const' => 'SN_SPEND_AI_ADMIN_KEY', 'transient' => SN_SPEND_AI_TRANSIENT ),
	);
	foreach ( $fields as $opt => $meta ) {
		if ( defined( $meta['const'] ) && constant( $meta['const'] ) ) {
			continue; // Constant-locked installs never reach the field.
		}
		$value = isset( $post[ $opt ] ) ? sanitize_text_field( wp_unslash( $post[ $opt ] ) ) : '';
		if ( 'clear' === $value ) {
			delete_option( $opt );
			delete_transient( $meta['transient'] );
		} elseif ( '' !== $value && 0 !== strpos( $value, '••••' ) ) {
			update_option( $opt, $value, false );
			delete_transient( $meta['transient'] );
		}
	}
}

/**
 * The two credential fields for the monitoring fieldset (Better Stack
 * markup vocabulary; sn_mask_secret round-trip).
 *
 * @return string
 */
function sn_spend_watch_settings_fields_html() {
	$html   = '';
	$fields = array(
		SN_SPEND_GH_TOKEN_OPT => array(
			'const' => 'SN_SPEND_GH_TOKEN',
			'label' => __( 'GitHub billing token (optional)', 'signal-and-noise-tools' ),
			'help'  => __( 'Classic PAT with the user scope. Powers the account-wide Actions-minutes line in the Health widget. Leave the obscured value alone to keep the existing token.', 'signal-and-noise-tools' ),
		),
		SN_SPEND_AI_KEY_OPT   => array(
			'const' => 'SN_SPEND_AI_ADMIN_KEY',
			'label' => __( 'Anthropic admin key (optional)', 'signal-and-noise-tools' ),
			'help'  => __( 'Organization admin key for the cost report. Powers the month-to-date AI-spend line. Leave the obscured value alone to keep the existing key.', 'signal-and-noise-tools' ),
		),
	);
	foreach ( $fields as $opt => $f ) {
		$html .= '<div class="sn-field"><label class="sn-field-label" for="' . esc_attr( $opt ) . '">' . esc_html( $f['label'] ) . '</label>';
		if ( defined( $f['const'] ) && constant( $f['const'] ) ) {
			$html .= '<input type="text" id="' . esc_attr( $opt ) . '" value="' . esc_attr( '••••' ) . '" disabled class="sn-mono">';
			$html .= '<p class="sn-field-helper"><strong>' . esc_html__( 'Locked.', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( 'Set via', 'signal-and-noise-tools' ) . ' <code>' . esc_html( $f['const'] ) . '</code> ' . esc_html__( 'in', 'signal-and-noise-tools' ) . ' <code>wp-config.php</code>.</p>';
		} else {
			$obscured = function_exists( 'sn_mask_secret' ) ? sn_mask_secret( (string) get_option( $opt, '' ) ) : '';
			$html    .= '<input type="text" id="' . esc_attr( $opt ) . '" name="' . esc_attr( $opt ) . '" value="' . esc_attr( $obscured ) . '" placeholder="' . esc_attr__( 'Paste a fresh value to update; type \'clear\' to remove', 'signal-and-noise-tools' ) . '" class="sn-mono">';
			$html    .= '<p class="sn-field-helper">' . esc_html( $f['help'] ) . '</p>';
		}
		$html .= '</div>';
	}
	return $html;
}
