<?php
/**
 * Signal & Noise Tools — ONE Cloudflare credential set.
 *
 * WHY. Until 14.10.0 the plugin held two Cloudflare tokens in two tabs: the
 * purge token under Connections › Cloudflare (later also the monitor's) and
 * an "Account Analytics Read token" under Measurement › Analytics (the
 * Analytics Engine SQL reads and the Edge view's GraphQL). Each tab carried
 * its own hint about which grant it needed, and on 2026-09-15 the owner
 * extended one token while the other silently lost its grant: the monitor
 * gained zone reads and the Analytics tab answered "HTTP 403 Authentication
 * error" the same hour. One token, one place, one grant list.
 *
 * WHAT. Three values, resolved here for every Cloudflare caller:
 *
 *   token       SN_CLOUDFLARE_API_TOKEN (wp-config) › sn_cf_api_token (option)
 *   zone_id     SN_CLOUDFLARE_ZONE_ID › sn_cf_zone_id
 *   account_id  SN_CF_ACCOUNT_ID › sn_cf_account_id
 *
 * The analytics token keeps working as an OVERRIDE (SN_CF_ANALYTICS_TOKEN,
 * then the sn_cf_analytics_token option) so nothing breaks on upgrade; the
 * Analytics tab says when the override is in force and offers to drop it.
 * On the first load after upgrade, an empty central token is filled from
 * the analytics one, once.
 *
 * @package SignalNoiseTools
 * @since 14.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_CREDENTIALS_MIGRATED_OPT = 'sn_cf_credentials_migrated';

/**
 * The account id: constant, then the central option.
 *
 * @return string '' when unset.
 */
function sn_cf_get_account_id() {
	if ( defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID ) {
		return (string) SN_CF_ACCOUNT_ID;
	}
	return (string) get_option( defined( 'SN_CF_ACCOUNT_ID_OPT' ) ? SN_CF_ACCOUNT_ID_OPT : 'sn_cf_account_id', '' );
}

/**
 * The analytics override token, or '' when the central token is in force.
 *
 * @return string
 */
function sn_cf_analytics_override_token() {
	if ( defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN ) {
		return (string) SN_CF_ANALYTICS_TOKEN;
	}
	return (string) get_option( defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ? SN_CF_ANALYTICS_TOKEN_OPT : 'sn_cf_analytics_token', '' );
}

/**
 * The token the ANALYTICS readers use: the override when set, else the
 * central token. Every Analytics Engine and Edge GraphQL call resolves here.
 *
 * @return string
 */
function sn_cf_analytics_token() {
	$override = sn_cf_analytics_override_token();
	if ( '' !== $override ) {
		return $override;
	}
	return function_exists( 'sn_cf_get_token' ) ? (string) sn_cf_get_token() : '';
}

/**
 * Whether a separate analytics token is in force, and from where.
 *
 * @return string 'constant' | 'option' | '' (no override: the central token is used).
 */
function sn_cf_analytics_override_source() {
	if ( defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN ) {
		return 'constant';
	}
	return '' !== (string) get_option( defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ? SN_CF_ANALYTICS_TOKEN_OPT : 'sn_cf_analytics_token', '' ) ? 'option' : '';
}

/**
 * The whole credential set with sources, for the leaves and sn-status.
 *
 * @return array<string,mixed>
 */
function sn_cf_credentials() {
	$token = function_exists( 'sn_cf_get_token' ) ? (string) sn_cf_get_token() : '';
	return array(
		'token_set'          => '' !== $token,
		'token_source'       => ( defined( 'SN_CLOUDFLARE_API_TOKEN' ) && SN_CLOUDFLARE_API_TOKEN ) ? 'constant' : ( '' !== $token ? 'option' : '' ),
		'zone_id'            => function_exists( 'sn_cf_get_zone' ) ? (string) sn_cf_get_zone() : '',
		'zone_source'        => defined( 'SN_CLOUDFLARE_ZONE_ID' ) ? 'constant' : '',
		'account_id'         => sn_cf_get_account_id(),
		'account_source'     => ( defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID ) ? 'constant' : '',
		'analytics_override' => sn_cf_analytics_override_source(),
	);
}

/**
 * Every grant the one token needs, with the caller that needs it. The
 * leaf paints this list so the owner can compare it against the token
 * summary in the Cloudflare dashboard. `status` says how sure we are:
 * `documented` (Cloudflare states it), `measured` (it worked here) or
 * `candidate` (refused so far; the docs name nothing).
 *
 * @return array<int,array{scope:string,grant:string,for:string,status:string}>
 */
function sn_cf_required_grants() {
	return array(
		array( 'scope' => 'Zone', 'grant' => 'Cache Purge › Purge', 'for' => __( 'edge purge on save and update', 'signal-and-noise-tools' ), 'status' => 'measured' ),
		array( 'scope' => 'Zone', 'grant' => 'Analytics › Read', 'for' => __( 'the monitor\'s zone reading; the Edge view', 'signal-and-noise-tools' ), 'status' => 'measured' ),
		array( 'scope' => 'Account', 'grant' => 'Account Analytics › Read', 'for' => __( 'Analytics Engine reads: S&N Analytics here, and the Machine Readers sensor and analytics worker through their own secrets (the same token loses them all)', 'signal-and-noise-tools' ), 'status' => 'measured' ),
		// 15.4.0: the edge posture. Each scope is the one the endpoint's API
		// reference names (developers.cloudflare.com/api). 15.4.1: measured,
		// all three answered on 2026-09-16 (the ruleset read also accepts
		// Account WAF Read at the account level; the schema lists both).
		array( 'scope' => 'Zone', 'grant' => 'Zone Settings › Read', 'for' => __( 'the edge posture: SSL mode, minimum TLS, Always Use HTTPS, Development Mode (Security › Firewall)', 'signal-and-noise-tools' ), 'status' => 'measured' ),
		array( 'scope' => 'Zone', 'grant' => 'Zone WAF › Read', 'for' => __( 'the custom firewall rules by name and state; the abilities rule\'s first witness', 'signal-and-noise-tools' ), 'status' => 'measured' ),
		array( 'scope' => 'Zone', 'grant' => 'DNS › Read', 'for' => __( 'DNSSEC status', 'signal-and-noise-tools' ), 'status' => 'measured' ),
	);
}

/**
 * Once per install: an empty central token takes the analytics token, so a
 * site that only ever configured Analytics keeps working when the callers
 * move to the central resolver. Runs on init; the flag option makes it a
 * single write ever. Never touches a wp-config constant.
 *
 * @return string 'copied' | 'nothing' | 'done_before'
 */
function sn_cf_credentials_migrate() {
	if ( get_option( SN_CF_CREDENTIALS_MIGRATED_OPT ) ) {
		return 'done_before';
	}
	$central_locked = defined( 'SN_CLOUDFLARE_API_TOKEN' ) && SN_CLOUDFLARE_API_TOKEN;
	$central        = (string) get_option( defined( 'SN_CF_TOKEN_OPT' ) ? SN_CF_TOKEN_OPT : 'sn_cf_api_token', '' );
	$analytics      = (string) get_option( defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ? SN_CF_ANALYTICS_TOKEN_OPT : 'sn_cf_analytics_token', '' );
	$verdict        = 'nothing';
	if ( ! $central_locked && '' === $central && '' !== $analytics ) {
		update_option( defined( 'SN_CF_TOKEN_OPT' ) ? SN_CF_TOKEN_OPT : 'sn_cf_api_token', $analytics, false );
		$verdict = 'copied';
	}
	update_option( SN_CF_CREDENTIALS_MIGRATED_OPT, array( 'at' => time(), 'verdict' => $verdict ), false );
	return $verdict;
}
add_action( 'init', 'sn_cf_credentials_migrate', 5 );
